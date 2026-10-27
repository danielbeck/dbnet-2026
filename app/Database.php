<?php
declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $directory = dirname(__DIR__) . '/storage';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the protected storage directory.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('The protected storage directory is not writable by PHP.');
        }

        $pdo = new PDO('sqlite:' . $directory . '/dbnet.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        self::createSchema($pdo);
        self::$connection = $pdo;

        return $pdo;
    }

    private static function createSchema(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pages (
                id TEXT PRIMARY KEY,
                parent_id TEXT REFERENCES pages(id) ON DELETE SET NULL,
                slug TEXT NOT NULL,
                title TEXT NOT NULL DEFAULT "",
                nav_label TEXT NOT NULL DEFAULT "",
                body TEXT NOT NULL DEFAULT "",
                format TEXT NOT NULL DEFAULT "html",
                tag TEXT NOT NULL DEFAULT "",
                show_in_nav INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS pages_parent_slug ON pages(COALESCE(parent_id, ""), slug)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS pages_parent_order ON pages(parent_id, sort_order, title)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS page_aliases (
                path TEXT PRIMARY KEY,
                page_id TEXT NOT NULL REFERENCES pages(id) ON DELETE CASCADE,
                filter_tag TEXT NOT NULL DEFAULT ""
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS pool_items (
                id TEXT PRIMARY KEY,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL DEFAULT "",
                body TEXT NOT NULL DEFAULT "",
                format TEXT NOT NULL DEFAULT "html",
                item_type TEXT NOT NULL DEFAULT "article",
                date INTEGER NOT NULL DEFAULT 0,
                tags TEXT NOT NULL DEFAULT "[]",
                image TEXT NOT NULL DEFAULT "",
                fields TEXT NOT NULL DEFAULT "{}",
                created_at INTEGER NOT NULL,
                updated_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS pool_date ON pool_items(date DESC)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS admins (
                username TEXT PRIMARY KEY,
                password_hash TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                attempted_at INTEGER NOT NULL
            )'
        );
    }
}
