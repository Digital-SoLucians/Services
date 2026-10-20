<?php
defined('DS') || exit;

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = rtrim($dir, '/.');
    }
    return $base;
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    $query = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== []);
    if ($query) $u .= '?' . http_build_query($query);
    return $u;
}

/** Full URL for links in emails and the API: the address saved at setup, never the request's Host header. */
function absolute_url(string $path = ''): string
{
    $origin = installed() ? (string) (config()['url'] ?? '') : '';
    if ($origin === '') {
        $host = preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $origin = $host !== '' ? (is_https() ? 'https://' : 'http://') . $host : 'https://services.digitalsolucians.com';
    }
    return rtrim($origin, '/') . url($path);
}

function asset(string $path): string
{
    $file = ROOT_DIR . '/assets/' . $path;
    $v = is_file($file) ? substr(md5(APP_VERSION . filemtime($file)), 0, 8) : APP_VERSION;
    return url('assets/' . $path) . '?v=' . $v;
}

function route_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rawurldecode($uri);
    $base = base_path();
    if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
    return trim($uri, '/');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_api_request(): bool
{
    return str_starts_with(route_path(), 'api/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function redirect(string $path, array $query = []): void
{
    header('Location: ' . url($path, $query), true, 303);
    exit;
}

/** Only same-app relative paths, so ?next= can't send people elsewhere. */
function safe_next(string $next): string
{
    $next = ltrim($next, '/');
    return preg_match('~^[a-z0-9/_-]*(\?[a-z0-9=&_%-]*)?$~i', $next) && !str_starts_with($next, '/') ? $next : '';
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function qs(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($t) || $t === '' || !hash_equals(csrf_token(), $t)) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Session expired</title><body style="font-family:system-ui;padding:48px 20px;background:#f4f2f0">'
            . '<h1 style="font-size:22px;font-weight:400">Your session expired</h1><p><a href="' . h(url('')) . '">Reload</a> and try again.</p>';
        exit;
    }
}

/* ---------- formatting ---------- */

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function to_local(?string $utc): ?DateTimeImmutable
{
    if (!$utc) return null;
    try {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Exception $e) {
        return null;
    }
}

function fmt_datetime(?string $utc): string
{
    $d = to_local($utc);
    return $d ? $d->format('j M Y, g:i a') : '';
}

function fmt_date(?string $value): string
{
    if (!$value) return '';
    $d = strlen($value) > 10 ? to_local($value) : DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $d ? $d->format('j M Y') : (string) $value;
}

function time_ago(?string $utc): string
{
    $d = to_local($utc);
    if (!$d) return '';
    $s = time() - $d->getTimestamp();
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 86400 * 7) return floor($s / 86400) . ' d ago';
    return $d->format('j M Y');
}

function money(?float $amount): string
{
    if ($amount === null) return '';
    return 'EC$' . number_format($amount, floor($amount) == $amount ? 0 : 2);
}

function plural(int $n, string $one, string $many): string
{
    return number_format($n) . ' ' . ($n === 1 ? $one : $many);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $i = '';
    foreach (array_slice($parts, 0, 2) as $p) $i .= mb_strtoupper(mb_substr($p, 0, 1));
    return $i !== '' ? $i : '?';
}

function slugify(string $s, int $max = 80): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $s), '-'));
    return substr($s, 0, $max) ?: 'item';
}

function autolink(string $text): string
{
    $html = nl2br(h($text));
    return (string) preg_replace_callback('~\bhttps?://[^\s<]+~i', function ($m) {
        $u = rtrim(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), '.,);:');
        $trail = substr(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), strlen($u));
        return '<a href="' . h($u) . '" target="_blank" rel="noopener noreferrer">' . h($u) . '</a>' . h($trail);
    }, $html);
}

function log_error(Throwable $e): void
{
    $dir = DATA_DIR . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . str_replace(ROOT_DIR, '', $e->getFile()) . ':' . $e->getLine()
        . ' (' . ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ")\n";
    @file_put_contents($dir . '/error.log', $line, FILE_APPEND | LOCK_EX);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

/** Small line icons (24px grid, 1.5 stroke). */
function icon(string $name, string $class = 'i'): string
{
    static $paths = [
        'home' => '<path d="M4 11l8-7 8 7v9h-5v-6H9v6H4z"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/>',
        'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
        'inbox' => '<path d="M4 13l2.5-8h11L20 13v6H4z"/><path d="M4 13h5l1 2h4l1-2h5"/>',
        'id' => '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.2"/><path d="M5.8 16c.6-1.6 1.8-2.4 3.2-2.4s2.6.8 3.2 2.4M14.5 10H18M14.5 13H18"/>',
        'user' => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20c1.2-3.6 3.8-5.4 7-5.4s5.8 1.8 7 5.4"/>',
        'building' => '<path d="M5 20V5l7-2v17M12 8l7 2v10M3 20h18M8 8h1M8 11h1M8 14h1M15 13h1M15 16h1"/>',
        'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'back' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'x' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 2"/>',
        'pin' => '<path d="M12 21s6.5-6 6.5-11a6.5 6.5 0 10-13 0c0 5 6.5 11 6.5 11z"/><circle cx="12" cy="10" r="2.3"/>',
        'phone' => '<path d="M6.5 4h3l1.5 4-2 1.3a10 10 0 005.7 5.7l1.3-2 4 1.5v3a2 2 0 01-2.2 2A16 16 0 014.5 6.2 2 2 0 016.5 4z"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>',
        'link' => '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
        'coin' => '<circle cx="12" cy="12" r="8"/><path d="M14.5 9.5c-.5-1-1.4-1.5-2.5-1.5-1.5 0-2.5.8-2.5 2s1 1.7 2.5 2 2.5.8 2.5 2-1 2-2.5 2c-1.1 0-2-.5-2.5-1.5M12 6.5v11"/>',
        'doc' => '<path d="M7 3.5h7l4 4V20a.5.5 0 01-.5.5h-10.5A.5.5 0 016.5 20V4a.5.5 0 01.5-.5z"/><path d="M14 3.5V8h4M9.5 12h5M9.5 15h5M9.5 18h3"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>',
        'download' => '<path d="M12 4v12M7 11l5 5 5-5M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>',
        'bell' => '<path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15zM10 20a2 2 0 004 0"/>',
        'bookmark' => '<path d="M7 4h10v16l-5-3.5L7 20z"/>',
        'shield' => '<path d="M12 3l7 3v5.5c0 4.4-3 8-7 9.5-4-1.5-7-5.1-7-9.5V6z"/><path d="M9 12l2 2 4-4"/>',
        'lock' => '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2M12 18.5v2M3.5 12h2M18.5 12h2M6 6l1.4 1.4M16.6 16.6L18 18M6 18l1.4-1.4M16.6 7.4L18 6"/>',
        'logout' => '<path d="M14 4h4a1 1 0 011 1v14a1 1 0 01-1 1h-4M10 16l-4-4 4-4M6 12h10"/>',
        'chat' => '<path d="M4 6a2 2 0 012-2h12a2 2 0 012 2v9a2 2 0 01-2 2H10l-4 3.5V17H6a2 2 0 01-2-2z"/>',
        'list' => '<path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01"/>',
        'bolt' => '<path d="M13 3L5 13.5h6L10 21l8-10.5h-6z"/>',
        'key' => '<circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5L19 4M16 7l2 2M14 9l2 2"/>',
        'chart' => '<path d="M4 20h16M7 16v-5M12 16V7M17 16v-8"/>',
        'eye' => '<path d="M3 12s3.2-6 9-6 9 6 9 6-3.2 6-9 6-9-6-9-6z"/><circle cx="12" cy="12" r="2.6"/>',
        'external' => '<path d="M14 5h5v5M19 5l-8 8M18 14v5H5V6h5"/>',
        'calendar' => '<rect x="4" y="5.5" width="16" height="14.5" rx="2"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
        'heart' => '<path d="M12 19.5s-7.5-4.4-7.5-10A4.3 4.3 0 0112 7a4.3 4.3 0 017.5 2.5c0 5.6-7.5 10-7.5 10z"/>',
        'baby' => '<circle cx="12" cy="9" r="4.5"/><path d="M10 9h.01M14 9h.01M10.5 11c.9.6 2.1.6 3 0M7 20c.8-3 2.7-4.5 5-4.5s4.2 1.5 5 4.5"/>',
        'car' => '<path d="M5 16v3M19 16v3M3.5 16h17v-4l-2-5h-13l-2 5z"/><circle cx="7.5" cy="13" r=".5"/><circle cx="16.5" cy="13" r=".5"/>',
        'plane' => '<path d="M3 13l18-7-5 14-4-5-6-1z"/><path d="M12 15l9-9"/>',
        'briefcase' => '<rect x="3.5" y="7.5" width="17" height="12" rx="2"/><path d="M9 7.5V5.5a1 1 0 011-1h4a1 1 0 011 1v2M3.5 12.5h17"/>',
        'school' => '<path d="M3 9l9-4.5L21 9l-9 4.5z"/><path d="M7 11v4.5c1.4 1.4 3 2 5 2s3.6-.6 5-2V11M21 9v5"/>',
        'land' => '<path d="M3 19l6-9 4 5 3-3 5 7z"/><circle cx="16.5" cy="6.5" r="2"/>',
        'ring' => '<circle cx="12" cy="14.5" r="5.5"/><path d="M9.5 5h5l-1.5 3.5h-2z"/>',
        'leaf' => '<path d="M5 19c0-8 5-13 14-14 0 9-5 14-13 14z"/><path d="M5 19c3-4 6-6.5 9-8"/>',
        'scale' => '<path d="M12 4v16M7 20h10M5 8h14M5 8l-2.5 6a2.5 2.5 0 005 0zM19 8l-2.5 6a2.5 2.5 0 005 0z"/>',
        'hands' => '<path d="M4 13l4-4 3 1 3-3 3 1 3 4-6 6H9z"/><path d="M8 9l3 3"/>',
        'umbrella' => '<path d="M3.5 12a8.5 8.5 0 0117 0z"/><path d="M12 12v6.5a2 2 0 01-4 0"/>',
        'wave' => '<path d="M3 15c2 0 2-2 4.5-2S10 15 12 15s2-2 4.5-2 2.5 2 4.5 2M3 19c2 0 2-2 4.5-2s2.5 2 4.5 2 2-2 4.5-2 2.5 2 4.5 2M8 11V5l8 3"/>',
        'cross' => '<path d="M9.5 4h5v5.5H20v5h-5.5V20h-5v-5.5H4v-5h5.5z"/>',
        'tax' => '<rect x="5" y="3.5" width="14" height="17" rx="1.5"/><path d="M8.5 8h7M8.5 12h7M8.5 16h4"/>',
        'globe' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.5 3.5 5.3 3.5 8.5s-1 6-3.5 8.5c-2.5-2.5-3.5-5.3-3.5-8.5s1-6 3.5-8.5z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M5.3 18.7l1.4-1.4M17.3 6.7l1.4-1.4"/>',
        'drop' => '<path d="M12 3.5s6 6.4 6 10.5a6 6 0 01-12 0c0-4.1 6-10.5 6-10.5z"/>',
        'more' => '<circle cx="5.5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="18.5" cy="12" r="1"/>',
        'filter' => '<path d="M4 5h16l-6 7.5V19l-4-1.5v-5z"/>',
        'refresh' => '<path d="M19 8a7.5 7.5 0 10.5 6M19 4v4h-4"/>',
        'trash' => '<path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13"/>',
        'camera' => '<path d="M4 8.5h3.5L9 6h6l1.5 2.5H20V19H4z"/><circle cx="12" cy="13" r="3.5"/>',
    ];
    $p = $paths[$name] ?? $paths['more'];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
