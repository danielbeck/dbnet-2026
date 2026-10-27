<article class="project-card">
    <a class="project-card-link" href="<?= Content::escape($href) ?>" aria-label="<?= Content::escape(Content::titleText($item['title'])) ?>">
        <?php if ($image !== ''): ?>
            <img class="project-card-image" src="<?= Content::escape($thumbnail) ?>" alt="" loading="lazy">
        <?php endif; ?>
        <div class="project-card-content">
            <h2 class="project-card-title"><?= Content::titleHtml($item['title']) ?></h2>
            <?php if ($summary !== ''): ?><p class="project-card-summary"><?= Content::escape($summary) ?></p><?php endif; ?>
            <!-- <?php if ($date !== ''): ?><time class="project-card-date"><?= Content::escape($date) ?></time><?php endif; ?> -->
        </div>
    </a>
</article>
