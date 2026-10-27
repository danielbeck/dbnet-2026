#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

function runSyncCommand(array $command, mixed $output = STDOUT): void
{
    $process = proc_open($command, [0 => STDIN, 1 => $output, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start sync command.');
    }
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Sync command failed: ' . $command[0]);
    }
}

$remote = $argv[1] ?? '';
$remotePhp = $argv[2] ?? '';
if (!preg_match('/^([a-zA-Z0-9_.@-]+):(.+)$/D', $remote, $match) || str_starts_with($remote, '-')) {
    throw new InvalidArgumentException('DEPLOY_TARGET must be an SSH destination such as user@host:directory.');
}
if (!class_exists(SQLite3::class)) {
    throw new RuntimeException('The local PHP SQLite3 extension is required for development sync.');
}
$root = dirname(__DIR__);
$storage = $root . '/storage';
$directory = rtrim($match[2], '/');
$host = $match[1];
if (!is_dir($storage) && !mkdir($storage, 0750, true)) {
    throw new RuntimeException('Could not create local storage.');
}
$temporary = tempnam($storage, 'sync-');
if ($temporary === false) {
    throw new RuntimeException('Could not create a temporary database file.');
}
$snapshotPath = $temporary . '.sqlite';
if (!rename($temporary, $snapshotPath)) {
    throw new RuntimeException('Could not name the temporary database file.');
}

try {
    $remoteCode = '$path = ' . var_export($directory . '/storage/dbnet.sqlite', true) . ';' . <<<'PHP'
if (!is_file($path)) {
    throw new RuntimeException('Remote database does not exist.');
}
$temporary = tempnam(sys_get_temp_dir(), 'dbnet-snapshot-');
if ($temporary === false) {
    throw new RuntimeException('Could not create remote snapshot file.');
}
try {
    $db = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 10000');
    $db->exec('VACUUM INTO ' . $db->quote($temporary));
    if (readfile($temporary) === false) {
        throw new RuntimeException('Could not stream remote snapshot.');
    }
} finally {
    unlink($temporary);
}
PHP;
    echo "Downloading a consistent database snapshot from {$host}...\n";
    $output = fopen($snapshotPath, 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not open snapshot output.');
    }
    try {
        $candidates = $remotePhp !== '' ? [$remotePhp] : ['php', 'php8.4-cli', 'php8.3-cli', 'php8.2-cli', 'php8.1-cli'];
        $remoteCommand = 'for candidate in ' . implode(' ', array_map('escapeshellarg', $candidates)) . "; do\n"
            . '  if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -v 2>/dev/null | grep -q "(cli)"; then' . "\n"
            . '    exec "$candidate" -r ' . escapeshellarg($remoteCode) . "\n"
            . "  fi\ndone\n"
            . 'echo "No suitable PHP CLI found on the server. Set REMOTE_PHP in .env to its executable (for example /usr/bin/php8.3-cli)." >&2' . "\n"
            . "exit 1\n";
        runSyncCommand(['ssh', $host, $remoteCommand], $output);
    } finally {
        fclose($output);
    }
    $snapshot = new SQLite3($snapshotPath, SQLITE3_OPEN_READONLY);
    $snapshot->enableExceptions(true);
    if ($snapshot->querySingle('PRAGMA integrity_check') !== 'ok') {
        throw new RuntimeException('Downloaded database failed its integrity check.');
    }
    foreach (['pages', 'pool_items', 'page_aliases', 'admins', 'login_attempts'] as $table) {
        $snapshot->querySingle('SELECT count(*) FROM ' . $table);
    }
    echo "Syncing content media (no deletions)...\n";
    runSyncCommand([
        'rsync', '-rltz', '--itemize-changes', '-e', 'ssh',
        '--exclude=.htaccess', '--exclude=index.html',
        $host . ':' . escapeshellarg($directory . '/content/'), $root . '/content/',
    ]);

    $localPath = $storage . '/dbnet.sqlite';
    if (is_file($localPath)) {
        $backupPath = $storage . '/before-dev-sync-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $local = new SQLite3($localPath, SQLITE3_OPEN_READWRITE);
        $local->enableExceptions(true);
        $local->busyTimeout(10000);
        $backup = new SQLite3($backupPath);
        $backup->enableExceptions(true);
        if (!$local->backup($backup) || $backup->querySingle('PRAGMA integrity_check') !== 'ok') {
            throw new RuntimeException('Could not create a valid local database backup.');
        }
        $backup->close();
        chmod($backupPath, 0600);
        echo "Local database backed up to {$backupPath}\n";
    } else {
        $local = new SQLite3($localPath);
        $local->enableExceptions(true);
        $local->busyTimeout(10000);
    }
    if (!$snapshot->backup($local)) {
        throw new RuntimeException('Could not restore the downloaded database locally.');
    }
    $local->close();
    $snapshot->close();
    chmod($localPath, 0600);
    echo "Local database and content updated from production.\n";
} finally {
    unlink($snapshotPath);
}
