<?php
/**
 * Database connection (PDO / MySQL).
 *
 * Reads credentials from environment variables so real credentials never
 * live in source control. Copy .env.example to .env (or set real env vars
 * on your server) before running.
 *
 *   DB_HOST=127.0.0.1
 *   DB_PORT=3306
 *   DB_NAME=book_marketplace
 *   DB_USER=root
 *   DB_PASS=secret
 *   DB_SSL=true   ← set to "true" on TiDB Cloud / any TLS-required host
 */

function get_env_or(string $key, string $default): string
{
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
}

$dbHost = get_env_or('DB_HOST', '127.0.0.1');
$dbPort = get_env_or('DB_PORT', '3306');
$dbName = get_env_or('DB_NAME', 'book_marketplace');
$dbUser = get_env_or('DB_USER', 'root');
$dbPass = get_env_or('DB_PASS', '');
$dbSsl = strtolower(get_env_or('DB_SSL', 'false')) === 'true';

// DSN — no ssl-mode here; PHP PDO MySQL ignores it. TLS is forced via MYSQL_ATTR_SSL_CA below.
$dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_TIMEOUT            => 10,
];

if ($dbSsl) {
    // Setting MYSQL_ATTR_SSL_CA is what actually initiates TLS in PHP PDO.
    // Try common CA bundle locations across Linux distros used by Vercel.
    $caBundles = [
        '/etc/ssl/certs/ca-certificates.crt',     // Debian / Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',        // Amazon Linux / CentOS / RHEL
        '/etc/ssl/ca-bundle.pem',                   // OpenSUSE
        '/usr/local/share/certs/ca-root-nss.crt',  // FreeBSD
        '/dev/null',                                // last resort: initiates TLS, skips CA check
    ];
    foreach ($caBundles as $bundle) {
        if (file_exists($bundle)) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $bundle;
            break;
        }
    }
    $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
}

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $pdoOptions);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Database connection failed',
        'details' => $e->getMessage(),
    ]);
    exit;
}
