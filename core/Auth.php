<?php
class Auth {
    private static ?array $user = null;

    // Granular permission definitions: key => [label, minimum role for default grant]
    const PERMISSIONS = [
        'manage_users'    => ['label' => 'Manage Users',              'default_role' => 'admin'],
        'manage_roles'    => ['label' => 'Manage Custom Roles',       'default_role' => 'admin'],
        'manage_settings' => ['label' => 'Manage Site Settings',      'default_role' => 'admin'],
        'manage_content'  => ['label' => 'Manage Pages & Blog Posts', 'default_role' => 'editor'],
        'manage_media'    => ['label' => 'Manage Media Library',      'default_role' => 'editor'],
        'view_analytics'  => ['label' => 'View Analytics',            'default_role' => 'editor'],
        'manage_contacts' => ['label' => 'Manage Contact Forms',      'default_role' => 'editor'],
        'manage_events'   => ['label' => 'Manage Events Calendar',    'default_role' => 'editor'],
    ];

    const ROLE_HIERARCHY = ['parishioner' => 0, 'author' => 1, 'editor' => 2, 'admin' => 3, 'super_admin' => 4];

    /**
     * @param bool $readOnly  When true and the visitor has no session or auth cookies,
     *                        skip session_start() entirely — anonymous visitors have
     *                        nothing stored in the session, so there is nothing to load.
     *                        This eliminates session file overhead for first-time visitors.
     *                        Always false for admin pages (need CSRF tokens, flash writes).
     *
     * NOTE: read_and_close was tried but breaks flash message deletion (the session closes
     *       before the unset() can be persisted).  Full session_start() is used whenever
     *       a session cookie already exists so flash reads and deletes work correctly.
     */
    public static function init(bool $readOnly = false): void {
        if (session_status() !== PHP_SESSION_NONE) return; // already started

        $sessionCookieName = defined('SESSION_NAME') ? SESSION_NAME : 'cms_session';

        // If this is a public (read-only) request and the visitor carries NO
        // session-related cookies, skip session_start() entirely.
        // Anonymous first-time visitors have empty sessions — there is nothing to read,
        // and skipping avoids the file-open overhead (which can be seconds on slow hosts).
        if ($readOnly
            && empty($_COOKIE[$sessionCookieName])
            && empty($_COOKIE['osf_remember'])) {
            return;
        }

        session_name($sessionCookieName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (APP_ENV === 'production'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start(); // full read-write so flash deletion persists correctly

        // Restore from remember-me cookie
        if (empty($_SESSION['user_id']) && !empty($_COOKIE['osf_remember'])) {
            $token = $_COOKIE['osf_remember'];
            $user  = Database::fetch("SELECT * FROM users WHERE remember_token = ?", [$token]);
            if ($user) {
                self::setSession($user);
            }
        }
    }

    public static function attempt(string $email, string $password, bool $remember = false): bool {
        $user = Database::fetch("SELECT * FROM users WHERE email = ?", [strtolower(trim($email))]);
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        self::setSession($user);
        Database::update('users', ['last_login' => date('Y-m-d H:i:s')], 'id = ?', [$user['id']]);

        if ($remember) {
            $token = bin2hex(random_bytes(32));
            Database::update('users', ['remember_token' => $token], 'id = ?', [$user['id']]);
            setcookie('osf_remember', $token, time() + 60 * 60 * 24 * 30, '/', '', APP_ENV === 'production', true);
        }
        return true;
    }

    public static function logout(): void {
        if (isset($_COOKIE['osf_remember'])) {
            Database::update('users', ['remember_token' => null], 'id = ?', [self::id()]);
            setcookie('osf_remember', '', time() - 3600, '/');
        }
        // Clear the page-cache bypass marker
        setcookie('cms_auth', '', time() - 3600, '/', '', APP_ENV === 'production', true);
        $_SESSION = [];
        session_destroy();
        self::$user = null;
    }

    public static function check(): bool {
        return !empty($_SESSION['user_id']);
    }

    public static function id(): ?int {
        return $_SESSION['user_id'] ?? null;
    }

    public static function user(): ?array {
        if (self::$user === null && self::check()) {
            self::$user = Database::fetch("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
            // Load custom role permissions if assigned
            if (self::$user && !empty(self::$user['custom_role_id'])) {
                try {
                    $rows = Database::fetchAll(
                        "SELECT permission FROM custom_role_permissions WHERE role_id = ?",
                        [self::$user['custom_role_id']]
                    );
                    self::$user['_custom_perms'] = array_column($rows, 'permission');
                    $cr = Database::fetch("SELECT base_role FROM custom_roles WHERE id = ?", [self::$user['custom_role_id']]);
                    self::$user['_custom_base_role'] = $cr['base_role'] ?? 'editor';
                } catch (\Throwable) {
                    self::$user['_custom_perms']     = [];
                    self::$user['_custom_base_role']  = null;
                }
            }
        }
        return self::$user;
    }

    public static function role(): string {
        return self::user()['role'] ?? '';
    }

    /** Hierarchy check — uses custom role's base_role when set. */
    public static function can(string $role): bool {
        $user = self::user();
        if (!$user) return false;
        if (($user['role'] ?? '') === 'super_admin') return true;
        $effective = $user['_custom_base_role'] ?? $user['role'] ?? '';
        return (self::ROLE_HIERARCHY[$effective] ?? 0) >= (self::ROLE_HIERARCHY[$role] ?? 99);
    }

    public static function requireLogin(string $redirect = '/admin/login'): void {
        if (!self::check()) {
            header('Location: ' . $redirect);
            exit;
        }
    }

    public static function requireRole(string $role): void {
        self::requireLogin();
        if (!self::can($role)) {
            http_response_code(403);
            die('Access denied.');
        }
    }

    public static function requirePermission(string $perm): void {
        self::requireLogin();
        if (!self::hasPermission($perm)) {
            http_response_code(403);
            die('Access denied.');
        }
    }

    private static function setSession(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        self::$user = $user;
        // Marker cookie used by PageCache to bypass caching for logged-in users.
        // This is NOT the session cookie — the session cookie is set on every visitor.
        // cms_auth is only set when someone actually logs in.
        setcookie('cms_auth', '1', 0, '/', '', APP_ENV === 'production', true);
    }

    public static function isSuperAdmin(): bool {
        return self::role() === 'super_admin';
    }

    // Super admins bypass all site-level permission checks.
    public static function hasPermission(string $perm): bool {
        $user = self::user();
        if (!$user) return false;
        if (self::isSuperAdmin()) return true;

        // Custom role: use its explicit permission list
        if (!empty($user['_custom_perms'])) {
            return in_array($perm, $user['_custom_perms'], true);
        }

        // Per-user JSON overrides
        $overrides = !empty($user['permissions']) ? json_decode($user['permissions'], true) : [];
        if (isset($overrides[$perm])) {
            return (bool) $overrides[$perm];
        }

        // Role hierarchy default
        $minRole  = self::PERMISSIONS[$perm]['default_role'] ?? 'admin';
        $minLevel = self::ROLE_HIERARCHY[$minRole] ?? 99;
        return (self::ROLE_HIERARCHY[$user['role']] ?? 0) >= $minLevel;
    }

    // Require super_admin role; used by network admin pages.
    public static function requireSuperAdmin(): void {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            http_response_code(403);
            die('Network admin access required.');
        }
    }

    public static function hashPassword(string $plain): string {
        return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function csrf(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(): void {
        $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            die('Invalid CSRF token.');
        }
    }
}
