<?php
/**
 * PageCache — simple file-based HTML cache for public pages.
 *
 * Behaviour:
 *   - Only caches GET requests from non-logged-in visitors.
 *   - Cache files live in BASE_PATH/cache/pages/ (auto-created).
 *   - Default TTL: 1 hour.  Cleared whenever content is saved via admin.
 *   - Cache key = md5(HTTP_HOST + REQUEST_URI).
 */
class PageCache
{
    private static string $dir  = '';
    private static int    $ttl  = 3600;  // seconds

    // ── Initialise ───────────────────────────────────────────────────────────

    public static function init(): void
    {
        self::$dir = BASE_PATH . '/cache/pages';
        if (!is_dir(self::$dir)) {
            @mkdir(self::$dir, 0755, true);
        }
    }

    // ── Cache key ────────────────────────────────────────────────────────────

    public static function key(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'default';
        $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
        return md5($host . '|' . $uri);
    }

    // ── Read ─────────────────────────────────────────────────────────────────

    /** Returns cached HTML string, or null if missing / expired. */
    public static function get(string $key): ?string
    {
        if (!self::$dir) return null;
        $file = self::$dir . '/' . $key . '.html';
        if (!file_exists($file)) return null;
        if (time() - filemtime($file) > self::$ttl) {
            @unlink($file);
            return null;
        }
        $html = @file_get_contents($file);
        return ($html !== false && $html !== '') ? $html : null;
    }

    // ── Write ────────────────────────────────────────────────────────────────

    public static function set(string $key, string $html): void
    {
        if (!self::$dir || !$html) return;
        @file_put_contents(self::$dir . '/' . $key . '.html', $html, LOCK_EX);
    }

    // ── Invalidation ─────────────────────────────────────────────────────────

    /** Delete all cached pages (call after any content save). */
    public static function clearAll(): void
    {
        if (!self::$dir || !is_dir(self::$dir)) return;
        foreach (glob(self::$dir . '/*.html') ?: [] as $f) {
            @unlink($f);
        }
    }

    // ── Eligibility check ────────────────────────────────────────────────────

    /**
     * True if this request is eligible for page caching:
     *   - GET only
     *   - No cms_auth cookie (only set on actual login, not the session cookie which
     *     PHP sets for every visitor — using the session cookie here would break the
     *     cache for all repeat anonymous visitors)
     *   - Not an admin, API, or form-submission URL
     */
    public static function shouldCache(string $uri): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        // cms_auth is explicitly set only when a user logs in.
        // The session cookie (cms_session) is set on every visitor and must NOT be used here.
        if (!empty($_COOKIE['cms_auth'])) return false;
        // Also bypass if the remember-me cookie is present (user was previously logged in)
        if (!empty($_COOKIE['osf_remember'])) return false;

        // Paths that must never be cached
        foreach (['/admin', '/api/', '/contact', '/prayer', '/forms', '/register'] as $excluded) {
            if (str_starts_with($uri, $excluded)) return false;
        }

        return true;
    }
}
