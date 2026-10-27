<div class="grid">
    <a class="grid-link<?= $image === '' ? ' grid-no-image' : '' ?>" href="<?= Content::escape($href) ?>" aria-label="<?= Content::escape($item['title']) ?>">
        <?php if ($image !== ''): ?><img src="<?= Content::escape($thumbnail) ?>" alt="" loading="lazy" width="124" height="124"><?php endif; ?>
        <span class="label" aria-hidden="true"><span><?= Content::escape($item['title']) ?></span></span>
    </a>
</div>
