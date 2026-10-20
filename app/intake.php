<?php
defined('DS') || exit;

/*
 * Government intake channel and digest pipeline.
 *
 * Channels: the console (form, file upload, pasted text, web page URL), the intake mailbox (IMAP)
 * and the agency API. Formats: CSV, XLSX, JSON, DOCX, PDF, HTML, TXT/Markdown, images (with AI).
 *
 * Each submission becomes an intake item. Processing turns it into one or more service drafts:
 *   structured  - spreadsheets, JSON and API records, mapped column by column
 *   ai          - Claude reads documents and returns records in a fixed JSON schema
 *   heuristic   - a rule-based reader for headings, labels, bullet lists, fees and phone numbers
 * Drafts are matched to existing services and wait for review; approving one publishes it with a
 * new version and marks the service as confirmed by the agency.
 */

const INTAKE_STATUS = ['received' => 'Received', 'processing' => 'Processing', 'review' => 'Ready for review', 'done' => 'Done', 'failed' => 'Couldn’t read', 'rejected' => 'Rejected'];
const INTAKE_CHANNELS = ['form' => 'Web form', 'upload' => 'File upload', 'paste' => 'Pasted text', 'url' => 'Web page', 'email' => 'Email', 'api' => 'Agency API'];

const AI_MODELS = [
    'claude-opus-5-5' => 'Claude Opus 5.5 (most accurate)',
    'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (faster)',
    'claude-haiku-5-5' => 'Claude Haiku 5.5 (quickest, cheapest)',
];

/** Column/label names that map to service fields. */
const FIELD_SYNONYMS = [
    'title' => ['title', 'name', 'service', 'service name', 'service title', 'service/procedure'],
    'summary' => ['summary', 'short description', 'overview', 'purpose', 'about this service'],
    'description' => ['description', 'details', 'about', 'more information', 'notes', 'information', 'additional information'],
    'eligibility' => ['eligibility', 'who can apply', 'who is eligible', 'eligible', 'criteria', 'who qualifies', 'qualification', 'who should apply'],
    'requirements' => ['requirements', 'documents', 'documents required', 'required documents', 'what you need', 'what to bring', 'what you will need', 'supporting documents', 'requirement'],
    'fees' => ['fee', 'fees', 'cost', 'costs', 'price', 'charges', 'charge', 'amount', 'payment'],
    'processing_time' => ['processing time', 'processing', 'turnaround', 'turnaround time', 'how long', 'duration', 'time frame', 'timeframe', 'waiting time'],
    'steps' => ['steps', 'how to apply', 'procedure', 'process', 'application process', 'how to', 'instructions', 'what to do'],
    'hours' => ['hours', 'opening hours', 'office hours', 'business hours', 'operating hours', 'hours of operation', 'times'],
    'location' => ['location', 'address', 'where', 'office', 'where to apply', 'where to go', 'venue'],
    'contact' => ['contact', 'contacts', 'phone', 'telephone', 'tel', 'email', 'contact information', 'contact details', 'enquiries'],
    'online_url' => ['online', 'online link', 'url', 'link', 'website', 'apply online', 'e-service', 'online application'],
    'form_url' => ['form', 'form link', 'application form', 'download form', 'form url'],
    'categories' => ['category', 'categories', 'topic', 'topics', 'life event', 'life events'],
    'agency' => ['agency', 'department', 'ministry', 'organisation', 'organization', 'division', 'unit', 'responsible agency'],
    'keywords' => ['keywords', 'tags', 'search terms'],
];

function intake_create(array $row): int
{
    return insert('intake_items', $row + ['status' => 'received', 'created_at' => now_utc()]);
}

/** Queues processing after the response, and cron.php picks up anything left over. */
function intake_queue(int $id): void
{
    after_response(function () use ($id) {
        @set_time_limit(300);
        intake_process($id);
    });
}

/** Turns one intake item into drafts. Safe to call again on failed items. */
function intake_process(int $id): void
{
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$id]);
    if (!$item || !in_array($item['status'], ['received', 'failed', 'processing'], true)) return;
    q("UPDATE intake_items SET status = 'processing', error = NULL WHERE id = ?", [$id]);
    try {
        [$records, $method] = intake_records($item);
        $records = array_values(array_filter($records, fn($r) => trim((string) ($r['title'] ?? '')) !== ''));
        if (!$records) throw new RuntimeException('No services were found in this submission. Add a clear service name and its details, or use the form.');
        $n = 0;
        foreach ($records as $r) $n += intake_make_draft($item, $r, $method);
        q("UPDATE intake_items SET status = 'review', method = ?, drafts = ?, processed_at = ? WHERE id = ?", [$method, $n, now_utc(), $id]);
        intake_auto_publish($id);
        $item = one('SELECT * FROM intake_items WHERE id = ?', [$id]);
        if ($item['status'] === 'review' && $item['agency_id']) {
            notify_agency((int) $item['agency_id'], plural($n, 'service draft', 'service drafts') . ' from ' . (INTAKE_CHANNELS[$item['channel']] ?? $item['channel']) . ' ready for review.', 'console/intake/' . $id);
        }
    } catch (Throwable $e) {
        if (!$e instanceof RuntimeException) log_error($e);
        q("UPDATE intake_items SET status = 'failed', error = ?, processed_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 500), now_utc(), $id]);
    }
}

/** Returns [records, method]. Each record is a loose service array (+ categories, agency). */
function intake_records(array $item): array
{
    $payload = $item['payload'] ? json_decode((string) $item['payload'], true) : null;
    if (is_array($payload) && $payload) return [array_map('map_record_keys', $payload), 'structured'];

    $file = $item['file_id'] ? one('SELECT * FROM files WHERE id = ?', [$item['file_id']]) : null;
    $bytes = $file ? vault_read($file) : null;
    if ($file && $bytes === null) throw new RuntimeException('The uploaded file couldn’t be read back.');
    $fmt = $item['format'];

    if ($bytes !== null && in_array($fmt, ['csv', 'xlsx'], true)) {
        $rows = $fmt === 'csv' ? csv_rows($bytes) : xlsx_rows($bytes);
        return [rows_to_records($rows), 'structured'];
    }
    if ($bytes !== null && $fmt === 'json') {
        $j = json_decode($bytes, true);
        if (!is_array($j)) throw new RuntimeException('That JSON file isn’t valid.');
        $list = isset($j['services']) && is_array($j['services']) ? $j['services'] : (array_is_list($j) ? $j : [$j]);
        return [array_map('map_record_keys', array_filter($list, 'is_array')), 'structured'];
    }

    // documents: Claude reads PDFs and images directly when it's configured
    if ($bytes !== null && in_array($fmt, ['pdf', 'jpg', 'png'], true) && ai_ready()) {
        $media = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$fmt];
        return [ai_extract_services('', $item, ['media' => $media, 'data' => $bytes]), 'ai'];
    }
    $text = (string) $item['raw_text'];
    if ($bytes !== null) {
        $text = match ($fmt) {
            'docx' => docx_text($bytes),
            'pdf' => pdf_text($bytes),
            'html' => html_doc_text($bytes),
            'txt', 'md' => to_utf8($bytes, 'UTF-8'),
            default => throw new RuntimeException('Images can only be read when AI reading is turned on (Console › Settings).'),
        };
        q('UPDATE intake_items SET raw_text = ? WHERE id = ?', [mb_substr($text, 0, 500000), $item['id']]);
    }
    $text = trim($text);
    if ($text === '') throw new RuntimeException('No readable text was found. Scanned documents need AI reading turned on.');
    if (ai_ready()) {
        try {
            return [ai_extract_services($text, $item), 'ai'];
        } catch (RuntimeException $e) {
            log_error($e);  // fall back to the rule-based reader
        }
    }
    return [heuristic_extract($text, (string) $item['title']), 'heuristic'];
}

/** Maps a loosely-keyed record (spreadsheet row, API JSON) onto service fields. */
function map_record_keys(array $r): array
{
    $out = [];
    foreach ($r as $k => $v) {
        $key = field_for_label((string) $k) ?? (in_array($k, SERVICE_FIELDS, true) ? $k : null);
        if ($key === null) continue;
        if (isset($out[$key]) && is_string($out[$key]) && is_string($v)) $out[$key] .= "\n" . $v;
        else $out[$key] = $v;
    }
    foreach (['requirements', 'steps'] as $k) if (isset($out[$k]) && is_string($out[$k])) $out[$k] = preg_split('/\r?\n|;\s*/', $out[$k]);
    if (isset($out['fees']) && is_string($out['fees'])) $out['fees'] = str_replace(';', "\n", $out['fees']);
    if (isset($out['fees']) && (is_int($out['fees']) || is_float($out['fees']))) $out['fees'] = [['label' => 'Fee', 'amount' => $out['fees']]];
    if (isset($out['categories']) && is_string($out['categories'])) $out['categories'] = preg_split('/\s*[,;\n]\s*/', $out['categories']);
    return $out;
}

function field_for_label(string $label): ?string
{
    $l = mb_strtolower(trim(preg_replace('/[\s_]+/', ' ', rtrim(trim($label), ':'))));
    if (in_array($l, SERVICE_FIELDS, true)) return $l;
    foreach (FIELD_SYNONYMS as $field => $syn) if (in_array($l, $syn, true)) return $field;
    return null;
}

function rows_to_records(array $rows): array
{
    $rows = array_values(array_filter($rows, fn($r) => implode('', array_map('trim', $r)) !== ''));
    if (count($rows) < 2) throw new RuntimeException('The spreadsheet needs a header row and at least one service row.');
    $header = array_map('strval', array_shift($rows));
    $map = [];
    foreach ($header as $i => $hname) if ($f = field_for_label($hname)) $map[$i] = $f;
    if (!in_array('title', $map, true)) throw new RuntimeException('Add a “Service name” (or “Title”) column so each row can be named.');
    $out = [];
    foreach ($rows as $r) {
        $rec = [];
        foreach ($map as $i => $f) {
            $v = trim((string) ($r[$i] ?? ''));
            if ($v === '') continue;
            $rec[$f] = isset($rec[$f]) ? $rec[$f] . "\n" . $v : $v;
        }
        if ($rec) $out[] = map_record_keys($rec);
    }
    return $out;
}

/* ---------- file readers ---------- */

function csv_rows(string $bytes): array
{
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
    $first = strtok($bytes, "\n");
    $delim = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (substr_count((string) $first, "\t") > substr_count((string) $first, ',') ? "\t" : ',');
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, to_utf8($bytes, 'UTF-8'));
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) if ($r !== [null]) $rows[] = $r;
    fclose($fh);
    return $rows;
}

function zip_entry(string $bytes, string $name): ?string
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('This server can’t open Office files (the PHP zip extension is off).');
    $tmp = tempnam(sys_get_temp_dir(), 'slu');
    file_put_contents($tmp, $bytes);
    $z = new ZipArchive();
    $out = null;
    if ($z->open($tmp) === true) {
        $stat = $z->statName($name);
        // refuse zip bombs: Office XML parts are small, and the declared size can lie, so the read is capped too
        $max = 15 * 1048576;
        if ($stat && $stat['size'] <= $max) {
            $out = $z->getFromName($name, $max + 1) ?: null;
            if ($out !== null && strlen($out) > $max) $out = null;
        }
        $z->close();
    }
    @unlink($tmp);
    return $out;
}

function xml_doc(string $xml): DOMDocument
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    return $d;
}

function xlsx_rows(string $bytes): array
{
    $shared = [];
    if ($ss = zip_entry($bytes, 'xl/sharedStrings.xml')) {
        foreach (xml_doc($ss)->getElementsByTagName('si') as $si) $shared[] = $si->textContent;
    }
    $sheet = zip_entry($bytes, 'xl/worksheets/sheet1.xml');
    if (!$sheet) throw new RuntimeException('That spreadsheet has no first sheet.');
    $rows = [];
    foreach (xml_doc($sheet)->getElementsByTagName('row') as $row) {
        $cells = [];
        foreach ($row->getElementsByTagName('c') as $c) {
            preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64);
            $t = $c->getAttribute('t');
            $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
            if ($t === 's') $v = $shared[(int) $v] ?? '';
            elseif ($t === 'inlineStr') $v = $c->getElementsByTagName('is')->item(0)?->textContent ?? '';
            $cells[$col - 1] = $v;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[] = (string) ($cells[$i] ?? '');
            $rows[] = $line;
        }
    }
    return $rows;
}

/** Word document to text, keeping headings (#), list items (-) and table rows (|). */
function docx_text(string $bytes): string
{
    $xml = zip_entry($bytes, 'word/document.xml');
    if (!$xml) throw new RuntimeException('That Word document couldn’t be opened.');
    $d = xml_doc($xml);
    $xp = new DOMXPath($d);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $out = [];
    $body = $xp->query('//w:body')->item(0);
    if (!$body) return '';
    foreach ($body->childNodes as $node) {
        if ($node->localName === 'p') {
            $t = '';
            foreach ($xp->query('.//w:t|.//w:tab|.//w:br', $node) as $r) $t .= $r->localName === 't' ? $r->textContent : ($r->localName === 'tab' ? ' ' : "\n");
            $t = trim($t);
            if ($t === '') { $out[] = ''; continue; }
            $style = $xp->query('./w:pPr/w:pStyle/@w:val', $node)->item(0)?->nodeValue ?? '';
            $list = $xp->query('./w:pPr/w:numPr', $node)->length > 0;
            if (preg_match('/^(heading|title)\s*(\d?)/i', $style, $m)) $out[] = str_repeat('#', max(1, min(3, (int) ($m[2] ?: 1)))) . ' ' . $t;
            elseif ($list) $out[] = '- ' . $t;
            else $out[] = $t;
        } elseif ($node->localName === 'tbl') {
            foreach ($xp->query('./w:tr', $node) as $tr) {
                $cells = [];
                foreach ($xp->query('./w:tc', $tr) as $tc) $cells[] = trim(preg_replace('/\s+/', ' ', $tc->textContent));
                $out[] = implode(' | ', $cells);
            }
            $out[] = '';
        }
    }
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $out)));
}

function html_doc_text(string $html): string
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_use_internal_errors($prev);
    foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'form', 'svg', 'iframe'] as $tag) {
        $list = $d->getElementsByTagName($tag);
        for ($i = $list->length - 1; $i >= 0; $i--) $list->item($i)->parentNode->removeChild($list->item($i));
    }
    $root = $d->getElementsByTagName('main')->item(0) ?? $d->getElementsByTagName('body')->item(0) ?? $d;
    $out = '';
    $walk = function (DOMNode $n) use (&$walk, &$out) {
        foreach ($n->childNodes as $c) {
            if ($c instanceof DOMText) { $out .= preg_replace('/\s+/', ' ', $c->textContent); continue; }
            if (!$c instanceof DOMElement) continue;
            $tag = strtolower($c->tagName);
            if (preg_match('/^h([1-4])$/', $tag, $m)) { $out .= "\n\n" . str_repeat('#', min(3, (int) $m[1])) . ' ' . trim(preg_replace('/\s+/', ' ', $c->textContent)) . "\n"; continue; }
            if ($tag === 'li') { $out .= "\n- " . trim(preg_replace('/\s+/', ' ', $c->textContent)); continue; }
            if ($tag === 'a' && preg_match('~^https?://~', $c->getAttribute('href')) && trim($c->textContent) !== '') { $out .= trim($c->textContent) . ' (' . $c->getAttribute('href') . ')'; continue; }
            if (in_array($tag, ['p', 'div', 'section', 'article', 'tr', 'br', 'table', 'ul', 'ol', 'dl', 'dt', 'dd'], true)) $out .= "\n";
            if (in_array($tag, ['td', 'th'], true)) $out .= ' | ';
            $walk($c);
            if (in_array($tag, ['p', 'div', 'section', 'tr', 'dt', 'dd'], true)) $out .= "\n";
        }
    };
    $walk($root);
    $lines = array_map(fn($l) => trim($l, " \t|"), explode("\n", html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
}

/** PDF text: pdftotext when the server has it, otherwise a built-in reader for simple PDFs. */
function pdf_text(string $bytes): string
{
    if (function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        foreach (['/usr/bin/pdftotext', '/usr/local/bin/pdftotext'] as $bin) {
            if (!is_executable($bin)) continue;
            $p = proc_open([$bin, '-layout', '-q', '-', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($p)) {
                fwrite($pipes[0], $bytes);
                fclose($pipes[0]);
                $out = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($p);
                if (trim((string) $out) !== '') return (string) $out;
            }
        }
    }
    $text = '';
    if (!preg_match_all('/<<(.*?)>>\s*stream\r?\n(.*?)\r?\n?endstream/s', $bytes, $streams, PREG_SET_ORDER)) return '';
    foreach ($streams as [, $dict, $data]) {
        $dict = preg_replace('/^.*\bobj\b/s', '', $dict);  // only this object's dictionary
        if (str_contains($dict, '/ASCII85Decode')) $data = ascii85_decode($data);
        elseif (str_contains($dict, '/ASCIIHexDecode')) $data = (string) @hex2bin(preg_replace('/[^0-9a-f]/i', '', explode('>', $data)[0]));
        if (str_contains($dict, '/FlateDecode')) {
            $data = @gzuncompress($data) ?: @gzinflate($data) ?: @gzinflate(substr($data, 2));
            if (!$data) continue;
        } elseif (preg_match('#/(DCTDecode|JPXDecode|CCITTFaxDecode)#', $dict)) {
            continue;
        }
        if (!preg_match('/\b(Tj|TJ)\b/', $data)) continue;
        if (!preg_match_all('/BT(.*?)ET/s', $data, $blocks)) continue;
        foreach ($blocks[1] as $b) {
            preg_match_all('/(\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|\((?:[^)\\\\]|\\\\.)*\)\s*(?:Tj|\'|")|T\*|-?[\d.]+\s+-?[\d.]+\s+T[dD]|-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+-?[\d.]+\s+Tm)/s', $b, $ops);
            foreach ($ops[0] as $op) {
                if (preg_match('/T\*|T[dD]$|Tm$/', $op)) {
                    if (preg_match('/(-?[\d.]+)\s+(-?[\d.]+)\s+T[dD]$/', $op, $m) && (float) $m[2] === 0.0) { $text .= ' '; continue; }
                    $text .= "\n";
                    continue;
                }
                preg_match_all('/\(((?:[^)\\\\]|\\\\.)*)\)|(-?\d{3,})/', $op, $parts, PREG_SET_ORDER);
                foreach ($parts as $pp) {
                    if (isset($pp[2]) && $pp[2] !== '') { if ((int) $pp[2] < -200) $text .= ' '; continue; }
                    $text .= pdf_unescape($pp[1]);
                }
            }
            $text .= "\n";
        }
    }
    $text = preg_replace("/[ \t]+\n/", "\n", $text);
    return trim(preg_replace("/\n{3,}/", "\n\n", to_utf8($text, 'Windows-1252')));
}

function ascii85_decode(string $s): string
{
    $s = preg_replace('/\s+/', '', $s);
    if (str_starts_with($s, '<~')) $s = substr($s, 2);
    if (($end = strpos($s, '~>')) !== false) $s = substr($s, 0, $end);
    $s = str_replace('z', '!!!!!', $s);
    $out = '';
    foreach (str_split($s, 5) as $group) {
        $n = strlen($group);
        if ($n < 2) break;
        $group = str_pad($group, 5, 'u');
        $v = 0;
        for ($i = 0; $i < 5; $i++) $v = $v * 85 + (ord($group[$i]) - 33);
        $out .= substr(pack('N', $v & 0xFFFFFFFF), 0, $n - 1);
    }
    return $out;
}

function pdf_unescape(string $s): string
{
    return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function ($m) {
        $map = ['n' => "\n", 'r' => '', 't' => ' ', 'b' => '', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\'];
        return $map[$m[1]] ?? chr(octdec($m[1]) & 0xFF);
    }, $s);
}

/* ---------- rule-based reader ---------- */

/** Splits free text into service records using headings, labels and bullet lists. */
function heuristic_extract(string $text, string $fallbackTitle = ''): array
{
    $lines = array_map(fn($l) => rtrim($l), explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));
    $isLabel = function (string $line): ?array {
        if (!preg_match('/^\s*#{0,3}\s*([A-Za-z][A-Za-z \/&\'’()-]{1,40}?)\s*[:\-–]\s*(.*)$/u', $line, $m) && !preg_match('/^\s*#{0,3}\s*([A-Za-z][A-Za-z \/&\'’()-]{1,40}?)\s*:?\s*$/u', $line, $m)) return null;
        $f = field_for_label($m[1]);
        return $f && $f !== 'title' ? [$f, trim($m[2] ?? '')] : null;
    };
    $isServiceHeading = function (string $line, ?string $next) use ($isLabel): bool {
        $t = trim($line);
        if ($t === '' || mb_strlen($t) > 90 || $isLabel($t)) return false;
        if (preg_match('/^#{1,3}\s+\S/', $t)) return true;
        if (preg_match('/[.;,]$/', $t) || preg_match('/^[-*•]/u', $t)) return false;
        $verb = preg_match('/^(apply|register|renew|replace|obtain|get|request|report|claim|book|pay|file|change|transfer|search|join|reserve|issue|application|registration|renewal)\b/i', $t);
        $noun = preg_match('/\b(certificate|licen[cs]e|permit|passport|registration|card|benefit|pension|assistance|application)\b/i', $t);
        return ($verb || $noun) && $next !== null && trim($next) !== '';
    };

    // find headings that start services
    $starts = [];
    for ($i = 0; $i < count($lines); $i++) {
        $next = null;
        for ($j = $i + 1; $j < count($lines); $j++) if (trim($lines[$j]) !== '') { $next = $lines[$j]; break; }
        if ($isServiceHeading($lines[$i], $next)) $starts[] = $i;
    }
    if (!$starts) $starts = [0];
    $records = [];
    foreach ($starts as $n => $s) {
        $end = $starts[$n + 1] ?? count($lines);
        $chunk = array_slice($lines, $s, $end - $s);
        $first = trim(preg_replace('/^#+\s*/', '', (string) array_shift($chunk)));
        $title = $first;
        if ($starts === [0] && ($isLabel($first) || mb_strlen($first) > 90)) { array_unshift($chunk, $first); $title = $fallbackTitle ?: mb_strimwidth($first, 0, 80, '…'); }
        $rec = ['title' => $title];
        $current = null;
        $free = [];
        foreach ($chunk as $line) {
            $t = trim(preg_replace('/^#+\s*/', '', $line));
            if ($t === '') { if ($current !== 'requirements' && $current !== 'steps' && $current !== 'fees') $current = null; continue; }
            if ($lab = $isLabel($t)) {
                [$current, $rest] = $lab;
                if ($rest !== '') $rec[$current] = isset($rec[$current]) ? $rec[$current] . "\n" . $rest : $rest;
                continue;
            }
            if ($current) { $rec[$current] = isset($rec[$current]) ? $rec[$current] . "\n" . $t : $t; continue; }
            $free[] = $t;
        }
        $body = implode("\n", $free);
        // summary: the first free sentence(s)
        if (!isset($rec['summary']) && $free) {
            $rec['summary'] = mb_strimwidth(preg_replace('/\s+/', ' ', implode(' ', array_filter($free, fn($l) => !preg_match('/^[-*•]/u', $l)))), 0, 400, '…');
        }
        if (!isset($rec['requirements'])) {
            $bul = array_values(array_filter($free, fn($l) => preg_match('/^[-*•]/u', $l)));
            if ($bul) $rec['requirements'] = implode("\n", $bul);
        }
        $all = $body . "\n" . implode("\n", array_map(fn($v) => is_string($v) ? $v : '', $rec));
        if (!isset($rec['fees']) && preg_match_all('/([^\n.]{0,60}?)(?:EC\$|XCD\s?)\s*([\d,]+(?:\.\d{2})?)/i', $all, $mm, PREG_SET_ORDER)) {
            $rec['fees'] = implode("\n", array_map(fn($m) => trim(trim($m[1]), ' :-–') . ': EC$' . $m[2], array_slice($mm, 0, 6)));
        }
        if (!isset($rec['processing_time']) && preg_match('/(\d+\s*(?:-|–|to)?\s*\d*\s*(?:working |business )?(?:days?|weeks?|hours?))/i', $all, $m)) $rec['processing_time'] = $m[1];
        if (!isset($rec['contact'])) {
            $c = [];
            if (preg_match_all('/\(?758\)?[\s.-]?\d{3}[\s.-]?\d{4}/', $all, $p)) $c = array_merge($c, array_unique($p[0]));
            if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $all, $e)) $c = array_merge($c, array_unique($e[0]));
            if ($c) $rec['contact'] = implode("\n", array_slice($c, 0, 4));
        }
        if (!isset($rec['online_url']) && preg_match('~https?://[^\s)<>"]+~', $all, $m)) $rec['online_url'] = rtrim($m[0], '.,;');
        if ($body !== '' && !isset($rec['description']) && mb_strlen($body) > mb_strlen((string) ($rec['summary'] ?? '')) + 40) $rec['description'] = mb_substr($body, 0, 4000);
        $records[] = map_record_keys($rec);
    }
    return $records;
}

/* ---------- AI reader (Claude) ---------- */

function ai_ready(): bool
{
    return decrypt_secret((string) setting('ai_key', '')) !== '' || (getenv('GOVT_AI_BASE_URL') && getenv('GOVT_AI_KEY'));
}

function ai_schema(): array
{
    $cats = array_column(all('SELECT slug FROM categories ORDER BY position'), 'slug');
    $str = ['type' => 'string'];
    $list = ['type' => 'array', 'items' => $str];
    return [
        'type' => 'object', 'additionalProperties' => false, 'required' => ['agency_name', 'services'],
        'properties' => [
            'agency_name' => ['type' => 'string', 'description' => 'The government agency the document is from, or an empty string.'],
            'services' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['title', 'summary', 'description', 'eligibility', 'requirements', 'fees', 'processing_time', 'steps', 'hours', 'location', 'contact', 'online_url', 'form_url', 'categories', 'keywords', 'confidence'],
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Action-led name, e.g. "Renew a driver’s licence".'],
                    'summary' => ['type' => 'string', 'description' => 'One or two plain-English sentences for residents.'],
                    'description' => $str, 'eligibility' => $str, 'requirements' => $list,
                    'fees' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['label', 'amount'],
                        'properties' => ['label' => $str, 'amount' => ['anyOf' => [['type' => 'number'], ['type' => 'null']], 'description' => 'Eastern Caribbean dollars; null if not stated.']]]],
                    'processing_time' => $str, 'steps' => $list, 'hours' => $str, 'location' => $str, 'contact' => $str, 'online_url' => $str, 'form_url' => $str,
                    'categories' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $cats]],
                    'keywords' => $str,
                    'confidence' => ['type' => 'integer', 'description' => '0-100: how complete and unambiguous the source was for this service.'],
                ],
            ]],
        ],
    ];
}

/**
 * Sends text (or a PDF/image) to Claude and returns service records.
 * Uses structured outputs so the reply always matches ai_schema().
 */
function ai_extract_services(string $text, array $item, ?array $doc = null): array
{
    $key = decrypt_secret((string) setting('ai_key', '')) ?: (string) getenv('GOVT_AI_KEY');
    $base = rtrim((string) (getenv('GOVT_AI_BASE_URL') ?: 'https://api.anthropic.com'), '/');
    $model = (string) setting('ai_model', 'claude-opus-5-5');
    if (!isset(AI_MODELS[$model])) $model = 'claude-opus-5-5';
    $agency = $item['agency_id'] ? (string) val('SELECT name FROM agencies WHERE id = ?', [$item['agency_id']]) : '';
    $system = "You turn material supplied by Government of Saint Lucia agencies into records for a public services directory used by residents.\n"
        . "- Return one record per distinct public service the material describes (an application, licence, certificate, registration, permit, benefit, report or similar). Skip news, staff lists and general background.\n"
        . "- Use only facts stated in the material. Never invent or estimate fees, documents, times, addresses, phone numbers or links: leave a field empty (or an empty list) when the material doesn't say.\n"
        . "- Titles start with what the resident does, e.g. “Apply for a passport”, “Renew a trade licence”.\n"
        . "- Summaries are one or two short sentences in plain English. Requirements and steps are short list items.\n"
        . "- Fees are in Eastern Caribbean dollars (EC$ or XCD) as plain numbers; use 0 when the material says there is no fee.\n"
        . "- Choose categories only from the allowed list, the topics and life events where a resident would look for the service.\n"
        . "- confidence reflects how complete and unambiguous the material was for that service.";
    $content = [];
    if ($doc) {
        $content[] = $doc['media'] === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($doc['data'])]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $doc['media'], 'data' => base64_encode($doc['data'])]];
    }
    $content[] = ['type' => 'text', 'text' => ($agency !== '' ? "Submitted by: $agency\n" : '') . ($item['title'] ? 'Submission title: ' . $item['title'] . "\n" : '')
        . ($item['source_url'] ? 'Source: ' . $item['source_url'] . "\n" : '') . ($text !== '' ? "\n<material>\n" . mb_substr($text, 0, 400000) . "\n</material>" : "\nThe material is the attached document.")];
    $body = [
        'model' => $model, 'max_tokens' => 16000,
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => ai_schema()]],
        'system' => $system, 'messages' => [['role' => 'user', 'content' => $content]],
    ];
    $headers = ['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'];
    // if a safety classifier declines, the API retries the same request on a suitable model
    if (in_array($model, ['claude-opus-5-5', 'claude-sonnet-5-5'], true)) {
        $body['fallbacks'] = 'default';
        $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
    }
    [$status, $res, $err] = http_request('POST', $base . '/v1/messages', $headers, json_encode($body, JSON_UNESCAPED_UNICODE), 300);
    if ($status !== 200) {
        $msg = json_decode($res, true)['error']['message'] ?? ($err ?: 'HTTP ' . $status);
        throw new RuntimeException('AI reading failed: ' . mb_strimwidth((string) $msg, 0, 200, '…'));
    }
    $r = json_decode($res, true);
    $stop = $r['stop_reason'] ?? '';
    if ($stop === 'refusal') throw new RuntimeException('AI reading declined this material.');
    if ($stop === 'max_tokens') throw new RuntimeException('The material is too long to read in one go. Split it into smaller files.');
    $json = '';
    foreach ($r['content'] ?? [] as $block) if (($block['type'] ?? '') === 'text') $json .= $block['text'];
    $out = json_decode($json, true);
    if (!is_array($out) || !isset($out['services'])) throw new RuntimeException('AI reading returned an unexpected answer.');
    return array_map(function ($s) use ($out) {
        $s['agency'] = $out['agency_name'] ?? '';
        $s['_confidence'] = max(0, min(100, (int) ($s['confidence'] ?? 60)));
        return $s;
    }, array_filter((array) $out['services'], 'is_array'));
}

/* ---------- drafts ---------- */

function normalize_title(string $t): string
{
    $t = str_replace(['’', 'license', 'licensing'], ['\'', 'licence', 'licencing'], mb_strtolower($t));
    $t = preg_replace('/\b(saint|st\.?)\s+lucia(n)?\b/u', ' ', $t);
    $t = preg_replace('/\b(apply for|application for|how to|get|obtain|request|a|an|the|for|of|your)\b/u', ' ', $t);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t));
}

/** Finds the existing service a draft most likely updates. */
function match_service(?int $agencyId, string $title): ?int
{
    $nt = normalize_title($title);
    if ($nt === '') return null;
    $rows = $agencyId ? all('SELECT id, title FROM services WHERE agency_id = ? AND status <> \'archived\'', [$agencyId]) : all('SELECT id, title FROM services WHERE status <> \'archived\'');
    $best = null;
    $bestScore = 0.0;
    foreach ($rows as $r) {
        similar_text($nt, normalize_title($r['title']), $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = (int) $r['id']; }
    }
    return $bestScore >= 82 ? $best : null;
}

function match_agency(string $name): ?int
{
    $name = trim($name);
    if ($name === '') return null;
    $id = val('SELECT id FROM agencies WHERE name = ? OR short_name = ? OR slug = ?', [$name, $name, slugify($name)]);
    if ($id) return (int) $id;
    $best = null;
    $bestScore = 0.0;
    foreach (all('SELECT id, name FROM agencies') as $a) {
        similar_text(mb_strtolower($name), mb_strtolower($a['name']), $pct);
        if ($pct > $bestScore) { $bestScore = $pct; $best = (int) $a['id']; }
    }
    return $bestScore >= 80 ? $best : null;
}

/** Finds a topic or life event by slug or name, ignoring "&" vs "and", case and punctuation. */
function category_id_for(string $name): ?int
{
    static $index = null;
    $key = fn($v) => slugify(str_replace('&', ' and ', (string) $v));
    if ($index === null) {
        $index = [];
        foreach (all('SELECT id, slug, name FROM categories') as $c) $index[$c['slug']] = $index[$key($c['name'])] = (int) $c['id'];
    }
    $name = trim($name);
    return $name === '' ? null : ($index[$key($name)] ?? $index[slugify($name)] ?? null);
}

function intake_make_draft(array $item, array $rec, string $method): int
{
    $agencyId = $item['agency_id'] ? (int) $item['agency_id'] : match_agency((string) ($rec['agency'] ?? ''));
    $data = normalize_service_data($rec);
    $cats = [];
    foreach ((array) ($rec['categories'] ?? []) as $c) if ($id = category_id_for((string) $c)) $cats[] = $id;
    $data['categories'] = array_values(array_unique($cats));
    // remember which fields the source actually gave, so an update never blanks the rest of a live page
    $data['_provided'] = array_values(array_filter(SERVICE_FIELDS, fn($k) => isset($rec[$k]) && !in_array($rec[$k], ['', []], true)));
    $filled = count(array_filter(['summary', 'eligibility', 'requirements', 'fees', 'processing_time', 'steps', 'hours', 'location', 'contact'], fn($k) => !empty($data[$k])));
    $confidence = (int) ($rec['_confidence'] ?? ($method === 'structured' ? 70 + $filled * 3 : 30 + $filled * 6));
    $serviceId = match_service($agencyId, $data['title']);
    // no agency named in the submission: the service it updates tells us whose it is
    if (!$agencyId && $serviceId) $agencyId = (int) val('SELECT agency_id FROM services WHERE id = ?', [$serviceId]);
    insert('intake_drafts', ['intake_id' => (int) $item['id'], 'agency_id' => $agencyId, 'service_id' => $serviceId,
        'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'method' => $method, 'confidence' => max(0, min(100, $confidence)), 'created_at' => now_utc()]);
    return 1;
}

/**
 * What a draft would publish. When it updates a live service, fields the submission didn't give keep
 * their current values (a spreadsheet with a few columns must not wipe the rest of the page), and
 * topics are added to the service's existing ones.
 */
function draft_service_data(array $draft): array
{
    $raw = json_decode((string) $draft['data'], true) ?: [];
    $current = $draft['service_id'] ? service_by_id((int) $draft['service_id']) : null;
    if (!$current) return $raw;
    $merged = service_snapshot($current);
    $empty = fn($v) => $v === null || $v === '' || $v === [];
    foreach (SERVICE_FIELDS as $k) {
        $v = $raw[$k] ?? null;
        $given = isset($raw['_provided']) ? in_array($k, $raw['_provided'], true)
            : !$empty($v) && !($k === 'mode' && $v === 'info') && !($k === 'requires_id' && !$v);
        if ($given && !$empty($v)) $merged[$k] = $v;
    }
    $merged['categories'] = array_values(array_unique(array_merge(service_category_ids((int) $current['id']), array_map('intval', (array) ($raw['categories'] ?? [])))));
    return $merged;
}

/** Approves a draft (optionally with edits): publishes it and marks the service confirmed by the agency. */
function approve_draft(array $draft, ?array $data, ?array $categories, ?array $actor, ?int $agencyId = null): int
{
    $d = $data ?? draft_service_data($draft);
    $agencyId = $agencyId ?: (int) $draft['agency_id'];
    if (!$agencyId) throw new RuntimeException('Choose which agency provides this service.');
    if ($draft['service_id']) {
        // the service may have moved agency since the draft was made
        $owner = (int) val('SELECT agency_id FROM services WHERE id = ?', [$draft['service_id']]);
        if (!$owner) throw new RuntimeException('The service this draft updates no longer exists.');
        if ($actor ? !can_manage_agency($actor, $owner) : $owner !== $agencyId) throw new RuntimeException('This draft updates a service that belongs to another agency.');
    }
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$draft['intake_id']]);
    $cats = $categories ?? ($d['categories'] ?? []);
    $sid = publish_service($d, ['service_id' => $draft['service_id'] ? (int) $draft['service_id'] : null, 'agency_id' => $agencyId, 'categories' => $cats,
        'source' => $item['channel'] ?? 'intake', 'source_url' => $item['source_url'] ?? null, 'verified' => true, 'draft_id' => (int) $draft['id'],
        'note' => 'From intake #' . $draft['intake_id']], $actor);
    q("UPDATE intake_drafts SET status = 'approved', service_id = ?, agency_id = ?, reviewer_id = ?, reviewed_at = ? WHERE id = ?", [$sid, $agencyId, $actor['id'] ?? null, now_utc(), $draft['id']]);
    intake_settle((int) $draft['intake_id']);
    return $sid;
}

function reject_draft(array $draft, ?array $actor, string $note = ''): void
{
    q("UPDATE intake_drafts SET status = 'rejected', reviewer_id = ?, reviewed_at = ?, note = ? WHERE id = ?", [$actor['id'] ?? null, now_utc(), mb_substr($note, 0, 500) ?: null, $draft['id']]);
    audit($actor, 'draft.reject', 'draft', (int) $draft['id'], $note);
    intake_settle((int) $draft['intake_id']);
}

/** An item is done once none of its drafts wait for review. */
function intake_settle(int $intakeId): void
{
    $pending = (int) val("SELECT COUNT(*) FROM intake_drafts WHERE intake_id = ? AND status = 'pending'", [$intakeId]);
    if (!$pending) q("UPDATE intake_items SET status = 'done' WHERE id = ? AND status = 'review'", [$intakeId]);
}

/** API keys marked "publish without review" publish their structured drafts straight away. */
function intake_auto_publish(int $intakeId): void
{
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$intakeId]);
    if (!$item || !$item['api_key_id'] || $item['method'] !== 'structured') return;
    $key = one('SELECT * FROM api_keys WHERE id = ? AND revoked_at IS NULL', [$item['api_key_id']]);
    if (!$key || !$key['auto_publish']) return;
    foreach (all("SELECT * FROM intake_drafts WHERE intake_id = ? AND status = 'pending'", [$intakeId]) as $d) {
        try {
            approve_draft($d, null, null, null, (int) $key['agency_id']);
        } catch (RuntimeException $e) {
            q('UPDATE intake_drafts SET note = ? WHERE id = ?', [mb_substr($e->getMessage(), 0, 500), $d['id']]);
        }
    }
}

/* ---------- channels ---------- */

/** Creates an intake item from an uploaded file ($_FILES entry). Returns [id, error]. */
function intake_from_upload(?array $f, ?int $agencyId, ?array $actor, string $title = '', array $extra = []): array
{
    [$fid, $err] = accept_upload($f, 'intake', $actor['id'] ?? null, INTAKE_TYPES, 20971520, true);
    if ($err) return [null, $err];
    $file = one('SELECT * FROM files WHERE id = ?', [$fid]);
    $fmt = INTAKE_TYPES[$file['mime']] ?? 'txt';
    $id = intake_create($extra + ['agency_id' => $agencyId, 'channel' => 'upload', 'format' => $fmt, 'title' => mb_substr($title ?: (string) $file['original_name'], 0, 255),
        'original_name' => $file['original_name'], 'file_id' => $fid, 'submitted_by' => $actor['id'] ?? null]);
    audit($actor, 'intake.upload', 'intake', $id, (string) $file['original_name']);
    return [$id, null];
}

function intake_from_url(string $url, ?int $agencyId, ?array $actor, array $extra = []): array
{
    if (!preg_match('~^https?://~i', $url)) return [null, 'Enter a full web address starting with https://'];
    [$status, $body, $err, $type] = fetch_public_url($url);
    if ($status !== 200 || $body === '') return [null, 'Couldn’t fetch that page' . ($err ? ' (' . $err . ')' : ($status ? ' (HTTP ' . $status . ')' : '')) . '.'];
    $fmt = 'html';
    $fileId = null;
    $text = null;
    if (str_contains($type, 'pdf')) { $fmt = 'pdf'; $fileId = vault_store($body, 'application/pdf', 'intake', $actor['id'] ?? null, basename(parse_url($url, PHP_URL_PATH) ?: 'page.pdf')); }
    else $text = html_doc_text($body);
    preg_match('/<title>(.*?)<\/title>/is', $body, $tm);
    $id = intake_create($extra + ['agency_id' => $agencyId, 'channel' => 'url', 'format' => $fmt, 'title' => mb_substr(html_entity_decode(trim($tm[1] ?? $url)), 0, 255),
        'source_url' => mb_substr($url, 0, 500), 'file_id' => $fileId, 'raw_text' => $text, 'submitted_by' => $actor['id'] ?? null]);
    audit($actor, 'intake.url', 'intake', $id, $url);
    return [$id, null];
}

/**
 * Whether our receiving mail server vouched for the From address. Mail servers add their own
 * Authentication-Results header at the top (the first one is ours; senders can add fake ones lower down).
 * Passes on DMARC for the From domain, or a valid DKIM signature from that domain (or its parent).
 */
function sender_verified(array $headers, string $from): bool
{
    $ar = strtolower((string) ($headers['authentication-results'] ?? ''));
    $domain = strtolower(substr(strrchr($from, '@') ?: '', 1));
    if ($ar === '' || $domain === '') return false;
    if (preg_match('/\bdmarc=pass\b[^;]*?header\.from=([a-z0-9.-]+)/', $ar, $m) && $m[1] === $domain) return true;
    if (preg_match_all('/\bdkim=pass\b[^;]*?header\.[di]=@?([a-z0-9.-]+)/', $ar, $mm)) {
        foreach ($mm[1] as $d) if ($d === $domain || (str_contains($d, '.') && str_ends_with($domain, '.' . $d))) return true;
    }
    return false;
}

/**
 * Reads new mail in the intake mailbox. Each email from an allowed sender becomes an intake item
 * (body text), and each readable attachment becomes its own item. Returns a status line.
 */
function intake_poll_mailbox(int $budget = 60): string
{
    $host = (string) setting('intake_imap_host', '');
    if ($host === '') return 'Intake mailbox not configured.';
    $port = (int) setting('intake_imap_port', '993');
    // the mail server may sit on the host's private network, but never on this machine or a link-local address
    $local = preg_match('/^(127\.|0\.|169\.254\.)/', gethostbyname($host)) || in_array(strtolower($host), ['localhost', '::1'], true);
    if (!getenv('GOVT_ALLOW_LOCAL_FETCH') && ($local || !in_array($port, [143, 993], true))) {
        set_setting('intake_imap_last', now_utc() . ' · Error: the mailbox server must be a public IMAP server (port 993 or 143).');
        return 'Error: the mailbox server must be a public IMAP server (port 993 or 143).';
    }
    $start = time();
    $added = 0;
    try {
        $c = new ImapClient($host, $port, (string) setting('intake_imap_security', 'ssl'), 20);
        $c->login((string) setting('intake_imap_user', ''), decrypt_secret((string) setting('intake_imap_pass', '')));
        $c->selectWrite('INBOX');
        $domains = array_filter(array_map(fn($d) => mb_strtolower(trim($d)), explode(',', (string) setting('intake_email_domains', 'govt.lc'))));
        foreach (array_slice($c->search('UNSEEN'), 0, 25) as $uid) {
            if (time() - $start > $budget) break;
            $raw = $c->fetchRaw($uid);
            $c->setSeen($uid);
            if ($raw === null) continue;
            $h = mime_headers(substr($raw, 0, 20000));
            $from = parse_addresses($h['from'] ?? '')[0]['email'] ?? '';
            $fromDomain = substr(strrchr($from, '@') ?: '', 1);
            $officer = $from !== '' ? one("SELECT * FROM users WHERE email = ? AND active = 1 AND role IN ('officer','admin','owner')", [$from]) : null;
            $agency = $officer['agency_id'] ?? val('SELECT id FROM agencies WHERE email = ?', [$from]);
            $allowed = $officer || $agency || in_array($fromDomain, $domains, true);
            if (!$allowed) { audit(null, 'intake.email_ignored', null, null, 'From ' . $from); continue; }
            // From can be forged: only a sender our mail server vouched for is attributed to an officer or agency.
            // Anything else waits for an admin, marked as unverified.
            $verified = sender_verified($h, $from);
            $mail = mime_walk($raw);
            $subject = mb_substr(mime_decode_words((string) ($h['subject'] ?? '')), 0, 255) ?: 'Email from ' . $from;
            $base = ['agency_id' => $verified && $agency ? (int) $agency : null, 'channel' => 'email', 'submitted_by' => $verified ? ($officer['id'] ?? null) : null,
                'submitter_email' => $from, 'sender_verified' => $verified ? 1 : 0];
            if (trim($mail['text']) !== '' && mb_strlen(trim($mail['text'])) > 40) {
                $id = intake_create($base + ['format' => 'txt', 'title' => $subject, 'raw_text' => mb_substr($mail['text'], 0, 500000)]);
                intake_process($id);
                $added++;
            }
            foreach ($mail['attachments'] as [$name, $type, $data]) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $mime = isset(INTAKE_TYPES[$type]) ? $type : (array_search($ext, INTAKE_TYPES, true) ?: null);
                if (!$mime || strlen($data) > 20971520) continue;
                $fid = vault_store($data, $mime, 'intake', $officer['id'] ?? null, $name);
                $id = intake_create($base + ['format' => INTAKE_TYPES[$mime], 'title' => $subject . ' — ' . $name, 'original_name' => mb_substr($name, 0, 255), 'file_id' => $fid]);
                intake_process($id);
                $added++;
            }
        }
        set_setting('intake_imap_last', now_utc() . ' · ' . plural($added, 'item', 'items'));
        return plural($added, 'new intake item', 'new intake items');
    } catch (Throwable $e) {
        set_setting('intake_imap_last', now_utc() . ' · Error: ' . mb_substr($e->getMessage(), 0, 200));
        return 'Error: ' . $e->getMessage();
    }
}
