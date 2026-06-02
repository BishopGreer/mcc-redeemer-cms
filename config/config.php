<?php
// MCC Our Redeemer CMS — configuration
//
// config.local.php is loaded FIRST so its values take priority.
// Constants defined here are only defaults used when config.local.php
// does not define them (e.g. on a fresh checkout before install).

// Use a temp variable so config.local.php can define BASE_PATH itself without collision.
$_basePath    = dirname(__DIR__);
$_localConfig = $_basePath . '/config/config.local.php';
if (file_exists($_localConfig)) {
    require $_localConfig;
}
// Only define BASE_PATH if config.local.php did not already define it.
if (!defined('BASE_PATH')) define('BASE_PATH', $_basePath);
unset($_basePath, $_localConfig);

// ---- Defaults (only applied if config.local.php did not define them) ----

if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'mcc_redeemer');
if (!defined('DB_USER'))    define('DB_USER',    'db_user');
if (!defined('DB_PASS'))    define('DB_PASS',    'db_password');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

if (!defined('UPLOAD_DIR')) define('UPLOAD_DIR', BASE_PATH . '/public/uploads');

if (!defined('MAX_UPLOAD_BYTES')) define('MAX_UPLOAD_BYTES', 20 * 1024 * 1024);

if (!defined('ALLOWED_MIME_TYPES')) define('ALLOWED_MIME_TYPES', [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'text/plain',
]);

if (!defined('SESSION_NAME'))  define('SESSION_NAME',  'cms_session');
if (!defined('THUMB_WIDTH'))   define('THUMB_WIDTH',   300);
if (!defined('THUMB_HEIGHT'))  define('THUMB_HEIGHT',  300);
if (!defined('APP_ENV'))       define('APP_ENV',       'production');

// -------------------------------------------------------
// Network / Multisite
//
// Set NETWORK_MODE = true and NETWORK_BASE_DOMAIN in config.local.php
// to enable subdomain-based multisite.
//
// Example config.local.php entries:
//   define('NETWORK_MODE', true);
//   define('NETWORK_BASE_DOMAIN', 'myocci.org');
// -------------------------------------------------------
if (!defined('NETWORK_MODE'))        define('NETWORK_MODE',        false);
if (!defined('NETWORK_BASE_DOMAIN')) define('NETWORK_BASE_DOMAIN', '');

// -------------------------------------------------------
// SITE_URL: auto-detected from the incoming request.
// In network mode this is the full URL of the current subdomain.
// Define SITE_URL in config.local.php to lock to one domain.
// -------------------------------------------------------
if (!defined('SITE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $scheme . '://' . $host);
}

// -------------------------------------------------------
// Subdomain / SITE_ID detection
//
// In network mode we inspect HTTP_HOST:
//   myocci.org           → SITE_ID = 1 (main site, no subdomain)
//   osfoc.myocci.org     → look up 'osfoc' in network_sites
//   www.myocci.org       → treated as main site
//
// SITE_ID defaults to 1 (single-site or main-site installs).
// SITE_SUBDOMAIN is '' for the main site or 'osfoc' for a subsite.
// IS_NETWORK_ADMIN is true only when on the main domain and the user
// navigates to /admin/network/… (checked at runtime, not here).
// -------------------------------------------------------
if (!defined('SITE_ID')) {
    if (NETWORK_MODE && NETWORK_BASE_DOMAIN !== '') {
        $requestHost = strtolower($_SERVER['HTTP_HOST'] ?? '');
        $baseDomain  = strtolower(NETWORK_BASE_DOMAIN);

        // Strip port if present
        $requestHost = preg_replace('/:\d+$/', '', $requestHost);

        if ($requestHost === $baseDomain || $requestHost === 'www.' . $baseDomain) {
            // Main domain — always site 1
            define('SITE_ID',        1);
            define('SITE_SUBDOMAIN', '');
        } else {
            // Attempt to extract subdomain
            $sub = null;
            if (str_ends_with($requestHost, '.' . $baseDomain)) {
                $sub = substr($requestHost, 0, strlen($requestHost) - strlen('.' . $baseDomain));
            }

            if ($sub !== null && preg_match('/^[a-z0-9][a-z0-9-]*$/', $sub)) {
                // Will be resolved to a real site_id after DB is available.
                // Store subdomain string now; Database::resolveSubdomain() finalises SITE_ID.
                define('SITE_ID',        0);   // 0 = unresolved; resolved in Database::init()
                define('SITE_SUBDOMAIN', $sub);
            } else {
                define('SITE_ID',        1);
                define('SITE_SUBDOMAIN', '');
            }
        }
    } else {
        define('SITE_ID',        1);
        define('SITE_SUBDOMAIN', '');
    }
}

if (!defined('UPLOAD_URL')) {
    define('UPLOAD_URL', SITE_URL . '/public/uploads');
}
