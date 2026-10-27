#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from the command line.\n");
    exit(1);
}
$username = trim($argv[1] ?? '');
if ($username === '') {
    fwrite(STDERR, "Usage: php bin/create-admin.php USERNAME\n");
    exit(1);
}
fwrite(STDOUT, "New password: ");
$password = trim((string)fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password of at least 12 characters.\n");
    exit(1);
}
$db = Database::connection();
$db->prepare(
    'INSERT INTO admins (username,password_hash,created_at) VALUES (?,?,?)
     ON CONFLICT(username) DO UPDATE SET password_hash=excluded.password_hash'
)->execute([$username, password_hash($password, PASSWORD_DEFAULT), time()]);
fwrite(STDOUT, "Administrator account saved.\n");
