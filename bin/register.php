#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$options = getopt('', [
    'id:',
    'file:',
    'title:',
    'description::',
    'poster-url::',
    'kind::',
    'mime::',
]);

if (!isset($options['id'], $options['file'], $options['title'])) {
    fwrite(
        STDERR,
        "Usage: php bin/register.php --id=ID --file=/abs/path --title=TITLE "
        . "[--description=TEXT] [--poster-url=URL] [--kind=movie|series] [--mime=TYPE]\n"
    );
    exit(2);
}

$kind = ($options['kind'] ?? 'movie') === 'series' ? 'series' : 'movie';

try {
    $record = app_storage()->upsert([
        'id' => (string) $options['id'],
        'file_path' => (string) $options['file'],
        'title' => (string) $options['title'],
        'description' => (string) ($options['description'] ?? ''),
        'poster_url' => (string) ($options['poster-url'] ?? ''),
        'kind' => $kind,
        'mime_type' => (string) ($options['mime'] ?? ''),
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

