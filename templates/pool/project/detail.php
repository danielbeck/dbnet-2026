<main class="pool-item project-detail">
    <h1><?= Content::titleHtml($item['title']) ?></h1>
    <p class="date"><?= Content::escape($date) ?></p>
    <?= Template::render('pool/navigation', ['previousUrl' => $previousUrl, 'nextUrl' => $nextUrl]) ?>
    <?php if (($item['fields']['url'] ?? '') !== '' || ($item['fields']['github_url'] ?? '') !== ''): ?>
        <div class="project-actions">
            <?php foreach (['url' => 'Visit project', 'github_url' => 'View on GitHub'] as $name => $label):
                $url = trim((string)($item['fields'][$name] ?? ''));
                if ($url === '') continue;
                $isWebUrl = filter_var($url, FILTER_VALIDATE_URL) !== false && preg_match('~^https?://~i', $url);
            ?>
                <?php if ($isWebUrl): ?>
                    <a class="<?= $name === 'url' ? 'project-visit' : 'project-source' ?>" href="<?= Content::escape($url) ?>"><?= $label ?></a>
                <?php else: ?>
                    <p class="project-link-invalid"><?= Content::escape($fields[$name]['label']) ?>: <?= Content::escape($url) ?> (invalid web URL)</p>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($body !== ''): ?><div class="content-body"><?= $body ?></div><?php endif; ?>
    <?php if ($image !== ''): ?><figure class="project-preview"><img src="<?= Content::escape($image) ?>" alt="<?= Content::escape(Content::titleText($item['title'])) ?>" loading="lazy"></figure><?php endif; ?>
    <?php $extraFields = array_diff_key($fields, array_flip(['url', 'github_url'])); ?>
    <?php if (array_filter($extraFields, static fn(array $definition, string $name): bool => ($item['fields'][$name] ?? '') !== '', ARRAY_FILTER_USE_BOTH)): ?>
        <dl class="item-fields">
            <?php foreach ($extraFields as $name => $definition): if (($item['fields'][$name] ?? '') === '') continue; ?>
                <dt><?= Content::escape($definition['label']) ?></dt><dd><?= Content::escape((string)$item['fields'][$name]) ?></dd>
            <?php endforeach; ?>
        </dl>
    <?php endif; ?>
</main>
