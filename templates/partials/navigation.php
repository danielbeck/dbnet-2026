<nav id="mainmenu" aria-label="Main navigation">
    <a id="skipnav" href="#main">Skip navigation</a>
    <ul class="home"><li><a href="/"<?= $activePath === '/' ? ' aria-current="page"' : '' ?>>Home</a></li></ul>
    <?php foreach ($rows as $index => $links): ?>
        <ul<?= $index > 0 ? ' class="subnav"' : '' ?>>
            <?php foreach ($links as $link): ?>
                <li<?= $link['active'] ? ' class="active"' : '' ?>><a href="<?= h($link['path']) ?>"><?= h($link['label']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach; ?>
</nav>
