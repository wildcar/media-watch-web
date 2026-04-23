<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = request_id();
if ($id === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing id';
    exit;
}

$record = app_storage()->get($id);
if ($record === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Not found';
    exit;
}

$filePath = (string) ($record['file_path'] ?? '');
if ($filePath === '' || !is_file($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File is missing';
    exit;
}

$mimeType = trim((string) ($record['mime_type'] ?? ''));
MediaWatchStreamer::send($filePath, $mimeType, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

