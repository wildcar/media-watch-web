<?php

declare(strict_types=1);

final class MediaWatchStreamer
{
    public static function send(
        string $path,
        string $mimeType,
        string $method = 'GET',
        bool $asAttachment = false,
        bool $useXSendfile = false,
    ): void {
        if (!is_readable($path) || !is_file($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'File is not readable';
            return;
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 0) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to determine file size';
            return;
        }

        // X-Sendfile path: hand the file off to Apache (mod_xsendfile)
        // so the response carries a real Content-Length. PHP-FPM streaming
        // ends up Transfer-Encoding: chunked, which kills download-progress
        // UI in browsers. Only the headers that *aren't* about the body
        // size are set here — Apache fills in Content-Length / Range.
        if ($useXSendfile) {
            header_remove('X-Powered-By');
            header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
            $disposition = $asAttachment ? 'attachment' : 'inline';
            header(
                'Content-Disposition: ' . $disposition
                . '; filename="' . addslashes(basename($path)) . '"'
            );
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('X-Sendfile: ' . $path);
            return;
        }

        $start = 0;
        $end = $size > 0 ? $size - 1 : 0;
        $status = 200;

        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
        if (is_string($rangeHeader) && $rangeHeader !== '') {
            $range = self::parseRange($rangeHeader, $size);
            if ($range === null) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return;
            }
            [$start, $end] = $range;
            $status = 206;
        }

        $length = $size === 0 ? 0 : ($end - $start + 1);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header_remove('X-Powered-By');
        header('Content-Type: ' . ($mimeType !== '' ? $mimeType : 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        $disposition = $asAttachment ? 'attachment' : 'inline';
        header(
            'Content-Disposition: ' . $disposition
            . '; filename="' . addslashes(basename($path)) . '"'
        );
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . $length);

        if ($status === 206) {
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }

        http_response_code($status);

        if (strcasecmp($method, 'HEAD') === 0 || $length === 0) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Failed to open file';
            return;
        }

        ignore_user_abort(true);
        set_time_limit(0);
        fseek($handle, $start);

        $remaining = $length;
        $chunkSize = 1024 * 1024;
        while ($remaining > 0 && !feof($handle)) {
            $read = min($chunkSize, $remaining);
            $buffer = fread($handle, $read);
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }

        fclose($handle);
    }

    /**
     * @return array{int, int}|null
     */
    private static function parseRange(string $header, int $size): ?array
    {
        if (!preg_match('/bytes=(\d*)-(\d*)/i', $header, $matches)) {
            return null;
        }

        $startRaw = $matches[1] ?? '';
        $endRaw = $matches[2] ?? '';

        if ($startRaw === '' && $endRaw === '') {
            return null;
        }

        if ($startRaw === '') {
            $suffixLength = (int) $endRaw;
            if ($suffixLength <= 0) {
                return null;
            }
            $start = max(0, $size - $suffixLength);
            $end = max(0, $size - 1);
            return [$start, $end];
        }

        $start = (int) $startRaw;
        $end = $endRaw === '' ? max(0, $size - 1) : (int) $endRaw;

        if ($size === 0 || $start < 0 || $start >= $size || $end < $start) {
            return null;
        }

        $end = min($end, $size - 1);
        return [$start, $end];
    }
}

