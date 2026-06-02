<?php
/**
 * POST /api/cc-signup
 * Subscribes a visitor to the Constant Contact mailing list.
 */
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/config.php';
require_once BASE_PATH . '/core/Database.php';
require_once BASE_PATH . '/core/helpers.php';
require_once BASE_PATH . '/core/ConstantContact.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (setting('newsletter_signup_enabled', '1') !== '1') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Newsletter sign-up is disabled.']);
    exit;
}

// hCaptcha verification (optional — only if configured)
$hcSecret = setting('hcaptcha_secret_key', '');
if ($hcSecret) {
    $token = $_POST['h-captcha-response'] ?? '';
    if (!$token) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Please complete the CAPTCHA.']);
        exit;
    }
    $ch = curl_init('https://hcaptcha.com/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['secret' => $hcSecret, 'response' => $token]),
        CURLOPT_TIMEOUT        => 8,
    ]);
    $hcResult = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (empty($hcResult['success'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'CAPTCHA verification failed.']);
        exit;
    }
}

$email     = trim($_POST['email']      ?? '');
$firstName = trim($_POST['first_name'] ?? '');
$lastName  = trim($_POST['last_name']  ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']);
    exit;
}

$result = ConstantContact::subscribe($email, $firstName, $lastName);
http_response_code($result['ok'] ? 200 : 400);
echo json_encode($result);
