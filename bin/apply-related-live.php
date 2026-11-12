<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || $argc !== 2) {
    throw new RuntimeException('Run from the command line with a database path.');
}
$path = $argv[1];
if (!is_file($path)) {
    throw new RuntimeException('Live database does not exist.');
}
$input = stream_get_contents(STDIN);
if ($input === false) {
    throw new RuntimeException('Could not read the related-link snapshot.');
}
$payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload) || ($payload['published'] ?? null) !== false
    || !isset($payload['snapshot'], $payload['proposals'], $payload['posts'], $payload['links'])
    || !is_array($payload['snapshot']) || !array_is_list($payload['snapshot'])
    || count($payload['snapshot']) === 0 || !is_array($payload['proposals'])
    || !array_is_list($payload['proposals']) || !is_int($payload['posts']) || !is_int($payload['links'])
    || $payload['posts'] !== count($payload['snapshot'])
    || $payload['links'] !== count($payload['proposals'])) {
    throw new RuntimeException('Invalid related-link snapshot.');
}

$db = new PDO('sqlite:' . $path, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 10000');
$db->exec('BEGIN IMMEDIATE');
try {
    $current = [];
    $hashes = [];
    foreach ($db->query('SELECT id, title, body, format, date FROM pool_items ORDER BY id') as $post) {
        $hash = hash('sha256', $post['title'] . "\0" . $post['body'] . "\0" . $post['format']);
        $current[] = ['id' => $post['id'], 'hash' => $hash, 'date' => (int) $post['date']];
        $hashes[$post['id']] = $hash;
    }
    if ($current !== $payload['snapshot']) {
        throw new RuntimeException('Live posts differ from the local snapshot; sync and regenerate before publishing.');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS related_items (
        source_id TEXT NOT NULL REFERENCES pool_items(id) ON DELETE CASCADE,
        target_id TEXT NOT NULL REFERENCES pool_items(id) ON DELETE CASCADE,
        rank INTEGER NOT NULL CHECK(rank > 0),
        similarity REAL NOT NULL,
        source_hash TEXT NOT NULL,
        target_hash TEXT NOT NULL,
        PRIMARY KEY (source_id, target_id),
        UNIQUE (source_id, rank),
        CHECK(source_id != target_id)
    )');
    $db->exec('DELETE FROM related_items');
    $insert = $db->prepare('INSERT INTO related_items
        (source_id, target_id, rank, similarity, source_hash, target_hash) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($payload['proposals'] as $link) {
        if (!is_array($link) || !isset($link['source_id'], $link['target_id'], $link['rank'], $link['similarity'])
            || !is_string($link['source_id']) || !is_string($link['target_id'])
            || !isset($hashes[$link['source_id']], $hashes[$link['target_id']])
            || !is_int($link['rank']) || $link['rank'] < 1
            || (!is_int($link['similarity']) && !is_float($link['similarity']))
            || !is_finite((float) $link['similarity'])
            || $link['similarity'] < -1 || $link['similarity'] > 1) {
            throw new RuntimeException('Invalid proposed related link.');
        }
        $insert->execute([$link['source_id'], $link['target_id'], $link['rank'], $link['similarity'],
            $hashes[$link['source_id']], $hashes[$link['target_id']]]);
    }
    $db->exec('COMMIT');
} catch (Throwable $error) {
    $db->exec('ROLLBACK');
    throw $error;
}
echo 'Published ' . count($payload['proposals']) . " related links to the live database.\n";
