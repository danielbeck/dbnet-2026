<?= '<?xml version="1.0" encoding="UTF-8"?>' ?>
<rss version="2.0">
    <channel>
        <title>Danielbeck.net</title>
        <link><?= Content::escape($siteUrl) ?></link>
        <description>Daniel Beck’s site</description>
        <?php foreach ($entries as $entry): ?>
            <item>
                <title><?= Content::escape($entry['item']['title']) ?></title>
                <link><?= Content::escape($entry['url']) ?></link>
                <guid><?= Content::escape($entry['url']) ?></guid>
                <pubDate><?= Content::escape($entry['date']) ?></pubDate>
                <description><![CDATA[<?= str_replace(']]>', ']]]]><![CDATA[>', $entry['item']['body']) ?>]]></description>
            </item>
        <?php endforeach; ?>
    </channel>
</rss>
