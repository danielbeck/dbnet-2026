<main class="admin-card">
    <h1>Admin sign in</h1>
    <?php if ($adminCount === 0): ?>
        <p>No administrator account exists yet. Create one from the command line with <code>php bin/create-admin.php</code>.</p>
    <?php endif; ?>
    <?php if ($message): ?><?= Template::render('partials/notice', ['message' => $message]) ?><?php endif; ?>
    <form method="post" action="/admin/login">
        <input type="hidden" name="_csrf" value="<?= h(csrfToken()) ?>">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="return_to" value="<?= h($returnTo) ?>">
        <label>Username <input name="username" autocomplete="username" required autofocus></label>
        <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
        <button>Sign in</button>
    </form>
</main>
