#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * One-shot post-deploy cleanup: drops every row in `records`.
 *
 * Used during the move to composite media-ids — the old `tt1234567`-style
 * keys are not migrated; we wipe the table and let the bot re-register
 * fresh downloads with `rt-<topic_id>` ids. The on-disk media files are
 * left alone (`/api/records/{id}` already only deletes DB rows).
 *
 *   php bin/wipe-records.php --yes
 *
 * Without `--yes` the script reports the row count and exits.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['yes']);
$dbPath = (string) app_config()['db_path'];

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$count = (int) $pdo->query('SELECT COUNT(*) FROM records')->fetchColumn();

if (!isset($opts['yes'])) {
    fwrite(STDERR, "Would delete $count record(s) from $dbPath. Re-run with --yes to confirm.\n");
    exit(0);
}

$pdo->exec('DELETE FROM records');
echo "deleted $count record(s)\n";
