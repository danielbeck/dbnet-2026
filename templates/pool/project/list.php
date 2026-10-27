<article class="pool-entry">
    <time><?= Content::escape($date) ?></time>
    <div>
        <a href="<?= Content::escape($href) ?>">
            <?= Content::titleHtml($item['title']) ?>
        </a>
    </div>
</article>
