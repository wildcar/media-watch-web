#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$storage = app_storage();
$rows = $storage->listMissingDimensions();

if ($rows === []) {
    fwrite(STDOUT, "Nothing to backfill.\n");
    exit(0);
}

$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $id = $row['id'];
    $path = $row['file_path'];
    $probe = $storage->probeVideo($path);
    if ($probe['width'] > 0 && $probe['height'] > 0) {
        $storage->setProbe($id, $probe['width'], $probe['height'], $probe['duration']);
        fwrite(STDOUT, sprintf(
            "ok   %s  %dx%d  %ds\n",
            $id, $probe['width'], $probe['height'], $probe['duration']
        ));
        $ok++;
    } else {
        fwrite(STDERR, sprintf("fail %s  (%s)\n", $id, $path));
        $fail++;
    }
}
fwrite(STDOUT, sprintf("\nDone: %d ok, %d failed\n", $ok, $fail));
