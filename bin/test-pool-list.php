<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Content.php';

$db = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('CREATE TABLE pool_items (
    id TEXT PRIMARY KEY, slug TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL,
    format TEXT NOT NULL, item_type TEXT NOT NULL, date INTEGER NOT NULL,
    tags TEXT NOT NULL, image TEXT NOT NULL, fields TEXT NOT NULL
)');
$insert = $db->prepare('INSERT INTO pool_items VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 15; $i++) {
    $insert->execute([(string)$i, 'item-' . $i, 'Item ' . $i, '', 'html', 'article', 1600000000000 - $i * 1000, '["geek"]', '', '{}']);
}
$insert->execute(['other', 'other', 'Other', '', 'html', 'article', 1600000000001, '["other"]', '', '{}']);

$itemsIn = static function (string $html): array {
    preg_match_all('~href="/archive/(item-\d+)\.html\?list=geek"~', $html, $matches);
    return $matches[1];
};
$expectedItems = static fn(int $start, int $end): array => array_map(
    static fn(int $i): string => 'item-' . $i,
    range($start, $end)
);
$cases = [
    ['10', 'desc', $expectedItems(1, 10)],
    ['11,*', 'desc', $expectedItems(11, 15)],
    ['[11,*]', 'desc', $expectedItems(11, 15)],
    ['3, 5', 'desc', $expectedItems(3, 5)],
    ['[3, 5]', 'desc', $expectedItems(3, 5)],
    ['11,*', 'asc', $expectedItems(5, 1)],
    ['16,*', 'desc', []],
    ['[11,*', 'desc', $expectedItems(1, 15)],
    ['11,*]', 'desc', $expectedItems(1, 15)],
];
foreach ($cases as [$limit, $sort, $expected]) {
    $html = Content::renderBody($db, '<pool-list tag="geek" limit="' . $limit . '" sort="' . $sort . '"></pool-list>', 'html');
    $actual = $itemsIn($html);
    if ($actual !== $expected) {
        throw new RuntimeException("Unexpected items for limit={$limit}, sort={$sort}: " . json_encode($actual));
    }
}

$body = '<pool-list tag="geek" limit="10" type="summary"></pool-list>'
    . '<h2>The rest of this is probably already obsolete</h2>'
    . '<pool-list tag="geek" limit="11,*"></pool-list>';
foreach (['html', 'markdown'] as $format) {
    $html = Content::renderBody($db, $body, $format);
    $parts = explode('<h2>The rest of this is probably already obsolete</h2>', $html);
    if (count($parts) !== 2 || $itemsIn($parts[0]) !== $expectedItems(1, 10) || $itemsIn($parts[1]) !== $expectedItems(11, 15)) {
        throw new RuntimeException("Unexpected split list for {$format}.");
    }
}
echo "Pool-list limit tests passed.\n";
