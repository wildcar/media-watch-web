<?php

declare(strict_types=1);

final class MediaWatchStorage
{
    private PDO $pdo;
    /** @var list<string> */
    private array $mediaRoots;

    /**
     * @param list<string> $mediaRoots
     */
    public function __construct(string $dbPath, array $mediaRoots = [])
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
        $kind = ($record['kind'] ?? 'movie') === 'series' ? 'series' : 'movie';
        $mimeType = trim((string) ($record['mime_type'] ?? ''));
        if ($mimeType === '') {
            $mimeType = $this->detectMimeType($resolvedPath);
        }

        $probe = $this->probeVideo($resolvedPath);

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO records (
                id, file_path, title, description, poster_url, mime_type, kind,
                video_width, video_height, duration_seconds,
                created_at, updated_at
            ) VALUES (
                :id, :file_path, :title, :description, :poster_url, :mime_type, :kind,
                :video_width, :video_height, :duration_seconds,
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
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

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
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        // Idempotent migrations for older deploys that pre-date the
        // dimension columns. SQLite has no IF NOT EXISTS for ADD COLUMN,
        // so we probe table_info first.
        $columns = [];
        foreach ($this->pdo->query('PRAGMA table_info(records)') as $row) {
            $columns[(string) $row['name']] = true;
        }
        if (!isset($columns['video_width'])) {
            $this->pdo->exec('ALTER TABLE records ADD COLUMN video_width INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($columns['video_height'])) {
            $this->pdo->exec('ALTER TABLE records ADD COLUMN video_height INTEGER NOT NULL DEFAULT 0');
        }
        if (!isset($columns['duration_seconds'])) {
            $this->pdo->exec('ALTER TABLE records ADD COLUMN duration_seconds INTEGER NOT NULL DEFAULT 0');
        }
    }

    /**
     * Run ffprobe against a video file and pull width / height /
     * duration. Failures (missing binary, broken file, timeout) are
     * non-fatal — we just return zeros so the caller can still store
     * the record.
     *
     * @return array{width:int,height:int,duration:int}
     */
    public function probeVideo(string $path): array
    {
        $cmd = sprintf(
            'ffprobe -v error -of json -select_streams v:0 '
            . '-show_entries stream=width,height -show_entries format=duration %s 2>/dev/null',
            escapeshellarg($path)
        );
        $output = @shell_exec($cmd);
        if (!is_string($output) || $output === '') {
            return ['width' => 0, 'height' => 0, 'duration' => 0];
        }
        $decoded = json_decode($output, true);
        if (!is_array($decoded)) {
            return ['width' => 0, 'height' => 0, 'duration' => 0];
        }
        $stream = $decoded['streams'][0] ?? [];
        $duration = (float) ($decoded['format']['duration'] ?? 0);
        return [
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'duration' => (int) round($duration),
        ];
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
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

