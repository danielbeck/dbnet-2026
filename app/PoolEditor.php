<?php
declare(strict_types=1);

final class PoolEditor
{
    public static function returnPath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\\\\]/', $path) || str_starts_with($path, '/admin')) {
            return '/';
        }
        return $path;
    }

    public static function smartquotes(string $text): string
    {
        return preg_replace_callback('/(<(?:pre|code|highlightjs)\b[^>]*>.*?<\/(?:pre|code|highlightjs)>|```.*?```|`[^`]*`|<[^>]+>)|([^<`]+|`)/su', static function (array $match): string {
            if ($match[1] !== '') {
                return $match[1];
            }
            $text = str_replace([' -- ', "'''"], [" \u{2014} ", "\u{2034}"], $match[2]);
            $text = preg_replace('/(^|[\s\[{(])"(?=\S)/u', '$1' . "\u{201C}", $text);
            $text = str_replace(['"', "''"], ["\u{201D}", "\u{2033}"], $text);
            $text = preg_replace("/(^|[\\s\\[{(])'(?=\\S)/u", '$1' . "\u{2018}", $text);
            return str_replace("'", "\u{2019}", $text);
        }, $text) ?? $text;
    }

    public static function render(PDO $db, ?array $item, ?array $context, string $returnTo, array $draft = []): string
    {
        $tags = [];
        if (!$item) {
            for ($cursor = $context; $cursor; $cursor = $cursor['parent_id'] ? Content::pageById($db, $cursor['parent_id']) : null) {
                if ($cursor['tag'] !== '') {
                    $tags[] = $cursor['tag'];
                }
            }
        }
        $values = array_replace([
            'title' => '', 'slug' => '', 'body' => '', 'format' => 'markdown',
            'item_type' => 'article', 'date' => (int)(microtime(true) * 1000),
            'tags' => array_values(array_unique($tags)), 'fields' => [], 'image' => '',
        ], $item ?? [], $draft);
        $values['fields'] = $draft['type_fields'][$values['item_type']] ?? $values['fields'];
        $dateValue = is_numeric($values['date'])
            ? date('Y-m-d\TH:i', (int)floor((int)$values['date'] / 1000))
            : $values['date'];
        $allTags = [];
        foreach ($db->query('SELECT tags FROM pool_items')->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $allTags = array_merge($allTags, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        }
        $allTags = array_values(array_unique($allTags));
        natcasesort($allTags);
        $types = Types::all();
        $escape = [Content::class, 'escape'];
        $deleteReturnTo = $context ? Content::pagePath($db, $context) : '/';
        return Template::render('admin/pool-form', [
            'item' => $item,
            'context' => $context,
            'returnTo' => $returnTo,
            'deleteReturnTo' => $deleteReturnTo,
            'draft' => $draft,
            'values' => $values,
            'dateValue' => $dateValue,
            'allTags' => $allTags,
            'types' => $types,
            'escape' => $escape,
        ]);
    }
}
