<?php
class Analytics {

    public static function track(): void {
        if (!Database::setting('analytics_enabled', '1')) return;

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (self::isBot($ua)) return;

        $url      = $_SERVER['REQUEST_URI'] ?? '/';
        $referrer = $_SERVER['HTTP_REFERER'] ?? null;
        $ip       = self::getIp();
        $ipHash   = hash('sha256', $ip . date('Y-m-d'));
        $device   = self::detectDevice($ua);

        Database::insert('analytics_views', [
            'site_id'    => Database::siteId(),
            'url'        => substr($url, 0, 500),
            'referrer'   => $referrer ? substr($referrer, 0, 500) : null,
            'ip_hash'    => $ipHash,
            'user_agent' => substr($ua, 0, 500),
            'device'     => $device,
        ]);
    }

    public static function summary(string $from, string $to): array {
        $since = $from . ' 00:00:00';
        $until = $to   . ' 23:59:59';
        $sid   = Database::siteId();

        $total = Database::fetch(
            "SELECT COUNT(*) as views, COUNT(DISTINCT ip_hash) as visitors
             FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'",
            [$sid, $since, $until]
        );

        $topPages = Database::fetchAll(
            "SELECT url, COUNT(*) as views FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             GROUP BY url ORDER BY views DESC LIMIT 10",
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

        $referrers = Database::fetchAll(
            "SELECT referrer, COUNT(*) as views FROM analytics_views
             WHERE site_id = ? AND viewed_at BETWEEN ? AND ? AND device != 'bot'
             AND referrer IS NOT NULL
             GROUP BY referrer ORDER BY views DESC LIMIT 10",
            [$sid, $since, $until]
        );

        return compact('total', 'topPages', 'byDay', 'byDevice', 'referrers');
    }

    private static function isBot(string $ua): bool {
        if (empty($ua)) return true;
        $bots = ['bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit',
                 'wget', 'curl', 'python', 'java/', 'libwww', 'go-http', 'Googlebot',
                 'Bingbot', 'YandexBot', 'DuckDuckBot', 'Baiduspider', 'semrush', 'ahrefs'];
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

    private static function getIp(): string {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                return trim(explode(',', $_SERVER[$key])[0]);
            }
        }
        return '0.0.0.0';
    }
}
