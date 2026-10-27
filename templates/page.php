<main><h1><?= Content::escape($page['title']) ?></h1><?php if ($body !== ''): ?><div class="content-body"><?= $body ?></div><?php endif; ?><?= $defaultList ?></main>
