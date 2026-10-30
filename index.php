<?php
declare(strict_types=1);

require_once __DIR__ . '/app/EmbedProxy.php';
EmbedProxy::handle(
    rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/')),
    (string)($_SERVER['QUERY_STRING'] ?? ''),
    strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/Types.php';
require_once __DIR__ . '/app/Content.php';
require_once __DIR__ . '/app/PoolEditor.php';
require_once __DIR__ . '/app/PageEditor.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
$requestPath = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$requestPath = '/' . trim($requestPath, '/') . (str_ends_with($requestPath, '/') && $requestPath !== '/' ? '/' : '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$isAdminRoute = str_starts_with($requestPath, '/admin');
if ($isAdminRoute || isset($_COOKIE[session_name()])) {
    session_start();
} else {
    $_SESSION = [];
}

$db = Database::connection();
$page = null;
$poolRoute = null;

function h(string $value): string
{
    return Content::escape($value);
}

function redirectTo(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void
{
    $provided = (string)($_POST['_csrf'] ?? '');
    if ($provided === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $provided)) {
        http_response_code(400);
        exit('The form expired. Please reload and try again.');
    }
}

function isAdmin(): bool
{
    return isset($_SESSION['admin']) && is_string($_SESSION['admin']);
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function normalizeSlug(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    return trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
}

function pages(PDO $db): array
{
    return $db->query('SELECT * FROM pages ORDER BY sort_order, title COLLATE NOCASE')->fetchAll();
}

function pageDescendants(PDO $db, string $id): array
{
    $descendants = [];
    $children = $db->prepare('SELECT id FROM pages WHERE parent_id = ?');
    $children->execute([$id]);
    foreach ($children->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        $descendants[] = (string)$childId;
        $descendants = array_merge($descendants, pageDescendants($db, (string)$childId));
    }
    return $descendants;
}

function pageAtPath(PDO $db, string $path): ?array
{
    foreach (pages($db) as $page) {
        if (Content::pagePath($db, $page) === $path) {
            return $page;
        }
    }
    $alias = $db->prepare('SELECT page_id FROM page_aliases WHERE path = ?');
    $alias->execute([$path]);
    $pageId = $alias->fetchColumn();
    if ($pageId !== false) {
        return Content::pageById($db, (string)$pageId);
    }
    return null;
}

function poolAtPath(PDO $db, string $path): ?array
{
    $candidates = [];
    foreach (pages($db) as $page) {
        $candidates[] = ['page' => $page, 'path' => Content::pagePath($db, $page), 'filter_tag' => ''];
        foreach (Content::pageAliases($db, $page['id']) as $alias) {
            $candidates[] = ['page' => $page, 'path' => $alias['path'], 'filter_tag' => $alias['filter_tag']];
        }
    }
    usort($candidates, static fn(array $a, array $b): int => strlen($b['path']) <=> strlen($a['path']));
    $requestedTags = array_values(array_filter(array_map('trim', explode(',', (string)($_GET['list'] ?? '')))));
    $requestedPageId = trim((string)($_GET['list_page'] ?? ''));
    foreach ($candidates as $candidate) {
        $prefix = $candidate['path'];
        if (str_starts_with($path, $prefix)) {
            $suffix = substr($path, strlen($prefix));
            if (preg_match('#^([^/]+)\.html$#', $suffix, $match)) {
                $page = $candidate['page'];
                $statement = $db->prepare('SELECT * FROM pool_items WHERE slug = ?');
                $statement->execute([$match[1]]);
                $row = $statement->fetch();
                if (!$row) {
                    continue;
                }
                $item = Content::hydratePoolItem($row);
                $context = $page;
                $context['route_path'] = $prefix;
                if ($requestedTags) {
                    $context['filter_tags'] = $requestedTags;
                } elseif ($requestedPageId !== '') {
                    $context['filter_page_id'] = $requestedPageId;
                } elseif ($candidate['filter_tag'] !== '') {
                    $context['filter_tags'] = array_values(array_filter(array_map('trim', explode(',', $candidate['filter_tag']))));
                } else {
                    $listFilter = Content::poolListFilterForItem($db, $page, $prefix, $item);
                    if ($listFilter && isset($listFilter['tags'])) {
                        $context['filter_tags'] = $listFilter['tags'];
                    } elseif ($listFilter && isset($listFilter['page_id'])) {
                        $context['filter_page_id'] = $listFilter['page_id'];
                    }
                }
                $items = contextPoolItems($db, $context);
                foreach ($items as $listedItem) {
                    if ((string)$listedItem['id'] === (string)$item['id']) {
                        return ['item' => $item, 'context' => $context];
                    }
                }
            }
        }
    }
    return null;
}

function contextPoolItems(PDO $db, array $context): array
{
    if (!empty($context['filter_tags'])) {
        return Content::itemsByTags($db, $context['filter_tags']);
    }
    if (!empty($context['filter_page_id'])) {
        $page = Content::pageById($db, (string)$context['filter_page_id']);
        return $page ? Content::itemsForPage($db, $page) : [];
    }
    return Content::itemsForPage($db, $context);
}

function urlForItem(PDO $db, array $item, array $context): string
{
    $prefix = $context['id'] === 'archive' && empty($context['route_path'])
        ? '/archive/'
        : Content::pagePath($db, $context);
    $url = $prefix . rawurlencode($item['slug']) . '.html';
    if (!empty($context['filter_tags'])) {
        $url .= '?list=' . rawurlencode(implode(',', $context['filter_tags']));
    } elseif (!empty($context['filter_page_id'])) {
        $url .= '?list_page=' . rawurlencode((string)$context['filter_page_id']);
    }
    return $url;
}

function poolNavigation(PDO $db, array $item, array $context): array
{
    $items = contextPoolItems($db, $context);
    if (!$items && $context['id'] !== 'archive' && $context['tag'] !== '') {
        $items = Content::itemsByTags($db, [$context['tag']]);
    }
    if ($context['id'] === 'archive' && !$items) {
        $items = Content::itemsByTags($db, []);
    }
    usort($items, static fn(array $a, array $b): int => (int)$a['date'] <=> (int)$b['date']);
    $index = null;
    foreach ($items as $candidateIndex => $candidate) {
        if ((string)$candidate['id'] === (string)$item['id']) {
            $index = $candidateIndex;
            break;
        }
    }
    return [
        'prev' => $index !== null && isset($items[$index - 1]) ? $items[$index - 1] : null,
        'next' => $index !== null && isset($items[$index + 1]) ? $items[$index + 1] : null,
    ];
}

function routeUrl(PDO $db, array $page): string
{
    return Content::pagePath($db, $page);
}

function absoluteUrl(string $path): string
{
    return 'https://danielbeck.net' . ($path === '' ? '/' : $path);
}

if ($isAdminRoute) {
    if ($method === 'POST') {
        verifyCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            $returnTo = PoolEditor::returnPath((string)($_POST['return_to'] ?? '/'));
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $db->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 900]);
            $attempt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
            $attempt->execute([$ip]);
            $blocked = (int)$attempt->fetchColumn() >= 10;
            $query = $db->prepare('SELECT password_hash FROM admins WHERE username = ?');
            $query->execute([$username]);
            $hash = $query->fetchColumn();
            if (!$blocked && is_string($hash) && password_verify($password, $hash)) {
                session_regenerate_id(true);
                $_SESSION['admin'] = $username;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                redirectTo($returnTo);
            }
            $db->prepare('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)')->execute([$ip, time()]);
            flash('Sign-in failed. Check your username and password.', 'error');
            redirectTo('/admin/login?return_to=' . rawurlencode($returnTo));
        }
        if (!isAdmin()) {
            http_response_code(403);
            exit('Sign in to continue.');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            redirectTo(PoolEditor::returnPath((string)($_POST['return_to'] ?? '/')));
        }
        if ($action === 'save_page') {
            $id = trim((string)($_POST['id'] ?? ''));
            $parentId = trim((string)($_POST['parent_id'] ?? '')) ?: null;
            $slug = normalizeSlug((string)($_POST['slug'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $navLabel = trim((string)($_POST['nav_label'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            $format = ($_POST['format'] ?? 'html') === 'markdown' ? 'markdown' : 'html';
            $tag = trim((string)($_POST['tag'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            $showNav = isset($_POST['show_in_nav']) ? 1 : 0;
            if ($id === 'Home') {
                $parentId = null;
                $slug = '';
            } elseif ($id === 'archive') {
                $parentId = null;
                $slug = 'archive';
            }
            if ($title === '' || ($id !== 'Home' && $slug === '')) {
                flash('A title and non-empty URL segment are required.', 'error');
                redirectTo('/admin/page' . ($id !== '' ? '?id=' . rawurlencode($id) : ''));
            }
            if ($id === '' || !Content::pageById($db, $id)) {
                $id = bin2hex(random_bytes(8));
            }
            if ($parentId === $id || ($parentId && in_array($parentId, pageDescendants($db, $id), true))) {
                flash('A page cannot be placed inside itself or one of its descendants.', 'error');
                redirectTo('/admin/page?id=' . rawurlencode($id));
            }
            if ($parentId && !Content::pageById($db, $parentId)) {
                flash('The selected parent page does not exist.', 'error');
                redirectTo('/admin/page');
            }
            $now = time();
            try {
                $existing = Content::pageById($db, $id);
                if ($existing) {
                    $db->prepare(
                        'UPDATE pages SET parent_id=?, slug=?, title=?, nav_label=?, body=?, format=?, tag=?, show_in_nav=?, sort_order=?, updated_at=? WHERE id=?'
                    )->execute([$parentId, $slug, $title, $navLabel, $body, $format, $tag, $showNav, $sort, $now, $id]);
                } else {
                    $db->prepare(
                        'INSERT INTO pages (id,parent_id,slug,title,nav_label,body,format,tag,show_in_nav,sort_order,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$id, $parentId, $slug, $title, $navLabel, $body, $format, $tag, $showNav, $sort, $now, $now]);
                }
            } catch (PDOException $error) {
                if (str_contains($error->getMessage(), 'UNIQUE')) {
                    flash('That URL segment is already in use under the selected parent.', 'error');
                    redirectTo('/admin/page' . ($existing ? '?id=' . rawurlencode($id) : ''));
                }
                throw $error;
            }
            flash('Page saved.');
            redirectTo(Content::pagePath($db, Content::pageById($db, $id)));
        }
        if ($action === 'delete_page') {
            $id = (string)($_POST['id'] ?? '');
            $deletedPage = Content::pageById($db, $id);
            $parent = $deletedPage && $deletedPage['parent_id'] ? Content::pageById($db, $deletedPage['parent_id']) : null;
            if (in_array($id, ['Home', 'archive'], true)) {
                flash('The home and archive pages cannot be deleted.', 'error');
                redirectTo('/admin/');
            }
            if (Content::children($db, $id)) {
                flash('Move or delete this page’s children before deleting it.', 'error');
                redirectTo('/admin/');
            }
            $db->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
            flash('Page deleted.');
            redirectTo($parent ? Content::pagePath($db, $parent) : '/');
        }
        if ($action === 'save_pool') {
            $returnTo = PoolEditor::returnPath((string)($_POST['return_to'] ?? '/'));
            $contextPage = Content::pageById($db, (string)($_POST['context_page'] ?? ''));
            if ($contextPage) {
                $returnPath = (string)parse_url($returnTo, PHP_URL_PATH);
                $prefix = str_ends_with($returnPath, '.html') ? dirname($returnPath) . '/' : $returnPath;
                $returnPage = pageAtPath($db, $prefix);
                if ($returnPage && $returnPage['id'] === $contextPage['id']) {
                    $contextPage['route_path'] = $prefix;
                }
            }
            $retryUrl = '/admin/pool?' . http_build_query([
                'id' => (string)($_POST['id'] ?? ''),
                'return_to' => $returnTo, 'context_page' => $contextPage['id'] ?? '',
            ]);
            $_SESSION['pool_draft'] = $_POST;
            $id = trim((string)($_POST['id'] ?? ''));
            $slug = normalizeSlug((string)($_POST['slug'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $body = (string)($_POST['body'] ?? '');
            $format = ($_POST['format'] ?? 'html') === 'markdown' ? 'markdown' : 'html';
            $type = (string)($_POST['item_type'] ?? 'article');
            if (!isset(Types::all()[$type])) {
                flash('Select a valid pool-item type.', 'error');
                redirectTo($retryUrl);
            }
            $schema = Types::get($type);
            $dateInput = (string)($_POST['date'] ?? '');
            $timestamp = strtotime($dateInput);
            if ($timestamp === false) {
                flash('Enter a valid date.', 'error');
                redirectTo($retryUrl);
            }
            $date = (int)($timestamp * 1000);
            $rawTags = $_POST['tags'] ?? [];
            if (!is_array($rawTags)) {
                $rawTags = [$rawTags];
            }
            $tags = [];
            foreach ($rawTags as $rawTag) {
                foreach (explode(',', (string)$rawTag) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') {
                        $tags[] = $tag;
                    }
                }
            }
            $tags = array_values(array_unique($tags));
            $fields = [];
            foreach ($schema['fields'] as $name => $definition) {
                $fields[$name] = trim((string)($_POST['type_fields'][$type][$name] ?? $_POST['fields'][$name] ?? ''));
            }
            if ($title === '' || $slug === '') {
                flash('A title and unique URL slug are required.', 'error');
                redirectTo($retryUrl);
            }
            if ($id === '' || !Content::poolItem($db, $id)) {
                $id = bin2hex(random_bytes(8));
            }
            $image = '';
            $current = Content::poolItem($db, $id);
            if ($current) {
                $image = $current['image'];
            }
            if (isset($_POST['remove_image'])) {
                $image = '';
            }
            if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $upload = $_FILES['image'];
                if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int)$upload['size'] > 12 * 1024 * 1024) {
                    flash('The image upload failed or exceeded the 12 MB limit.', 'error');
                    redirectTo($retryUrl);
                }
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    flash('Upload a JPEG, PNG, GIF, or WebP image.', 'error');
                    redirectTo($retryUrl);
                }
                $poolDirectory = __DIR__ . '/content/pool';
                if (!is_dir($poolDirectory) && !mkdir($poolDirectory, 0755, true) && !is_dir($poolDirectory)) {
                    throw new RuntimeException('Could not create the image directory.');
                }
                $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($upload['tmp_name'], $poolDirectory . '/' . $filename)) {
                    throw new RuntimeException('Could not save the uploaded image.');
                }
                $image = $filename;
            }
            $now = time();
            $title = PoolEditor::smartquotes($title);
            $body = PoolEditor::smartquotes($body);
            $db->beginTransaction();
            try {
                if ($current) {
                    $db->prepare(
                        'UPDATE pool_items SET slug=?,title=?,body=?,format=?,item_type=?,date=?,tags=?,image=?,fields=?,updated_at=? WHERE id=?'
                    )->execute([$slug, $title, $body, $format, $type, $date, json_encode($tags, JSON_THROW_ON_ERROR), $image, json_encode($fields, JSON_THROW_ON_ERROR), $now, $id]);
                } else {
                    $db->prepare(
                        'INSERT INTO pool_items (id,slug,title,body,format,item_type,date,tags,image,fields,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
                    )->execute([$id, $slug, $title, $body, $format, $type, $date, json_encode($tags, JSON_THROW_ON_ERROR), $image, json_encode($fields, JSON_THROW_ON_ERROR), $now, $now]);
                }
            } catch (PDOException $error) {
                $db->rollBack();
                if (str_contains($error->getMessage(), 'UNIQUE')) {
                    flash('That pool-item URL slug is already in use.', 'error');
                    redirectTo($retryUrl);
                }
                throw $error;
            }
            $db->commit();
            unset($_SESSION['pool_draft']);
            flash('Pool item saved.');
            $saved = Content::poolItem($db, $id);
            if ($contextPage && Content::containsItem(Content::itemsForPage($db, $contextPage), $id)) {
                parse_str((string)(parse_url($returnTo, PHP_URL_QUERY) ?? ''), $returnQuery);
                $query = array_intersect_key($returnQuery, array_flip(['list', 'list_page']));
                redirectTo(urlForItem($db, $saved, $contextPage) . ($query ? '?' . http_build_query($query) : ''));
            }
            redirectTo('/archive/' . rawurlencode($slug) . '.html');
        }
        if ($action === 'delete_pool') {
            $db->prepare('DELETE FROM pool_items WHERE id = ?')->execute([(string)($_POST['id'] ?? '')]);
            flash('Pool item deleted.');
            redirectTo(PoolEditor::returnPath((string)($_POST['delete_return_to'] ?? '/')));
        }
        http_response_code(400);
        exit('Unknown admin action.');
    }

    if ($requestPath === '/admin/login' || $requestPath === '/admin/login/') {
        $returnTo = PoolEditor::returnPath((string)($_GET['return_to'] ?? '/'));
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $adminCount = (int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        $pageTitle = 'Admin sign in';
        $content = Template::render('admin/login', compact('adminCount', 'message', 'returnTo'));
    } elseif (!isAdmin()) {
        redirectTo('/admin/login');
    } elseif ($requestPath === '/admin/page' || $requestPath === '/admin/page/') {
        $id = (string)($_GET['id'] ?? '');
        $page = $id !== '' ? Content::pageById($db, $id) : null;
        $pageTitle = $page ? 'Edit page' : 'Add page';
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $content = Template::render('admin/editor', [
            'pageTitle' => $pageTitle, 'message' => $message,
            'form' => PageEditor::render($db, $page, $page ? Content::pagePath($db, $page) : '/admin/'),
        ]);
    } elseif ($requestPath === '/admin/pool' || $requestPath === '/admin/pool/') {
        $id = (string)($_GET['id'] ?? '');
        $item = $id !== '' ? Content::poolItem($db, $id) : null;
        $context = Content::pageById($db, (string)($_GET['context_page'] ?? ''));
        $returnTo = PoolEditor::returnPath((string)($_GET['return_to'] ?? '/'));
        $pageTitle = $item ? 'Edit pool item' : 'Add pool item';
        $message = $_SESSION['flash'] ?? null;
        $draft = $_SESSION['pool_draft'] ?? [];
        unset($_SESSION['flash'], $_SESSION['pool_draft']);
        $content = Template::render('admin/editor', [
            'pageTitle' => $pageTitle, 'message' => $message,
            'form' => PoolEditor::render($db, $item, $context, $returnTo, $draft),
        ]);
    } else {
        $message = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $allPages = pages($db);
        foreach ($allPages as &$adminPage) {
            $adminPage['path'] = Content::pagePath($db, $adminPage);
        }
        unset($adminPage);
        $poolItems = $db->query('SELECT * FROM pool_items ORDER BY date DESC, id DESC')->fetchAll();
        $pageTitle = 'Admin';
        $content = Template::render('admin/dashboard', [
            'pages' => $allPages, 'poolItems' => $poolItems, 'message' => $message,
            'username' => (string)$_SESSION['admin'],
        ]);
    }
} else {
    if ($requestPath !== '/' && !str_ends_with($requestPath, '/') && !str_ends_with($requestPath, '.html')) {
        $slashPath = $requestPath . '/';
        if (pageAtPath($db, $slashPath)) {
            $queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
            header('Location: ' . $slashPath . ($queryString !== '' ? '?' . $queryString : ''), true, 301);
            exit;
        }
    }
    $page = pageAtPath($db, $requestPath);
    $poolRoute = $page ? null : poolAtPath($db, $requestPath);
    if ($requestPath === '/sitemap.xml' || $requestPath === '/sitemap.txt') {
        $urls = [];
        foreach (pages($db) as $sitePage) {
            $urls[] = absoluteUrl(Content::pagePath($db, $sitePage));
        }
        foreach ($db->query('SELECT slug FROM pool_items')->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $urls[] = absoluteUrl('/archive/' . rawurlencode((string)$slug) . '.html');
        }
        if ($requestPath === '/sitemap.txt') {
            header('Content-Type: text/plain; charset=utf-8');
            echo implode("\n", array_unique($urls)) . "\n";
        } else {
            header('Content-Type: application/xml; charset=utf-8');
            echo Template::render('feeds/sitemap', ['urls' => array_unique($urls)]);
        }
        exit;
    }
    if ($requestPath === '/rss.xml' || str_starts_with($requestPath, '/rss-')) {
        $filter = match ($requestPath) {
            '/rss-blog.xml' => 'blog',
            '/rss-photos.xml' => 'photos',
            '/rss-geek.xml' => 'geek',
            default => '',
        };
        $items = Content::itemsByTags($db, $filter !== '' ? [$filter] : []);
        $items = array_slice($items, 0, 25);
        header('Content-Type: application/rss+xml; charset=utf-8');
        $entries = array_map(static fn(array $item): array => [
            'item' => $item,
            'url' => absoluteUrl('/archive/' . rawurlencode($item['slug']) . '.html'),
            'date' => gmdate(DATE_RSS, (int)floor((int)$item['date'] / 1000)),
        ], $items);
        echo Template::render('feeds/rss', ['siteUrl' => absoluteUrl('/'), 'entries' => $entries]);
        exit;
    }
    if (!$page && !$poolRoute) {
        http_response_code(404);
        $pageTitle = 'Not found';
        $content = Template::render('not-found');
    } elseif ($page) {
        $pageTitle = $page['title'];
        $showArchiveList = $page['id'] === 'archive' && Content::pagePath($db, $page) === $requestPath;
        $showDefaultList = ($showArchiveList || $page['tag'] !== '')
            && !preg_match('/<pool[-_]list\b/i', $page['body']);
        $content = Template::render('page', [
            'page' => $page,
            'body' => $page['body'] !== '' ? Content::renderBody($db, $page['body'], $page['format'], $page) : '',
            'defaultList' => $showDefaultList ? Content::renderPoolList($db, 'type="list"', $page) : '',
        ]);
    } else {
        $item = $poolRoute['item'];
        $context = $poolRoute['context'] ?? Content::pageById($db, 'archive');
        $pageTitle = $item['title'];
        $nav = poolNavigation($db, $item, $context);
        $content = Template::render(Types::template($item['item_type'], 'detail'), [
            'item' => $item, 'contextPage' => $context,
            'date' => $item['date'] ? date('F j, Y', (int)floor((int)$item['date'] / 1000)) : '',
            'body' => $item['body'] !== '' ? Content::renderBody($db, $item['body'], $item['format'], $context) : '',
            'image' => Content::imageUrl($item['image']),
            'fields' => Types::get($item['item_type'])['fields'],
            'previousUrl' => $nav['prev'] ? urlForItem($db, $nav['prev'], $context) : null,
            'nextUrl' => $nav['next'] ? urlForItem($db, $nav['next'], $context) : null,
        ]);
    }
}

$poolEditor = '';
$pageEditor = '';
$currentUrl = PoolEditor::returnPath((string)($_SERVER['REQUEST_URI'] ?? '/'));
if (isAdmin() && !$isAdminRoute && ($page || $poolRoute)) {
    $authorControls = '';
    if ($page) {
        $pageEditor = Template::render('admin/dialog', [
            'id' => 'page-dialog', 'headingId' => 'page-editor-heading', 'label' => 'Edit page',
            'form' => PageEditor::render($db, $page, $currentUrl),
        ]);
        $authorControls = Template::render('admin/author-control', [
            'kind' => 'page', 'url' => '/admin/page?id=' . rawurlencode($page['id']), 'label' => 'Edit page',
        ]);
    }
    $editorItem = $poolRoute['item'] ?? null;
    $editorContext = $poolRoute['context'] ?? $page;
    $editorLabel = $editorItem ? 'Edit pool item' : 'Add pool item';
    $editorUrl = '/admin/pool?' . http_build_query([
        'id' => $editorItem['id'] ?? '', 'context_page' => $editorContext['id'] ?? '',
        'return_to' => $currentUrl,
    ]);
    $authorControls = Template::render('admin/author-control', ['kind' => 'pool', 'url' => $editorUrl, 'label' => $editorLabel]) . $authorControls;
    $content = '<nav class="author-controls" aria-label="Content editing">' . $authorControls . '</nav>' . $content;
    $poolEditor = Template::render('admin/dialog', [
        'id' => 'pool-dialog', 'headingId' => 'pool-editor-heading', 'label' => $editorLabel,
        'form' => PoolEditor::render($db, $editorItem, $editorContext, $currentUrl),
    ]);
}

$topPages = Content::children($db, null, true);
$activePath = $requestPath;
$navigationRows = [[]];
foreach ($topPages as $topPage) {
    if ($topPage['id'] === 'Home') {
        continue;
    }
    $topPath = Content::pagePath($db, $topPage);
    $navLabel = $topPage['nav_label'] !== '' ? $topPage['nav_label'] : $topPage['title'];
    $navigationRows[0][] = ['path' => $topPath, 'label' => $navLabel, 'active' => str_starts_with($activePath, $topPath)];
}
$activePage = pageAtPath($db, $activePath);
if (!$activePage && $poolRoute) {
    $activePage = $poolRoute['context'];
}
$pageChain = [];
for ($cursor = $activePage; $cursor; $cursor = $cursor['parent_id'] ? Content::pageById($db, $cursor['parent_id']) : null) {
    array_unshift($pageChain, $cursor);
}
if (count($pageChain) >= 1) {
    for ($depth = 0; $depth < min(count($pageChain), 2); $depth++) {
        $navigationParent = $pageChain[$depth];
        $children = Content::children($db, $navigationParent['id'], true);
        if (!$children) {
            break;
        }
        $row = [];
        foreach ($children as $child) {
            $childPath = Content::pagePath($db, $child);
            $navLabel = $child['nav_label'] !== '' ? $child['nav_label'] : $child['title'];
            $row[] = ['path' => $childPath, 'label' => $navLabel, 'active' => str_starts_with($activePath, $childPath)];
        }
        $navigationRows[] = $row;
    }
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
if ($flash && !$isAdminRoute) {
    $content = Template::render('partials/notice', ['message' => $flash]) . $content;
}
$canonicalUrl = null;
if (!$isAdminRoute && $poolRoute) {
    $canonicalUrl = absoluteUrl('/archive/' . rawurlencode($poolRoute['item']['slug']) . '.html');
} elseif (!$isAdminRoute && $page) {
    $canonicalUrl = absoluteUrl(Content::pagePath($db, $page));
}
echo Template::render('layout', [
    'pageTitle' => $pageTitle ?? 'Danielbeck.net', 'canonicalUrl' => $canonicalUrl,
    'isAdmin' => isAdmin(), 'currentUrl' => $currentUrl, 'content' => $content,
    'navigation' => Template::render('partials/navigation', ['activePath' => $activePath, 'rows' => $navigationRows]),
    'dialogs' => $poolEditor . $pageEditor,
]);
