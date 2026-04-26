<?php

declare(strict_types=1);

/**
 * HTTP registration API surface used by the Telegram bot orchestrator.
 *
 * Endpoints:
 *   POST   /api/register           — register one or many records from a path
 *   DELETE /api/records/{id}       — remove a single record
 *
 * Auth: Bearer token (`api_token` in config.local.php or
 * `MEDIA_WATCH_API_TOKEN` env). Endpoint returns 503 if no token is
 * configured — refusing to run as an open relay.
 */
final class MediaWatchApi
{
    private const VIDEO_EXTENSIONS = [
        'mkv', 'mp4', 'avi', 'mov', 'm4v', 'webm',
        'ts', 'm2ts', 'mpg', 'mpeg', 'wmv', 'flv',
    ];

    /**
     * Composite media id format: `<source>-<id>`. The bot is the source
     * of truth — it picks the prefix at registration time so different
     * release sources of the same movie don't collide on a single PK.
     *
     *   imdb-tt1234567   — IMDb-only references (rare; bot prefers rt- when there's a torrent)
     *   rt-6843582       — rutracker topic id (default for any rutracker download)
     *   yt-dQw4w9WgXcQ   — YouTube video id (reserved; YouTube flow not implemented yet)
     */
    private const MEDIA_ID_REGEX = '/^(imdb-tt\d{7,10}|rt-\d+|yt-[A-Za-z0-9_-]{6,32})$/';

    /** Tried in order; first hit wins. Capture order: season, episode. */
    private const EPISODE_PATTERNS = [
        '/(?:^|[._\-\s\[(])s(\d{1,2})\s*[\.\s_\-]?\s*e(\d{1,3})(?:[\.\s_\-\])]|$)/i',
        '/(?:^|[._\-\s\[(])(\d{1,2})x(\d{1,3})(?:[\.\s_\-\])]|$)/i',
        '/season[\s_\-]?(\d{1,2}).{0,12}episode[\s_\-]?(\d{1,3})/i',
    ];

    /** @param array<string,mixed> $config */
    public function __construct(
        private MediaWatchStorage $storage,
        private array $config,
    ) {}

    public function handle(string $method, string $path): void
    {
        $this->emitJsonHeaders();

        $token = (string) ($this->config['api_token'] ?? '');
        if ($token === '') {
            $this->respond(503, ['error' => 'api_disabled', 'message' => 'API token is not configured']);
            return;
        }

        if (!$this->authorize($token)) {
            $this->respond(401, ['error' => 'unauthorized', 'message' => 'Invalid or missing bearer token']);
            return;
        }

        if ($method === 'POST' && $path === '/api/register') {
            $this->handleRegister();
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/records/([^/]+)/?$#', $path, $m) === 1) {
            $this->handleDelete(rawurldecode($m[1]));
            return;
        }

        $this->respond(404, ['error' => 'not_found', 'message' => 'Unknown endpoint']);
    }

    private function authorize(string $expectedToken): bool
    {
        $header = $this->bearerHeader();
        if ($header === null) {
            return false;
        }
        return hash_equals($expectedToken, $header);
    }

    private function bearerHeader(): ?string
    {
        $raw = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $raw, $m) !== 1) {
            return null;
        }
        return trim($m[1]);
    }

    private function handleRegister(): void
    {
        $body = $this->readJsonBody();
        if ($body === null) {
            $this->respond(400, ['error' => 'invalid_json', 'message' => 'Request body must be valid JSON']);
            return;
        }

        $path = trim((string) ($body['path'] ?? ''));
        $title = trim((string) ($body['title'] ?? ''));
        $kind = ($body['kind'] ?? 'movie') === 'series' ? 'series' : 'movie';
        $mediaId = trim((string) ($body['media_id'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));
        $posterUrl = trim((string) ($body['poster_url'] ?? ''));
        $mimeType = trim((string) ($body['mime_type'] ?? ''));

        if ($path === '' || $title === '' || $mediaId === '') {
            $this->respond(400, ['error' => 'invalid_argument', 'message' => '`path`, `title` and `media_id` are required']);
            return;
        }
        if (preg_match(self::MEDIA_ID_REGEX, $mediaId) !== 1) {
            $this->respond(400, [
                'error' => 'invalid_argument',
                'message' => '`media_id` must match <source>-<id> with source in {imdb,rt,yt}',
            ]);
            return;
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            $this->respond(400, ['error' => 'invalid_argument', 'message' => 'Path does not exist: ' . $path]);
            return;
        }

        try {
            $files = is_dir($resolved)
                ? $this->collectFromDirectory($resolved, $kind)
                : [$this->collectFromFile($resolved)];
        } catch (RuntimeException $e) {
            $this->respond(400, ['error' => 'invalid_argument', 'message' => $e->getMessage()]);
            return;
        }

        $warnings = [];
        $records = [];
        $baseId = $mediaId;

        foreach ($files as $entry) {
            if (isset($entry['warning'])) {
                $warnings[] = $entry['warning'];
                continue;
            }

            $file = $entry['file'];
            $season = $entry['season'] ?? null;
            $episode = $entry['episode'] ?? null;

            if ($kind === 'series' && ($season === null || $episode === null)) {
                $warnings[] = sprintf('skipped: %s (no SxxEyy marker)', basename($file));
                continue;
            }

            $idCandidate = $kind === 'series'
                ? sprintf('%s-s%02de%02d', $baseId, $season, $episode)
                : $baseId;

            try {
                $id = $this->allocateId($idCandidate, $file);
            } catch (RuntimeException $e) {
                $warnings[] = sprintf('skipped: %s (%s)', basename($file), $e->getMessage());
                continue;
            }

            $entryTitle = $kind === 'series'
                ? sprintf('%s — S%02dE%02d', $title, $season, $episode)
                : $title;

            try {
                $stored = $this->storage->upsert([
                    'id' => $id,
                    'file_path' => $file,
                    'title' => $entryTitle,
                    'description' => $description,
                    'poster_url' => $posterUrl,
                    'kind' => $kind,
                    'mime_type' => $mimeType,
                ]);
            } catch (Throwable $e) {
                $warnings[] = sprintf('failed: %s (%s)', basename($file), $e->getMessage());
                continue;
            }

            $records[] = [
                'id' => $stored['id'],
                'title' => $stored['title'],
                'kind' => $stored['kind'],
                'file_path' => $stored['file_path'],
                'season' => $season,
                'episode' => $episode,
                'watch_url' => full_url('/watch/' . rawurlencode($stored['id'])),
                'stream_url' => full_url('/stream/' . rawurlencode($stored['id'])),
            ];
        }

        if ($records === []) {
            $this->respond(400, [
                'error' => 'no_records',
                'message' => 'No registrable media files were found at the given path',
                'warnings' => $warnings,
            ]);
            return;
        }

        $this->respond(200, ['records' => $records, 'warnings' => $warnings]);
    }

    private function handleDelete(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            $this->respond(400, ['error' => 'invalid_argument', 'message' => '`id` is required']);
            return;
        }
        $deleted = $this->storage->delete($id);
        $this->respond($deleted ? 200 : 404, [
            'id' => $id,
            'deleted' => $deleted,
        ]);
    }

    /**
     * @return list<array{file:string,season:?int,episode:?int}|array{warning:string}>
     */
    private function collectFromDirectory(string $directory, string $kind): array
    {
        $files = $this->scanVideoFiles($directory);
        if ($files === []) {
            throw new RuntimeException('No video files found in ' . $directory);
        }

        if ($kind === 'movie') {
            usort($files, static fn(array $a, array $b): int => $b['size'] <=> $a['size']);
            return [['file' => $files[0]['path'], 'season' => null, 'episode' => null]];
        }

        $out = [];
        foreach ($files as $f) {
            $parsed = $this->parseEpisode(basename($f['path']));
            if ($parsed === null) {
                $out[] = ['warning' => sprintf('skipped: %s (no SxxEyy marker)', basename($f['path']))];
                continue;
            }
            $out[] = ['file' => $f['path'], 'season' => $parsed[0], 'episode' => $parsed[1]];
        }
        return $out;
    }

    /**
     * @return array{file:string,season:?int,episode:?int}
     */
    private function collectFromFile(string $file): array
    {
        if (!$this->isVideoFile($file)) {
            throw new RuntimeException('Not a recognized video file: ' . $file);
        }
        $parsed = $this->parseEpisode(basename($file));
        return [
            'file' => $file,
            'season' => $parsed[0] ?? null,
            'episode' => $parsed[1] ?? null,
        ];
    }

    /**
     * @return list<array{path:string,size:int}>
     */
    private function scanVideoFiles(string $directory): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        $out = [];
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            if (!$this->isVideoFile($path)) {
                continue;
            }
            if ($this->looksLikeSample($path, $directory)) {
                continue;
            }
            $out[] = ['path' => $path, 'size' => (int) $entry->getSize()];
        }
        return $out;
    }

    /**
     * "Sample" detection: trip on a directory component named `sample`/
     * `samples`, or on the token `sample` appearing in the filename in
     * any position **other than the first** — leading "Sample.Movie…"
     * is a real title and must be kept.
     */
    private function looksLikeSample(string $path, string $rootDir): bool
    {
        $rel = ltrim(substr($path, strlen($rootDir)), DIRECTORY_SEPARATOR);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        $filename = array_pop($parts);
        foreach ($parts as $dir) {
            if (strcasecmp($dir, 'sample') === 0 || strcasecmp($dir, 'samples') === 0) {
                return true;
            }
        }
        $stem = pathinfo((string) $filename, PATHINFO_FILENAME);
        $tokens = preg_split('/[._\-\s]+/', $stem) ?: [];
        foreach ($tokens as $i => $t) {
            if ($i > 0 && strcasecmp($t, 'sample') === 0) {
                return true;
            }
        }
        return false;
    }

    private function isVideoFile(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext !== '' && in_array($ext, self::VIDEO_EXTENSIONS, true);
    }

    /**
     * @return array{0:int,1:int}|null
     */
    private function parseEpisode(string $name): ?array
    {
        foreach (self::EPISODE_PATTERNS as $regex) {
            if (preg_match($regex, $name, $m) === 1) {
                return [(int) $m[1], (int) $m[2]];
            }
        }
        return null;
    }

    private function allocateId(string $candidate, string $newPath): string
    {
        $existing = $this->storage->get($candidate);
        if ($existing === null) {
            return $candidate;
        }
        if (($existing['file_path'] ?? '') === $newPath) {
            return $candidate;
        }

        for ($n = 2; $n <= 99; $n++) {
            $next = sprintf('%s-%d', $candidate, $n);
            $existing = $this->storage->get($next);
            if ($existing === null) {
                return $next;
            }
            if (($existing['file_path'] ?? '') === $newPath) {
                return $next;
            }
        }
        throw new RuntimeException('Could not allocate a free id for ' . $candidate);
    }

    private function readJsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function emitJsonHeaders(): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
    }

    private function respond(int $status, array $payload): void
    {
        http_response_code($status);
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );
    }
}
