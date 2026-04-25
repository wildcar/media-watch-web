#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$id = $argv[1] ?? '';
if ($id === '') {
    fwrite(STDERR, "Usage: remux-worker.php <record_id>\n");
    exit(2);
}

$storage = app_storage();
$record = $storage->get($id);
if ($record === null) {
    fwrite(STDERR, "No record: $id\n");
    exit(2);
}

$status = (string) $record['remux_status'];
if (!in_array($status, ['pending', 'failed'], true)) {
    // Either already running (a previous spawn is still going) or
    // already ready / not_needed. Bail without touching anything.
    exit(0);
}

$sharePath = (string) $record['share_path'];
$sourcePath = (string) $record['file_path'];
if ($sharePath === '' || $sourcePath === '') {
    $storage->setRemuxFailed($id, 'missing share_path or file_path');
    exit(1);
}

$shareDir = dirname($sharePath);
if (!is_dir($shareDir) && !@mkdir($shareDir, 0775, true) && !is_dir($shareDir)) {
    $storage->setRemuxFailed($id, 'cannot create share dir: ' . $shareDir);
    exit(1);
}

// Advisory lock on the output file so a duplicate spawn no-ops cleanly
// instead of two ffmpeg processes fighting over the same path.
$lockFile = $sharePath . '.lock';
$lock = @fopen($lockFile, 'c');
if ($lock === false) {
    $storage->setRemuxFailed($id, 'cannot open lock file: ' . $lockFile);
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    // Another worker holds the lock — leave the DB row alone.
    exit(0);
}

$storage->setRemuxRunning($id);

$probe = $storage->probeVideo($sourcePath);
$audioStrategy = $storage->audioStrategy($probe['audio_codecs']);

$tmpPath = $sharePath . '.tmp';
@unlink($tmpPath);

$audioArgs = $audioStrategy === 'aac'
    ? '-c:a aac -b:a 192k'
    : '-c:a copy';

$cmd = sprintf(
    'ffmpeg -nostdin -y -i %s -map 0:v:0 -c:v copy -map 0:a %s '
    . '-movflags +faststart -f mp4 %s 2>&1',
    escapeshellarg($sourcePath),
    $audioArgs,
    escapeshellarg($tmpPath)
);

$output = [];
$rc = 0;
exec($cmd, $output, $rc);

if ($rc !== 0 || !is_file($tmpPath) || filesize($tmpPath) === 0) {
    @unlink($tmpPath);
    $tail = implode("\n", array_slice($output, -10));
    $storage->setRemuxFailed($id, sprintf('ffmpeg rc=%d: %s', $rc, $tail));
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockFile);
    exit(1);
}

if (!@rename($tmpPath, $sharePath)) {
    @unlink($tmpPath);
    $storage->setRemuxFailed($id, 'rename tmp -> final failed');
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockFile);
    exit(1);
}

$storage->setRemuxReady($id, $sharePath);
flock($lock, LOCK_UN);
fclose($lock);
@unlink($lockFile);
exit(0);
