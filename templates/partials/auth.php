<div class="auth-controls">
    <?php if ($isAdmin): ?>
        <a href="/admin/">Manage site</a>
        <form method="post" action="/admin/"><input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="logout"><input type="hidden" name="return_to" value="<?= h($currentUrl) ?>"><button>Log out</button></form>
    <?php else: ?>
        <a href="/admin/login?return_to=<?= rawurlencode($currentUrl) ?>">Admin</a>
    <?php endif; ?>
</div>
