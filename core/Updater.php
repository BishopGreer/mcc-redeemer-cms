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

    const APP_VERSION     = '1.9.0';   // bump this with every release commit
    const LOCK_FILE       = BASE_PATH . '/config/install.lock';
    const MIGRATIONS_DIR  = BASE_PATH . '/install/migrations';
    const GITHUB_REPO     = 'BishopGreer/mcc-redeemer-cms';
    const GITHUB_API      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    // -------------------------------------------------------
    // Version info
    // -------------------------------------------------------

    /**
     * The version recorded in install.lock (what's actually running on this server).
     * Falls back to APP_VERSION when no lock file exists (e.g. dev environments).
     */
    public static function installedVersion(): string {
        if (!file_exists(self::LOCK_FILE)) return self::APP_VERSION;
        $data = json_decode(file_get_contents(self::LOCK_FILE), true);
        return $data['version'] ?? self::APP_VERSION;
    }

    /** The version baked into this copy of the code. */
    public static function codeVersion(): string {
        return self::APP_VERSION;
    }

    /**
     * The effective running version — the higher of the lock file and the code.
     * This is the right value to compare against GitHub releases, because code
     * may have been deployed manually (FTP/rsync) without updating the lock file.
     */
    public static function runningVersion(): string {
        $lock = self::installedVersion();
        return version_compare(self::APP_VERSION, $lock, '>') ? self::APP_VERSION : $lock;
    }

    /** Returns true when install.lock is behind APP_VERSION (needs a sync). */
    public static function lockNeedsSync(): bool {
        return version_compare(self::APP_VERSION, self::installedVersion(), '>');
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

    /** Return the current git remote URL for 'origin', or empty string. */
    public static function gitRemoteUrl(): string {
        if (!self::gitAvailable() || !self::isGitRepo()) return '';
        return trim(shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git remote get-url origin 2>/dev/null') ?? '');
    }

    /** True when origin already points at the canonical GitHub repo. */
    public static function gitRemoteIsGitHub(): bool {
        $url = self::gitRemoteUrl();
        return str_contains($url, 'github.com') && str_contains($url, self::GITHUB_REPO);
    }

    public static function gitStatus(): array {
        if (!self::gitAvailable() || !self::isGitRepo()) {
            return ['available' => false];
        }

        $branch    = trim(shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse --abbrev-ref HEAD 2>&1') ?? '');
        $hash      = trim(shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git rev-parse --short HEAD 2>&1') ?? '');
        $remoteUrl = self::gitRemoteUrl();
        $isGitHub  = self::gitRemoteIsGitHub();

        // Only fetch (and count behind) if remote is GitHub — otherwise fetch
        // would silently fail and report 0, which is misleading.
        $behind = 0;
        if ($isGitHub) {
            shell_exec('cd ' . escapeshellarg(BASE_PATH) . ' && git fetch origin 2>&1');
            $behind = (int)trim(shell_exec(
                'cd ' . escapeshellarg(BASE_PATH) . ' && git rev-list HEAD..origin/' . escapeshellarg($branch) . ' --count 2>&1'
            ) ?? '0');
        }

        return [
            'available' => true,
            'branch'    => $branch,
            'hash'      => $hash,
            'behind'    => $behind,
            'remoteUrl' => $remoteUrl,
            'isGitHub'  => $isGitHub,
        ];
    }

    /**
     * Point origin at GitHub and immediately pull.
     * Used when the server's git remote was never set up correctly.
     */
    public static function gitConnectAndPull(): array {
        if (!self::gitAvailable() || !self::isGitRepo()) {
            return ['ok' => false, 'output' => 'Git is not available on this server.', 'migrations' => []];
        }

        $repoUrl = 'https://github.com/' . self::GITHUB_REPO . '.git';
        $dir     = escapeshellarg(BASE_PATH);

        // Set the remote URL
        shell_exec("cd {$dir} && git remote set-url origin " . escapeshellarg($repoUrl) . ' 2>&1');

        // Fetch + pull
        shell_exec("cd {$dir} && git fetch origin 2>&1");
        $output = shell_exec("cd {$dir} && git pull --ff-only origin main 2>&1") ?? '';

        $ok = str_contains($output, 'Already up to date') || str_contains($output, 'Fast-forward');

        $migResults = [];
        if ($ok) {
            $migResults = self::runPendingMigrations();
            self::updateLockVersion(self::APP_VERSION);
        }

        return [
            'ok'         => $ok,
            'output'     => trim($output),
            'migrations' => $migResults,
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
            // Stamp the lock file so installedVersion() reflects the pulled code.
            self::updateLockVersion(self::APP_VERSION);
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
    // GitHub release — direct ZIP download & apply
    // -------------------------------------------------------

    /**
     * Download the source ZIP for a GitHub release and apply it directly.
     * Works on any server (no git permissions needed).
     *
     * GitHub source ZIPs are structured as:
     *   mcc-redeemer-cms-1.6.0/
     *     admin/
     *     core/
     *     ...
     *
     * We copy everything except config/, public/uploads/, and .git/,
     * then run pending migrations and stamp the lock file.
     *
     * @param  string $tag     e.g. 'v1.6.0'
     * @param  string $zipUrl  GitHub zipball_url or archive URL
     * @return array  ['ok'=>bool, 'message'=>string, 'migrations'=>array]
     */
    public static function downloadAndApplyGitHubRelease(string $tag, string $zipUrl): array
    {
        if (!extension_loaded('zip')) {
            return ['ok' => false, 'message' => 'PHP zip extension is not available.', 'migrations' => []];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => 'cURL is not available on this server.', 'migrations' => []];
        }

        // --- Download ZIP ---
        $tmpZip = sys_get_temp_dir() . '/mccoor_update_' . time() . '.zip';
        $fh     = fopen($tmpZip, 'wb');
        if (!$fh) {
            return ['ok' => false, 'message' => 'Cannot write to temp directory.', 'migrations' => []];
        }

        $ch = curl_init($zipUrl);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_FOLLOWLOCATION => true,   // GitHub redirects to S3
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'MCCOOR-CMS/' . self::APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($curlErr || $httpCode !== 200) {
            @unlink($tmpZip);
            return ['ok' => false, 'message' => "Download failed (HTTP {$httpCode}): {$curlErr}", 'migrations' => []];
        }

        // --- Extract ZIP ---
        $tmpDir = sys_get_temp_dir() . '/mccoor_update_' . time();
        if (!mkdir($tmpDir, 0755, true)) {
            @unlink($tmpZip);
            return ['ok' => false, 'message' => 'Could not create temp extraction directory.', 'migrations' => []];
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            self::rrmdir($tmpDir);
            return ['ok' => false, 'message' => 'Could not open downloaded ZIP.', 'migrations' => []];
        }
        $zip->extractTo($tmpDir);
        $zip->close();
        @unlink($tmpZip);

        // GitHub source ZIPs have a single top-level folder: repo-version/
        $entries = array_values(array_filter(
            glob($tmpDir . '/*'),
            fn($p) => is_dir($p)
        ));
        if (empty($entries)) {
            self::rrmdir($tmpDir);
            return ['ok' => false, 'message' => 'Unexpected ZIP structure — no root folder found.', 'migrations' => []];
        }
        $srcRoot = $entries[0]; // e.g. /tmp/mccoor_update_.../mcc-redeemer-cms-1.6.0

        // --- Directories to never overwrite ---
        $skip = ['config', '.git', 'public/uploads', 'cache'];

        // --- Copy files recursively, skipping protected dirs ---
        self::rcopySelective($srcRoot, BASE_PATH, $skip);

        // --- Copy new migration files into place ---
        $srcMig = $srcRoot . '/install/migrations';
        $dstMig = self::MIGRATIONS_DIR;
        if (is_dir($srcMig)) {
            foreach (glob($srcMig . '/*.sql') as $f) {
                $dest = $dstMig . '/' . basename($f);
                if (!file_exists($dest)) {
                    copy($f, $dest);
                }
            }
        }

        self::rrmdir($tmpDir);

        // --- Run pending migrations ---
        $migResults = self::runPendingMigrations();

        // --- Stamp the version ---
        $version = ltrim($tag, 'v');
        self::updateLockVersion($version);

        return [
            'ok'         => true,
            'message'    => "Updated to {$tag} successfully.",
            'migrations' => $migResults,
        ];
    }

    /**
     * Recursive copy that skips specified subdirectory names.
     * @param string[] $skip  directory basenames to skip (relative to $dst root)
     */
    private static function rcopySelective(string $src, string $dst, array $skip, string $relPath = ''): void
    {
        $dir = opendir($src);
        if (!$dir) return;
        while (($file = readdir($dir)) !== false) {
            if ($file === '.' || $file === '..') continue;
            $rel     = $relPath ? $relPath . '/' . $file : $file;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            // Skip protected dirs
            foreach ($skip as $s) {
                if ($rel === $s || str_starts_with($rel, $s . '/')) continue 2;
            }
            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
                self::rcopySelective($srcPath, $dstPath, $skip, $rel);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
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
        // Use the higher of the lock-file version and the code version so that
        // manually-deployed updates (FTP / rsync) don't falsely show as outdated.
        $running   = self::runningVersion();
        $newer     = version_compare($latest, $running, '>');

        // Build the GitHub archive ZIP URL (always available, no asset upload needed)
        $tag    = $data['tag_name'];
        $zipUrl = "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$tag}.zip";

        return [
            'tag'     => $tag,
            'url'     => $data['html_url'] ?? '',
            'zip_url' => $zipUrl,
            'notes'   => $data['body'] ?? '',
            'assets'  => $data['assets'] ?? [],
            'newer'   => $newer,
            'latest'  => $latest,
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
