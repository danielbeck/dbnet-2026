<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/content/.*\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh)$#i', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if (preg_match('#^/(?:app|bin|storage|templates)(?:/|$)#i', $path) || str_starts_with($path, '/.')) {
    http_response_code(403);
    exit('Forbidden');
}
$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file)) {
    return false;
}
require dirname(__DIR__) . '/index.php';
