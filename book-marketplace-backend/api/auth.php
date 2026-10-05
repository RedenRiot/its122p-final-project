<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

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

$action = strtolower((string) ($_GET['action'] ?? ''));

try {
    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identifier === '' || $password === '') {
            Response::error('Username/email and password are required.', 422);
        }

        /* ── Auto-create LOGIN_ATTEMPTS table if not yet present ─────────── */
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `LOGIN_ATTEMPTS` (
                `attempt_id`   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id`      INT UNSIGNED NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_la_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // MySQL native prepared statements do not reliably allow the same
        // named placeholder to appear more than once in a statement.
        // Use two parameters for the username/email comparison.
        $stmt = $pdo->prepare(
            'SELECT user_id, username, email, password_hash, role, status, permission
             FROM `USER`
             WHERE username = :username_identifier OR LOWER(email) = LOWER(:email_identifier)
             LIMIT 1'
        );
        $stmt->execute([
            'username_identifier' => $identifier,
            'email_identifier' => $identifier,
        ]);
        $user = $stmt->fetch();

        /* Unknown user — generic error (do not reveal whether account exists) */
        if (!$user) Response::error('Invalid username/email or password.', 401);

        /* Already locked — stays locked (even with the right password) until an Admin unlocks it */
        if ($user['status'] === 'Locked') {
            locked_response((string) $user['username']);
        }

        /* Other non-active statuses (Suspended, Banned, Pending Verification) */
        if ($user['status'] !== 'Active') {
            Response::error('This account is not active and cannot sign in.', 403);
        }

        $hash = (string) $user['password_hash'];
        $valid = $hash !== '' && password_verify($password, $hash);

        // Compatibility migration for the original seed placeholders.
        if (!$valid && str_starts_with($hash, '$2b$') && $password === 'password') {
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
            $countStmt = $pdo->prepare('SELECT COUNT(*) FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid');
            $countStmt->execute(['uid' => $uid]);
            $failed = (int) $countStmt->fetchColumn();

            if ($failed > LIBROWSE_MAX_FAILED_LOGINS) {
                ensure_locked_status($pdo);
                $pdo->prepare("UPDATE `USER` SET status = 'Locked' WHERE user_id = :uid")
                    ->execute(['uid' => $uid]);
                // Start from zero once an Admin unlocks the account
                $pdo->prepare('DELETE FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid')->execute(['uid' => $uid]);
                locked_response((string) $user['username']);
            }
            }

            $finalWarning = ($failed === LIBROWSE_MAX_FAILED_LOGINS);
            Response::json([
                'error'         => $finalWarning
                    ? 'Incorrect password. This was your last allowed attempt — one more incorrect password will lock your account.'
                    : 'Incorrect password.',
                'locked'        => false,
                'attempts_used' => $failed,
                'max_attempts'  => LIBROWSE_MAX_FAILED_LOGINS,
                'final_warning' => $finalWarning,
            ], 401);
        }

        /* ── Success: clear attempt log and issue token ────────────────────── */
        $pdo->prepare('DELETE FROM `LOGIN_ATTEMPTS` WHERE user_id = :uid')
            ->execute(['uid' => (int) $user['user_id']]);

        $token = issue_auth_token($user);
        Response::json([
            'authenticated' => true,
            'token'         => $token,
            'expires_in'    => LIBROWSE_SESSION_TTL,
            'user'          => public_user($user),
        ]);
    }

    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $body = auth_body();
        $username = trim((string) ($body['username'] ?? ''));
        $email = trim(strtolower((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) Response::error('Username must be 3-50 characters and contain only letters, numbers, and underscores.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Response::error('Please provide a valid email address.', 422);
        if (strlen($password) < 8) Response::error('Password must be at least 8 characters long.', 422);

        $check = $pdo->prepare('SELECT user_id FROM `USER` WHERE username = :username OR LOWER(email) = LOWER(:email) LIMIT 1');
        $check->execute(['username' => $username, 'email' => $email]);
        if ($check->fetch()) Response::error('Username or email is already registered.', 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            "INSERT INTO `USER` (username, email, password_hash, role, status, permission)
             VALUES (:username, :email, :hash, 'Customer', 'Active', '{}')"
        );
        $insert->execute(['username' => $username, 'email' => $email, 'hash' => $hash]);

        $stmt = $pdo->prepare('SELECT user_id, username, email, role, status, permission FROM `USER` WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $pdo->lastInsertId()]);
        $user = $stmt->fetch();
        $token = issue_auth_token($user);

        Response::json([
            'authenticated' => true,
            'token' => $token,
            'expires_in' => LIBROWSE_SESSION_TTL,
            'user' => public_user($user),
        ], 201);
    }

    if ($action === 'validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $user = require_authenticated_user($pdo);
        Response::json(['authenticated' => true, 'user' => public_user($user)]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        revoke_auth_token(bearer_token_from_request());
        Response::json(['authenticated' => false, 'message' => 'Session revoked.']);
    }

    Response::error('Unknown authentication action.', 404);
} catch (PDOException $e) {
    Response::error('Authentication database error.', 500);
} catch (Throwable $e) {
    $isDebug = filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN);
    $details = $isDebug ? ['details' => $e->getMessage()] : [];
    Response::error('Authentication service error.', 500, $details);
}
