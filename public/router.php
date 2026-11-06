<?php

declare(strict_types=1);

// Development server only. The production document root is public/.
require_once dirname(__DIR__) . '/app/bootstrap.php';
$surface = \Verdant\Http\LocalSiteSurface::resolve($_SERVER);
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
if ($surface === null) {
    \Verdant\Http\LocalSiteSurface::rejectUnknownHost($method);
}
$decision = $surface->requestPolicy($requestUri, $method);
if (($decision['action'] ?? null) !== 'continue') {
    $surface->respond($decision, $method);
}
$_SERVER['REQUEST_URI'] = $decision['request_uri'] ?? $requestUri;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && preg_match('#^/assets/[A-Za-z0-9_./-]+$#', $path) && !str_contains($path, '..')) {
    $asset = realpath(__DIR__ . $path);
    $root = realpath(__DIR__ . '/assets') . DIRECTORY_SEPARATOR;
    if ($asset && str_starts_with($asset, $root) && is_file($asset)
        && in_array(strtolower(pathinfo($asset, PATHINFO_EXTENSION)), ['css', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'js'], true)) {
        return false;
    }
}
require __DIR__ . '/index.php';
