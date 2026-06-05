<?php
/**
 * MCCOOR CMS — Update Manager
 *
 * Handles:
 *  - Reading the current installed version (from install.lock)
 *  - Discovering and running pending database migrations
 *  - Git-based code updates (when the server has git)
 *  - Manual zip-file updates
 *  - Checking GitHub for new releases
 */
class Updater {

    const LOCK_FILE       = BASE_PATH . '/config/install.lock';
    const MIGRATIONS_DIR  = BASE_PATH . '/install/migrations';
    const GITHUB_REPO     = 'BishopGreer/mcc-redeemer-cms';
    const GITHUB_API      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    // -------------------------------------------------------
    // Version info
    // -------------------------------------------------------

    public static function installedVersion(): string {
        if (!file_exists(self::LOCK_FILE)) return 'unknown';
        $data = json_decode(file_get_contents(self::LOCK_FILE), true);
        return $data['version'] ?? 'unknown';
    }

    public static function updateLockVersion(string $version): void {
        $data = [];
        if (file_exists(self::LOCK_FILE)) {
            $data = json_decode(file_get_contents(self::LOCK_FILE), true) ?? [];
        }
        $data['version']    = $version;
        $data['updated_at'] = date('c');
        file_put_contents(self::LOCK_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }

    // -------------------------------------------------------
    // Migrations
    // -------------------------------------------------------

    /** Return all migration files in the migrations dir, sorted. */
    public static function allMigrations(): array {
        if (!is_dir(self::MIGRATIONS_DIR)) return [];
        $files = glob(self::MIGRATIONS_DIR . '/*.sql');
        sort($files);
        return array_map(fn($f) => [
            'file'    => $f,
            'version' => basename($f, '.sql'),
        ], $files);
    }

    /** Return which migrations have already been applied. */
    public static function appliedMigrations(): array {
        try {
            $rows = Database::fetchAll("SELECT version FROM migrations ORDER BY version ASC");
            return array_column($rows, 'version');
        } catch (\PDOException) {
            return [];
        }
    }

    /** Return migrations that have NOT yet been applied. */
    public static function pendingMigrations(): array {
        $applied = self::appliedMigrations();
        return array_filter(
            self::allMigrations(),
            fn($m) => !in_array($m['version'], $applied, true)
        );
    }

    /**
     * Migrations recorded in the DB that no longer have a file on disk.
     * Useful for detecting deleted migration files.
     */
    public static function orphanedMigrations(): array {
        $onDisk  = array_column(self::allMigrations(), 'version');
        $applied = self::appliedMigrations();
        return array_values(array_diff($applied, $onDisk));
    }

    /**
     * Remove a migration's applied record so it can be re-run.
     */
    public static function resetMigration(string $version): void {
        Database::delete('migrations', 'version = ?', [$version]);
    }

    /**
     * Clear all applied-migration records then rerun every migration file.
     * Returns same result format as runPendingMigrations().
     */
    public static function resetAndRerunAll(): array {
        Database::query("DELETE FROM migrations");
        return self::runPendingMigrations();
    }

    /**
     * Run all pending migrations in order.
     * Returns an array of results: [version => ['ok'=>bool, 'error'=>string|null]]
     */
    public static function runPendingMigrations(): array {
        $pending = self::pendingMigrations();
        $results = [];

        foreach ($pending as $m) {
            $sql = file_get_contents($m['file']);
            if ($sql === false) {
                $results[$m['version']] = ['ok' => false, 'error' => 'Could not read file.'];
                continue;
            }

            try {
                $pdo = Database::get();
                // Split on semicolons, strip leading comment lines from each chunk,
                // then discard anything that is empty after stripping.
                $statements = array_filter(
                    array_map(function(string $raw): string {
                        // Remove lines that are purely -- comments from the top of the chunk
                        $lines = explode("\n", $raw);
                        while ($lines && preg_match('/^\s*(--|$)/', $lines[0])) {
                            array_shift($lines);
                        }
                        return trim(implode("\n", $lines));
                    }, explode(';', $sql)),
                    fn($s) => $s !== ''
                );
                foreach ($statements as $stmt) {
                    if (!empty(trim($stmt))) {
                        $pdo->exec($stmt);
                    }
                }

                // Record as applied
                Database::insert('migrations', [
                    'version'    => $m['version'],
                    'applied_at' => date('Y-m-d H:i:s'),
                ]);

                $results[$m['version']] = ['ok' => true, 'error' => null];

            } catch (\PDOException $e) {
                $results[$m['version']] = ['ok' => false, 'error' => $e->getMessage()];
                break; // Stop on first failure
            }
        }

        return $results;
    }

    // -------------------------------------------------------
    // Git-based updates
    // -------------------------------------------------------

    public static function gitAvailable(): bool {
        $out = shell_exec('which git 2>/dev/null');
        return !empty(trim($out ?? ''));
    }

    public static function isGitRepo(): bool {
        return is_dir(BASE_PATH . '/.git');
    }

    public static function gitStatus(): array {
        if (!self::gitAvailable() || !self::isGitRepo()) {
            return ['available' => false];
        }

        $branch = trim(shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse --abbrev-ref HEAD 2>&1') ?? '');
        $hash   = trim(shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse --short HEAD 2>&1') ?? '');

        // Check if remote has newer commits
        shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git fetch origin 2>&1');
        $behind = trim(shell_exec(
            'cd ' . escapeshellarg(BASE_PATH) . ' && git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count 2>&1'
        ) ?? '0');

        return [
            'available' => true,
            'branch'    => $branch,
            'hash'      => $hash,
            'behind'    => (int)$behind,
        ];
    }

    /**
     * Pull latest code via git and run any new migrations.
     * Returns ['ok'=>bool, 'output'=>string, 'migrations'=>array]
     */
    public static function gitPull(): array {
        if (!self::gitAvailable() || !self::isGitRepo()) {
            return ['ok' => false, 'output' => 'Git is not available on this server.', 'migrations' => []];
        }

        $output = shell_exec(
            'cd ' . escapeshellarg(BASE_PATH) . ' && git pull --ff-only origin 2>&1'
        );

        $ok = str_contains($output, 'Already up to date') || str_contains($output, 'Fast-forward');

        $migResults = [];
        if ($ok) {
            $migResults = self::runPendingMigrations();
        }

        return [
            'ok'         => $ok,
            'output'     => trim($output),
            'migrations' => $migResults,
        ];
    }

    // -------------------------------------------------------
    // Manual ZIP update
    // -------------------------------------------------------

    /**
     * Apply a manually uploaded ZIP update package.
     *
     * The ZIP must have this layout:
     *   update/
     *     files/      — files to overwrite (relative to BASE_PATH)
     *     migrations/ — new .sql migration files
     *     version.txt — new version string
     *
     * Returns ['ok'=>bool, 'message'=>string, 'migrations'=>array]
     */
    public static function applyZipUpdate(string $zipPath): array {
        if (!extension_loaded('zip')) {
            return ['ok' => false, 'message' => 'PHP zip extension is not available.', 'migrations' => []];
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'Could not open ZIP file.', 'migrations' => []];
        }

        $tmpDir = sys_get_temp_dir() . '/osf_update_' . time();
        if (!mkdir($tmpDir, 0755, true)) {
            return ['ok' => false, 'message' => 'Could not create temp directory.', 'migrations' => []];
        }

        $zip->extractTo($tmpDir);
        $zip->close();

        $updateDir = $tmpDir . '/update';
        if (!is_dir($updateDir)) {
            self::rrmdir($tmpDir);
            return ['ok' => false, 'message' => 'Invalid update package: missing update/ directory.', 'migrations' => []];
        }

        // Read new version
        $newVersion = trim(file_get_contents($updateDir . '/version.txt') ?? '');

        // Copy updated files
        $filesDir = $updateDir . '/files';
        if (is_dir($filesDir)) {
            self::rcopy($filesDir, BASE_PATH);
        }

        // Copy new migrations
        $migrDir = $updateDir . '/migrations';
        if (is_dir($migrDir)) {
            foreach (glob($migrDir . '/*.sql') as $f) {
                $dest = self::MIGRATIONS_DIR . '/' . basename($f);
                if (!file_exists($dest)) {
                    copy($f, $dest);
                }
            }
        }

        self::rrmdir($tmpDir);

        // Run any new migrations
        $migResults = self::runPendingMigrations();

        // Update lock
        if ($newVersion) {
            self::updateLockVersion($newVersion);
        }

        return [
            'ok'         => true,
            'message'    => 'Update applied' . ($newVersion ? " (version {$newVersion})" : '') . '.',
            'migrations' => $migResults,
        ];
    }

    // -------------------------------------------------------
    // GitHub release check
    // -------------------------------------------------------

    /**
     * Check GitHub for the latest release.
     * Returns ['tag'=>string, 'url'=>string, 'notes'=>string, 'newer'=>bool] or ['error'=>string]
     */
    public static function checkGitHub(): array {
        if (!function_exists('curl_init')) {
            return ['error' => 'cURL is not available on this server.'];
        }

        $ch = curl_init(self::GITHUB_API);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_USERAGENT      => 'MCCOOR-CMS/' . self::installedVersion(),
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err || $code !== 200) {
            return ['error' => $err ?: "GitHub returned HTTP {$code}"];
        }

        $data = json_decode($body, true);
        if (!$data || empty($data['tag_name'])) {
            return ['error' => 'Could not parse GitHub response.'];
        }

        $latest    = ltrim($data['tag_name'], 'v');
        $installed = self::installedVersion();
        $newer     = version_compare($latest, $installed, '>');

        return [
            'tag'    => $data['tag_name'],
            'url'    => $data['html_url'] ?? '',
            'notes'  => $data['body'] ?? '',
            'assets' => $data['assets'] ?? [],
            'newer'  => $newer,
            'latest' => $latest,
        ];
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    private static function rcopy(string $src, string $dst): void {
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
                self::rcopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }

    private static function rrmdir(string $dir): void {
        if (!is_dir($dir)) return;
        $objects = scandir($dir);
        foreach ($objects as $o) {
            if ($o === '.' || $o === '..') continue;
            $path = $dir . '/' . $o;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
