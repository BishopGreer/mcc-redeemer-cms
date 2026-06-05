<?php
/**
 * MCCOOR CMS — Installer Engine
 * Handles all logic for the web-based setup wizard.
 */
class Installer {

    const VERSION   = '1.3.0';
    const MIN_PHP   = '8.1.0';
    const LOCK_FILE = __DIR__ . '/../config/install.lock';
    const CONFIG    = __DIR__ . '/../config/config.local.php';
    const SCHEMA    = __DIR__ . '/schema.sql';

    // -------------------------------------------------------
    // Checks
    // -------------------------------------------------------

    public static function isAlreadyInstalled(): bool {
        return file_exists(self::LOCK_FILE);
    }

    public static function checkRequirements(): array {
        $checks = [];

        // PHP version
        $phpOk = version_compare(PHP_VERSION, self::MIN_PHP, '>=');
        $checks[] = [
            'label'   => 'PHP Version (8.1+ required)',
            'value'   => PHP_VERSION,
            'pass'    => $phpOk,
            'fatal'   => true,
        ];

        // Required extensions
        $exts = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'gd', 'fileinfo', 'openssl'];
        foreach ($exts as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'label'  => "PHP extension: $ext",
                'value'  => $loaded ? 'Loaded' : 'MISSING',
                'pass'   => $loaded,
                'fatal'  => in_array($ext, ['pdo', 'pdo_mysql', 'mbstring', 'json']),
            ];
        }

        // Writable paths
        $paths = [
            'config/'         => dirname(__DIR__) . '/config',
            'public/uploads/' => dirname(__DIR__) . '/public/uploads',
        ];
        foreach ($paths as $label => $path) {
            $writable = is_writable($path);
            $checks[] = [
                'label'  => "Directory writable: $label",
                'value'  => $writable ? 'OK' : 'Not writable',
                'pass'   => $writable,
                'fatal'  => true,
            ];
        }

        // mod_rewrite / server
        $checks[] = [
            'label'  => 'Web server',
            'value'  => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'pass'   => true,
            'fatal'  => false,
        ];

        return $checks;
    }

    public static function hasBlockingFailures(array $checks): bool {
        foreach ($checks as $c) {
            if ($c['fatal'] && !$c['pass']) return true;
        }
        return false;
    }

    // -------------------------------------------------------
    // Database
    // -------------------------------------------------------

    public static function testDbConnection(string $host, string $name, string $user, string $pass, int $port = 3306): array {
        try {
            $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Check if database exists
            $stmt = $pdo->query("SHOW DATABASES LIKE " . $pdo->quote($name));
            $exists = $stmt->fetchColumn() !== false;

            if (!$exists) {
                // Try to create it
                $pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            }

            // Verify we can select it
            $pdo->exec("USE `{$name}`");
            return ['ok' => true, 'created' => !$exists];

        } catch (PDOException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function importSchema(string $host, string $name, string $user, string $pass, int $port = 3306): array {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $sql = file_get_contents(self::SCHEMA);
            if (!$sql) throw new RuntimeException('Schema file not found.');

            foreach (self::splitSql($sql) as $stmt) {
                $pdo->exec($stmt);
            }

            // Seed migrations table so applied migrations are not re-run later
            self::seedMigrations($pdo);

            return ['ok' => true];

        } catch (Exception $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Split a SQL file into individual statements, correctly ignoring semicolons
     * inside quoted strings (single-quote, double-quote, backtick) and -- comments.
     */
    private static function splitSql(string $sql): array {
        $statements = [];
        $current    = '';
        $len        = strlen($sql);
        $i          = 0;

        while ($i < $len) {
            $ch = $sql[$i];

            // -- line comment: skip to end of line
            if ($ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // quoted string: copy verbatim until closing quote, honouring \-escapes and doubled quotes
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote    = $ch;
                $current .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    $current .= $c;
                    if ($c === '\\') {           // backslash escape — skip next char
                        $i++;
                        if ($i < $len) { $current .= $sql[$i]; $i++; }
                        continue;
                    }
                    if ($c === $quote) {
                        if (isset($sql[$i + 1]) && $sql[$i + 1] === $quote) {
                            // doubled-quote escape inside string
                            $current .= $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;                   // end of quoted string
                    }
                    $i++;
                }
                continue;
            }

            // statement terminator
            if ($ch === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $ch;
            $i++;
        }

        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }

    private static function seedMigrations(PDO $pdo): void {
        $dir = __DIR__ . '/migrations';
        if (!is_dir($dir)) return;
        $files = glob($dir . '/*.sql');
        sort($files);
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $pdo->exec(
                "INSERT IGNORE INTO migrations (version, applied_at) VALUES (" . $pdo->quote($version) . ", NOW())"
            );
        }
    }

    // -------------------------------------------------------
    // Admin user
    // -------------------------------------------------------

    public static function createAdminUser(
        PDO    $pdo,
        string $name,
        string $email,
        string $password,
        bool   $networkMode = false
    ): void {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $role = $networkMode ? 'super_admin' : 'admin';
        $pdo->exec("DELETE FROM users WHERE email = " . $pdo->quote(strtolower($email)));
        $stmt = $pdo->prepare(
            "INSERT INTO users (site_id, name, email, password, role) VALUES (NULL, ?, ?, ?, ?)"
        );
        $stmt->execute([$name, strtolower($email), $hash, $role]);
    }

    // -------------------------------------------------------
    // Config file writer
    // -------------------------------------------------------

    public static function generateConfig(array $cfg): string {
        $env          = $cfg['env']          ?? 'production';
        $date         = $cfg['date']         ?? date('Y-m-d H:i:s');
        $networkMode  = !empty($cfg['network_mode'])   ? 'true'  : 'false';
        $baseDomain   = $cfg['base_domain_q'] ?? "''";

        $networkBlock = $networkMode === 'true'
            ? "\ndefine('NETWORK_MODE',        true);\ndefine('NETWORK_BASE_DOMAIN', {$baseDomain});\n"
            : "\n// Network mode disabled — single-site install.\n// define('NETWORK_MODE',        true);\n// define('NETWORK_BASE_DOMAIN', 'yourdomain.org');\n";

        return <<<PHP
<?php
// Parish CMS — Local Configuration
// Generated by the installer on {$date}
// Do NOT commit this file to version control.

define('DB_HOST',    {$cfg['db_host_q']});
define('DB_NAME',    {$cfg['db_name_q']});
define('DB_USER',    {$cfg['db_user_q']});
define('DB_PASS',    {$cfg['db_pass_q']});
define('DB_CHARSET', 'utf8mb4');

define('BASE_PATH', {$cfg['base_path_q']});

// SITE_URL is auto-detected from the request — no change needed when
// switching domains. Un-comment the line below ONLY if you want to
// lock this installation to one specific domain:
// define('SITE_URL', 'https://your-site.org');
{$networkBlock}
define('APP_ENV', '$env');
PHP;
    }

    public static function writeConfig(array $cfg): bool {
        return (bool) file_put_contents(self::CONFIG, self::generateConfig($cfg));
    }

    public static function configPath(): string {
        return self::CONFIG;
    }

    // -------------------------------------------------------
    // Site settings (after DB is up)
    // -------------------------------------------------------

    public static function writeSiteSettings(PDO $pdo, array $site): void {
        $updates = [
            'site_name'    => $site['site_name'],
            'site_tagline' => $site['tagline'],
            'site_url'     => $site['site_url'],
            'admin_email'  => $site['admin_email'],
        ];
        foreach ($updates as $key => $value) {
            $pdo->exec(
                "UPDATE settings SET `value` = " . $pdo->quote($value) .
                " WHERE site_id = 1 AND `key` = " . $pdo->quote($key)
            );
        }
    }

    // -------------------------------------------------------
    // Lock / unlock
    // -------------------------------------------------------

    public static function writeLock(string $version): void {
        file_put_contents(self::LOCK_FILE, json_encode([
            'version'      => $version,
            'installed_at' => date('c'),
        ], JSON_PRETTY_PRINT));
    }

    public static function getLockData(): array {
        if (!file_exists(self::LOCK_FILE)) return [];
        return json_decode(file_get_contents(self::LOCK_FILE), true) ?? [];
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    public static function quote(string $val): string {
        return "'" . addcslashes($val, "\\'") . "'";
    }

    public static function makePdo(string $host, string $name, string $user, string $pass, int $port = 3306): PDO {
        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
