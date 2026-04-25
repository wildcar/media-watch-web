<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: text/html; charset=UTF-8');

$id = request_id();
if ($id === '') {
    http_response_code(400);
    echo 'Missing id';
    exit;
}

$record = app_storage()->get($id);
if ($record === null) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

$title = trim((string) ($record['title'] ?? 'Видео'));
$description = trim((string) ($record['description'] ?? ''));
if ($description === '') {
    $description = 'Просмотр видео в браузере';
}

$posterUrl = trim((string) ($record['poster_url'] ?? ''));
$mimeType = trim((string) ($record['mime_type'] ?? ''));
$videoWidth = (int) ($record['video_width'] ?? 0);
$videoHeight = (int) ($record['video_height'] ?? 0);
$watchUrl = full_url('/watch/' . rawurlencode($id));
$originalStreamUrl = full_url('/stream/' . rawurlencode($id));
$shareStreamUrl = full_url('/stream/' . rawurlencode($id) . '?share=1');
$downloadUrl = $originalStreamUrl . '?download=1';
$filename = trim(basename((string) ($record['file_path'] ?? '')));
$ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
$siteName = (string) app_config()['site_name'];

$remuxStatus = (string) ($record['remux_status'] ?? '');
$browserPlayableExt = in_array($ext, ['mp4', 'm4v', 'webm', 'ogv', 'mov'], true);

// Decide what (if anything) to render in place of the player.
//   `play_original` → original file directly in <video>.
//   `play_share`    → use the remuxed mp4 in /mnt/.../Share.
//   `pending`       → "Идёт подготовка..." with meta-refresh.
//   `unplayable`    → "Воспроизведение здесь невозможно".
if ($browserPlayableExt || $remuxStatus === 'not_needed') {
    $playerMode = 'play_original';
    $playerSrc = $originalStreamUrl;
    $playerMime = $mimeType !== '' ? $mimeType : 'video/mp4';
} elseif ($remuxStatus === 'ready') {
    $playerMode = 'play_share';
    $playerSrc = $shareStreamUrl;
    $playerMime = 'video/mp4';
} elseif ($remuxStatus === 'pending' || $remuxStatus === 'running') {
    $playerMode = 'pending';
    $playerSrc = '';
    $playerMime = '';
} else {
    $playerMode = 'unplayable';
    $playerSrc = '';
    $playerMime = '';
}

// og:video should point at whatever is actually playable, so Telegram
// can build an inline preview. If nothing is playable yet, drop the
// video tags entirely — clients will render a plain article card.
$ogVideoUrl = $playerMode === 'play_original' || $playerMode === 'play_share'
    ? $playerSrc
    : '';
$ogVideoType = $playerMode === 'play_share' ? 'video/mp4' : $mimeType;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e($siteName) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="robots" content="noindex">
<?php if ($playerMode === 'pending'): ?>
    <meta http-equiv="refresh" content="20">
<?php endif; ?>

    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:type" content="video.movie">
    <meta property="og:url" content="<?= e($watchUrl) ?>">
<?php if ($posterUrl !== ''): ?>
    <meta property="og:image" content="<?= e($posterUrl) ?>">
<?php endif; ?>

<?php if ($ogVideoUrl !== ''): ?>
    <meta property="og:video" content="<?= e($ogVideoUrl) ?>">
    <meta property="og:video:url" content="<?= e($ogVideoUrl) ?>">
    <meta property="og:video:secure_url" content="<?= e($ogVideoUrl) ?>">
<?php if ($ogVideoType !== ''): ?>
    <meta property="og:video:type" content="<?= e($ogVideoType) ?>">
<?php endif; ?>
<?php if ($videoWidth > 0 && $videoHeight > 0): ?>
    <meta property="og:video:width" content="<?= $videoWidth ?>">
    <meta property="og:video:height" content="<?= $videoHeight ?>">
<?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:player" content="<?= e($ogVideoUrl) ?>">
    <meta name="twitter:player:stream" content="<?= e($ogVideoUrl) ?>">
<?php if ($ogVideoType !== ''): ?>
    <meta name="twitter:player:stream:content_type" content="<?= e($ogVideoType) ?>">
<?php endif; ?>
<?php if ($videoWidth > 0 && $videoHeight > 0): ?>
    <meta name="twitter:player:width" content="<?= $videoWidth ?>">
    <meta name="twitter:player:height" content="<?= $videoHeight ?>">
<?php endif; ?>
<?php endif; ?>

    <style>
        :root {
            color-scheme: dark;
            --bg: #0f172a;
            --panel: rgba(15, 23, 42, 0.88);
            --line: rgba(148, 163, 184, 0.24);
            --text: #e5e7eb;
            --muted: #94a3b8;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at top, rgba(14, 165, 233, 0.16), transparent 28rem),
                linear-gradient(180deg, #020617 0%, #0f172a 100%);
            color: var(--text);
            font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main {
            width: min(72rem, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 1.25rem 0 2rem;
        }
        .shell {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 1.25rem 4rem rgba(0, 0, 0, 0.35);
        }
        header {
            padding: 1.25rem 1.25rem 0;
        }
        h1 {
            margin: 0;
            font-size: clamp(1.5rem, 4vw, 2.5rem);
            line-height: 1.1;
        }
        .description {
            margin: 0.75rem 0 0;
            color: var(--muted);
            max-width: 60ch;
        }
        video {
            display: block;
            width: 100%;
            max-height: 78vh;
            background: #000;
            margin-top: 1rem;
        }
        .placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 14rem;
            margin-top: 1rem;
            background: #000;
            color: var(--muted);
            text-align: center;
            padding: 2rem 1rem;
            font-size: 1.05rem;
            line-height: 1.5;
        }
        .placeholder strong { color: var(--text); }
        .meta {
            padding: 1rem 1.25rem 1.25rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.95rem;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .meta .download {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }
        .meta .filename {
            font-size: 0.85rem;
            opacity: 0.7;
            word-break: break-all;
        }
        a { color: #7dd3fc; }
    </style>
</head>
<body>
<main>
    <div class="shell">
        <header>
            <h1><?= e($title) ?></h1>
            <p class="description"><?= e($description) ?></p>
        </header>

<?php if ($playerMode === 'play_original' || $playerMode === 'play_share'): ?>
        <video
            controls
            preload="metadata"
<?php if ($posterUrl !== ''): ?>
            poster="<?= e($posterUrl) ?>"
<?php endif; ?>
        >
            <source src="<?= e($playerSrc) ?>" type="<?= e($playerMime) ?>">
            Ваш браузер не поддерживает встроенное воспроизведение этого формата.
        </video>
<?php elseif ($playerMode === 'pending'): ?>
        <div class="placeholder">
            <div>
                <strong>Идёт подготовка к воспроизведению.</strong><br>
                Файл перепаковывается в формат, понятный браузеру. Страница
                обновится сама через 20 секунд.
            </div>
        </div>
<?php else: ?>
        <div class="placeholder">
            <div>
                <strong>Воспроизведение данного файла здесь невозможно.</strong><br>
                Скачайте файл и откройте его в локальном плеере (например,
                <a href="https://www.videolan.org/vlc/" rel="noopener noreferrer" target="_blank">VLC</a>).
            </div>
        </div>
<?php endif; ?>

        <div class="meta">
            <div class="download">
                📥 <a href="<?= e($downloadUrl) ?>" download="<?= e($filename) ?>">Скачать файл</a>
<?php if ($filename !== ''): ?>
                <span class="filename"><?= e($filename) ?></span>
<?php endif; ?>
            </div>
        </div>
    </div>
</main>
</body>
</html>
