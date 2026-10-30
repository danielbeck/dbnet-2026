<?php
declare(strict_types=1);

final class EmbedProxy
{
    private const DESTINATIONS = [
        'poi' => 'http://nosebeep.com/poi/',
    ];

    public static function upstream(string $path, string $query = ''): ?string
    {
        if (!str_starts_with($path, '/embed/')) {
            return null;
        }
        $parts = explode('/', substr($path, strlen('/embed/')), 2);
        $base = self::DESTINATIONS[$parts[0]] ?? null;
        if ($base === null) {
            throw new InvalidArgumentException('Unknown embed destination.');
        }
        $asset = $parts[1] ?? '';
        foreach (explode('/', $asset) as $segment) {
            if ($segment === '.' || $segment === '..'
                || !preg_match('/^[a-zA-Z0-9._-]*$/D', $segment)) {
                throw new InvalidArgumentException('Invalid embed asset path.');
            }
        }
        if (strpbrk($query, "\r\n") !== false) {
            throw new InvalidArgumentException('Invalid embed query.');
        }
        return $base . $asset . ($query === '' ? '' : '?' . $query);
    }

    public static function handle(string $path, string $query, string $method): void
    {
        if (!str_starts_with($path, '/embed/')) {
            return;
        }
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        // An HTTP upstream must not gain access to the parent site's origin.
        header('Content-Security-Policy: sandbox allow-scripts allow-popups');
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            self::fail(405, 'Method not allowed.');
        }
        try {
            $upstream = self::upstream($path, $query);
        } catch (InvalidArgumentException $error) {
            self::fail(404, $error->getMessage());
        }
        if (!str_contains(substr($path, strlen('/embed/')), '/')) {
            header('Location: ' . $path . '/' . ($query === '' ? '' : '?' . $query), true, 308);
            exit;
        }
        if (!extension_loaded('curl')) {
            error_log('Embed proxy requires the PHP cURL extension.');
            self::fail(502, 'Embed proxy is unavailable.');
        }
        $curl = curl_init($upstream);
        if ($curl === false) {
            error_log('Unable to initialize embed proxy transport.');
            self::fail(502, 'Unable to load embedded content.');
        }
        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 8 * 1024 * 1024) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $success = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $type = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        $error = curl_error($curl);
        if ($success === false || $status < 200 || $status >= 300) {
            error_log("Embed proxy failed for {$path}: HTTP {$status}; {$error}");
            self::fail(502, 'Unable to load embedded content.');
        }
        if ($status === 204) {
            http_response_code(204);
            exit;
        }
        $mime = strtolower(trim(explode(';', $type)[0]));
        $allowed = [
            'text/html', 'text/css', 'text/javascript', 'application/javascript',
            'application/json', 'image/svg+xml', 'image/png', 'image/jpeg',
            'image/gif', 'image/webp', 'image/x-icon', 'font/woff', 'font/woff2',
        ];
        if (!in_array($mime, $allowed, true)) {
            error_log("Embed proxy rejected content type for {$path}: {$type}");
            self::fail(502, 'Unsupported embedded content type.');
        }
        header('Content-Type: ' . $mime . (str_starts_with($mime, 'text/') || $mime === 'application/javascript'
            ? '; charset=utf-8' : ''));
        if ($method !== 'HEAD') {
            echo $body;
        }
        exit;
    }

    private static function fail(int $status, string $message): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        exit($message);
    }
}
