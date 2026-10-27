        <form class="pool-editor" method="post" action="/admin/" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= $escape(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_pool">
            <input type="hidden" name="id" value="<?= $escape($item['id'] ?? '') ?>">
            <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
            <input type="hidden" name="context_page" value="<?= $escape($context['id'] ?? '') ?>">
            <input type="hidden" name="delete_return_to" value="<?= $escape($deleteReturnTo) ?>">
            <?php if ($item): ?><label>ID <input value="<?= $escape($item['id']) ?>" disabled></label><?php endif; ?>
            <label>Title <input name="title" value="<?= $escape($values['title']) ?>" required></label>
            <label>Slug <input name="slug" value="<?= $escape($values['slug']) ?>" required></label>
            <label>Date <input name="date" type="datetime-local" value="<?= $escape($dateValue) ?>" required></label>
            <label>Item type <select name="item_type"><?php foreach ($types as $key => $definition): ?><option value="<?= $escape($key) ?>" <?= $values['item_type'] === $key ? 'selected' : '' ?>><?= $escape($definition['label']) ?></option><?php endforeach; ?></select></label>
            <?php foreach ($types as $key => $definition): if (!$definition['fields']) continue; ?>
                <fieldset data-type-field="<?= $escape($key) ?>"><legend><?= $escape($definition['label']) ?></legend>
                <?php foreach ($definition['fields'] as $name => $field): ?>
                    <label><?= $escape($field['label']) ?><input type="<?= $escape($field['type']) ?>" name="type_fields[<?= $escape($key) ?>][<?= $escape($name) ?>]" value="<?= $escape((string)($values['fields'][$name] ?? '')) ?>"></label>
                <?php endforeach; ?></fieldset>
            <?php endforeach; ?>
            <label>Format <select name="format"><option value="markdown" <?= $values['format'] === 'markdown' ? 'selected' : '' ?>>Markdown</option><option value="html" <?= $values['format'] === 'html' ? 'selected' : '' ?>>HTML</option></select></label>
            <label>Body <textarea name="body" rows="18"><?= $escape($values['body']) ?></textarea></label>
            <button type="button" data-fix-html hidden>Fix HTML</button>
            <fieldset class="pool-tags"><legend>Tags</legend>
                <div data-tags><?php foreach ($values['tags'] ?: [''] as $tag): ?>
                    <label class="tag-row"><span>Tag</span><input name="tags[]" list="pool-tags" value="<?= $escape($tag) ?>"><button type="button" data-remove-tag hidden aria-label="Remove tag">Remove</button></label>
                <?php endforeach; ?></div>
                <button type="button" data-add-tag hidden>Add tag</button>
                <datalist id="pool-tags"><?php foreach ($allTags as $tag): ?><option value="<?= $escape($tag) ?>"><?php endforeach; ?></datalist>
                <details><summary>Tags in use</summary><p><?= $escape(implode(', ', $allTags)) ?></p></details>
            </fieldset>
            <label>Image <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp"></label>
            <div class="pool-previews">
                <img data-image-preview class="admin-thumb" <?= $values['image'] !== '' ? 'src="' . $escape(Content::imageUrl($values['image'])) . '"' : 'hidden' ?> alt="Image preview">
                <img data-thumbnail-preview <?= $values['image'] !== '' ? 'src="' . $escape(Content::imageUrl($values['image'])) . '"' : 'hidden' ?> alt="Thumbnail preview" width="124" height="124">
            </div>
            <label class="check"><input type="checkbox" name="remove_image" value="1" <?= isset($draft['remove_image']) ? 'checked' : '' ?>> Remove image</label>
            <p class="hint">Page tag filters determine where this item is listed. No separate page assignment is needed.</p>
            <p class="hint">Title and body text receive smart quotes on save. Code blocks are left unchanged.</p>
            <div class="pool-actions"><button>Save</button>
                <?php if ($item): ?><button class="danger" name="action" value="delete_pool" data-delete-pool formnovalidate>Delete</button><?php endif; ?>
                <a class="button secondary" href="<?= $escape($returnTo) ?>" data-pool-cancel>Cancel</a>
            </div>
        </form>
        <?php
