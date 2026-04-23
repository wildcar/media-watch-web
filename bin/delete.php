#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', ['id:']);

if (!isset($options['id'])) {
    fwrite(STDERR, "Usage: php bin/delete.php --id=ID\n");
    exit(2);
}

$deleted = app_storage()->delete((string) $options['id']);

echo json_encode(
    ['id' => (string) $options['id'], 'deleted' => $deleted],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) . PHP_EOL;

