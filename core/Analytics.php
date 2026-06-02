<?php
class Analytics {

    public static function track(): void {
        if (!Database::setting('analytics_enabled', '1')) return;

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (self::isBot($ua)) return;

        // Skip admin visits when setting is on — Auth::init() has already started the session
        if (Database::setting('analytics_exclude_admins', '1') === '1') {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_start();
            }
            $isAdmin = !empty($_SESSION['user_id']);
            if ($isAdmin) return;
        }

        $url      = $_SERVER['REQUEST_URI'] ?? '/';
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $ip       = self::getIp();
        $ipHash   = hash('sha256', $ip . date('Y-m-d'));
        $device   = self::detectDevice($ua);
        $browser  = Database::setting('analytics_track_browser', '1') === '1' ? self::detectBrowser($ua) : null;
        $os       = Database::setting('analytics_track_os', '1')      === '1' ? self::detectOs($ua)      : null;

        // Session tracking — cookie-based, server-side session ID
        $sessionId = null;
        $isEntry   = 0;
        $sessionMinutes = (int) Database::setting('analytics_session_minutes', '30');
        $cookieName = 'cms_asid';
        if ($sessionMinutes > 0) {
            $now     = time();
            $ttl     = $sessionMinutes * 60;
            $existing = $_COOKIE[$cookieName] ?? null;
            if ($existing && preg_match('/^[a-f0-9]{64}$/', $existing)) {
                $sessionId = $existing;
                // Check if last view in this session was within TTL
                $lastView = Database::fetch(
                    "SELECT MAX(viewed_at) as lv FROM analytics_views
                     WHERE session_id = ? AND site_id = ?",
                    [$sessionId, Database::siteId()]
                );
                $lastTs = $lastView['lv'] ? strtotime($lastView['lv']) : 0;
                if ($now - $lastTs > $ttl) {
                    // Session expired — start a new one
                    $sessionId = null;
                }
            }
            if (!$sessionId) {
                $sessionId = hash('sha256', $ip . $ua . microtime(true) . random_bytes(8));
                $isEntry   = 1;
                if (!headers_sent()) {
                    setcookie($cookieName, $sessionId, [
                        'expires'  => $now + 86400 * 365,
                        'path'     => '/',
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }
            }
        }

        Database::insert('analytics_views', [
            'site_id'    => Database::siteId(),
            'url'        => substr($url, 0, 500),
            'referrer'   => $referrer ? substr($referrer, 0, 500) : null,
            'ip_hash'    => $ipHash,
            'user_agent' => substr($ua, 0, 500),
            'device'     => $device,
            'browser'    => $browser,
            'os'         => $os,
            'session_id' => $sessionId,
            'is_entry'   => $isEntry,
        ]);
    }

    public static function summary(string $from, string $to): array {
        $since = $from . ' 00:00:00';
        $until = $to   . ' 23:59:59';
        $sid   = Database::siteId();

        $total = Database::fetch(
            "SELECT COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors,
                    COUNT(DISTINCT session_id) as sessions
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'",
            [$sid, $since, $until]
        ) ?: ['views' => 0, 'visitors' => 0, 'sessions' => 0];

        // Bounce rate: sessions with only 1 page view
        $bounceData = Database::fetch(
            "SELECT
               COUNT(*) as total_sessions,
               SUM(CASE WHEN page_count = 1 THEN 1 ELSE 0 END) as bounce_sessions
             FROM (
               SELECT session_id, COUNT(*) as page_count
               FROM analytics_views
               WHERE site_id = ? AND viewed_at BETWEEN ? AND ?
                 AND device != 'bot' AND session_id IS NOT NULL
               GROUP BY session_id
             ) s",
            [$sid, $since, $until]
        ) ?: ['total_sessions' => 0, 'bounce_sessions' => 0];

        $bounceRate = $bounceData['total_sessions'] > 0
            ? round(($bounceData['bounce_sessions'] / $bounceData['total_sessions']) * 100, 1)
            : 0;

        // Avg session duration (seconds)
        $avgDuration = Database::fetch(
            "SELECT AVG(duration_sec) as avg_dur
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ?
               AND device != 'bot' AND duration_sec IS NOT NULL",
            [$sid, $since, $until]
        );

        $topPages = Database::fetchAll(
            "SELECT url, COUNT(*) as views FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY url ORDER BY views DESC LIMIT 15",
            [$sid, $since, $until]
        );

        $byDay = Database::fetchAll(
            "SELECT DATE(viewed_at) as day, COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY DATE(viewed_at) ORDER BY day ASC",
            [$sid, $since, $until]
        );

        $byDevice = Database::fetchAll(
            "SELECT device, COUNT(*) as views FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY device",
            [$sid, $since, $until]
        );

        $byBrowser = Database::fetchAll(
            "SELECT COALESCE(browser, 'Unknown') as browser, COUNT(*) as views
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY browser ORDER BY views DESC LIMIT 10",
            [$sid, $since, $until]
        );

        $byOs = Database::fetchAll(
            "SELECT COALESCE(os, 'Unknown') as os, COUNT(*) as views
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY os ORDER BY views DESC LIMIT 10",
            [$sid, $since, $until]
        );

        // Hourly distribution (0–23)
        $byHour = Database::fetchAll(
            "SELECT HOUR(viewed_at) as hour, COUNT(*) as views
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY HOUR(viewed_at) ORDER BY hour ASC",
            [$sid, $since, $until]
        );

        // Entry pages (first page per session)
        $entryPages = Database::fetchAll(
            "SELECT url, COUNT(*) as entries FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND is_entry = 1
             GROUP BY url ORDER BY entries DESC LIMIT 10",
            [$sid, $since, $until]
        );

        // Referrer domains (grouped)
        $referrers = Database::fetchAll(
            "SELECT
               CASE
                 WHEN referrer IS NULL THEN 'Direct'
                 WHEN referrer LIKE CONCAT('%', ?) THEN 'Internal'
                 ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(REPLACE(REPLACE(referrer, 'https://', ''), 'http://', ''), '/', 1), '?', 1)
               END as referrer_domain,
               COUNT(*) as views
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY referrer_domain ORDER BY views DESC LIMIT 15",
            [parse_url(SITE_URL, PHP_URL_HOST) ?: '', $sid, $since, $until]
        );

        return compact(
            'total', 'bounceRate', 'avgDuration',
            'topPages', 'byDay', 'byDevice',
            'byBrowser', 'byOs', 'byHour',
            'entryPages', 'referrers'
        );
    }

    public static function recordDuration(string $sessionId, string $url, int $seconds): void {
        if (!$sessionId || $seconds < 1 || $seconds > 7200) return;
        Database::query(
            "UPDATE analytics_views SET duration_sec = ?
             WHERE session_id = ? AND url = ? AND duration_sec IS NULL
             ORDER BY viewed_at DESC LIMIT 1",
            [$seconds, $sessionId, substr($url, 0, 500)]
        );
    }

    private static function isBot(string $ua): bool {
        if (empty($ua)) return true;
        $bots = ['bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit',
                 'wget', 'curl', 'python', 'java/', 'libwww', 'go-http', 'Googlebot',
                 'Bingbot', 'YandexBot', 'DuckDuckBot', 'Baiduspider', 'semrush', 'ahrefs',
                 'headlesschrome', 'phantomjs', 'selenium', 'lighthouse', 'nmap'];
        $lower = strtolower($ua);
        foreach ($bots as $b) {
            if (str_contains($lower, strtolower($b))) return true;
        }
        return false;
    }

    private static function detectDevice(string $ua): string {
        $ua = strtolower($ua);
        if (str_contains($ua, 'mobile') || str_contains($ua, 'android') || str_contains($ua, 'iphone')) {
            return 'mobile';
        }
        if (str_contains($ua, 'tablet') || str_contains($ua, 'ipad')) {
            return 'tablet';
        }
        return 'desktop';
    }

    private static function detectBrowser(string $ua): string {
        $ua = strtolower($ua);
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edge/'))    return 'Edge';
        if (str_contains($ua, 'opr/') || str_contains($ua, 'opera'))    return 'Opera';
        if (str_contains($ua, 'samsung'))                                return 'Samsung';
        if (str_contains($ua, 'firefox'))                               return 'Firefox';
        if (str_contains($ua, 'chrome') || str_contains($ua, 'crios')) return 'Chrome';
        if (str_contains($ua, 'safari') || str_contains($ua, 'fxios')) return 'Safari';
        if (str_contains($ua, 'msie') || str_contains($ua, 'trident'))  return 'IE';
        return 'Other';
    }

    private static function detectOs(string $ua): string {
        $ua = strtolower($ua);
        if (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) return 'iOS';
        if (str_contains($ua, 'android'))                 return 'Android';
        if (str_contains($ua, 'windows phone'))           return 'Windows Phone';
        if (str_contains($ua, 'windows'))                 return 'Windows';
        if (str_contains($ua, 'macintosh') || str_contains($ua, 'mac os x')) return 'macOS';
        if (str_contains($ua, 'linux'))                   return 'Linux';
        if (str_contains($ua, 'cros'))                    return 'ChromeOS';
        return 'Other';
    }

    private static function getIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
