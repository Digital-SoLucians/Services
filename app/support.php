<?php
defined('DS') || exit;

/* ---------- encryption (AES-256-GCM via OpenSSL) ---------- */

/** A separate key per purpose, derived from the install key in data/config.php. */
function subkey(string $purpose): string
{
    return hash_hmac('sha256', 'slu-services|' . $purpose, (string) (config()['key'] ?? ''), true);
}

function encrypt_bytes(string $plain, string $purpose): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', subkey($purpose), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return $iv . $tag . $ct;
}

function decrypt_bytes(string $blob, string $purpose): ?string
{
    if (strlen($blob) < 29) return null;
    $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', subkey($purpose), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
    return $plain === false ? null : $plain;
}

function encrypt_secret(string $plain): string
{
    return 'v2:' . base64_encode(encrypt_bytes($plain, 'secret'));
}

function decrypt_secret(?string $stored): string
{
    if (!$stored || !str_starts_with($stored, 'v2:')) return '';
    $raw = base64_decode(substr($stored, 3), true);
    return $raw === false ? '' : (decrypt_bytes($raw, 'secret') ?? '');
}

/** Keyed hash for looking things up without storing them in the clear (ID numbers, tokens). */
function lookup_hash(string $value, string $purpose = 'lookup'): string
{
    return hash_hmac('sha256', $value, subkey($purpose));
}

/* ---------- encrypted file vault (ID documents, request attachments, intake files) ---------- */

const UPLOAD_TYPES = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
];
const INTAKE_TYPES = [
    'text/csv' => 'csv', 'text/plain' => 'txt', 'text/markdown' => 'md', 'text/html' => 'html', 'application/json' => 'json',
    'application/pdf' => 'pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'image/jpeg' => 'jpg', 'image/png' => 'png',
];

function vault_dir(): string
{
    $dir = DATA_DIR . '/vault';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Encrypts bytes into the vault. Returns the files.id. */
function vault_store(string $bytes, string $mime, string $purpose, ?int $userId, ?string $originalName): int
{
    $name = bin2hex(random_bytes(24));
    if (file_put_contents(vault_dir() . '/' . $name . '.bin', encrypt_bytes($bytes, 'vault'), LOCK_EX) === false) {
        throw new RuntimeException('Couldn’t save the file. Check that the data folder is writable.');
    }
    return insert('files', ['user_id' => $userId, 'purpose' => $purpose, 'storage_name' => $name, 'mime' => $mime, 'size' => strlen($bytes),
        'sha256' => hash('sha256', $bytes), 'original_name' => $originalName !== null ? mb_substr(basename($originalName), 0, 255) : null, 'created_at' => now_utc()]);
}

function vault_read(array $file): ?string
{
    if ($file['deleted_at']) return null;
    $path = vault_dir() . '/' . $file['storage_name'] . '.bin';
    if (!preg_match('/^[a-f0-9]{48}$/', $file['storage_name']) || !is_file($path)) return null;
    return decrypt_bytes((string) file_get_contents($path), 'vault');
}

function vault_delete(int $fileId): void
{
    $f = one('SELECT * FROM files WHERE id = ?', [$fileId]);
    if (!$f || $f['deleted_at']) return;
    $path = vault_dir() . '/' . $f['storage_name'] . '.bin';
    if (is_file($path)) {
        // overwrite before unlinking so the ciphertext isn't left in place
        @file_put_contents($path, random_bytes(min(1048576, max(16, (int) filesize($path)))));
        @unlink($path);
    }
    q('UPDATE files SET deleted_at = ? WHERE id = ?', [now_utc(), $fileId]);
}

/**
 * Validates one uploaded file ($_FILES entry) against allowed types and size, then stores it.
 * Returns [file_id|null, error|null]. A missing optional file returns [null, null].
 */
function accept_upload(?array $f, string $purpose, ?int $userId, array $types = UPLOAD_TYPES, int $maxBytes = 8388608, bool $required = false): array
{
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, $required ? 'Choose a file to upload.' : null];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return [null, 'The upload didn’t finish. Please try again.'];
    if ($f['size'] > $maxBytes) return [null, 'That file is too large (the limit is ' . round($maxBytes / 1048576) . ' MB).'];
    if ($userId && in_array($purpose, ['request', 'id'], true)
        && (int) val('SELECT COUNT(*) FROM files WHERE user_id = ? AND created_at > ?', [$userId, gmdate('Y-m-d H:i:s', time() - 3600)]) >= 30) {
        return [null, 'You’ve uploaded a lot of files in the last hour. Try again later.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: 'application/octet-stream';
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    // office files and plain text are detected loosely by libmagic: trust the extension within the allowed set
    $byExt = array_search($ext, $types, true);
    if (!isset($types[$mime]) && $byExt !== false && in_array($mime, ['application/zip', 'application/octet-stream', 'text/plain', 'application/x-empty', 'text/x-Algol68', 'application/csv'], true)) $mime = $byExt;
    if (!isset($types[$mime])) return [null, 'That type of file isn’t accepted here.'];
    $bytes = (string) file_get_contents($f['tmp_name']);
    return [vault_store($bytes, $mime, $purpose, $userId, (string) $f['name']), null];
}

/* ---------- audit log ---------- */

function audit(?array $actor, string $action, ?string $target = null, ?int $targetId = null, string $detail = ''): void
{
    insert('audit_log', ['actor_id' => $actor['id'] ?? null, 'action' => $action, 'target' => $target, 'target_id' => $targetId,
        'detail' => mb_substr($detail, 0, 1000) ?: null, 'ip' => client_ip(), 'created_at' => now_utc()]);
}

/* ---------- notifications (in-app + email) ---------- */

function notify(int $userId, string $text, ?string $path = null, bool $email = true): void
{
    $u = one('SELECT id, email, name, notify_email, active FROM users WHERE id = ?', [$userId]);
    if (!$u || !$u['active']) return;
    insert('notifications', ['user_id' => $userId, 'text' => mb_substr($text, 0, 500), 'url' => $path, 'created_at' => now_utc()]);
    if ($email && $u['notify_email']) {
        $link = absolute_url($path ?? '');
        after_response(fn() => send_mail($u['email'], app_name() . ': ' . mb_strimwidth($text, 0, 90, '…'), "Hi " . $u['name'] . ",\n\n" . $text . "\n\n" . $link . "\n\n— " . app_name()));
    }
}

/** Every active officer of an agency (and its admins) gets the notification. */
function notify_agency(int $agencyId, string $text, string $path): void
{
    foreach (all("SELECT id FROM users WHERE active = 1 AND role = 'officer' AND agency_id = ?", [$agencyId]) as $u) notify((int) $u['id'], $text, $path);
}

function unread_count(int $userId): int
{
    return (int) val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
}

/* ---------- work after the response ---------- */

function after_response(callable $fn): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = [];
        register_shutdown_function(function () use (&$queue) {
            if (!$queue) return;
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            foreach ($queue as $job) {
                try {
                    $job();
                } catch (Throwable $e) {
                    log_error($e);
                }
            }
        });
    }
    $queue[] = $fn;
}

/* ---------- outgoing email ---------- */

function app_name(): string
{
    return installed() ? (string) setting('app_name', 'SLU Services') : 'SLU Services';
}

function send_mail(string $to, string $subject, string $text): bool
{
    if (preg_match('/[\r\n]/', $to . $subject)) return false;
    // test environments set GOVT_MAIL_LOG to read messages from a file instead of sending them
    $log = getenv('GOVT_MAIL_LOG');
    if ($log) {
        @file_put_contents($log, json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'at' => gmdate('c')]) . "\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    $host = preg_replace('/^services\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'digitalsolucians.com'));
    $from = (string) setting('mail_from', '');
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) $from = 'no-reply@' . preg_replace('/[^a-z0-9.-]/i', '', $host);
    $headers = [
        'From' => mb_encode_mimeheader(app_name(), 'UTF-8') . ' <' . $from . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => '8bit',
    ];
    return @mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, $headers);
}

/* ---------- HTTP client (URL intake, Claude) ---------- */

/** Public internet hosts only: URL intake must not reach the server's own network. */
/** Address ranges a fetched URL must never reach: private, loopback, link-local (cloud metadata), shared, test, multicast and reserved. */
const BLOCKED_IPV4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
    '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4'];

/** The public IPv4 address a host name resolves to, or null if any of its addresses is private or reserved (IPv6 isn't used). */
function public_ip_for(string $host): ?string
{
    $host = trim($host, '[]');
    if ($host === '') return null;
    $ips = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : (filter_var($host, FILTER_VALIDATE_IP) ? [] : (gethostbynamel($host) ?: []));
    if (getenv('GOVT_ALLOW_LOCAL_FETCH')) return $ips[0] ?? null;  // local tests only
    foreach ($ips as $ip) {
        $n = ip2long($ip);
        if ($n === false) return null;
        foreach (BLOCKED_IPV4 as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $mask = (~0 << (32 - (int) $bits)) & 0xFFFFFFFF;
            if (($n & $mask) === (ip2long($net) & $mask)) return null;
        }
    }
    return $ips[0] ?? null;
}

/** The public address to fetch a URL from, or null when it isn't safe. Only ports 80 and 443. */
function public_host_ip(string $url): ?string
{
    $p = parse_url($url);
    if (!in_array((int) ($p['port'] ?? 0), [0, 80, 443], true) && !getenv('GOVT_ALLOW_LOCAL_FETCH')) return null;
    return public_ip_for((string) ($p['host'] ?? ''));
}

/** Returns [status, body, error, content_type]. Only http(s). */
function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20, int $maxBytes = 15728640): array
{
    if (!preg_match('~^https?://~i', $url)) return [0, '', 'Only http and https addresses are allowed.', ''];
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'SLU-Services/' . APP_VERSION . ' (+https://services.digitalsolucians.com)',
        CURLOPT_MAXFILESIZE => $maxBytes,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $res = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = $res === false ? curl_error($ch) : '';
    curl_close($ch);
    return [$status, $res === false ? '' : (string) $res, $err, $type];
}

/** Fetches a public web page or document. Each hop is checked, and curl connects to the IP that was checked. */
function fetch_public_url(string $url): array
{
    for ($i = 0; $i < 4; $i++) {
        $ip = public_host_ip($url);
        if ($ip === null) return [0, '', 'That address isn’t a public website.', ''];
        $p = parse_url($url);
        $port = (int) ($p['port'] ?? (strtolower($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 15728640, CURLOPT_USERAGENT => 'SLU-Services/' . APP_VERSION,
            CURLOPT_RESOLVE => [trim($p['host'], '[]') . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)], CURLOPT_PROXY => '']);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($status >= 300 && $status < 400 && $next !== '') { $url = $next; continue; }
        return [$status, $body === false ? '' : (string) $body, $err, $type];
    }
    return [0, '', 'Too many redirects.', ''];
}

/* ---------- installable app ---------- */

function pwa_manifest(): void
{
    header('Content-Type: application/manifest+json; charset=utf-8');
    $base = base_path();
    echo json_encode([
        'name' => app_name(), 'short_name' => 'SLU Services', 'start_url' => $base . '/', 'scope' => $base . '/', 'display' => 'standalone',
        'background_color' => '#f4f2f0', 'theme_color' => '#1a1919',
        'icons' => [
            ['src' => $base . '/assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => $base . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => $base . '/assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => [['name' => 'My requests', 'url' => $base . '/requests'], ['name' => 'Find a service', 'url' => $base . '/services']],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function pwa_worker(): void
{
    header('Content-Type: text/javascript; charset=utf-8');
    header('Cache-Control: no-cache');
    // Pages are never cached (they hold personal data); only the offline notice is.
    echo <<<'JS'
const OFFLINE = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Offline</title>'
  + '<body style="font-family:system-ui,sans-serif;padding:48px 20px;max-width:480px;margin:auto;color:#0c0a08;background:#f4f2f0">'
  + '<h1 style="font-size:28px;font-weight:400">You’re offline</h1><p>SLU Services needs a connection. Check your internet and try again.</p></body>';
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', e => {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
JS;
}
