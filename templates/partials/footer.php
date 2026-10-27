<footer class="site-footer">
    <p class="feed-links">
        <a href="/rss.xml"><img src="/assets/rss.gif" alt="RSS feed"></a>
        <a href="/rss-photos.xml"><img src="/assets/rss_images.gif" alt="Photos RSS feed"></a>
        <a href="/rss-blog.xml"><img src="/assets/rss_blog.gif" alt="Blog RSS feed"></a>
        <a href="/rss-geek.xml"><img src="/assets/rss_geek.gif" alt="Tech RSS feed"></a>
    </p>
    <p>Text and photos containing people © <?= date('Y') ?> Daniel Beck. Other photos CC BY-NC-SA 4.0.</p>
    <p class="site-logo"><img src="/assets/db.svg" alt="Daniel Beck logo"></p>
    <?php if ($isAdmin): ?><p><a href="/admin/">Manage site</a></p><?php else: ?><p><a href="/admin/login">Admin</a></p><?php endif; ?>
</footer>
