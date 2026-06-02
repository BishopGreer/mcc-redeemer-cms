<?php
/**
 * Constant Contact API v3 integration.
 * Handles newsletter sign-up submissions only — this CMS does not send emails.
 */
class ConstantContact {

    private const API_BASE = 'https://api.cc.email/v3';

    /**
     * Subscribe an email address to the configured list.
     * Returns ['ok' => true] or ['ok' => false, 'error' => 'message'].
     */
    public static function subscribe(string $email, string $firstName = '', string $lastName = ''): array {
        $apiKey = Database::setting('constant_contact_api_key', '');
        $listId = Database::setting('constant_contact_list_id', '');

        if (!$apiKey || !$listId) {
            return ['ok' => false, 'error' => 'Constant Contact is not configured.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email address.'];
        }

        $payload = [
            'email_address'   => ['address' => $email, 'permission_to_send' => 'implicit'],
            'list_memberships' => [$listId],
        ];
        if ($firstName) $payload['first_name'] = substr($firstName, 0, 50);
        if ($lastName)  $payload['last_name']  = substr($lastName,  0, 50);

        // Try PUT (upsert) so existing contacts are just added to the list
        $result = self::request('POST', '/contacts/sign_up_form', $payload, $apiKey);

        if ($result['http_code'] >= 200 && $result['http_code'] < 300) {
            return ['ok' => true];
        }

        $msg = $result['body']['error_message']
            ?? ($result['body'][0]['error_message'] ?? 'Could not subscribe. Please try again.');
        return ['ok' => false, 'error' => $msg];
    }

    /**
     * Fetch the lists available for the API key (for the settings page picker).
     */
    public static function getLists(string $apiKey): array {
        if (!$apiKey) return [];
        $result = self::request('GET', '/contact_lists?include_count=true', null, $apiKey);
        if ($result['http_code'] !== 200) return [];
        return $result['body']['lists'] ?? [];
    }

    private static function request(string $method, string $endpoint, ?array $body, string $apiKey): array {
        $url = self::API_BASE . $endpoint;
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = $response ? (json_decode($response, true) ?? []) : [];
        return ['http_code' => $code, 'body' => $decoded];
    }
}
