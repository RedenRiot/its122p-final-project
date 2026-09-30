<?php
declare(strict_types=1);

/**
 * Server-side session management backed by the database SESSIONS table.
 *
 * The original implementation stored sessions in an XML file on disk.
 * Vercel (and most serverless platforms) have a read-only filesystem, so
 * that approach always fails in production. Sessions are now stored in the
 * same TiDB database that the rest of the app uses.
 *
 * Only SHA-256 token hashes are persisted; raw bearer tokens never touch the DB.
 */
const LIBROWSE_SESSION_TTL = 28800; // 8 hours

/**
 * Ensure the SESSIONS table exists. Safe to call on every request — the
 * CREATE TABLE is IF NOT EXISTS so it is a no-op once the table is there.
 */
function ensure_sessions_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `SESSIONS` (
            `token_hash`  VARCHAR(64)  NOT NULL,
            `user_id`     INT UNSIGNED NOT NULL,
            `role`        VARCHAR(50)  NOT NULL,
            `created_at`  DATETIME     NOT NULL,
            `expires_at`  DATETIME     NOT NULL,
            PRIMARY KEY (`token_hash`),
            INDEX `idx_sessions_expires_at` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function issue_auth_token(array $user): string
{
    global $pdo;
    ensure_sessions_table($pdo);

    $token   = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $now     = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $expires = $now->modify('+' . LIBROWSE_SESSION_TTL . ' seconds');

    // Purge expired sessions while we're here.
    $pdo->exec("DELETE FROM `SESSIONS` WHERE expires_at < UTC_TIMESTAMP()");

    $stmt = $pdo->prepare(
        'INSERT INTO `SESSIONS` (token_hash, user_id, role, created_at, expires_at)
         VALUES (:hash, :user_id, :role, :created_at, :expires_at)'
    );
    $stmt->execute([
        'hash'       => hash('sha256', $token),
        'user_id'    => (int) $user['user_id'],
        'role'       => (string) $user['role'],
        'created_at' => $now->format('Y-m-d H:i:s'),
        'expires_at' => $expires->format('Y-m-d H:i:s'),
    ]);

    return $token;
}

function bearer_token_from_request(): ?string
{
    $header = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) ? trim($matches[1]) : null;
}

function current_authenticated_user(PDO $pdo): ?array
{
    $token = bearer_token_from_request();
    if (!$token) return null;

    ensure_sessions_table($pdo);

    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT user_id, role FROM `SESSIONS`
         WHERE token_hash = :hash AND expires_at > UTC_TIMESTAMP()
         LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $session = $stmt->fetch();

    if (!$session) return null;

    $stmt = $pdo->prepare(
        'SELECT user_id, username, email, role, status, permission
         FROM `USER` WHERE user_id = :id LIMIT 1'
    );
    $stmt->execute(['id' => (int) $session['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'Active' || $user['role'] !== (string) $session['role']) {
        revoke_auth_token($token);
        return null;
    }

    if (is_string($user['permission'] ?? null)) {
        $decoded = json_decode($user['permission'], true);
        $user['permission'] = is_array($decoded) ? $decoded : [];
    }

    return $user;
}

function require_authenticated_user(PDO $pdo, array $allowedRoles = []): array
{
    $user = current_authenticated_user($pdo);
    if ($user === null) Response::error('Authentication required or session expired.', 401);
    if ($allowedRoles && !in_array($user['role'], $allowedRoles, true)) {
        Response::error('You are not authorized to perform this action.', 403);
    }
    return $user;
}

function revoke_auth_token(?string $token): void
{
    global $pdo;
    if (!$token) return;

    ensure_sessions_table($pdo);

    $stmt = $pdo->prepare('DELETE FROM `SESSIONS` WHERE token_hash = :hash');
    $stmt->execute(['hash' => hash('sha256', $token)]);
}
