<?php
$stylesheetVersion = hash_file('sha256', dirname(__DIR__) . '/assets/site.css');
if ($stylesheetVersion === false) {
    throw new RuntimeException('Unable to version site stylesheet.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h(Content::titleText($pageTitle)) ?> | Danielbeck.net</title>
    <link rel="icon" href="/assets/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon/favicon-32x32.png">
    <link rel="apple-touch-icon" href="/assets/favicon/apple-touch-icon.png">
    <link rel="manifest" href="/assets/favicon/site.webmanifest">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Asap:400,700,400italic,700italic|Alegreya+Sans:400,700">
    <link rel="stylesheet" href="/assets/site.css?v=<?= h($stylesheetVersion) ?>">
    <?php if ($canonicalUrl !== null): ?><link rel="canonical" href="<?= h($canonicalUrl) ?>"><?php endif; ?>
</head>
<body>
<?= $navigation ?>
<div class="site-wrap" id="main"><?= $content ?></div>
<?= $dialogs ?>
<script src="/assets/pool-navigation.js" defer></script>
<script src="/assets/header-parallax.js" defer></script>
<script src="/assets/footnotes.js" defer></script>
<script src="/assets/diagrams.js" defer></script>
<script src="/assets/syntax.js" defer></script>
<?php if ($isAdmin): ?><script src="/assets/pool-editor.js" defer></script><?php endif; ?>
<?= Template::render('partials/footer', ['isAdmin' => $isAdmin, 'currentUrl' => $currentUrl]) ?>
</body>
</html>
