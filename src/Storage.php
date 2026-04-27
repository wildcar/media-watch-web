<?php

declare(strict_types=1);

final class MediaWatchStorage
{
    /** Extensions that play in modern browsers without conversion. */
    private const BROWSER_PLAYABLE_EXTS = ['mp4', 'm4v', 'webm', 'ogv', 'mov'];

    /** Video codecs we can carry into MP4 with `-c:v copy`. Anything else
     *  requires a full transcode, which the user explicitly opted out of. */
    private const REMUXABLE_VIDEO_CODECS = ['h264', 'hevc'];

    /** Audio codecs MP4 holds without transcode. Everything else gets
     *  re-encoded to AAC at remux time. */
    private const COPYABLE_AUDIO_CODECS = ['aac', 'mp3'];

    private PDO $pdo;
    /** @var list<string> */
    private array $mediaRoots;
    private string $shareDir;

    /**
     * @param list<string> $mediaRoots
     */
    public function __construct(string $dbPath, array $mediaRoots = [], string $shareDir = '')
    {
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create SQLite directory: ' . $dir);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA synchronous = NORMAL');
        $this->mediaRoots = $mediaRoots;
        $this->shareDir = $shareDir;
        $this->ensureSchema();
    }

    public function get(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM records WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function upsert(array $record): array
    {
        $id = trim((string) ($record['id'] ?? ''));
        $filePath = trim((string) ($record['file_path'] ?? ''));
        $title = trim((string) ($record['title'] ?? ''));

        if ($id === '') {
            throw new InvalidArgumentException('`id` is required.');
        }
        if ($title === '') {
            throw new InvalidArgumentException('`title` is required.');
        }

        $resolvedPath = $this->assertAllowedFile($filePath);
        $kindRaw = (string) ($record['kind'] ?? 'movie');
        $kind = match ($kindRaw) {
            'series' => 'series',
            'cartoon' => 'cartoon',
            default => 'movie',
        };
        $mimeType = trim((string) ($record['mime_type'] ?? ''));
        if ($mimeType === '') {
            $mimeType = $this->detectMimeType($resolvedPath);
        }

        $probe = $this->probeVideo($resolvedPath);
        $remux = $this->decideRemux($id, $resolvedPath, $probe);

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO records (
                id, file_path, title, description, poster_url, mime_type, kind,
                video_width, video_height, duration_seconds,
                share_path, remux_status, remux_error,
                created_at, updated_at
            ) VALUES (
                :id, :file_path, :title, :description, :poster_url, :mime_type, :kind,
                :video_width, :video_height, :duration_seconds,
                :share_path, :remux_status, :remux_error,
                :created_at, :updated_at
            )
            ON CONFLICT(id) DO UPDATE SET
                file_path = excluded.file_path,
                title = excluded.title,
                description = excluded.description,
                poster_url = excluded.poster_url,
                mime_type = excluded.mime_type,
                kind = excluded.kind,
                video_width = excluded.video_width,
                video_height = excluded.video_height,
                duration_seconds = excluded.duration_seconds,
                share_path = excluded.share_path,
                remux_status = excluded.remux_status,
                remux_error = excluded.remux_error,
                updated_at = excluded.updated_at'
        );

        $stmt->execute([
            ':id' => $id,
            ':file_path' => $resolvedPath,
            ':title' => $title,
            ':description' => trim((string) ($record['description'] ?? '')),
            ':poster_url' => trim((string) ($record['poster_url'] ?? '')),
            ':mime_type' => $mimeType,
            ':kind' => $kind,
            ':video_width' => $probe['width'],
            ':video_height' => $probe['height'],
            ':duration_seconds' => $probe['duration'],
            ':share_path' => $remux['share_path'],
            ':remux_status' => $remux['status'],
            ':remux_error' => $remux['error'],
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        if ($remux['status'] === 'pending') {
            $this->spawnRemuxWorker($id);
        }

        $stored = $this->get($id);
        if ($stored === null) {
            throw new RuntimeException('Failed to read back stored record.');
        }
        return $stored;
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM records WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS records (
                id TEXT PRIMARY KEY,
                file_path TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT "",
                poster_url TEXT NOT NULL DEFAULT "",
                mime_type TEXT NOT NULL DEFAULT "application/octet-stream",
                kind TEXT NOT NULL DEFAULT "movie",
                video_width INTEGER NOT NULL DEFAULT 0,
                video_height INTEGER NOT NULL DEFAULT 0,
                duration_seconds INTEGER NOT NULL DEFAULT 0,
                share_path TEXT NOT NULL DEFAULT "",
                remux_status TEXT NOT NULL DEFAULT "",
                remux_error TEXT NOT NULL DEFAULT "",
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        // Idempotent migrations for older deploys. SQLite has no
        // IF NOT EXISTS for ADD COLUMN, so we probe table_info first.
        $columns = [];
        foreach ($this->pdo->query('PRAGMA table_info(records)') as $row) {
            $columns[(string) $row['name']] = true;
        }
        $migrations = [
            'video_width' => 'INTEGER NOT NULL DEFAULT 0',
            'video_height' => 'INTEGER NOT NULL DEFAULT 0',
            'duration_seconds' => 'INTEGER NOT NULL DEFAULT 0',
            'share_path' => 'TEXT NOT NULL DEFAULT ""',
            'remux_status' => 'TEXT NOT NULL DEFAULT ""',
            'remux_error' => 'TEXT NOT NULL DEFAULT ""',
        ];
        foreach ($migrations as $col => $defn) {
            if (!isset($columns[$col])) {
                $this->pdo->exec("ALTER TABLE records ADD COLUMN $col $defn");
            }
        }
    }

    /**
     * Run ffprobe and pull width / height / duration / video codec /
     * audio codecs. Failures are non-fatal — we just return zeros and
     * empty codec strings so the record still stores.
     *
     * @return array{width:int,height:int,duration:int,video_codec:string,audio_codecs:list<string>}
     */
    public function probeVideo(string $path): array
    {
        $blank = ['width' => 0, 'height' => 0, 'duration' => 0, 'video_codec' => '', 'audio_codecs' => []];
        $cmd = sprintf(
            'ffprobe -v error -of json -show_entries '
            . 'stream=index,codec_type,codec_name,width,height '
            . '-show_entries format=duration %s 2>/dev/null',
            escapeshellarg($path)
        );
        $output = @shell_exec($cmd);
        if (!is_string($output) || $output === '') {
            return $blank;
        }
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return $blank;
        }

        $videoCodec = '';
        $width = 0;
        $height = 0;
        $audioCodecs = [];
        foreach (($decoded['streams'] ?? []) as $stream) {
            $type = (string) ($stream['codec_type'] ?? '');
            $name = strtolower((string) ($stream['codec_name'] ?? ''));
            if ($type === 'video' && $videoCodec === '') {
                $videoCodec = $name;
                $width = (int) ($stream['width'] ?? 0);
                $height = (int) ($stream['height'] ?? 0);
            } elseif ($type === 'audio') {
                $audioCodecs[] = $name;
            }
        }
        $duration = (float) ($decoded['format']['duration'] ?? 0);
        return [
            'width' => $width,
            'height' => $height,
            'duration' => (int) round($duration),
            'video_codec' => $videoCodec,
            'audio_codecs' => $audioCodecs,
        ];
    }

    /**
     * Pick the remux status for a freshly-registered record:
     *
     * - `not_needed` — original plays in the browser, no work required.
     * - `pending`    — the source video codec is H.264/HEVC, so we can
     *                  carry it into MP4 unchanged. A worker spawn is
     *                  expected immediately after upsert.
     * - `failed`     — neither original-playable nor remuxable.
     *
     * @param array{video_codec:string,audio_codecs:list<string>} $probe
     * @return array{status:string,share_path:string,error:string}
     */
    private function decideRemux(string $id, string $resolvedPath, array $probe): array
    {
        $ext = strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION));
        if (in_array($ext, self::BROWSER_PLAYABLE_EXTS, true)) {
            return ['status' => 'not_needed', 'share_path' => '', 'error' => ''];
        }

        if ($probe['video_codec'] === '') {
            return [
                'status' => 'failed',
                'share_path' => '',
                'error' => 'ffprobe could not identify a video stream',
            ];
        }
        if (!in_array($probe['video_codec'], self::REMUXABLE_VIDEO_CODECS, true)) {
            return [
                'status' => 'failed',
                'share_path' => '',
                'error' => sprintf(
                    'video codec "%s" cannot be remuxed to mp4 without full re-encoding',
                    $probe['video_codec']
                ),
            ];
        }

        if ($this->shareDir === '') {
            return [
                'status' => 'failed',
                'share_path' => '',
                'error' => 'share_dir is not configured',
            ];
        }

        $sharePath = $this->shareDir . DIRECTORY_SEPARATOR . $id . '.mp4';
        return ['status' => 'pending', 'share_path' => $sharePath, 'error' => ''];
    }

    /**
     * Decide whether the source audio can ride along with `-c:a copy` or
     * whether we need to transcode every audio stream to AAC. Returns
     * either "copy" or "aac".
     *
     * @param list<string> $audioCodecs
     */
    public function audioStrategy(array $audioCodecs): string
    {
        if ($audioCodecs === []) {
            return 'copy';
        }
        foreach ($audioCodecs as $codec) {
            if (!in_array($codec, self::COPYABLE_AUDIO_CODECS, true)) {
                return 'aac';
            }
        }
        return 'copy';
    }

    public function setRemuxRunning(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE records SET remux_status = "running", remux_error = "", updated_at = :u WHERE id = :id'
        );
        $stmt->execute([':u' => gmdate('c'), ':id' => $id]);
    }

    public function setRemuxReady(string $id, string $sharePath): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE records SET remux_status = "ready", share_path = :p, remux_error = "", updated_at = :u WHERE id = :id'
        );
        $stmt->execute([':u' => gmdate('c'), ':p' => $sharePath, ':id' => $id]);
    }

    public function setRemuxFailed(string $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE records SET remux_status = "failed", remux_error = :e, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([':u' => gmdate('c'), ':e' => $error, ':id' => $id]);
    }

    public function setProbe(string $id, int $width, int $height, int $duration): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE records
                SET video_width = :w, video_height = :h, duration_seconds = :d, updated_at = :u
              WHERE id = :id'
        );
        $stmt->execute([
            ':w' => $width,
            ':h' => $height,
            ':d' => $duration,
            ':u' => gmdate('c'),
            ':id' => $id,
        ]);
    }

    /**
     * @return list<array{id:string,file_path:string}>
     */
    public function listMissingDimensions(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, file_path FROM records WHERE video_width = 0 OR video_height = 0') as $row) {
            $out[] = ['id' => (string) $row['id'], 'file_path' => (string) $row['file_path']];
        }
        return $out;
    }

    /**
     * @return list<array{id:string,file_path:string,share_path:string,remux_status:string}>
     */
    public function listAllForRemuxAudit(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, file_path, share_path, remux_status FROM records') as $row) {
            $out[] = [
                'id' => (string) $row['id'],
                'file_path' => (string) $row['file_path'],
                'share_path' => (string) $row['share_path'],
                'remux_status' => (string) $row['remux_status'],
            ];
        }
        return $out;
    }

    /**
     * Re-evaluate remux status for an existing record: useful for
     * backfilling rows that were registered before the remux feature
     * shipped. Does not spawn a worker — caller decides.
     */
    public function reapplyRemuxDecision(string $id): array
    {
        $record = $this->get($id);
        if ($record === null) {
            throw new RuntimeException('No such record: ' . $id);
        }
        $probe = $this->probeVideo((string) $record['file_path']);
        $decision = $this->decideRemux($id, (string) $record['file_path'], $probe);
        $stmt = $this->pdo->prepare(
            'UPDATE records SET share_path = :p, remux_status = :s, remux_error = :e, updated_at = :u WHERE id = :id'
        );
        $stmt->execute([
            ':p' => $decision['share_path'],
            ':s' => $decision['status'],
            ':e' => $decision['error'],
            ':u' => gmdate('c'),
            ':id' => $id,
        ]);
        return $decision;
    }

    public function spawnRemuxWorker(string $id): void
    {
        $worker = dirname(__DIR__) . '/bin/remux-worker.php';
        if (!is_file($worker)) {
            $this->setRemuxFailed($id, 'remux-worker.php is missing');
            return;
        }
        $php = getenv('MEDIA_WATCH_PHP_CLI') ?: '/usr/bin/php';
        $cmd = sprintf(
            'nohup %s %s %s > /dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($worker),
            escapeshellarg($id)
        );
        // proc_open with `bypass_shell` would be cleaner, but we need
        // shell-level `&` to detach. Closing stdio + nohup is enough on
        // Linux for the parent (PHP-FPM request) to finish without
        // waiting on the worker.
        @exec($cmd);
    }

    private function assertAllowedFile(string $path): string
    {
        if ($path === '') {
            throw new InvalidArgumentException('`file_path` is required.');
        }

        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            throw new InvalidArgumentException('File does not exist: ' . $path);
        }

        if ($this->mediaRoots !== []) {
            foreach ($this->mediaRoots as $root) {
                $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if (str_starts_with($resolved, $prefix) || $resolved === $root) {
                    return $resolved;
                }
            }

            throw new InvalidArgumentException(
                'File is outside MEDIA_WATCH_MEDIA_ROOTS: ' . $resolved
            );
        }

        return $resolved;
    }

    private function detectMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);
        return is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function mapRow(array $row): array
    {
        return [
            'id' => (string) ($row['id'] ?? ''),
            'file_path' => (string) ($row['file_path'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'poster_url' => (string) ($row['poster_url'] ?? ''),
            'mime_type' => (string) ($row['mime_type'] ?? 'application/octet-stream'),
            'kind' => (string) ($row['kind'] ?? 'movie'),
            'video_width' => (int) ($row['video_width'] ?? 0),
            'video_height' => (int) ($row['video_height'] ?? 0),
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
            'share_path' => (string) ($row['share_path'] ?? ''),
            'remux_status' => (string) ($row['remux_status'] ?? ''),
            'remux_error' => (string) ($row['remux_error'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
