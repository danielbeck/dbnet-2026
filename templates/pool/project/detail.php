<main class="pool-item">
    <h1><?= Content::escape($item['title']) ?></h1>
    <p class="date"><?= Content::escape($date) ?></p>
    <?= Template::render('pool/navigation', ['previousUrl' => $previousUrl, 'nextUrl' => $nextUrl]) ?>
    <?php if ($body !== ''): ?><div class="content-body"><?= $body ?></div><?php endif; ?>
    <?php if ($image !== ''): ?><figure class="pageimage"><img src="<?= Content::escape($image) ?>" alt="<?= Content::escape($item['title']) ?>"></figure><?php endif; ?>
    <?php if ($item['fields']): ?>
        <dl class="item-fields">
            <?php foreach ($fields as $name => $definition): if (($item['fields'][$name] ?? '') === '') continue; ?>
                <dt><?= Content::escape($definition['label']) ?></dt><dd><?= Content::escape((string)$item['fields'][$name]) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</main>
