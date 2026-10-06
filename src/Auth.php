<?php
declare(strict_types=1);

/**
 * Single hardcoded admin account for this internal tool (no user table by
 * design — it's a one-admin gate, not multi-tenant auth). To change the
 * password, generate a new hash and replace the constant below:
 *   php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
 * Never put the plaintext password in source — only the hash.
 */
const ADMIN_USERNAME = 'admin';
const ADMIN_PASSWORD_HASH = '$2y$10$vTTtWu5AcdpqLwLfV0gUJuPgunAeqx3zPN2qv9EbCmc88tbYk5pL2';

const SESSION_IDLE_TIMEOUT = 7200; // 2 hours of inactivity
const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300; // 5 minutes

/**
 * Starts the session using a private save path under data/ (outside the web
 * root, mode 0700) instead of the server-wide /tmp — this box hosts several
 * unrelated sites, so keeping session files out of a shared temp dir avoids
 * leaking them to other tenants. Cookie is HttpOnly + Secure + SameSite=Strict.
 */
function auth_bootstrap_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $sessionDir = dirname(__DIR__) . '/data/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0700, true);
    }
    ini_set('session.save_path', $sessionDir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Strict');
    session_name('mopboard_sid');
    session_start();
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function is_logged_in(): bool
{
    auth_bootstrap_session();
    if (empty($_SESSION['user']) || !hash_equals(ADMIN_USERNAME, (string) $_SESSION['user'])) {
        return false;
    }
    $now = time();
    if (!empty($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        auth_logout();
        return false;
    }
    $_SESSION['last_activity'] = $now;
    return true;
}

function auth_logout(): void
{
    auth_bootstrap_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function csrf_token(): string
{
    auth_bootstrap_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(?string $token): bool
{
    auth_bootstrap_session();
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals((string) $_SESSION['csrf'], $token);
}

function login_attempts_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS mop_login_attempts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ip TEXT NOT NULL,
        success INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function is_ip_locked(PDO $pdo, string $ip): bool
{
    login_attempts_migrate($pdo);
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM mop_login_attempts
        WHERE ip = ? AND success = 0 AND created_at > datetime('now', ?)
    ");
    $stmt->execute([$ip, '-' . LOGIN_LOCKOUT_SECONDS . ' seconds']);
    return ((int) $stmt->fetchColumn()) >= LOGIN_MAX_ATTEMPTS;
}

function record_login_attempt(PDO $pdo, string $ip, bool $success): void
{
    login_attempts_migrate($pdo);
    $pdo->prepare("INSERT INTO mop_login_attempts (ip, success) VALUES (?, ?)")->execute([$ip, $success ? 1 : 0]);
    if ($success) {
        $pdo->prepare("DELETE FROM mop_login_attempts WHERE ip = ? AND success = 0")->execute([$ip]);
    }
}

/**
 * @return array{0: bool, 1: ?string} [success, error message]
 */
function attempt_login(PDO $pdo, string $username, string $password): array
{
    $ip = client_ip();
    if (is_ip_locked($pdo, $ip)) {
        return [false, 'Terlalu banyak percobaan gagal. Coba lagi dalam beberapa menit.'];
    }

    // password_verify always runs (even for a wrong username) so response
    // timing doesn't trivially reveal whether the username was correct.
    $ok = hash_equals(ADMIN_USERNAME, $username) && password_verify($password, ADMIN_PASSWORD_HASH);
    record_login_attempt($pdo, $ip, $ok);

    if (!$ok) {
        usleep(400000);
        return [false, 'Username atau password salah.'];
    }

    auth_bootstrap_session();
    session_regenerate_id(true);
    $_SESSION['user'] = ADMIN_USERNAME;
    $_SESSION['last_activity'] = time();
    unset($_SESSION['csrf']); // rotate CSRF token after privilege change
    return [true, null];
}

function require_login_api(): void
{
    if (!is_logged_in()) {
        json_error('Unauthorized. Silakan login kembali.', 401);
    }
}
