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

$wantShare = isset($_GET['share']) && $_GET['share'] !== '' && $_GET['share'] !== '0';
$asAttachment = isset($_GET['download']) && $_GET['download'] !== '' && $_GET['download'] !== '0';

if ($wantShare) {
    if ((string) $record['remux_status'] !== 'ready') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Share file is not ready';
        exit;
    }
    $filePath = (string) $record['share_path'];
    $mimeType = 'video/mp4';
} else {
    $filePath = (string) ($record['file_path'] ?? '');
    $mimeType = trim((string) ($record['mime_type'] ?? ''));
}

if ($filePath === '' || !is_file($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'File is missing';
    exit;
}

MediaWatchStreamer::send(
    $filePath,
    $mimeType,
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $asAttachment,
    !empty(app_config()['use_xsendfile']),
);
