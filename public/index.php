<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

http_response_code(200);
header('Content-Type: text/html; charset=UTF-8');

$siteName = (string) app_config()['site_name'];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($siteName) ?></title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: #111827;
            color: #f3f4f6;
            font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main {
            max-width: 48rem;
            padding: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
<main>
    <h1><?= e($siteName) ?></h1>
    <p>Service is running. Open a registered link under <code>/watch/&lt;id&gt;</code>.</p>
</main>
</body>
</html>

