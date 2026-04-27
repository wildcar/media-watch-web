<?php

declare(strict_types=1);

/**
 * End-to-end smoke test for the registration HTTP API.
 *
 * Spawns `php -S` against `public/`, registers a movie file, a movie
 * directory, and a series directory, then exercises auth, collisions,
 * and DELETE.
 *
 *   php tests/api_smoke.php
 *
 * The PHP built-in server does not honour `.htaccess` rewrites, so this
 * test hits `api.php` directly. Apache rewrites are validated separately
 * at deploy time.
 */

const TOKEN = 'smoke-token-please-do-not-use-in-prod';
const PORT = 18765;
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/media-watch-smoke-' . bin2hex(random_bytes(4));
$mediaRoot = $tmp . '/media';
$dbPath = $tmp . '/db.sqlite';
mkdir($mediaRoot . '/Movie/Sample.Movie.2024.1080p', 0777, true);
mkdir($mediaRoot . '/Series/Show.S01.1080p', 0777, true);
mkdir($mediaRoot . '/Series/Друзья (1994)/Сезон 9', 0777, true);

// Two video files in a movie dir + one sample → bigger non-sample wins.
file_put_contents($mediaRoot . '/Movie/Sample.Movie.2024.1080p/Sample.Movie.2024.mkv', str_repeat('A', 4096));
file_put_contents($mediaRoot . '/Movie/Sample.Movie.2024.1080p/Sample.sample.mkv', str_repeat('B', 32768));
// Standalone movie file.
file_put_contents($mediaRoot . '/Movie/Standalone.mp4', str_repeat('C', 1024));
// Series with three episodes; one filename has no SxxEyy marker.
file_put_contents($mediaRoot . '/Series/Show.S01.1080p/Show.S01E01.1080p.mkv', str_repeat('D', 1024));
file_put_contents($mediaRoot . '/Series/Show.S01.1080p/Show.1x02.WEBRip.mkv', str_repeat('E', 1024));
file_put_contents($mediaRoot . '/Series/Show.S01.1080p/extras.mkv', str_repeat('F', 1024));
// Russian-style layout: season in the directory name, episodes numbered
// raw in the filename. Single + range double-episode filenames.
file_put_contents($mediaRoot . '/Series/Друзья (1994)/Сезон 9/01. The One Where No One Proposes.mkv', str_repeat('G', 1024));
file_put_contents($mediaRoot . '/Series/Друзья (1994)/Сезон 9/02. The One Where Emma Cries.mkv', str_repeat('H', 1024));
file_put_contents($mediaRoot . '/Series/Друзья (1994)/Сезон 9/23-24. The One in Barbados.mkv', str_repeat('I', 1024));

$env = [
    'PATH' => getenv('PATH'),
    'MEDIA_WATCH_BASE_URL' => 'http://127.0.0.1:' . PORT,
    'MEDIA_WATCH_DB_PATH' => $dbPath,
    'MEDIA_WATCH_MEDIA_ROOTS' => $mediaRoot . '/Movie,' . $mediaRoot . '/Series,' . $mediaRoot . '/Series/Друзья (1994)',
    'MEDIA_WATCH_SITE_NAME' => 'Smoke',
    'MEDIA_WATCH_API_TOKEN' => TOKEN,
];

$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open(
    sprintf('exec php -S 127.0.0.1:%d -t %s', PORT, escapeshellarg($root . '/public')),
    $descriptors,
    $pipes,
    $root,
    $env,
);
if (!is_resource($proc)) {
    fwrite(STDERR, "failed to spawn php -S\n");
    exit(1);
}

register_shutdown_function(static function () use ($proc, $tmp): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});

// Wait for the server to listen.
for ($i = 0; $i < 50; $i++) {
    $sock = @fsockopen('127.0.0.1', PORT, $errno, $errstr, 0.2);
    if ($sock) { fclose($sock); break; }
    usleep(100_000);
}

function call(string $method, string $apiPath, ?array $body, ?string $token): array {
    $ch = curl_init('http://127.0.0.1:' . PORT . '/api.php' . $apiPath);
    $headers = ['Content-Type: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp ? json_decode((string) $resp, true) : null];
}

function assert_eq($expected, $actual, string $msg): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: $msg — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

global $mediaRoot;

// 1) No token → 401.
[$code, $body] = call('POST', '/api/register', ['path' => $mediaRoot . '/Movie/Standalone.mp4', 'title' => 'X', 'kind' => 'movie'], null);
assert_eq(401, $code, 'no auth → 401');

// 2) Wrong token → 401.
[$code, $body] = call('POST', '/api/register', ['path' => $mediaRoot . '/Movie/Standalone.mp4', 'title' => 'X', 'kind' => 'movie'], 'wrong');
assert_eq(401, $code, 'wrong token → 401');

// 3) Movie via single file (rt- prefix — typical rutracker download).
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Standalone.mp4',
    'title' => 'Standalone',
    'kind' => 'movie',
    'media_id' => 'rt-6843582',
], TOKEN);
assert_eq(200, $code, 'single-file movie → 200');
assert_eq(1, count($body['records']), 'single-file movie produces one record');
assert_eq('rt-6843582', $body['records'][0]['id'], 'movie id = media_id');
assert_eq(true, str_starts_with($body['records'][0]['watch_url'], 'http://127.0.0.1:' . PORT . '/watch/'), 'watch_url uses base_url');

// 4) Movie via directory (imdb- prefix — bot fell back to bare IMDb id).
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Sample.Movie.2024.1080p',
    'title' => 'Sample Movie',
    'kind' => 'movie',
    'media_id' => 'imdb-tt7654321',
], TOKEN);
assert_eq(200, $code, 'directory movie → 200');
assert_eq(1, count($body['records']), 'directory movie produces one record');
assert_eq('Sample.Movie.2024.mkv', basename($body['records'][0]['file_path']), 'sample file is skipped');
assert_eq('imdb-tt7654321', $body['records'][0]['id'], 'imdb-prefixed media_id round-trips');

// 5) Re-register same path same id → idempotent (no suffix).
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Standalone.mp4',
    'title' => 'Standalone',
    'kind' => 'movie',
    'media_id' => 'rt-6843582',
], TOKEN);
assert_eq('rt-6843582', $body['records'][0]['id'], 're-register same path keeps id');

// 6) Different path, same media_id → suffix -2.
copy($mediaRoot . '/Movie/Standalone.mp4', $mediaRoot . '/Movie/Standalone.alt.mp4');
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Standalone.alt.mp4',
    'title' => 'Standalone',
    'kind' => 'movie',
    'media_id' => 'rt-6843582',
], TOKEN);
assert_eq('rt-6843582-2', $body['records'][0]['id'], 'collision adds -2 suffix');

// 7) Series directory.
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Series/Show.S01.1080p',
    'title' => 'Show',
    'kind' => 'series',
    'media_id' => 'rt-9999999',
], TOKEN);
assert_eq(200, $code, 'series directory → 200');
assert_eq(2, count($body['records']), 'series produces 2 episodes');
$ids = array_map(static fn($r) => $r['id'], $body['records']);
sort($ids);
assert_eq(['rt-9999999-s01e01', 'rt-9999999-s01e02'], $ids, 'episode ids');
assert_eq(true, !empty($body['warnings']), 'extras.mkv is reported as warning');

// 8) Path outside media_roots → 400.
[$code, $body] = call('POST', '/api/register', [
    'path' => '/etc/hostname',
    'title' => 'evil',
    'kind' => 'movie',
    'media_id' => 'rt-1',
], TOKEN);
assert_eq(400, $code, 'path outside media_roots → 400');

// 9) Missing media_id → 400.
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Standalone.mp4',
    'title' => 'Standalone',
    'kind' => 'movie',
], TOKEN);
assert_eq(400, $code, 'missing media_id → 400');

// 10) Malformed media_id → 400.
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Standalone.mp4',
    'title' => 'Standalone',
    'kind' => 'movie',
    'media_id' => 'tt1234567',
], TOKEN);
assert_eq(400, $code, 'bare imdb id (no prefix) → 400');

// 11a) Cartoon kind: behaves like movie (single file from dir), but
//     persists as kind=cartoon so the bot's /list shows the 🎨 marker
//     and the dispatcher uses /Video/Cartoon/ on the rtorrent side.
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Movie/Sample.Movie.2024.1080p',
    'title' => 'Toy Story',
    'kind' => 'cartoon',
    'media_id' => 'rt-77777',
], TOKEN);
assert_eq(200, $code, 'cartoon → 200');
assert_eq(1, count($body['records']), 'cartoon registers one record');
assert_eq('cartoon', $body['records'][0]['kind'], 'cartoon kind round-trips');

// 11) Russian raw-numbered layout: season in directory name, plain
//     episode-number filenames. The double-episode range collapses to
//     its first number.
[$code, $body] = call('POST', '/api/register', [
    'path' => $mediaRoot . '/Series/Друзья (1994)/Сезон 9',
    'title' => 'Друзья',
    'kind' => 'series',
    'media_id' => 'rt-12345',
], TOKEN);
assert_eq(200, $code, 'season-in-dir + raw filenames → 200');
assert_eq(3, count($body['records']), 'three episodes registered (incl. 23-24 double)');
$episodes = array_map(static fn($r) => [(int) $r['season'], (int) $r['episode']], $body['records']);
sort($episodes);
assert_eq([[9, 1], [9, 2], [9, 23]], $episodes, 'season 9 episodes 1, 2, 23 (range → first)');

// 12) DELETE.
[$code, $body] = call('DELETE', '/api/records/rt-6843582', null, TOKEN);
assert_eq(200, $code, 'delete returns 200');
assert_eq(true, $body['deleted'], 'deleted=true');

[$code, $body] = call('DELETE', '/api/records/rt-6843582', null, TOKEN);
assert_eq(404, $code, 'second delete returns 404');

echo "all good\n";
