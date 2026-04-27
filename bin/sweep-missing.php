#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Drop database rows whose `file_path` no longer exists on disk.
 *
 * Files are routinely deleted (rutracker re-uploads supersede the old
 * release; user manually clears Plex; storage hits the cap). The DB
 * row stays around forever though, so /watch/<id> 404s and /list
 * surfaces dead links. Run this periodically (systemd timer is set up
 * in deploy/) to keep the catalogue honest.
 *
 *   php bin/sweep-missing.php
 *   php bin/sweep-missing.php --quiet   # for the timer; only error logs
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

$opts = getopt('', ['quiet']);
$quiet = isset($opts['quiet']);

$deleted = app_storage()->sweepMissing();
$count = count($deleted);

if (!$quiet || $count > 0) {
    fwrite(STDERR, "swept $count missing record(s)\n");
    foreach ($deleted as $id) {
        fwrite(STDERR, "  - $id\n");
    }
}
