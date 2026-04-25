<?php

declare(strict_types=1);

require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Streamer.php';

function app_root(): string
{
    return dirname(__DIR__);
}

function app_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $root = app_root();
    $config = [
        'base_url' => getenv('MEDIA_WATCH_BASE_URL') ?: 'https://v.sitename.org',
        'db_path' => getenv('MEDIA_WATCH_DB_PATH') ?: $root . '/var/media_watch.sqlite',
        'media_roots' => app_parse_media_roots(getenv('MEDIA_WATCH_MEDIA_ROOTS') ?: ''),
        'site_name' => getenv('MEDIA_WATCH_SITE_NAME') ?: 'Media Watch',
        'api_token' => getenv('MEDIA_WATCH_API_TOKEN') ?: '',
    ];

    $localConfigPath = $root . '/config.local.php';
    if (is_file($localConfigPath)) {
        $override = require $localConfigPath;
        if (is_array($override)) {
            $config = array_replace($config, $override);
        }
    }

    $config['base_url'] = rtrim((string) $config['base_url'], '/');
    $config['db_path'] = (string) $config['db_path'];
    $config['site_name'] = (string) $config['site_name'];
    $config['api_token'] = (string) ($config['api_token'] ?? '');
    $config['media_roots'] = app_normalize_media_roots($config['media_roots']);

    return $config;
}

function app_storage(): MediaWatchStorage
{
    static $storage = null;
    if ($storage !== null) {
        return $storage;
    }

    $config = app_config();
    $storage = new MediaWatchStorage($config['db_path'], $config['media_roots']);
    return $storage;
}

function app_parse_media_roots(string $value): array
{
    if ($value === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
}

function app_normalize_media_roots(mixed $roots): array
{
    if (!is_array($roots)) {
        return [];
    }

    $normalized = [];
    foreach ($roots as $root) {
        if (!is_string($root)) {
            continue;
        }
        $trimmed = rtrim(trim($root), DIRECTORY_SEPARATOR);
        if ($trimmed === '') {
            continue;
        }
        $normalized[] = $trimmed;
    }

    return array_values(array_unique($normalized));
}

function full_url(string $path): string
{
    return app_config()['base_url'] . '/' . ltrim($path, '/');
}

function request_id(): string
{
    $id = $_GET['id'] ?? '';
    return is_string($id) ? trim($id) : '';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

