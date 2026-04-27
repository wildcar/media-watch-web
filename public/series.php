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

$episodes = app_storage()->listSeriesEpisodes($id);
if ($episodes === []) {
    http_response_code(404);
    echo 'Not found';
    exit;
}

// Reconstruct the parent series title from the first episode's display
// title — register stamps it as `<series> — S01E01`, so strip the
// trailing season/episode marker. Falls back to the first row's title
// verbatim when the marker is missing.
$firstTitle = (string) $episodes[0]['title'];
$seriesTitle = preg_replace('/\s+—\s+S\d+E\d+\s*$/u', '', $firstTitle) ?: $firstTitle;

$siteName = (string) app_config()['site_name'];
$pageTitle = $seriesTitle . ' — серии · ' . $siteName;

// Group by season for a cleaner UI on long shows.
$bySeason = [];
foreach ($episodes as $ep) {
    $bySeason[$ep['season']][] = $ep;
}
ksort($bySeason);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title><?= e($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
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
            width: min(48rem, calc(100vw - 2rem));
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
            padding: 1.25rem 1.25rem 0.5rem;
        }
        h1 {
            margin: 0 0 0.25rem;
            font-size: clamp(1.4rem, 3.5vw, 2.1rem);
            line-height: 1.2;
        }
        .summary {
            color: var(--muted);
            margin: 0 0 0.5rem;
            font-size: 0.95rem;
        }
        h2 {
            margin: 1.25rem 1.25rem 0.5rem;
            font-size: 1.05rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }
        ul {
            list-style: none;
            margin: 0;
            padding: 0 1.25rem 0.75rem;
        }
        li {
            border-top: 1px solid var(--line);
        }
        li:first-child { border-top: 0; }
        li a {
            display: flex;
            align-items: baseline;
            gap: 0.75rem;
            padding: 0.75rem 0;
            color: var(--text);
            text-decoration: none;
        }
        li a:hover { color: #7dd3fc; }
        .ep-code {
            color: var(--muted);
            font-variant-numeric: tabular-nums;
            font-size: 0.9rem;
            min-width: 4.5rem;
        }
        .ep-title {
            flex: 1 1 auto;
            min-width: 0;
            overflow-wrap: anywhere;
        }
        footer {
            padding: 1rem 1.25rem 1.25rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<main>
    <div class="shell">
        <header>
            <h1><?= e($seriesTitle) ?></h1>
            <p class="summary">Доступно серий: <?= count($episodes) ?>.</p>
        </header>

<?php foreach ($bySeason as $season => $eps): ?>
        <h2>Сезон <?= (int) $season ?> · <?= count($eps) ?> <?= count($eps) === 1 ? 'серия' : 'серий' ?></h2>
        <ul>
<?php foreach ($eps as $ep): ?>
            <li>
                <a href="<?= e(full_url('/watch/' . rawurlencode((string) $ep['id']))) ?>">
                    <span class="ep-code">S<?= sprintf('%02d', $ep['season']) ?>E<?= sprintf('%02d', $ep['episode']) ?></span>
                    <span class="ep-title"><?= e((string) $ep['title']) ?></span>
                </a>
            </li>
<?php endforeach; ?>
        </ul>
<?php endforeach; ?>

        <footer>
            <a href="/">← <?= e($siteName) ?></a>
        </footer>
    </div>
</main>
</body>
</html>
