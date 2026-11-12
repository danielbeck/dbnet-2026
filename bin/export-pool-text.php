#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php bin/export-pool-text.php > storage/pool-text.txt\n");
    exit(1);
}

require_once dirname(__DIR__) . '/app/Database.php';

try {
    $items = Database::connection()->query(
        'SELECT title, body FROM pool_items ORDER BY date ASC, id ASC'
    );
    while ($item = $items->fetch()) {
        foreach (['title', 'body'] as $field) {
            $line = str_replace(["\r\n", "\r", "\n"], '\\n', $item[$field]) . "\n";
            $offset = 0;
            $length = strlen($line);
            while ($offset < $length) {
                $written = fwrite(STDOUT, substr($line, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not write the export.');
                }
                $offset += $written;
            }
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Export failed: ' . $error->getMessage() . "\n");
    exit(1);
}
