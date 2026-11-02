<?php
$assetUrl = static function (string $filename): string {
    $version = hash_file('sha256', dirname(__DIR__) . '/assets/' . $filename);
    if ($version === false) {
        throw new RuntimeException('Unable to version asset: ' . $filename);
    }
    return '/assets/' . $filename . '?v=' . $version;
};
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
    <link rel="stylesheet" href="<?= h($assetUrl('site.css')) ?>">
    <?php if ($canonicalUrl !== null): ?><link rel="canonical" href="<?= h($canonicalUrl) ?>"><?php endif; ?>
</head>
<body>
<?= $navigation ?>
<div class="site-wrap" id="main"><?= $content ?></div>
<?= $dialogs ?>
<script src="<?= h($assetUrl('navigation.js')) ?>" defer></script>
<script src="<?= h($assetUrl('pool-navigation.js')) ?>" defer></script>
<script src="<?= h($assetUrl('header-parallax.js')) ?>" defer></script>
<script src="<?= h($assetUrl('footnotes.js')) ?>" defer></script>
<script src="<?= h($assetUrl('diagrams.js')) ?>" defer></script>
<script src="<?= h($assetUrl('syntax.js')) ?>" defer></script>
<?php if ($isAdmin): ?><script src="<?= h($assetUrl('pool-editor.js')) ?>" defer></script><?php endif; ?>
<?= Template::render('partials/footer', ['isAdmin' => $isAdmin, 'currentUrl' => $currentUrl]) ?>
</body>
</html>
