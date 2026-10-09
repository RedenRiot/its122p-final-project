<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Parse and validate incoming JSON request body.
 */
function auth_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) Response::error('Request body must be valid JSON.', 400);
    return $decoded;
}

function public_user(array $user): array
{
    $permission = $user['permission'] ?? [];
    if (is_string($permission)) {
        $permission = json_decode($permission, true) ?: [];
    }
    return [
        'user_id' => (int) $user['user_id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'status' => $user['status'],
        'permission' => $permission,
    ];
}

/* 3 wrong passwords allowed; the 4th locks the account */
const LIBROWSE_MAX_FAILED_LOGINS = 3;

function locked_response(string $username): void
{
    Response::json([
        'error'        => 'Your account is locked because of too many incorrect password attempts. Please contact an administrator to unlock it.',
        'locked'       => true,
        'username'     => $username,
        'max_attempts' => LIBROWSE_MAX_FAILED_LOGINS,
    ], 423);
}

/* Older databases don't list 'Locked' as a USER status yet — add it once */
function ensure_locked_status(PDO $pdo): void
{
    $col = $pdo->query("SHOW COLUMNS FROM `USER` LIKE 'status'")->fetch();
    if ($col && strpos((string) $col['Type'], "'Locked'") === false) {
        $pdo->exec("ALTER TABLE `USER` MODIFY `status` ENUM('Active','Suspended','Banned','Pending Verification','Locked') NOT NULL DEFAULT 'Pending Verification'");
    }
}

ensure_column($pdo, 'USER', 'deleted_at');
ensure_auth_tokens_tables($pdo);

$action = strtolower((string) ($_GET['action'] ?? ''));

try {
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identifier === '' || $password === '') {
            Response::error('Username/email and password are required.', 422);
        }

        // MySQL native prepared statements do not reliably allow the same
        // named placeholder to appear more than once in a statement.
        // Use two parameters for the username/email comparison.
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, role, status, permission
             FROM `USER`
             WHERE deleted_at IS NULL AND (username = :username_identifier OR LOWER(email) = LOWER(:email_identifier))
             LIMIT 1'
        );
        $stmt->execute([
            'username_identifier' => $identifier,
            'email_identifier' => $identifier,
        ]);
        $user = $stmt->fetch();
        /* ── RATE LIMITING ────────────────────────────────────────────────
           3 wrong passwords are allowed. The 4th wrong password locks the
           account by setting USER.status = 'Locked' in the database, so the
           lock survives refreshes, new browsers and new devices. Only an
           Admin can unlock it (Admin → Users → Unlock).
           ──────────────────────────────────────────────────────────────── */
        $maxAttempts = 3;
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `LOGIN_ATTEMPTS` (
                `attempt_id`   INT UNSIGNED AUTO_INCREMENT NOT NULL,
                `user_id`      INT UNSIGNED NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`attempt_id`),
                INDEX `idx_la_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        ensure_column($pdo, 'LOGIN_ATTEMPTS', 'cleared_at');
        $lockedResponse = function (string $username) use ($maxAttempts): void {
            Response::json([
                'error'        => 'Your account is locked because of too many incorrect password attempts. Please contact an administrator to unlock it.',
                'locked'       => true,
                'username'     => $username,
                'max_attempts' => $maxAttempts,
            ], 423);
        };

        if (!$user) Response::error('Invalid username/email or password.', 401);

        if ($user['status'] === 'Locked') {
            locked_response((string) $user['username']);
        }

        if ($user['status'] === 'Pending Verification') {
            Response::json([
                'error' => 'Please verify your email address before signing in. Check your inbox for the confirmation link.',
                'pending_verification' => true,
                'email' => (string) $user['email'],
                'username' => (string) $user['username'],
            ], 403);
        }

        if ($user['status'] !== 'Active') {
            Response::error('This account is not active and cannot sign in.', 403);
        }

        $hash = (string) $user['password_hash'];
        $valid = $hash !== '' && password_verify($password, $hash);

        // Compatibility migration for the original seed placeholders.
        // Only for the broken placeholder hashes in the original seed data
        // (they are too short to be real bcrypt hashes). Real hashes never match here.
        if (!$valid && str_starts_with($hash, '$2b$') && strlen($hash) < 60 && $password === 'password') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id');
            $up->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }
        if (!$valid && $hash !== '' && !str_starts_with($hash, '$') && hash_equals($hash, $password)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $up = $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :id');
            $up->execute(['hash' => $hash, 'id' => $user['user_id']]);
            $valid = true;
        }

        if (!$valid) {
            /* ── Record the failed attempt ─────────────────────────────────────
               Every wrong password since the last successful sign-in counts —
               there is no time window, so waiting does not reset the count.
               3 wrong passwords are allowed; the 4th locks the account. */
            $uid = (int) $user['user_id'];
            $pdo->prepare('INSERT INTO `LOGIN_ATTEMPTS` (user_id, attempted_at) VALUES (:uid, UTC_TIMESTAMP())')
                ->execute(['uid' => $uid]);
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid AND cleared_at IS NULL');
            $countStmt->execute(['uid' => $uid]);
            $failed = (int) $countStmt->fetchColumn();

            if ($failed > LIBROWSE_MAX_FAILED_LOGINS) {
                ensure_locked_status($pdo);
                $pdo->prepare("UPDATE `USER` SET status = 'Locked' WHERE user_id = :uid")
                    ->execute(['uid' => $uid]);
                // Start from zero once an Admin unlocks the account
                $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')->execute(['uid' => $uid]);
                locked_response((string) $user['username']);
            }

            $finalWarning = ($failed === LIBROWSE_MAX_FAILED_LOGINS);
            Response::json([
                'error'         => $finalWarning
                    ? 'Incorrect password. This was your last allowed attempt: one more incorrect password will lock your account.'
                    : 'Incorrect password.',
                'locked'        => false,
                'attempts_used' => $failed,
                'max_attempts'  => LIBROWSE_MAX_FAILED_LOGINS,
                'final_warning' => $finalWarning,
            ], 401);
        }

        if ($user['status'] !== 'Active') Response::error('This account is not active and cannot sign in.', 403);

        // Successful sign-in clears the failed-attempt counter.
        $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')->execute(['uid' => (int) $user['user_id']]);

        $token = issue_auth_token($user);
        record_activity_log($pdo, [
            'actor_user_id'   => (int) $user['user_id'],
            'activity_type'   => 'Auth',
            'activity_action' => 'Login',
            'outcome'         => 'Success',
            'page_path'       => '/login.html',
            'details'         => [
                'role' => $user['role'],
                'status' => $user['status'],
            ],
        ]);
        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
            'user' => public_user($user),
        ]);
    }

    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $emailPattern = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';
        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) Response::error('Username must be 3-50 characters and contain only letters, numbers, and underscores.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match($emailPattern, $email)) Response::error('Please enter the right format for your email address (e.g. you@example.com).', 422);
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters long.', 422);

        $check = $pdo->prepare('SELECT user_id FROM `USER` WHERE username = :username OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username' => $username, 'email' => $email]);
        if ($check->fetch()) Response::error('Username or email is already registered.', 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO `USER` (username, email, password_hash, role, status, permission)
             VALUES (:username, :email, :hash, 'Customer', 'Pending Verification', '{}')"
        );
        $insert->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);
        $newUserId = (int) $pdo->lastInsertId();

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 172800);

        $stmtToken = $pdo->prepare('INSERT INTO `EMAIL_VERIFICATIONS` (user_id, token_hash, expires_at) VALUES (:uid, :thash, :expires)');
        $stmtToken->execute([
            'uid'     => $newUserId,
            'thash'   => $tokenHash,
            'expires' => $expiresAt,
        ]);

        $mailRes = send_verification_email($email, $username, $rawToken);

        record_activity_log($pdo, [
            'actor_user_id'   => $newUserId,
            'activity_type'   => 'Account',
            'activity_action' => 'Register',
            'outcome'         => 'Success',
            'page_path'       => '/register.html',
            'details'         => [
                'role'     => 'Customer',
                'status'   => 'Pending Verification',
                'dev_mode' => $mailRes['dev_mode'] ?? false,
            ],
        ]);

        Response::json([
            'registered'            => true,
            'requires_verification' => true,
            'message'               => 'Account created successfully. Please check your email to verify your account before signing in.',
            'user'                  => [
                'user_id'  => $newUserId,
                'username' => $username,
                'email'    => $email,
                'role'     => 'Customer',
                'status'   => 'Pending Verification',
            ],
            'dev_preview_link'      => $mailRes['dev_preview_link'] ?? null,
        ], 201);
    }

    if ($action === 'verify-email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $token = trim((string) ($body['token'] ?? ''));
        if ($token === '') {
            Response::error('Verification token is required.', 422);
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT ev.verification_id, ev.user_id, ev.expires_at, u.username, u.email, u.status
             FROM `EMAIL_VERIFICATIONS` ev
             JOIN `USER` u ON u.user_id = ev.user_id
             WHERE ev.token_hash = :hash
             ORDER BY ev.verification_id DESC
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $rec = $stmt->fetch();

        if (!$rec) {
            Response::error('This verification link is invalid or has already been used.', 400);
        }

        if (strtotime((string) $rec['expires_at']) < time()) {
            $pdo->prepare('DELETE FROM `EMAIL_VERIFICATIONS` WHERE verification_id = :id')
                ->execute(['id' => $rec['verification_id']]);
            Response::error('This verification link has expired. Please request a new verification email.', 400);
        }

        $uid = (int) $rec['user_id'];
        $pdo->prepare("UPDATE `USER` SET status = 'Active' WHERE user_id = :uid AND status = 'Pending Verification'")
            ->execute(['uid' => $uid]);
        $pdo->prepare('DELETE FROM `EMAIL_VERIFICATIONS` WHERE user_id = :uid')
            ->execute(['uid' => $uid]);

        record_activity_log($pdo, [
            'actor_user_id'   => $uid,
            'activity_type'   => 'Account',
            'activity_action' => 'VerifyEmail',
            'outcome'         => 'Success',
            'page_path'       => '/verify-email.html',
            'details'         => ['email' => $rec['email']],
        ]);

        Response::json([
            'verified' => true,
            'message'  => 'Your email address has been verified successfully. You can now sign in.',
        ]);
    }

    if ($action === 'resend-verification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? $body['email'] ?? ''));
        if ($identifier === '') {
            Response::error('Email address or username is required.', 422);
        }
        $emailPattern = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';
        if (str_contains($identifier, '@') && (!filter_var($identifier, FILTER_VALIDATE_EMAIL) || !preg_match($emailPattern, $identifier))) {
            Response::error('Please enter the right format for your email address (e.g. you@example.com).', 422);
        }

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, status FROM `USER`
             WHERE deleted_at IS NULL AND (username = :u OR LOWER(email) = LOWER(:e))
             LIMIT 1'
        );
        $stmt->execute(['u' => $identifier, 'e' => $identifier]);
        $user = $stmt->fetch();

        $mailRes = null;
        if ($user && $user['status'] === 'Pending Verification') {
            $uid = (int) $user['user_id'];
            $pdo->prepare('DELETE FROM `EMAIL_VERIFICATIONS` WHERE user_id = :uid')->execute(['uid' => $uid]);

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 172800);

            $ins = $pdo->prepare('INSERT INTO `EMAIL_VERIFICATIONS` (user_id, token_hash, expires_at) VALUES (:uid, :thash, :expires)');
            $ins->execute(['uid' => $uid, 'thash' => $tokenHash, 'expires' => $expiresAt]);

            $mailRes = send_verification_email((string) $user['email'], (string) $user['username'], $rawToken);
        }

        Response::json([
            'message'          => 'If an unverified account matches that information, a verification email has been sent.',
            'dev_preview_link' => $mailRes['dev_preview_link'] ?? null,
        ]);
    }

    if (($action === 'forgot-password' || $action === 'forgot_password') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? $body['email'] ?? ''));
        if ($identifier === '') {
            Response::error('Email address or username is required.', 422);
        }
        $emailPattern = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';
        if (str_contains($identifier, '@') && (!filter_var($identifier, FILTER_VALIDATE_EMAIL) || !preg_match($emailPattern, $identifier))) {
            Response::error('Please enter the right format for your email address (e.g. you@example.com).', 422);
        }

        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, status FROM `USER`
             WHERE deleted_at IS NULL AND (username = :u OR LOWER(email) = LOWER(:e))
             LIMIT 1'
        );
        $stmt->execute(['u' => $identifier, 'e' => $identifier]);
        $user = $stmt->fetch();

        $mailRes = null;
        if ($user) {
            $uid = (int) $user['user_id'];
            $pdo->prepare('UPDATE `PASSWORD_RESETS` SET used_at = UTC_TIMESTAMP() WHERE user_id = :uid AND used_at IS NULL')
                ->execute(['uid' => $uid]);

            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 3600);

            $ins = $pdo->prepare('INSERT INTO `PASSWORD_RESETS` (user_id, token_hash, expires_at) VALUES (:uid, :thash, :expires)');
            $ins->execute(['uid' => $uid, 'thash' => $tokenHash, 'expires' => $expiresAt]);

            $mailRes = send_password_reset_email((string) $user['email'], (string) $user['username'], $rawToken);
        }

        Response::json([
            'message'          => 'If an account exists with that email or username, a password reset link has been sent. Please check your inbox.',
            'dev_preview_link' => $mailRes['dev_preview_link'] ?? null,
        ]);
    }

    if ($action === 'verify-reset-token' && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
        $body = auth_body();
        $token = trim((string) ($_GET['token'] ?? $body['token'] ?? ''));
        if ($token === '') {
            Response::error('Reset token is required.', 422);
        }

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT pr.reset_id, pr.user_id, pr.expires_at, pr.used_at, u.username
             FROM `PASSWORD_RESETS` pr
             JOIN `USER` u ON u.user_id = pr.user_id
             WHERE pr.token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $rec = $stmt->fetch();

        if (!$rec || $rec['used_at'] !== null) {
            Response::error('This password reset link is invalid or has already been used.', 400);
        }

        if (strtotime((string) $rec['expires_at']) < time()) {
            Response::error('This password reset link has expired. Please request a new one.', 400);
        }

        Response::json([
            'valid'    => true,
            'username' => $rec['username'],
        ]);
    }

    if ($action === 'reset-password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $token = trim((string) ($body['token'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($token === '') Response::error('Reset token is required.', 422);
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters long.', 422);

        $tokenHash = hash('sha256', $token);
        $stmt = $pdo->prepare(
            'SELECT pr.reset_id, pr.user_id, pr.expires_at, pr.used_at, u.username, u.status
             FROM `PASSWORD_RESETS` pr
             JOIN `USER` u ON u.user_id = pr.user_id
             WHERE pr.token_hash = :hash
             LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $rec = $stmt->fetch();

        if (!$rec || $rec['used_at'] !== null) {
            Response::error('This password reset link is invalid or has already been used.', 400);
        }

        if (strtotime((string) $rec['expires_at']) < time()) {
            Response::error('This password reset link has expired. Please request a new one.', 400);
        }

        $uid = (int) $rec['user_id'];
        $newHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->prepare('UPDATE `USER` SET password_hash = :hash WHERE user_id = :uid')
            ->execute(['hash' => $newHash, 'uid' => $uid]);

        if ($rec['status'] === 'Locked') {
            $pdo->prepare("UPDATE `USER` SET status = 'Active' WHERE user_id = :uid")->execute(['uid' => $uid]);
        }
        $pdo->prepare('UPDATE `LOGIN_ATTEMPTS` SET cleared_at = UTC_TIMESTAMP() WHERE user_id = :uid AND cleared_at IS NULL')
            ->execute(['uid' => $uid]);

        $pdo->prepare('UPDATE `PASSWORD_RESETS` SET used_at = UTC_TIMESTAMP() WHERE reset_id = :rid')
            ->execute(['rid' => $rec['reset_id']]);

        $pdo->prepare('UPDATE `LIBROWSE_SESSIONS` SET revoked_at = UTC_TIMESTAMP() WHERE user_id = :uid AND revoked_at IS NULL')
            ->execute(['uid' => $uid]);

        Response::json([
            'success' => true,
            'message' => 'Password reset successfully. You can now sign in with your new password.',
        ]);
    }

    if ($action === 'validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_authenticated_user($pdo);
        Response::json(['authenticated' => true, 'user' => public_user($user)]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $user = current_authenticated_user($pdo);
        revoke_auth_token(bearer_token_from_request());
        if ($user) {
            record_activity_log($pdo, [
                'actor_user_id'   => (int) $user['user_id'],
                'activity_type'   => 'Auth',
                'activity_action' => 'Logout',
                'outcome'         => 'Success',
                'page_path'       => '/logout',
                'details'         => [
                    'role' => $user['role'],
                ],
            ]);
        }
        Response::json(['authenticated' => false, 'message' => 'Session revoked.']);
    }

    Response::error('Unknown authentication action.', 404);
} catch (PDOException $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
} catch (Throwable $e) {
    error_log('[librowse] auth: ' . $e->getMessage());
    Response::error('Sign-in is temporarily unavailable. Please try again.', 500, api_debug() ? ['details' => $e->getMessage()] : []);
}
