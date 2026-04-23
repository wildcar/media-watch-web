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

        $now = gmdate('c');
        $stmt = $this->pdo->prepare(
            'INSERT INTO records (
                id, file_path, title, description, poster_url, mime_type, kind, created_at, updated_at
            ) VALUES (
                :id, :file_path, :title, :description, :poster_url, :mime_type, :kind, :created_at, :updated_at
            )
            ON CONFLICT(id) DO UPDATE SET
                file_path = excluded.file_path,
                title = excluded.title,
                description = excluded.description,
                poster_url = excluded.poster_url,
                mime_type = excluded.mime_type,
                kind = excluded.kind,
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
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
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
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}

