#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this script from the command line.');
}

require_once dirname(__DIR__) . '/app/Database.php';

$db = Database::connection();
$db->beginTransaction();
try {
    $count = (int)$db->query('SELECT COUNT(*) FROM pages')->fetchColumn();
    if ($count === 0) {
        $insert = $db->prepare(
            'INSERT INTO pages (id,parent_id,slug,title,nav_label,show_in_nav,sort_order,created_at,updated_at)
             VALUES (?,NULL,?,?,?,?,?,?,?)'
        );
        $now = time();
        $insert->execute(['Home', '', 'Home', 'Home', 0, 0, $now, $now]);
        $insert->execute(['archive', 'archive', 'Archive', 'Archive', 1, 90, $now, $now]);
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $error;
}

fwrite(STDOUT, $count === 0
    ? "Home and Archive created. Create an administrator with bin/create-admin.php.\n"
    : "Pages already exist; no initialization changes made.\n");
