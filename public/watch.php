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
$watchUrl = full_url('/watch/' . rawurlencode($id));
$streamUrl = full_url('/stream/' . rawurlencode($id));
$siteName = (string) app_config()['site_name'];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e($siteName) ?></title>
    <meta name="description" content="<?= e($description) ?>">
    <meta name="robots" content="noindex,nofollow">

    <meta property="og:title" content="<?= e($title) ?>">
    <meta property="og:description" content="<?= e($description) ?>">
    <meta property="og:type" content="video.movie">
    <meta property="og:url" content="<?= e($watchUrl) ?>">
<?php if ($posterUrl !== ''): ?>
    <meta property="og:image" content="<?= e($posterUrl) ?>">
<?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">

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
        .meta {
            padding: 1rem 1.25rem 1.25rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.95rem;
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

        <video
            controls
            preload="metadata"
<?php if ($posterUrl !== ''): ?>
            poster="<?= e($posterUrl) ?>"
<?php endif; ?>
        >
            <source src="<?= e($streamUrl) ?>"<?= $mimeType !== '' ? ' type="' . e($mimeType) . '"' : '' ?>>
            Ваш браузер не поддерживает встроенное воспроизведение этого формата.
        </video>

        <div class="meta">
            <div>Ссылка: <a href="<?= e($watchUrl) ?>"><?= e($watchUrl) ?></a></div>
        </div>
    </div>
</main>
</body>
</html>

