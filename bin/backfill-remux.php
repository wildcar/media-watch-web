#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$storage = app_storage();
$rows = $storage->listAllForRemuxAudit();

if ($rows === []) {
    fwrite(STDOUT, "No records.\n");
    exit(0);
}

$spawned = 0;
$ready = 0;
$skipped = 0;
$failed = 0;
foreach ($rows as $row) {
    $id = $row['id'];
    $current = $row['remux_status'];

    // Already-good rows we leave alone.
    if (in_array($current, ['ready', 'running'], true)) {
        $skipped++;
        continue;
    }

    // Re-evaluate decision based on the original file.
    $decision = $storage->reapplyRemuxDecision($id);
    fwrite(STDOUT, sprintf("%s -> %s%s\n",
        $id,
        $decision['status'],
        $decision['error'] !== '' ? ' (' . $decision['error'] . ')' : ''
    ));

    if ($decision['status'] === 'pending') {
        $storage->spawnRemuxWorker($id);
        $spawned++;
    } elseif ($decision['status'] === 'not_needed') {
        $ready++;
    } else {
        $failed++;
    }
}

fwrite(STDOUT, sprintf(
    "\nDone: %d spawned, %d not_needed, %d failed, %d skipped (already ready/running)\n",
    $spawned, $ready, $failed, $skipped
));
