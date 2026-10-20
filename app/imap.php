<?php
defined('DS') || exit;

// A small IMAP client and MIME parser in plain PHP (no imap extension needed). SLU Services uses it
// to read the government intake mailbox: each new email and its attachments become intake items.

class ImapClient
{
    private $s;
    private int $tag = 0;

    public function __construct(string $host, int $port, string $security, int $timeout = 20)
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $errno = 0;
        $errstr = '';
        $this->s = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) throw new RuntimeException('Couldn’t connect to ' . $host . ':' . $port . ($errstr ? ' (' . $errstr . ')' : '') . '. Check the server name, port and security.');
        stream_set_timeout($this->s, $timeout);
        $greeting = $this->line();
        if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) throw new RuntimeException('That server didn’t answer like an IMAP server.');
        if ($security === 'starttls') {
            $this->cmd('STARTTLS');
            if (!@stream_socket_enable_crypto($this->s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed.');
        }
    }

    public function __destruct()
    {
        if ($this->s) {
            @fwrite($this->s, 'Z LOGOUT' . "\r\n");
            @fclose($this->s);
        }
    }

    private function line(): string
    {
        $l = fgets($this->s);
        if ($l === false) {
            $meta = stream_get_meta_data($this->s);
            throw new RuntimeException($meta['timed_out'] ? 'The mail server stopped responding.' : 'The mail server closed the connection.');
        }
        return $l;
    }

    private function read(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->s, min(65536, $n - strlen($buf)));
            if ($chunk === false || $chunk === '') throw new RuntimeException('The mail server stopped mid-message.');
            $buf .= $chunk;
        }
        return $buf;
    }

    public static function quote(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }

    /** Runs a command. Returns untagged responses: each ['text' => line with \x00Ln\x00 for literals, 'lits' => [...]]. */
    public function cmd(string $command): array
    {
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, $tag . ' ' . $command . "\r\n");
        $out = [];
        while (true) {
            $line = $this->line();
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    $msg = trim(preg_replace('/^' . $tag . ' (NO|BAD)\s*(\[[^\]]*\]\s*)?/i', '', $line));
                    throw new ImapCommandError($msg !== '' ? $msg : 'The mail server refused: ' . strtok($command, ' '));
                }
                return $out;
            }
            if (str_starts_with($line, '+')) continue;
            $text = $line;
            $lits = [];
            while (preg_match('/\{(\d+)\}\r\n$/', $text, $m)) {
                $lits[] = $this->read((int) $m[1]);
                $text = substr($text, 0, -strlen($m[0])) . "\x00L" . (count($lits) - 1) . "\x00" . $this->line();
            }
            $out[] = ['text' => $text, 'lits' => $lits];
        }
    }

    public function login(string $user, string $pass): void
    {
        try {
            $this->cmd('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
        } catch (ImapCommandError $e) {
            throw new RuntimeException('The mail server rejected the username or password' . ($e->getMessage() ? ' (' . $e->getMessage() . ')' : '') . '.');
        }
    }

    /** Folder names; the sent folder is found by its \Sent flag or a common name. */
    public function folders(): array
    {
        $out = [];
        foreach ($this->cmd('LIST "" "*"') as $r) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"(?:[^"\\\\]|\\\\.)*"|NIL) (.*)$/s', rtrim($r['text']), $m)) continue;
            $name = trim($m[2]);
            if (preg_match('/^\x00L(\d+)\x00$/', $name, $lm)) $name = $r['lits'][(int) $lm[1]];
            elseif (preg_match('/^"(.*)"$/s', $name, $qm)) $name = stripcslashes($qm[1]);
            $out[] = ['name' => $name, 'flags' => strtolower($m[1])];
        }
        return $out;
    }

    public function sentFolder(): ?string
    {
        $folders = $this->folders();
        foreach ($folders as $f) if (str_contains($f['flags'], '\\sent')) return $f['name'];
        foreach ($folders as $f) if (preg_match('~^(INBOX[./])?(Sent|Sent Items|Sent Messages|Sent Mail)$|^\[Gmail\]/Sent Mail$~i', $f['name'])) return $f['name'];
        return null;
    }

    /** Saves a message to a folder (a copy of sent mail in Sent). */
    public function append(string $folder, string $raw): void
    {
        $raw = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
        $tag = 'A' . (++$this->tag);
        fwrite($this->s, $tag . ' APPEND ' . self::quote($folder) . ' (\\Seen) {' . strlen($raw) . "}\r\n");
        $l = $this->line();
        if (!str_starts_with($l, '+')) throw new ImapCommandError('The mail server wouldn’t save to ' . $folder . '.');
        fwrite($this->s, $raw . "\r\n");
        while (true) {
            $l = $this->line();
            if (!str_starts_with($l, $tag . ' ')) continue;
            if (!preg_match('/^' . $tag . ' OK/i', $l)) throw new ImapCommandError(trim(substr($l, strlen($tag) + 1)));
            return;
        }
    }

    /** Selects a folder; returns [uidvalidity, uidnext]. */
    public function select(string $folder): array
    {
        $validity = 0;
        $next = 0;
        foreach ($this->cmd('EXAMINE ' . self::quote($folder)) as $r) {
            if (preg_match('/UIDVALIDITY (\d+)/i', $r['text'], $m)) $validity = (int) $m[1];
            if (preg_match('/UIDNEXT (\d+)/i', $r['text'], $m)) $next = (int) $m[1];
        }
        return [$validity, $next];
    }

    public function search(string $criteria): array
    {
        $uids = [];
        foreach ($this->cmd('UID SEARCH ' . $criteria) as $r) {
            if (preg_match('/^\* SEARCH ?(.*)$/i', trim($r['text']), $m)) {
                foreach (preg_split('/\s+/', trim($m[1])) as $u) if (ctype_digit($u)) $uids[] = (int) $u;
            }
        }
        sort($uids);
        return $uids;
    }

    /** Opens a folder read-write (needed to mark messages as read). */
    public function selectWrite(string $folder): void
    {
        $this->cmd('SELECT ' . self::quote($folder));
    }

    public function setSeen(int $uid): void
    {
        $this->cmd('UID STORE ' . $uid . ' +FLAGS (\\Seen)');
    }

    /** The whole raw message (headers and body), up to $max bytes. */
    public function fetchRaw(int $uid, int $max = 15728640): ?string
    {
        foreach ($this->cmd('UID FETCH ' . $uid . ' (UID BODY.PEEK[]<0.' . $max . '>)') as $r) {
            if (preg_match('/BODY\[\](?:<0>)? \x00L(\d+)\x00/i', $r['text'], $m)) return $r['lits'][(int) $m[1]];
        }
        return null;
    }

    /** Header and the first part of the body for one message. */
    public function fetch(int $uid, int $maxBody = 65536): ?array
    {
        foreach ($this->cmd('UID FETCH ' . $uid . ' (UID INTERNALDATE BODY.PEEK[HEADER] BODY.PEEK[TEXT]<0.' . $maxBody . '>)') as $r) {
            $t = $r['text'];
            if (!preg_match('/FETCH \(/i', $t)) continue;
            $get = function (string $re) use ($t, $r) {
                if (preg_match('/' . $re . ' \x00L(\d+)\x00/i', $t, $m)) return $r['lits'][(int) $m[1]];
                if (preg_match('/' . $re . ' "((?:[^"\\\\]|\\\\.)*)"/i', $t, $m)) return stripcslashes($m[1]);
                return '';
            };
            preg_match('/INTERNALDATE "([^"]+)"/i', $t, $dm);
            return ['uid' => $uid, 'date' => $dm[1] ?? null, 'header' => $get('BODY\[HEADER\]'), 'body' => $get('BODY\[TEXT\](?:<0>)?')];
        }
        return null;
    }
}

class ImapCommandError extends RuntimeException
{
}

/* ---------- MIME ---------- */

function mime_headers(string $raw): array
{
    $raw = preg_replace("/\r?\n[ \t]+/", ' ', str_replace("\r\n", "\n", $raw));
    $h = [];
    foreach (explode("\n", $raw) as $line) {
        if (!preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) continue;
        $k = strtolower($m[1]);
        if (!isset($h[$k])) $h[$k] = trim($m[2]);
    }
    return $h;
}

function mime_decode_words(string $v): string
{
    if (!str_contains($v, '=?')) return to_utf8($v, 'UTF-8');
    $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($d === false || $d === '') $d = mb_decode_mimeheader($v);
    return to_utf8($d, 'UTF-8');
}

function to_utf8(string $s, string $charset): string
{
    $charset = strtoupper(trim($charset, " \"'")) ?: 'UTF-8';
    if (in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
    $out = @mb_convert_encoding($s, 'UTF-8', $charset);
    if ($out === false || $out === '') $out = @iconv($charset, 'UTF-8//IGNORE', $s);
    return $out === false ? mb_convert_encoding($s, 'UTF-8', 'Windows-1252') : $out;
}

function mime_param(string $header, string $name): string
{
    if (preg_match('/' . preg_quote($name, '/') . '\*?="?([^";]+)"?/i', $header, $m)) {
        $v = $m[1];
        if (preg_match("/^[A-Za-z0-9-]+''(.*)$/", $v, $mm)) $v = rawurldecode($mm[1]);
        return $v;
    }
    return '';
}

/** Splits an address header into [['email' => …, 'name' => …], …]. */
function parse_addresses(string $v): array
{
    $v = mime_decode_words($v);
    $out = [];
    // split on commas outside quotes and angle brackets
    $parts = [];
    $buf = '';
    $q = false;
    $a = 0;
    foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if ($ch === '"') $q = !$q;
        if (!$q && $ch === '<') $a++;
        if (!$q && $ch === '>') $a = max(0, $a - 1);
        if ($ch === ',' && !$q && !$a) { $parts[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    $parts[] = $buf;
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (preg_match('/^(.*)<([^>]+)>$/', $p, $m)) { $name = trim($m[1], " \"'"); $email = trim($m[2]); }
        else { $name = ''; $email = trim($p, " <>"); }
        $email = mb_strtolower($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $out[] = ['email' => $email, 'name' => mb_substr($name, 0, 190)];
    }
    return $out;
}

function mime_decode_body(string $body, string $cte): string
{
    $cte = strtolower(trim($cte));
    if ($cte === 'base64') return (string) base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body));
    if ($cte === 'quoted-printable') return quoted_printable_decode($body);
    return $body;
}

function html_to_text(string $html): string
{
    $html = preg_replace('~<(script|style|head)[^>]*>.*?</\1>~is', '', $html);
    $html = preg_replace('~<br\s*/?>|</(p|div|tr|li|h[1-6])>~i', "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return preg_replace("/[ \t]+/", ' ', $text);
}

/** The readable text of a message (prefers text/plain, falls back to stripped HTML). */
function mime_text(array $headers, string $body, int $depth = 0): string
{
    $ct = $headers['content-type'] ?? 'text/plain';
    $cte = $headers['content-transfer-encoding'] ?? '';
    if ($depth < 5 && stripos($ct, 'multipart/') === 0) {
        $boundary = mime_param($ct, 'boundary');
        if ($boundary === '') return '';
        $parts = preg_split('/\r?\n--' . preg_quote($boundary, '/') . '(?:--)?\s*\r?\n?/', "\n" . $body);
        $plain = $html = null;
        foreach ($parts as $part) {
            if (trim($part) === '') continue;
            $split = preg_split("/\r?\n\r?\n/", ltrim($part, "\r\n"), 2);
            if (count($split) < 2) continue;
            $ph = mime_headers($split[0]);
            if (stripos($ph['content-disposition'] ?? '', 'attachment') === 0) continue;
            $pct = strtolower($ph['content-type'] ?? 'text/plain');
            if (str_starts_with($pct, 'multipart/')) {
                $t = mime_text($ph, $split[1], $depth + 1);
                if ($t !== '' && $plain === null) $plain = $t;
            } elseif (str_starts_with($pct, 'text/plain') && $plain === null) {
                $plain = to_utf8(mime_decode_body($split[1], $ph['content-transfer-encoding'] ?? ''), mime_param($pct, 'charset'));
            } elseif (str_starts_with($pct, 'text/html') && $html === null) {
                $html = html_to_text(to_utf8(mime_decode_body($split[1], $ph['content-transfer-encoding'] ?? ''), mime_param($pct, 'charset')));
            }
        }
        return trim((string) ($plain ?? $html ?? ''));
    }
    $text = to_utf8(mime_decode_body($body, $cte), mime_param($ct, 'charset'));
    if (stripos($ct, 'text/html') === 0) $text = html_to_text($text);
    elseif (stripos($ct, 'text/') !== 0) return '';
    return trim($text);
}

/**
 * Walks a MIME message. Returns ['text' => readable body, 'attachments' => [[name, type, data]]].
 */
function mime_walk(string $raw, int $depth = 0): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    [$head, $body] = array_pad(explode("\n\n", $raw, 2), 2, '');
    $h = mime_headers($head);
    $ct = $h['content-type'] ?? 'text/plain';
    $cte = $h['content-transfer-encoding'] ?? '';
    $out = ['text' => '', 'attachments' => []];
    if ($depth < 6 && stripos($ct, 'multipart/') === 0) {
        $b = mime_param($ct, 'boundary');
        if ($b === '') return $out;
        $alt = stripos($ct, 'multipart/alternative') === 0;
        foreach (array_slice(explode('--' . $b, $body), 1) as $part) {
            if (str_starts_with(ltrim($part), '--')) break;
            $sub = mime_walk(ltrim($part, "\n"), $depth + 1);
            if ($alt) {
                // prefer the plain-text alternative; fall back to the first one with text
                $isPlain = (bool) preg_match('/^content-type:\s*text\/plain/im', substr(ltrim($part, "\n"), 0, 500));
                if ($sub['text'] !== '' && ($out['text'] === '' || $isPlain)) $out['text'] = $sub['text'];
            } else {
                $out['text'] .= ($out['text'] !== '' && $sub['text'] !== '' ? "\n\n" : '') . $sub['text'];
            }
            $out['attachments'] = array_merge($out['attachments'], $sub['attachments']);
        }
        return $out;
    }
    $disp = $h['content-disposition'] ?? '';
    $name = mime_decode_words(mime_param($disp, 'filename') ?: mime_param($ct, 'name'));
    $data = mime_decode_body($body, $cte);
    if ($name !== '' || stripos($disp, 'attachment') === 0) {
        $out['attachments'][] = [$name ?: 'attachment', strtolower(trim(explode(';', $ct)[0])), $data];
    } elseif (stripos($ct, 'text/plain') === 0) {
        $out['text'] = to_utf8($data, mime_param($ct, 'charset') ?: 'UTF-8');
    } elseif (stripos($ct, 'text/html') === 0) {
        $out['text'] = html_to_text(to_utf8($data, mime_param($ct, 'charset') ?: 'UTF-8'));
    }
    return $out;
}
