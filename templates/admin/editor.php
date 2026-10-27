<main class="admin-card wide">
    <h1><?= h($pageTitle) ?></h1>
    <?php if ($message): ?><?= Template::render('partials/notice', ['message' => $message]) ?><?php endif; ?>
    <?= $form ?>
</main>
