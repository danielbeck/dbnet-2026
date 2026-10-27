<main class="admin-card wide">
    <div class="admin-heading"><h1>Manage site</h1><form method="post" action="/admin/"><input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out <?= h($username) ?></button></form></div>
    <?php if ($message): ?><?= Template::render('partials/notice', ['message' => $message]) ?><?php endif; ?>
    <section><h2>Pages</h2><p><a class="button" href="/admin/page">Add page</a></p>
        <ul class="manage-list"><?php foreach ($pages as $page): ?><li><a href="/admin/page?id=<?= rawurlencode($page['id']) ?>"><?= h($page['title']) ?></a><span><?= h($page['path']) ?></span><a href="<?= h($page['path']) ?>">View</a></li><?php endforeach; ?></ul>
    </section>
    <section><h2>Pool items</h2><p><a class="button" href="/admin/pool">Add pool item</a></p>
        <ul class="manage-list"><?php foreach ($poolItems as $poolItem): ?><li><a href="/admin/pool?id=<?= rawurlencode($poolItem['id']) ?>"><?= h($poolItem['title']) ?></a><span><?= h(Types::get($poolItem['item_type'])['label']) ?> · <?= h($poolItem['slug']) ?></span><a href="/archive/<?= rawurlencode($poolItem['slug']) ?>.html">View</a></li><?php endforeach; ?></ul>
    </section>
</main>
