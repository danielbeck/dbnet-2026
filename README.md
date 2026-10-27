# dbnet-2026

A server-rendered PHP site with SQLite storage and a live admin interface for managing pages and content.

## Requirements

- PHP 8.1+ with PDO SQLite (`pdo_sqlite`)
- Apache 2.4 with `mod_rewrite`, `mod_authz_core`, and `.htaccess` overrides enabled
- Writable `storage/` and `content/pool/` directories
- For development sync and deployment: SSH and rsync on both machines, remote PHP CLI with PDO SQLite, and local PHP with the SQLite3 extension

## Installation

1. Copy the application to the domain's webroot, including all hidden `.htaccess` files. Hosting at the domain root is required.
2. Ensure PHP can write to `storage/` and `content/pool/`.
3. For a fresh site, initialize the required Home and Archive pages:

   ```sh
   php bin/initialize.php
   ```

4. Create an administrator:

   ```sh
   php bin/create-admin.php YOUR_USERNAME
   ```

   Use a password of at least 12 characters.
5. Visit `/admin/login` to sign in and start adding content.

The database is stored at `storage/dbnet.sqlite`. To install an existing site, transfer a consistent SQLite backup and its `content/` directory instead of initializing an empty site. Keep all access-protection files in place, and verify that the host blocks web access to `storage/` before uploading private data.

On IONOS, use a PHP **CLI** executable for command-line tasks; the default `php` may be a CGI executable. If the home page works but other routes return HTTP 500, confirm the root `.htaccess` was uploaded and check the Apache error log.

## Managing content

Use **Admin** in the footer to sign in. Once signed in:

- **Manage site** opens the dashboard for adding pages and arranging the hierarchy.
- **Edit page**, **Add pool item**, and **Edit pool item** open editors on the page you are viewing.
- **Log out** ends the session.

### Pages and pool items

**Pages** define the site's URL hierarchy and navigation. Each page has a parent, URL segment, body, and optional tag filter. Changing a parent changes descendant URLs, so check existing links after rearranging pages.

**Pool items** are reusable content records: Articles, Photos, or Projects. Projects have additional project and GitHub URL fields. Tags determine which pages list an item; a tag is not an item type.

Items can appear beneath any page with a matching tag filter, and every item has a canonical URL under `/archive/`. Archive includes all items. Previous/next links, arrow keys, and swipe navigation follow the list in which the item was opened.

### Writing content

Bodies support HTML or a small Markdown subset, including headings, lists, links, emphasis, inline HTML, and fenced code blocks. Only trusted administrators should author HTML. Add a language to a code fence for syntax highlighting, or use `mermaid` for diagrams.

Special tags work in page and item bodies:

```html
<pool-list tag="blog,geek" type="summary" sort="desc" limit="10"></pool-list>
<pool-list page="photos" type="image"></pool-list>
<footnote>A note shown in a floating panel beside its reference.</footnote>
```

For `pool-list`:

- `tag`: comma-separated tags, matching any of them
- `page`: a page ID whose tag filter should be used
- `type`: a registered view, normally `list`, `summary`, or `image`
- `sort`: `asc` or `desc`
- `limit`: a count or `[start,end]` range

A tagged page gets an automatic item list unless its body already contains a pool-list tag. An explicit `tag` filter overrides the page filter.

Image uploads accept JPEG, PNG, GIF, and WebP files up to 12 MB. Uploaded item images live in `content/pool/`; use `/content/` URLs for other content media.

## Local development

Configure the SSH destination once:

```sh
cp .env.example .env
# Edit .env and set DEPLOY_TARGET='user@host:directory'
```

Then run:

```sh
./dev.sh
```

**Each startup pulls the production database and new or changed content files before starting the server. Production replaces local content edits and accounts.** The previous local database is backed up as `storage/before-dev-sync-*.sqlite`. Local-only media is not deleted. These database files are private and Git-ignored.

The server runs at `http://127.0.0.1:8080`; override `HOST` or `PORT` if needed. If remote PHP detection fails, set `REMOTE_PHP` in `.env` to the server's CLI executable, for example `/usr/bin/php8.3-cli`.

To run locally **without** pulling production data:

```sh
php -S 127.0.0.1:8080 -t . bin/dev-router.php
```

Keep `.env` local and trusted: it is sourced as shell configuration and is excluded from Git and deployment. An environment-provided `DEPLOY_TARGET` overrides the file.

## Deploying updates

Using the same `.env` configuration:

```sh
./deploy.sh --dry-run
./deploy.sh
```

The deployment script previews changes and asks for confirmation before uploading application code, templates, assets, and protection files.

**It never uploads the database or content media, and never deletes remote files.** Live content and accounts are preserved. Transfer media separately when needed; do not overwrite the live database with a local development copy. Removed code files must be reviewed and cleaned up separately.

Back up both `storage/dbnet.sqlite` and `content/` regularly. Use a consistent SQLite backup rather than copying an active database file.

## Customization

- `app/Types.php`: item types, extra fields, and available views. The editor uses these definitions automatically.
- `templates/`: site layouts, admin forms, and independent Article, Photo, and Project templates.
- `assets/`: site styles, scripts, and framework images.
- `content/`: uploaded and authored media.

To add a view, create its template and register it for each item type the list can contain. Keep a `detail` view for full item pages. Escape plain template values with `Content::escape()` or `h()`; emit only already-rendered HTML directly.

RSS feeds are available at `/rss.xml`, `/rss-blog.xml`, `/rss-photos.xml`, and `/rss-geek.xml`. Sitemaps are at `/sitemap.xml` and `/sitemap.txt`.
