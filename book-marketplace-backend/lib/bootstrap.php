<?php
declare(strict_types=1);

// Kill HTML error output immediately — all errors must surface as JSON.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

/**
 * Catch PHP fatal errors (which bypass try/catch) and return JSON
 * so the frontend never receives an HTML string it can't parse.
 */
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        $payload = ['error' => 'Server error'];
        if (filter_var($_ENV['APP_DEBUG'] ?? getenv('APP_DEBUG') ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $payload['details'] = $err['message'] . ' in ' . $err['file'] . ':' . $err['line'];
        }
        echo json_encode($payload);
    }
});

/**
 * CORS — allow localhost for dev and any *.vercel.app for prod.
 */
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOriginPattern = '#^https?://(?:localhost|127\.0\.0\.1|[^/]+\.vercel\.app)(?::\d+)?$#i';

if ($origin !== '' && preg_match($allowedOriginPattern, $origin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');
header('Access-Control-Max-Age: 600');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Crud.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/dispatch.php';
