<?php
/**
 * Social media sharing — Facebook, BlueSky, Threads, Mastodon.
 * Each platform method returns ['success' => bool, 'post_id' => string|null, 'error' => string|null].
 */
class Social {

    // -------------------------------------------------------
    // Facebook Graph API
    // Requires: Page ID + long-lived Page Access Token
    // Permissions: pages_manage_posts, pages_read_engagement
    // -------------------------------------------------------
    public static function shareToFacebook(string $message, string $link, string $pageId, string $accessToken): array {
        if (!$pageId || !$accessToken) {
            return ['success' => false, 'post_id' => null, 'error' => 'Facebook credentials not configured.'];
        }

        // Exchange user/system-user token for a Page Access Token.
        // Posting to /{page-id}/feed requires a page token, not a user token.
        $tokenUrl  = "https://graph.facebook.com/v25.0/{$pageId}?fields=access_token&access_token=" . urlencode($accessToken);
        $tokenResp = @file_get_contents($tokenUrl, false, stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]));
        if ($tokenResp !== false) {
            $tokenData = json_decode($tokenResp, true);
            if (!empty($tokenData['access_token'])) {
                $accessToken = $tokenData['access_token'];
            }
        }

        $url     = "https://graph.facebook.com/v25.0/{$pageId}/feed";
        $payload = http_build_query(['message' => $message, 'link' => $link, 'access_token' => $accessToken]);

        $result  = self::httpPost($url, $payload, 'application/x-www-form-urlencoded');
        if ($result === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'HTTP request to Facebook failed.'];
        }

        $data = json_decode($result, true);
        if (!empty($data['id'])) {
            return ['success' => true, 'post_id' => $data['id'], 'error' => null];
        }

        $err = $data['error']['message'] ?? 'Unknown Facebook error.';
        return ['success' => false, 'post_id' => null, 'error' => $err];
    }

    // -------------------------------------------------------
    // BlueSky (AT Protocol)
    // Requires: handle (e.g. user.bsky.social) + App Password
    // App Passwords: bsky.app → Settings → App Passwords
    // -------------------------------------------------------
    public static function shareToBlueSky(string $text, string $handle, string $appPassword): array {
        if (!$handle || !$appPassword) {
            return ['success' => false, 'post_id' => null, 'error' => 'BlueSky credentials not configured.'];
        }

        // Normalize: strip leading @ from handle, strip whitespace from both
        $handle      = ltrim(trim($handle), '@');
        $appPassword = trim($appPassword);

        // 1. Create session
        $session = self::httpPost(
            'https://bsky.social/xrpc/com.atproto.server.createSession',
            json_encode(['identifier' => $handle, 'password' => $appPassword]),
            'application/json'
        );
        if ($session === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'BlueSky authentication request failed.'];
        }

        $sess = json_decode($session, true);
        if (empty($sess['accessJwt']) || empty($sess['did'])) {
            $err = $sess['message'] ?? 'BlueSky login failed.';
            return ['success' => false, 'post_id' => null, 'error' => $err];
        }

        $jwt = $sess['accessJwt'];
        $did = $sess['did'];

        // 2. Detect any URL in text and build facets
        $facets = self::bskyFacets($text);

        // 3. Enforce 300-grapheme limit — already handled by defaultMessage() but guard here too
        if (mb_strlen($text, 'UTF-8') > 300) {
            $text = mb_substr($text, 0, 299, 'UTF-8') . '…';
        }

        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        if (!empty($facets)) {
            $record['facets'] = $facets;
        }

        $body = json_encode([
            'repo'       => $did,
            'collection' => 'app.bsky.feed.post',
            'record'     => $record,
        ]);

        $result = self::httpPost(
            'https://bsky.social/xrpc/com.atproto.repo.createRecord',
            $body,
            'application/json',
            ["Authorization: Bearer {$jwt}"]
        );

        if ($result === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'BlueSky post request failed.'];
        }

        $data = json_decode($result, true);
        if (!empty($data['uri'])) {
            return ['success' => true, 'post_id' => $data['uri'], 'error' => null];
        }

        $err = $data['message'] ?? 'Unknown BlueSky error.';
        return ['success' => false, 'post_id' => null, 'error' => $err];
    }

    // -------------------------------------------------------
    // Threads (Meta)
    // Requires: Threads User ID + Access Token
    // Obtain via: developers.facebook.com → Threads API
    // -------------------------------------------------------
    public static function shareToThreads(string $text, string $userId, string $accessToken): array {
        if (!$userId || !$accessToken) {
            return ['success' => false, 'post_id' => null, 'error' => 'Threads credentials not configured.'];
        }

        $base = "https://graph.threads.net/v1.0";

        // Threads API requires all params — including access_token — as URL query parameters.
        // Step 1 — Create media container
        $qs1 = http_build_query(['media_type' => 'TEXT', 'text' => $text, 'access_token' => $accessToken]);
        $r1  = self::httpPost("{$base}/{$userId}/threads?{$qs1}", '', 'application/x-www-form-urlencoded');
        if ($r1 === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'Threads container creation request failed.'];
        }

        $d1 = json_decode($r1, true);
        if (empty($d1['id'])) {
            $err = $d1['error']['message'] ?? 'Threads container creation failed.';
            return ['success' => false, 'post_id' => null, 'error' => $err];
        }

        // Step 2 — Publish the container
        $qs2 = http_build_query(['creation_id' => $d1['id'], 'access_token' => $accessToken]);
        $r2  = self::httpPost("{$base}/{$userId}/threads_publish?{$qs2}", '', 'application/x-www-form-urlencoded');
        if ($r2 === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'Threads publish request failed.'];
        }

        $d2 = json_decode($r2, true);
        if (!empty($d2['id'])) {
            return ['success' => true, 'post_id' => $d2['id'], 'error' => null];
        }

        $err = $d2['error']['message'] ?? 'Unknown Threads error.';
        return ['success' => false, 'post_id' => null, 'error' => $err];
    }

    // -------------------------------------------------------
    // Mastodon
    // Requires: instance URL (e.g. mastodon.social) + Access Token
    // Obtain token: Your instance → Preferences → Development → New Application
    // -------------------------------------------------------
    public static function shareToMastodon(string $text, string $instanceUrl, string $accessToken): array {
        if (!$instanceUrl || !$accessToken) {
            return ['success' => false, 'post_id' => null, 'error' => 'Mastodon credentials not configured.'];
        }

        $instanceUrl = rtrim($instanceUrl, '/');
        $url         = "{$instanceUrl}/api/v1/statuses";

        // Mastodon limit is 500 characters (varies by server, we use 500 as the safe max)
        if (mb_strlen($text, 'UTF-8') > 500) {
            $text = mb_substr($text, 0, 497, 'UTF-8') . '...';
        }

        $result = self::httpPost(
            $url,
            json_encode(['status' => $text]),
            'application/json',
            ["Authorization: Bearer {$accessToken}"],
            30
        );

        if ($result === false) {
            return ['success' => false, 'post_id' => null, 'error' => 'Mastodon request failed.'];
        }

        $data = json_decode($result, true);
        if (!empty($data['id'])) {
            return ['success' => true, 'post_id' => $data['id'], 'error' => null];
        }

        $err = $data['error'] ?? 'Unknown Mastodon error.';
        return ['success' => false, 'post_id' => null, 'error' => $err];
    }

    // -------------------------------------------------------
    // Helper: HTTP POST via file_get_contents
    // Returns response body string, or false on failure.
    // -------------------------------------------------------
    private static function httpPost(string $url, string $body, string $contentType, array $extraHeaders = [], int $timeout = 15): string|false {
        $headers = array_merge([
            "Content-Type: {$contentType}",
            'Content-Length: ' . strlen($body),
        ], $extraHeaders);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => $timeout,
                'ignore_errors' => true, // capture 4xx/5xx bodies
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        return @file_get_contents($url, false, $ctx);
    }

    // -------------------------------------------------------
    // Helper: Build BlueSky facets array for URLs in text.
    // AT Proto requires byte offsets in UTF-8 encoding.
    // -------------------------------------------------------
    private static function bskyFacets(string $text): array {
        $facets  = [];
        $pattern = '#https?://[^\s\]\)>\"\']+#u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as [$url, $charOffset]) {
            // PREG_OFFSET_CAPTURE gives byte offset in UTF-8 string — good, that's what we need
            $byteStart = $charOffset;
            $byteEnd   = $byteStart + strlen($url);

            $facets[] = [
                'index'    => ['byteStart' => $byteStart, 'byteEnd' => $byteEnd],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
        }

        return $facets;
    }

    // -------------------------------------------------------
    // Compose the default share message for a blog post.
    // Returns a string suitable for all platforms.
    // Caller may trim/truncate per-platform as needed.
    // -------------------------------------------------------
    public static function defaultMessage(array $post, string $postUrl, int $maxLength = 0): string {
        $title   = trim($post['title']   ?? '');
        $excerpt = trim($post['excerpt'] ?? '');

        // Fall back to post content when excerpt is empty
        if (!$excerpt && !empty($post['content'])) {
            $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($post['content'])));
        }

        if ($maxLength > 0 && $excerpt) {
            // Overhead = title + two \n\n separators + URL
            $overhead = mb_strlen($title, 'UTF-8') + 4 + mb_strlen($postUrl, 'UTF-8');
            $room     = $maxLength - $overhead;
            if ($room >= 3) {
                if (mb_strlen($excerpt, 'UTF-8') > $room) {
                    $excerpt = mb_substr($excerpt, 0, $room - 1, 'UTF-8') . '…';
                }
            } else {
                $excerpt = '';
            }
        }

        $msg = $title;
        if ($excerpt) $msg .= "\n\n" . $excerpt;
        $msg .= "\n\n" . $postUrl;

        return $msg;
    }
}
