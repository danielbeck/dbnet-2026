<?php if ($items): ?>
<section class="related-items">
    <h2>Related</h2>
    <ul>
        <?php foreach ($items as $item): ?>
        <li><a href="/archive/<?= rawurlencode($item['slug']) ?>.html"><?= Content::titleHtml($item['title']) ?></a></li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
