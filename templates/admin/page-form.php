        <form method="post" action="/admin/">
            <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_page">
            <input type="hidden" name="id" value="<?= h($id) ?>">
            <label>Title <input name="title" value="<?= h($page['title'] ?? '') ?>" required></label>
            <label>Navigation text <input name="nav_label" value="<?= h($page['nav_label'] ?? '') ?>" placeholder="<?= h($page['title'] ?? 'Defaults to page title') ?>"></label>
            <label>Parent <select name="parent_id" <?= $reserved ? 'disabled' : '' ?>><option value="">— top level —</option><?php foreach ($parentOptions as $option): ?><option value="<?= h($option['id']) ?>"<?= $option['selected'] ? ' selected' : '' ?>><?= h($option['label']) ?></option><?php endforeach; ?></select></label>
            <label>URL segment <input name="slug" value="<?= h($page['slug'] ?? '') ?>" <?= $reserved ? 'readonly' : 'required' ?> placeholder="e.g. travel"></label>
            <label>Pool tag filter <input name="tag" value="<?= h($page['tag'] ?? '') ?>" placeholder="optional"></label>
            <label>Sort order <input name="sort_order" type="number" value="<?= h((string)($page['sort_order'] ?? 0)) ?>"></label>
            <label>Content format <select name="format"><option value="html" <?= ($page['format'] ?? 'html') === 'html' ? 'selected' : '' ?>>HTML</option><option value="markdown" <?= ($page['format'] ?? '') === 'markdown' ? 'selected' : '' ?>>Markdown</option></select></label>
            <label class="check"><input type="checkbox" name="show_in_nav" value="1" <?= !isset($page['show_in_nav']) || (int)$page['show_in_nav'] === 1 ? 'checked' : '' ?>> Show in navigation</label>
            <label>Body <textarea name="body" rows="18"><?= h($page['body'] ?? '') ?></textarea></label>
            <p class="hint">Special tags: <code>&lt;pool-list tag="blog" type="summary" limit="10"&gt;</code> and <code>&lt;footnote&gt;Note text&lt;/footnote&gt;</code>.</p>
            <button>Save page</button> <a class="button secondary" href="<?= h($cancelUrl) ?>" data-page-cancel>Cancel</a>
        </form>
        <?php if ($page && !$reserved): ?>
            <form method="post" action="/admin/" onsubmit="return confirm('Delete this page?')">
                <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete_page"><input type="hidden" name="id" value="<?= h($id) ?>"><button class="danger">Delete page</button>
            </form>
        <?php endif; ?>
        <?php
