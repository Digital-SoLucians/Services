<?php
defined('DS') || exit;

const SESSION_LIFETIME = 28800; // 8 hours: shorter than the CRM, this app holds identity data
const ROLES = [
    'citizen' => 'Resident',
    'officer' => 'Agency officer',
    'verifier' => 'Identity verifier',
    'admin' => 'Platform admin',
    'owner' => 'Owner',
];
const DISTRICTS = ['Anse la Raye', 'Canaries', 'Castries', 'Choiseul', 'Dennery', 'Gros Islet', 'Laborie', 'Micoud', 'Soufrière', 'Vieux Fort'];

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name('slusvc');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() === '' ? '/' : base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // idle timeout
    if (isset($_SESSION['seen']) && time() - (int) $_SESSION['seen'] > SESSION_LIFETIME) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function send_security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header('Cache-Control: no-store');
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($uid > 0 && installed()) {
        $u = one('SELECT * FROM users WHERE id = ?', [$uid]);
        // a password change elsewhere signs out every other session
        if ($u && (int) $u['active'] === 1 && hash_equals(session_fingerprint($u), (string) ($_SESSION['fp'] ?? ''))) {
            $user = $u;
        } else {
            unset($_SESSION['uid'], $_SESSION['fp']);
        }
    }
    return $user;
}

function session_fingerprint(array $u): string
{
    return hash_hmac('sha256', $u['id'] . '|' . $u['password_hash'], (string) (config()['key'] ?? ''));
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at'], $_SESSION['csrf']);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['fp'] = session_fingerprint($u);
    q('UPDATE users SET last_login_at = ?, totp_failures = 0, totp_locked_until = NULL WHERE id = ?', [now_utc(), $u['id']]);
    q('DELETE FROM login_attempts WHERE email = ? OR created_at < ?', [mb_strtolower((string) $u['email']), gmdate('Y-m-d H:i:s', time() - 86400)]);
    if (is_staff($u)) audit($u, 'login');
}

function logout_user(): void
{
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy();
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $next = route_path();
        redirect('login', $next !== '' ? ['next' => $next] : []);
    }
    if ((int) $u['must_change_password'] === 1 && route_path() !== 'account/security') {
        flash('Please choose your own password before you continue.', 'warn');
        redirect('account/security');
    }
    return $u;
}

/** Applying for services and linking ID need a confirmed email address. */
function require_verified_email(): array
{
    $u = require_login();
    if (!$u['email_verified_at']) {
        flash('Confirm your email address first. We sent you a 6-digit code.', 'warn');
        redirect('verify-email', ['next' => route_path()]);
    }
    return $u;
}

function is_staff(?array $u): bool
{
    return $u && in_array($u['role'], ['officer', 'verifier', 'admin', 'owner'], true);
}

function is_admin(?array $u): bool
{
    return $u && in_array($u['role'], ['admin', 'owner'], true);
}

function can_verify(?array $u): bool
{
    return $u && in_array($u['role'], ['verifier', 'admin', 'owner'], true);
}

/** Officers manage their own agency; admins manage every agency. */
function can_manage_agency(?array $u, ?int $agencyId): bool
{
    if (is_admin($u)) return true;
    return $u && $u['role'] === 'officer' && $agencyId && (int) $u['agency_id'] === $agencyId;
}

/** Staff pages: the right role, and two-step sign-in set up when the workspace requires it. */
function require_staff(array $roles = ['officer', 'verifier', 'admin', 'owner']): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        http_response_code(403);
        flash('That page is for government staff with the right access.', 'warn');
        redirect('');
    }
    if ($u['role'] === 'officer' && !$u['agency_id']) {
        flash('Your officer account isn’t linked to an agency yet. Ask a platform admin to choose it in People.', 'warn');
        redirect('');
    }
    if (setting('require_staff_2fa', '1') === '1' && !$u['totp_enabled']) {
        flash('Staff accounts must turn on two-step sign-in before using the console.', 'warn');
        redirect('account/security');
    }
    return $u;
}

function require_admin(): array
{
    return require_staff(['admin', 'owner']);
}

/* ---------- passwords and throttling ---------- */

function login_throttled(string $email): bool
{
    $since = gmdate('Y-m-d H:i:s', time() - 900);
    $byIp = (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $since]);
    $byEmail = (int) val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > ?', [mb_strtolower($email), $since]);
    return $byIp >= 20 || $byEmail >= 6;
}

function record_failed_login(string $email): void
{
    insert('login_attempts', ['ip' => client_ip(), 'email' => mb_strtolower(trim($email)), 'created_at' => now_utc()]);
}

function check_password(string $email, string $password): ?array
{
    $email = mb_strtolower(trim($email));
    $u = one('SELECT * FROM users WHERE email = ?', [$email]);
    // verify against a dummy hash when the user doesn't exist, so timing doesn't reveal accounts
    $hash = $u['password_hash'] ?? '$2y$10$aaw5AhA.AAK5WnkzXHO0Xuskvi.7aI/rvdPk1Rge3b5VtIb4a3EFW';
    $ok = password_verify($password, $hash);
    if (!$u || !$ok || (int) $u['active'] !== 1) {
        record_failed_login($email);
        return null;
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        $u = one('SELECT * FROM users WHERE id = ?', [$u['id']]);
    }
    // failed attempts are cleared only once sign-in completes (see login_user), so two-step codes stay throttled
    return $u;
}

function password_problem(string $pw, string $email = ''): ?string
{
    if (mb_strlen($pw) < 10) return 'Use at least 10 characters for your password.';
    if (mb_strlen($pw) > 200) return 'That password is too long.';
    if ($email !== '' && stripos($pw, strstr($email, '@', true) ?: "\0") !== false) return 'Don’t use your email address in your password.';
    if (in_array(mb_strtolower($pw), ['password123', '1234567890', 'qwertyuiop', 'stlucia123', 'saintlucia1'], true)) return 'That password is too easy to guess.';
    return null;
}

function temp_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 14; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4, 5) . '-' . substr($s, 9);
}

/* ---------- one-time tokens (email codes, password reset links) ---------- */

/** Returns the plaintext token. Email codes are 6 digits; links are 48 hex characters. */
function issue_token(int $userId, string $kind, int $ttl): string
{
    q('UPDATE user_tokens SET used_at = ? WHERE user_id = ? AND kind = ? AND used_at IS NULL', [now_utc(), $userId, $kind]);
    $plain = $kind === 'email' ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : bin2hex(random_bytes(24));
    insert('user_tokens', ['user_id' => $userId, 'kind' => $kind, 'token_hash' => lookup_hash($plain, 'token-' . $kind), 'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl), 'created_at' => now_utc()]);
    return $plain;
}

/** Checks and consumes a token. Codes allow 5 tries. */
function use_token(?int $userId, string $kind, string $plain): ?array
{
    $hash = lookup_hash(trim($plain), 'token-' . $kind);
    if ($userId) {
        $t = one('SELECT * FROM user_tokens WHERE user_id = ? AND kind = ? AND used_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$userId, $kind, now_utc()]);
        if (!$t) return null;
        if (!hash_equals($t['token_hash'], $hash)) {
            q('UPDATE user_tokens SET tries = tries + 1, used_at = IF(tries + 1 >= 5, ?, used_at) WHERE id = ?', [now_utc(), $t['id']]);
            return null;
        }
    } else {
        $t = one('SELECT * FROM user_tokens WHERE token_hash = ? AND kind = ? AND used_at IS NULL AND expires_at > ?', [$hash, $kind, now_utc()]);
        if (!$t) return null;
    }
    q('UPDATE user_tokens SET used_at = ? WHERE id = ?', [now_utc(), $t['id']]);
    return $t;
}

function send_email_code(array $u): void
{
    $code = issue_token((int) $u['id'], 'email', 1800);
    send_mail($u['email'], 'Your ' . app_name() . ' code: ' . $code, "Hi " . $u['name'] . ",\n\nYour confirmation code is: $code\n\nIt expires in 30 minutes. If you didn't create an account, ignore this email.\n\n— " . app_name());
}

/* ---------- two-step sign-in (TOTP, RFC 6238) ---------- */

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function base32_decode(string $s): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totp_at(string $secretB32, int $step): string
{
    $h = hash_hmac('sha1', pack('J', $step), base32_decode($secretB32), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the code for now ±30 s, once (replay protection via totp_last_step). */
function totp_check(array $u, string $code, ?string $secret = null): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $secret = $secret ?? decrypt_secret($u['totp_secret']);
    if ($secret === '') return false;
    $now = intdiv(time(), 30);
    for ($d = -1; $d <= 1; $d++) {
        $step = $now + $d;
        if ($step <= (int) $u['totp_last_step']) continue;
        if (hash_equals(totp_at($secret, $step), $code)) {
            q('UPDATE users SET totp_last_step = ? WHERE id = ?', [$step, $u['id']]);
            return true;
        }
    }
    return false;
}

/** One-time recovery codes for a lost phone: 8 codes, stored hashed. */
function new_recovery_codes(int $userId): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5));
    q('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode(array_map(fn($c) => lookup_hash($c, 'recovery'), $codes)), $userId]);
    return $codes;
}

function use_recovery_code(array $u, string $code): bool
{
    $hashes = json_decode((string) $u['recovery_codes'], true) ?: [];
    $h = lookup_hash(strtoupper(trim($code)), 'recovery');
    $i = array_search($h, $hashes, true);
    if ($i === false) return false;
    unset($hashes[$i]);
    q('UPDATE users SET recovery_codes = ? WHERE id = ?', [json_encode(array_values($hashes)), $u['id']]);
    return true;
}

function user_name(?int $id): string
{
    static $cache = [];
    if (!$id) return 'System';
    return $cache[$id] ??= (string) (val('SELECT name FROM users WHERE id = ?', [$id]) ?? 'Former user');
}
