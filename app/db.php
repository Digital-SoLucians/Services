<?php
defined('DS') || exit;

function config(): array
{
    static $c = null;
    if ($c === null) {
        $c = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : [];
        if (!is_array($c)) $c = [];
    }
    return $c;
}

function installed(): bool
{
    return !empty(config()['db']['name']);
}

function db_connect(array $c): PDO
{
    $dsn = 'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    if (!empty($c['port'])) $dsn .= ';port=' . (int) $c['port'];
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) $pdo = db_connect(config()['db']);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    q($sql, array_values($data));
    return (int) db()->lastInsertId();
}

function update(string $table, int $id, array $data): void
{
    if (!$data) return;
    $set = implode(',', array_map(fn($c) => '`' . $c . '` = ?', array_keys($data)));
    q('UPDATE `' . $table . '` SET ' . $set . ' WHERE id = ?', [...array_values($data), $id]);
}

function in_list(array $ids): string
{
    return implode(',', array_fill(0, max(1, count($ids)), '?'));
}

/* ---------- workspace settings ---------- */

function settings_all(bool $reload = false): array
{
    static $s = null;
    if ($s === null || $reload) {
        $s = [];
        foreach (all('SELECT k, v FROM settings') as $r) $s[$r['k']] = $r['v'];
    }
    return $s;
}

function setting(string $key, $default = null)
{
    $s = settings_all();
    return array_key_exists($key, $s) ? $s[$key] : $default;
}

function setting_json(string $key, $default = [])
{
    $v = setting($key);
    if ($v === null) return $default;
    $d = json_decode((string) $v, true);
    return $d ?? $default;
}

function set_setting(string $key, $value): void
{
    if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, (string) $value]);
    settings_all(true);
}



function write_config(array $cfg): void
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0750, true);
    $php = "<?php\n// SLU Services settings, written by the setup page. Keep this file private; it is never in git.\nreturn " . var_export($cfg, true) . ";\n";
    $tmp = CONFIG_FILE . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $php, LOCK_EX) === false) throw new RuntimeException('Could not write ' . CONFIG_FILE);
    @chmod($tmp, 0640);
    rename($tmp, CONFIG_FILE);
    if (function_exists('opcache_invalidate')) @opcache_invalidate(CONFIG_FILE, true);
}
