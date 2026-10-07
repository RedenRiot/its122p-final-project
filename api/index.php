<?php
declare(strict_types=1);

// Resolve target route
$route = $_GET['__route'] ?? '';

// Fallback to REQUEST_URI if __route query parameter is empty
if ($route === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    if (preg_match('#^/api/(.+)$#', $path, $matches)) {
        $route = $matches[1];
    } elseif ($path === '/robots.txt') {
        $route = 'robots.php';
    } elseif ($path === '/sitemap.xml') {
        $route = 'sitemap.php';
    }
}

// Strip query string and trim slashes
$route = trim(explode('?', $route, 2)[0], '/');

// Clean up routing query parameter so downstream scripts receive untainted superglobals
unset($_GET['__route']);
if (isset($_REQUEST['__route'])) {
    unset($_REQUEST['__route']);
}

// Serve root API index / healthcheck if no specific route or index requested
if ($route === '' || $route === 'index.php') {
    require __DIR__ . '/../book-marketplace-backend/index.php';
    exit;
}

// Security: Prevent path traversal and enforce basename
$filename = basename($route);
if (!str_ends_with($filename, '.php')) {
    $filename .= '.php';
}

$target = __DIR__ . '/../book-marketplace-backend/api/' . $filename;

if (file_exists($target)) {
    require $target;
    exit;
}

// Return 404 if endpoint does not exist
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'status' => 'error',
    'message' => 'API endpoint not found: ' . $filename
]);
exit;
