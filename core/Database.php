<?php
class Database {
    private static ?PDO   $pdo              = null;
    private static int    $siteId           = 0;     // 0 = not yet resolved
    private static array  $settingsCache    = [];
    private static bool   $settingsLoaded   = false;

    public static function get(): PDO {
        if (self::$pdo === null) {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);

            // Resolve subdomain → site_id on first connection
            if (defined('NETWORK_MODE') && NETWORK_MODE && defined('SITE_ID') && SITE_ID === 0) {
                self::resolveSubdomain(SITE_SUBDOMAIN);
            } else {
                self::$siteId = defined('SITE_ID') ? (int) SITE_ID : 1;
            }
        }
        return self::$pdo;
    }

    // Called once after the DB connection is open when SITE_ID is still 0.
    private static function resolveSubdomain(string $subdomain): void {
        try {
            $row = self::fetch("SELECT id, status FROM network_sites WHERE subdomain = ?", [$subdomain]);
            if ($row && $row['status'] === 'active') {
                self::$siteId = (int) $row['id'];
            } else {
                // Unknown or suspended subdomain → 404
                http_response_code(404);
                echo '<!DOCTYPE html><html><body><h1>Site not found</h1>'
                   . '<p>The requested subdomain <strong>' . htmlspecialchars($subdomain) . '</strong> does not exist.</p>'
                   . '</body></html>';
                exit;
            }
        } catch (\PDOException) {
            self::$siteId = 1;
        }
    }

    // Current site ID for this request.
    public static function siteId(): int {
        if (self::$siteId === 0) {
            self::get(); // triggers resolution
        }
        return self::$siteId ?: 1;
    }

    // Allow super-admins to temporarily act on a different site (e.g. network panel).
    public static function setSiteId(int $id): void {
        self::$siteId = $id;
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $row = self::query($sql, $params)->fetch();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        return self::query($sql, $params)->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $cols   = implode(', ', array_map(fn($k) => "`$k`", array_keys($data)));
        $places = implode(', ', array_fill(0, count($data), '?'));
        self::query("INSERT INTO `$table` ($cols) VALUES ($places)", array_values($data));
        return (int) self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
        $stmt = self::query("UPDATE `$table` SET $set WHERE $where", [...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        return self::query("DELETE FROM `$table` WHERE $where", $params)->rowCount();
    }

    // ── Settings — cached in memory for the lifetime of the request ────────────

    /** Load all settings for this site in one query (called lazily on first access). */
    private static function loadSettings(): void {
        if (self::$settingsLoaded) return;
        self::$settingsLoaded = true;
        try {
            $rows = self::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE site_id = ?",
                [self::siteId()]
            );
            foreach ($rows as $r) {
                self::$settingsCache[$r['key']] = $r['value'];
            }
        } catch (\PDOException) {
            // settings table may not exist yet during install
        }
    }

    /** Site-scoped setting lookup — single bulk load, then served from memory. */
    public static function setting(string $key, string $default = ''): string {
        self::loadSettings();
        return array_key_exists($key, self::$settingsCache)
            ? (string) self::$settingsCache[$key]
            : $default;
    }

    /** Write a setting and update the in-memory cache. */
    public static function setSetting(string $key, string $value): void {
        $sid = self::siteId();
        self::query(
            "INSERT INTO settings (site_id, `key`, `value`) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE `value` = ?",
            [$sid, $key, $value, $value]
        );
        self::$settingsCache[$key] = $value;
    }

    /** Invalidate the settings cache (call after bulk settings saves). */
    public static function clearSettingsCache(): void {
        self::$settingsCache  = [];
        self::$settingsLoaded = false;
    }

    /** All settings as key→value array (returns from cache if already loaded). */
    public static function allSettings(): array {
        self::loadSettings();
        return self::$settingsCache;
    }
}
