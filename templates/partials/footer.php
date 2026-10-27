<footer class="site-footer">
    <div class="fineprint">
    <p class="feed-links">
        <span>Now meeting all your syndication needs:</span>
        <a href="/rss.xml"><img src="/assets/rss.gif" alt="RSS feed"></a>
        <a href="/rss-photos.xml"><img src="/assets/rss_images.gif" alt="Photos RSS feed"></a>
        <a href="/rss-blog.xml"><img src="/assets/rss_blog.gif" alt="Blog RSS feed"></a>
        <a href="/rss-geek.xml"><img src="/assets/rss_geek.gif" alt="Tech RSS feed"></a>
    </p>
    <hr>
    <p>Text and photos containing people © <?= date('Y') ?> Daniel Beck. Other photos CC BY-NC-SA 4.0.</p>
    <?= Template::render('partials/auth', ['isAdmin' => $isAdmin, 'currentUrl' => $currentUrl]) ?>
    </div>
    <p class="site-logo"><img src="/assets/db.svg" alt="Daniel Beck logo"></p>
</footer>
