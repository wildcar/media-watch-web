<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/Api.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) ($_SERVER['REQUEST_URI'] ?? '/');
if (($q = strpos($path, '?')) !== false) {
    $path = substr($path, 0, $q);
}
// Apache mod_rewrite gives a clean REQUEST_URI like `/api/register`. The
// PHP built-in server (and any setup without rewrites) leaves the script
// name in the URI, e.g. `/api.php/api/register` — strip it so routing
// works in both environments.
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
if ($scriptName !== '' && str_starts_with($path, $scriptName)) {
    $path = substr($path, strlen($scriptName));
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
}

$api = new MediaWatchApi(app_storage(), app_config());
$api->handle($method, $path);
