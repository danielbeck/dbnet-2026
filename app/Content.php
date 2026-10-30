<?php
declare(strict_types=1);

use League\CommonMark\GithubFlavoredMarkdownConverter;

require_once __DIR__ . '/Template.php';
require_once __DIR__ . '/Types.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

final class Content
{
    public static function pagePath(PDO $db, array $page): string
    {
        if (!empty($page['route_path'])) {
            return (string)$page['route_path'];
        }
        $segments = [];
        $seen = [];
        $cursor = $page;
        while ($cursor) {
            if (isset($seen[$cursor['id']])) {
                throw new RuntimeException('A circular page hierarchy was detected.');
            }
            $seen[$cursor['id']] = true;
            if ($cursor['slug'] !== '') {
                array_unshift($segments, $cursor['slug']);
            }
            $cursor = $cursor['parent_id'] ? self::pageById($db, $cursor['parent_id']) : null;
        }
        return '/' . ($segments ? implode('/', $segments) . '/' : '');
    }

    public static function pageAliases(PDO $db, string $pageId): array
    {
        $statement = $db->prepare('SELECT path, filter_tag FROM page_aliases WHERE page_id = ? ORDER BY length(path) DESC');
        $statement->execute([$pageId]);
        return $statement->fetchAll();
    }

    public static function poolListFilterForItem(PDO $db, array $page, string $routePath, array $item): ?array
    {
        preg_match_all('/<pool[-_]list\b([^>]*)/is', (string)$page['body'], $matches);
        foreach ($matches[1] as $attributeText) {
            $attrs = self::parseAttributes($attributeText);
            $prefix = (string)($attrs['path'] ?? '');
            if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_starts_with($prefix, '//'))) {
                continue;
            }
            if ($prefix === '') {
                $prefix = $page['id'] === 'Home' ? '/archive/' : self::pagePath($db, $page);
            } else {
                $prefix = '/' . trim($prefix, '/') . (trim($prefix, '/') === '' ? '' : '/');
            }
            if ($prefix !== $routePath) {
                continue;
            }
            $tags = array_values(array_filter(array_map('trim', explode(',', (string)($attrs['tag'] ?? '')))));
            if ($tags && array_intersect($tags, $item['tags'])) {
                return ['tags' => $tags];
            }
            if (!empty($attrs['page'])) {
                $listPage = self::pageById($db, (string)$attrs['page']);
                if ($listPage && self::containsItem(self::itemsForPage($db, $listPage), $item['id'])) {
                    return ['page_id' => $listPage['id']];
                }
            }
        }
        return null;
    }

    public static function pageById(PDO $db, string $id): ?array
    {
        $statement = $db->prepare('SELECT * FROM pages WHERE id = ?');
        $statement->execute([$id]);
        return $statement->fetch() ?: null;
    }

    public static function poolItem(PDO $db, string $id): ?array
    {
        $statement = $db->prepare('SELECT * FROM pool_items WHERE id = ?');
        $statement->execute([$id]);
        $item = $statement->fetch();
        return $item ? self::hydratePoolItem($item) : null;
    }

    public static function hydratePoolItem(array $item): array
    {
        $item['tags'] = json_decode($item['tags'] ?: '[]', true) ?: [];
        $item['fields'] = json_decode($item['fields'] ?: '{}', true) ?: [];
        return $item;
    }

    public static function children(PDO $db, ?string $parentId, bool $navOnly = false): array
    {
        $sql = 'SELECT * FROM pages WHERE parent_id IS ' . ($parentId === null ? 'NULL' : ':parent');
        if ($parentId !== null) {
            $sql = 'SELECT * FROM pages WHERE parent_id = :parent';
        }
        if ($navOnly) {
            $sql .= ' AND show_in_nav = 1';
        }
        $sql .= ' ORDER BY sort_order, title COLLATE NOCASE';
        $statement = $db->prepare($sql);
        if ($parentId !== null) {
            $statement->bindValue(':parent', $parentId);
        }
        $statement->execute();
        return $statement->fetchAll();
    }

    public static function itemsForPage(PDO $db, array $page): array
    {
        if ($page['id'] === 'archive') {
            return self::itemsByTags($db, []);
        }
        if ($page['tag'] !== '') {
            return self::itemsByTags($db, [$page['tag']]);
        }
        return [];
    }

    public static function itemsByTags(PDO $db, array $tags): array
    {
        $items = $db->query('SELECT * FROM pool_items ORDER BY date DESC, id DESC')->fetchAll();
        $tags = array_filter(array_map('trim', $tags));
        return array_values(array_map(
            [self::class, 'hydratePoolItem'],
            array_filter($items, static function (array $item) use ($tags): bool {
                if (!$tags) {
                    return true;
                }
                $itemTags = json_decode($item['tags'] ?: '[]', true) ?: [];
                return (bool)array_intersect($tags, $itemTags);
            })
        ));
    }

    public static function renderBody(PDO $db, string $body, string $format, ?array $contextPage = null): string
    {
        $codeBlocks = [];
        if ($format === 'markdown') {
            $body = str_replace(["\r\n", "\r"], "\n", $body);
            $codePrefix = 'DBNETFENCEDCODE';
            while (str_contains($body, $codePrefix)) {
                $codePrefix .= 'X';
            }
            $body = preg_replace_callback(
                '~^ {0,3}(`{3,}|\~{3,})[ \t]*([a-z0-9_+#.-]*)[ \t]*\n(.*?)(?:\n {0,3}\1[ \t]*(?=\n|$)|\z)~ims',
                static function (array $match) use (&$codeBlocks, $codePrefix): string {
                    $token = $codePrefix . count($codeBlocks) . 'END';
                    $language = strtolower($match[2] !== '' ? $match[2] : 'text');
                    $codeBlocks[$token] = rtrim(Template::render(
                        $language === 'mermaid' ? 'content/mermaid' : 'content/code',
                        ['language' => $language, 'code' => $match[3]]
                    ));
                    return "\n\n" . $token . "\n\n";
                },
                $body
            ) ?? $body;
            $specialTags = [];
            $body = preg_replace_callback(
                '/<footnote\b[^>]*>.*?<\/footnote\s*>|<pool[-_]list\b[^>]*>(?:\s*<\/pool[-_]list\s*>)?|<(?:highlightjs|router-link|nuxt-link)\b[^>]*>.*?<\/(?:highlightjs|router-link|nuxt-link)\s*>/is',
                static function (array $match) use (&$specialTags): string {
                    $token = 'DBNETSPECIAL' . count($specialTags) . 'TOKEN';
                    $specialTags[$token] = $match[0];
                    return $token;
                },
                $body
            ) ?? $body;
            $body = self::markdown($body);
            $body = strtr($body, $specialTags);
        }
        static $footnoteGroup = 0;
        $footnotePrefix = $footnoteGroup++ === 0 ? '' : 'group-' . $footnoteGroup . '-';
        $notes = [];
        $body = preg_replace_callback(
            '/<footnote\b[^>]*>(.*?)<\/footnote\s*>/is',
            static function (array $match) use (&$notes, $footnotePrefix): string {
                $number = count($notes) + 1;
                $notes[$number] = $match[1];
                return rtrim(Template::render('content/footnote-reference', ['number' => $number, 'prefix' => $footnotePrefix]));
            },
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/<pool[-_]list\b([^>]*)>(?:\s*<\/pool[-_]list\s*>)?/is',
            static function (array $match) use ($db, $contextPage): string {
                return self::renderPoolList($db, $match[1], $contextPage);
            },
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/<highlightjs\b([^>]*)>(.*?)<\/highlightjs\s*>/is',
            static function (array $match): string {
                $attrs = self::parseAttributes($match[1]);
                $language = strtolower((string)($attrs['language'] ?? $attrs['lang'] ?? 'auto'));
                if (!preg_match('/^[a-z0-9_+#.-]{1,30}$/', $language)) {
                    $language = 'text';
                }
                $code = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                return rtrim(Template::render(
                    $language === 'mermaid' ? 'content/mermaid' : 'content/code',
                    compact('language', 'code')
                ));
            },
            $body
        ) ?? $body;

        $body = preg_replace_callback(
            '/<(?:router-link|nuxt-link)\b([^>]*)>(.*?)<\/(?:router-link|nuxt-link)\s*>/is',
            static function (array $match): string {
                $attrs = self::parseAttributes($match[1]);
                $target = html_entity_decode((string)($attrs['to'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($target === '' || str_starts_with($target, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $target)) {
                    return $match[2];
                }
                return rtrim(Template::render('content/link', ['target' => $target, 'label' => $match[2]]));
            },
            $body
        ) ?? $body;

        if ($notes) {
            $body .= Template::render('content/footnotes', ['notes' => $notes, 'prefix' => $footnotePrefix]);
        }
        foreach ($codeBlocks as $token => $code) {
            $body = str_replace('<p>' . $token . '</p>', $code, $body);
        }
        return strtr($body, $codeBlocks);
    }

    public static function renderPoolList(PDO $db, string $attributes, ?array $contextPage = null): string
    {
        $attrs = self::parseAttributes($attributes);
        $linkContext = $contextPage;
        if (!empty($attrs['page'])) {
            $page = self::pageById($db, (string)$attrs['page']);
            $items = $page ? self::itemsForPage($db, $page) : [];
            $linkContext = $page;
        } elseif (!empty($attrs['tag'])) {
            $items = self::itemsByTags($db, explode(',', (string)$attrs['tag']));
        } elseif ($contextPage) {
            $items = self::itemsForPage($db, $contextPage);
        } else {
            $items = self::itemsByTags($db, []);
        }
        $sort = strtolower((string)($attrs['sort'] ?? 'desc'));
        usort($items, static fn(array $a, array $b): int => $sort === 'asc'
            ? ((int)$a['date'] <=> (int)$b['date'])
            : ((int)$b['date'] <=> (int)$a['date']));
        if (isset($attrs['limit']) && preg_match('/^\d+$/', (string)$attrs['limit'])) {
            $items = array_slice($items, 0, (int)$attrs['limit']);
        } elseif (isset($attrs['limit']) && preg_match('/^\[(\d+),\s*(\d+|\*)\]$/', (string)$attrs['limit'], $range)) {
            $start = max(0, (int)$range[1] - 1);
            $items = array_slice($items, $start, $range[2] === '*' ? null : max(0, (int)$range[2] - $start));
        }

        $type = strtolower((string)($attrs['type'] ?? 'list'));
        $prefix = (string)($attrs['path'] ?? '');
        if ($prefix !== '' && (!str_starts_with($prefix, '/') || str_starts_with($prefix, '//'))) {
            $prefix = '';
        }
        $knownView = false;
        foreach (array_keys(Types::all()) as $itemType) {
            if ($type !== 'detail' && isset(Types::templates($itemType)[$type])) {
                $knownView = true;
                break;
            }
        }
        if (!$knownView) {
            throw new InvalidArgumentException('Unknown pool-list template: ' . $type);
        }
        $html = '';
        foreach ($items as $item) {
            $itemPrefix = $prefix !== '' ? rtrim($prefix, '/') . '/' : (
                $linkContext && !in_array($linkContext['id'], ['Home', 'archive'], true)
                    ? self::pagePath($db, $linkContext)
                    : '/archive/'
            );
            $href = $itemPrefix . rawurlencode($item['slug']) . '.html';
            $filterTags = array_values(array_filter(array_map('trim', explode(',', (string)($attrs['tag'] ?? '')))));
            if ($filterTags && (count($filterTags) !== 1 || !$linkContext || (string)$linkContext['tag'] !== $filterTags[0])) {
                $href .= '?list=' . rawurlencode(implode(',', $filterTags));
            } elseif (!empty($attrs['page']) && (!$linkContext || $linkContext['id'] !== $attrs['page'])) {
                $href .= '?list_page=' . rawurlencode((string)$attrs['page']);
            }
            $date = $item['date'] ? date('F j, Y', (int)floor((int)$item['date'] / 1000)) : '';
            $image = self::imageUrl($item['image']);
            $thumbnail = preg_replace('/_image(\.[a-z0-9]+)$/i', '_thumbnail$1', $image) ?: $image;
            $summary = self::summary((string)$item['body'], (string)$item['format'], 150);
            $html .= Template::render(Types::template($item['item_type'], $type), [
                'item' => $item, 'href' => $href, 'date' => $date, 'image' => $image,
                'thumbnail' => $thumbnail, 'summary' => $summary, 'view' => $type,
                'contextPage' => $linkContext, 'attributes' => $attrs,
            ]);
        }
        return Template::render('pool/container', ['view' => $type, 'entries' => $html]);
    }

    public static function imageUrl(string $image): string
    {
        if ($image === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }
        if (str_starts_with($image, '/content/')) {
            return $image;
        }
        return '/content/pool/' . rawurlencode(basename($image));
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function titleHtml(string $title): string
    {
        $parts = preg_split('~(<[^>]*>)~', strip_tags($title, '<em><strong><b><i><s><strike><del><sub><sup><code><br>'), -1, PREG_SPLIT_DELIM_CAPTURE);
        return implode('', array_map(static function (string $part): string {
            if (preg_match('~^<(/?)(em|strong|b|i|s|strike|del|sub|sup|code|br)\b[^>]*>$~i', $part, $match)) {
                return '<' . $match[1] . strtolower($match[2]) . '>';
            }
            return self::escape(html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }, $parts));
    }

    public static function titleText(string $title): string
    {
        return html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function parseAttributes(string $input): array
    {
        preg_match_all('/([:@]?[a-z][a-z0-9_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $input, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $match) {
            $name = strtolower(ltrim($match[1], ':@'));
            $attrs[$name] = $match[2] !== '' ? $match[2] : ($match[3] !== '' ? $match[3] : $match[4]);
        }
        return $attrs;
    }

    public static function containsItem(array $items, string $itemId): bool
    {
        foreach ($items as $item) {
            if ((string)$item['id'] === $itemId) {
                return true;
            }
        }
        return false;
    }

    private static function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length)) . '…';
    }

    private static function summary(string $body, string $format, int $length): string
    {
        $html = $format === 'markdown' ? self::markdown($body) : $body;
        if (preg_match_all('~<p\b[^>]*>(.*?)</p\s*>~is', $html, $paragraphs)) {
            foreach ($paragraphs[1] as $paragraph) {
                $text = html_entity_decode(strip_tags($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (trim($text) !== '') {
                    return self::truncate($text, $length);
                }
            }
            return '';
        }
        if ($format === 'markdown') {
            return '';
        }

        $body = preg_replace(
            '~<(table|pre|ul|ol|dl|figure|iframe|script|style|svg)\b[^>]*>.*?</\1\s*>~is',
            ' ',
            $body
        ) ?? $body;
        return self::truncate(
            html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $length
        );
    }

    private static function markdown(string $source): string
    {
        static $converter;
        $converter ??= new GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
        return $converter->convert($source)->getContent();
    }
}
