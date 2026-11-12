#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    throw new RuntimeException('Usage: php publish-related-live.php DEPLOY_TARGET REMOTE_PHP SNAPSHOT');
}
[$script, $remote, $remotePhp, $snapshotPath] = $argv;
if (!preg_match('/^([a-zA-Z0-9_.@-]+):(.+)$/D', $remote, $match) || str_starts_with($remote, '-')) {
    throw new InvalidArgumentException('DEPLOY_TARGET must be an SSH destination such as user@host:directory.');
}
if (!is_file($snapshotPath) || !is_readable($snapshotPath)) {
    throw new RuntimeException('Related-link snapshot is not readable.');
}
$code = file_get_contents(__DIR__ . '/apply-related-live.php');
if ($code === false || !str_starts_with($code, '<?php')) {
    throw new RuntimeException('Could not read the live publisher.');
}
$code = substr($code, 5);
$path = rtrim($match[2], '/') . '/storage/dbnet.sqlite';
$candidates = $remotePhp !== '' ? [$remotePhp] : ['php', 'php8.4-cli', 'php8.3-cli', 'php8.2-cli', 'php8.1-cli'];
$remoteCommand = 'for candidate in ' . implode(' ', array_map('escapeshellarg', $candidates)) . "; do\n"
    . '  if command -v "$candidate" >/dev/null 2>&1 && "$candidate" -v 2>/dev/null | grep -q "(cli)"; then' . "\n"
    . '    exec "$candidate" -r ' . escapeshellarg($code) . ' ' . escapeshellarg($path) . "\n"
    . "  fi\ndone\n"
    . 'echo "No suitable PHP CLI found on the server. Set REMOTE_PHP in .env." >&2' . "\n"
    . "exit 1\n";
$process = proc_open(['ssh', $match[1], $remoteCommand],
    [0 => ['file', $snapshotPath, 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start SSH.');
}
if (proc_close($process) !== 0) {
    throw new RuntimeException('Live publishing failed; check the SSH or remote error above.');
}
