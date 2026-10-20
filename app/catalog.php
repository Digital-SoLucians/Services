<?php
defined('DS') || exit;

// The service catalogue: agencies, topics and life events, services, search, publishing with
// version history, and the request form engine used by online services.

const SERVICE_MODES = [
    'info' => 'Information only (apply in person)',
    'request' => 'Online request through this app',
    'external' => 'Apply on another government website',
];
const REQUEST_STATUS = [
    'submitted' => 'Submitted', 'in_review' => 'In review', 'action_needed' => 'Action needed', 'approved' => 'Approved',
    'ready' => 'Ready for collection', 'completed' => 'Completed', 'rejected' => 'Not approved', 'withdrawn' => 'Withdrawn',
];
const OPEN_STATUSES = ['submitted', 'in_review', 'action_needed', 'approved', 'ready'];
const FIELD_TYPES = ['text' => 'Short text', 'textarea' => 'Paragraph', 'number' => 'Number', 'date' => 'Date', 'email' => 'Email', 'phone' => 'Phone',
    'select' => 'Choose one', 'checkbox' => 'Tick box', 'file' => 'File upload'];
const AGENCY_TYPES = ['ministry' => 'Ministry', 'department' => 'Department', 'unit' => 'Unit', 'office' => 'Office', 'statutory' => 'Statutory body'];
/** The fields a service record holds (used by intake, the editor, versions and the API). */
const SERVICE_FIELDS = ['title', 'summary', 'description', 'eligibility', 'requirements', 'fees', 'processing_time', 'steps', 'hours', 'location', 'contact',
    'online_url', 'form_url', 'mode', 'keywords', 'requires_id', 'request_fields'];

function service_decode(array $s): array
{
    foreach (['requirements', 'steps', 'fees', 'request_fields'] as $k) $s[$k] = json_decode((string) ($s[$k] ?? ''), true) ?: [];
    return $s;
}

function service_by_slug(string $slug, bool $publishedOnly = true): ?array
{
    $s = one('SELECT s.*, a.name AS agency_name, a.slug AS agency_slug, a.short_name AS agency_short FROM services s JOIN agencies a ON a.id = s.agency_id WHERE s.slug = ?' . ($publishedOnly ? " AND s.status = 'published'" : ''), [$slug]);
    return $s ? service_decode($s) : null;
}

function service_by_id(int $id): ?array
{
    $s = one('SELECT s.*, a.name AS agency_name, a.slug AS agency_slug, a.short_name AS agency_short FROM services s JOIN agencies a ON a.id = s.agency_id WHERE s.id = ?', [$id]);
    return $s ? service_decode($s) : null;
}

function categories(string $kind): array
{
    return all('SELECT c.*, (SELECT COUNT(*) FROM service_categories sc JOIN services s ON s.id = sc.service_id AND s.status = \'published\' WHERE sc.category_id = c.id) AS n FROM categories c WHERE kind = ? ORDER BY position', [$kind]);
}

function service_category_ids(int $serviceId): array
{
    return array_map('intval', array_column(all('SELECT category_id FROM service_categories WHERE service_id = ?', [$serviceId]), 'category_id'));
}

function agency_label(array $a): string
{
    return (string) ($a['short_name'] ?: $a['name']);
}

function agencies_list(bool $activeOnly = true): array
{
    return all('SELECT a.*, (SELECT COUNT(*) FROM services s WHERE s.agency_id = a.id AND s.status = \'published\') AS n FROM agencies a' . ($activeOnly ? ' WHERE a.active = 1' : '') . ' ORDER BY a.name');
}

/** Lists published services with optional filters; each row has agency_name. */
function list_services(array $where = [], array $params = [], string $order = 's.title', int $limit = 500): array
{
    $sql = "SELECT s.*, a.name AS agency_name, a.slug AS agency_slug, a.short_name AS agency_short FROM services s JOIN agencies a ON a.id = s.agency_id WHERE s.status = 'published'"
        . ($where ? ' AND ' . implode(' AND ', $where) : '') . " ORDER BY $order LIMIT " . (int) $limit;
    return array_map('service_decode', all($sql, $params));
}

/** Simple ranked search across title, summary, keywords, description and agency. */
function search_services(string $q, int $limit = 60): array
{
    $terms = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($q)) ?: [], fn($t) => mb_strlen($t) > 1 && !in_array($t, ['a', 'an', 'the', 'for', 'to', 'of', 'my', 'how', 'do', 'i', 'get', 'in', 'and', 'on', 'apply'], true)));
    if (!$terms) return [];
    $like = [];
    $params = [];
    foreach ($terms as $t) {
        $stem = mb_strlen($t) > 4 ? rtrim($t, 's') : $t;
        $like[] = '(s.title LIKE ? OR s.summary LIKE ? OR s.keywords LIKE ? OR s.description LIKE ? OR a.name LIKE ? OR a.short_name LIKE ?)';
        array_push($params, ...array_fill(0, 6, '%' . addcslashes($stem, '\\%_') . '%'));
    }
    $rows = list_services(['(' . implode(' OR ', $like) . ')'], $params, 's.title', 300);
    foreach ($rows as &$r) {
        $score = 0;
        $title = mb_strtolower($r['title']);
        foreach ($terms as $t) {
            $stem = mb_strlen($t) > 4 ? rtrim($t, 's') : $t;
            if (str_contains($title, $stem)) $score += 10;
            if (str_contains(mb_strtolower((string) $r['keywords']), $stem)) $score += 6;
            if (str_contains(mb_strtolower((string) $r['summary']), $stem)) $score += 3;
            if (str_contains(mb_strtolower($r['agency_name'] . ' ' . $r['agency_short']), $stem)) $score += 2;
            if (str_contains(mb_strtolower((string) $r['description']), $stem)) $score += 1;
        }
        if (str_contains($title, mb_strtolower(trim($q)))) $score += 15;
        $r['_score'] = $score + min(3, (int) $r['views'] / 50);
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['_score'] <=> $a['_score'] ?: strcmp($a['title'], $b['title']));
    return array_slice($rows, 0, $limit);
}

/** The fields of a service as a plain array (for versions, intake comparison and the API). */
function service_snapshot(array $s): array
{
    $s = isset($s['requirements']) && is_string($s['requirements']) ? service_decode($s) : $s;
    $out = [];
    foreach (SERVICE_FIELDS as $k) $out[$k] = $s[$k] ?? null;
    $out['requires_id'] = (int) ($out['requires_id'] ?? 0);
    $out['mode'] = $out['mode'] ?: 'info';
    return $out;
}

/** Cleans a proposed service record from any source (editor, intake, API). */
function normalize_service_data(array $d): array
{
    $str = fn($v, $max) => mb_substr(trim(preg_replace("/[ \t]+/", ' ', (string) ($v ?? ''))), 0, $max);
    $list = function ($v) {
        if (is_string($v)) $v = preg_split('/\r?\n|;\s*(?=\S)/', $v);
        $out = [];
        foreach ((array) $v as $item) {
            $item = trim(preg_replace('/^\s*([-*•●▪◦]|\d+[.)])\s*/u', '', is_array($item) ? implode(' ', $item) : (string) $item));
            if ($item !== '') $out[] = mb_substr($item, 0, 500);
        }
        return array_slice($out, 0, 40);
    };
    $fees = [];
    $rawFees = $d['fees'] ?? [];
    if (is_string($rawFees)) $rawFees = array_map('parse_fee_line', array_filter(array_map('trim', preg_split('/\r?\n/', $rawFees))));
    foreach ((array) $rawFees as $f) {
        if (!is_array($f)) continue;
        $amount = $f['amount'] ?? null;
        $amount = $amount === null || $amount === '' ? null : (float) preg_replace('/[^\d.]/', '', (string) $amount);
        $label = $str($f['label'] ?? 'Fee', 160) ?: 'Fee';
        $fees[] = ['label' => $label, 'amount' => $amount];
    }
    $url = fn($v) => preg_match('~^https?://[^\s<>"]+$~i', trim((string) $v)) ? mb_substr(trim((string) $v), 0, 500) : null;
    $mode = in_array($d['mode'] ?? '', array_keys(SERVICE_MODES), true) ? $d['mode'] : 'info';
    $fields = [];
    foreach ((array) ($d['request_fields'] ?? []) as $f) {
        if (!is_array($f) || trim((string) ($f['label'] ?? '')) === '') continue;
        $type = isset(FIELD_TYPES[$f['type'] ?? '']) ? $f['type'] : 'text';
        $key = substr(slugify((string) ($f['key'] ?? $f['label']), 40), 0, 40);
        $key = str_replace('-', '_', $key);
        $fields[] = ['key' => $key, 'label' => $str($f['label'], 160), 'type' => $type, 'required' => !empty($f['required']),
            'options' => $type === 'select' ? array_slice(array_values(array_filter(array_map(fn($o) => $str($o, 120), is_string($f['options'] ?? null) ? preg_split('/\r?\n|,/', $f['options']) : (array) ($f['options'] ?? [])))), 0, 40) : []];
    }
    if ($mode === 'external' && !$url($d['online_url'] ?? '')) $mode = 'info';
    return [
        'title' => $str($d['title'] ?? '', 255), 'summary' => $str($d['summary'] ?? '', 600), 'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 8000),
        'eligibility' => mb_substr(trim((string) ($d['eligibility'] ?? '')), 0, 3000), 'requirements' => $list($d['requirements'] ?? []), 'fees' => array_slice($fees, 0, 20),
        'processing_time' => $str($d['processing_time'] ?? '', 255), 'steps' => $list($d['steps'] ?? []), 'hours' => $str($d['hours'] ?? '', 255),
        'location' => mb_substr(trim((string) ($d['location'] ?? '')), 0, 1000), 'contact' => mb_substr(trim((string) ($d['contact'] ?? '')), 0, 1000),
        'online_url' => $url($d['online_url'] ?? ''), 'form_url' => $url($d['form_url'] ?? ''), 'mode' => $mode,
        'keywords' => $str($d['keywords'] ?? '', 500), 'requires_id' => !empty($d['requires_id']) ? 1 : 0, 'request_fields' => $fields,
    ];
}

/**
 * Creates or updates a published service and records a version.
 * $meta: agency_id (required for new), service_id, categories (ids), source, source_url, verified (bool), draft_id, note.
 */
function publish_service(array $data, array $meta, ?array $actor): int
{
    $d = normalize_service_data($data);
    if ($d['title'] === '') throw new RuntimeException('A service needs a title.');
    $now = now_utc();
    $row = [
        'title' => $d['title'], 'summary' => $d['summary'] ?: null, 'description' => $d['description'] ?: null, 'eligibility' => $d['eligibility'] ?: null,
        'requirements' => json_encode($d['requirements'], JSON_UNESCAPED_UNICODE), 'fees' => json_encode($d['fees'], JSON_UNESCAPED_UNICODE),
        'processing_time' => $d['processing_time'] ?: null, 'steps' => json_encode($d['steps'], JSON_UNESCAPED_UNICODE), 'hours' => $d['hours'] ?: null,
        'location' => $d['location'] ?: null, 'contact' => $d['contact'] ?: null, 'online_url' => $d['online_url'], 'form_url' => $d['form_url'], 'mode' => $d['mode'],
        'request_fields' => $d['request_fields'] ? json_encode($d['request_fields'], JSON_UNESCAPED_UNICODE) : null, 'requires_id' => $d['requires_id'],
        'keywords' => $d['keywords'] ?: null, 'status' => 'published', 'updated_at' => $now,
    ];
    if (!empty($meta['verified'])) { $row['agency_verified'] = 1; $row['last_verified_at'] = $now; }
    if (!empty($meta['source'])) $row['source'] = $meta['source'];
    if (array_key_exists('source_url', $meta) && $meta['source_url']) $row['source_url'] = $meta['source_url'];
    $id = (int) ($meta['service_id'] ?? 0);
    if ($id) {
        $cur = one('SELECT version FROM services WHERE id = ?', [$id]);
        if (!$cur) throw new RuntimeException('That service no longer exists.');
        $row['version'] = (int) $cur['version'] + 1;
        if (!empty($meta['agency_id'])) $row['agency_id'] = (int) $meta['agency_id'];
        update('services', $id, $row);
    } else {
        $slug = slugify($d['title'], 110);
        $base = $slug;
        for ($i = 2; val('SELECT 1 FROM services WHERE slug = ?', [$slug]); $i++) $slug = $base . '-' . $i;
        $id = insert('services', $row + ['slug' => $slug, 'agency_id' => (int) $meta['agency_id'], 'version' => 1, 'source' => $meta['source'] ?? 'manual', 'created_at' => $now]);
    }
    if (isset($meta['categories'])) {
        q('DELETE FROM service_categories WHERE service_id = ?', [$id]);
        foreach (array_unique(array_map('intval', (array) $meta['categories'])) as $c) if ($c) q('INSERT IGNORE INTO service_categories (service_id, category_id) VALUES (?, ?)', [$id, $c]);
    }
    $version = (int) val('SELECT version FROM services WHERE id = ?', [$id]);
    insert('service_versions', ['service_id' => $id, 'version' => $version, 'data' => json_encode($d, JSON_UNESCAPED_UNICODE), 'actor_id' => $actor['id'] ?? null,
        'draft_id' => $meta['draft_id'] ?? null, 'note' => mb_substr((string) ($meta['note'] ?? ''), 0, 255) ?: null, 'created_at' => $now]);
    audit($actor, $version === 1 ? 'service.create' : 'service.update', 'service', $id, $d['title'] . ' (v' . $version . ')');
    // people who saved the service hear about changes
    if ($version > 1) {
        $slug = (string) val('SELECT slug FROM services WHERE id = ?', [$id]);
        foreach (all('SELECT user_id FROM saved_services WHERE service_id = ?', [$id]) as $s) {
            notify((int) $s['user_id'], '“' . $d['title'] . '” was updated by the agency.', 'services/' . $slug, false);
        }
    }
    return $id;
}

/** "Regular passport: EC$80", "Express - 130", "Search: free" -> [label, amount]. */
function parse_fee_line(string $line): array
{
    $line = trim(preg_replace('/^\s*([-*•]|\d+[.)])\s*/u', '', $line));
    if (preg_match('/^(.*?)[\s:–-]*(?:EC\$|XCD|\$)\s*([\d,]+(?:\.\d{1,2})?)\b(.*)$/i', $line, $m)) {
        $label = trim(trim($m[1]) . ' ' . trim($m[3]), " :-–") ?: 'Fee';
        if (substr_count($label, '(') > substr_count($label, ')')) $label .= ')';
        if (str_starts_with($label, '(') && str_ends_with($label, ')')) $label = substr($label, 1, -1);
        return ['label' => $label, 'amount' => (float) str_replace(',', '', $m[2])];
    }
    if (preg_match('/^(.*?)\s*[:–-]\s*([\d,]+(?:\.\d{1,2})?)\s*$/', $line, $m)) return ['label' => trim($m[1]) ?: 'Fee', 'amount' => (float) str_replace(',', '', $m[2])];
    if (preg_match('/^(.*?)\s*[:–-]\s*(free|no fee|none|nil)\s*$/i', $line, $m)) return ['label' => trim($m[1]) ?: 'Fee', 'amount' => 0.0];
    if (preg_match('/^(free|no fee|none)$/i', $line)) return ['label' => 'Fee', 'amount' => 0.0];
    return ['label' => mb_substr(trim($line, ' :'), 0, 160) ?: 'Fee', 'amount' => null];
}

function fee_lines(array $fees): string
{
    return implode("\n", array_map(fn($f) => ($f['label'] ?? 'Fee') . ': ' . (($f['amount'] ?? null) === null ? 'varies' : ((float) $f['amount'] == 0 ? 'no fee' : 'EC$' . rtrim(rtrim(number_format((float) $f['amount'], 2, '.', ''), '0'), '.'))), $fees));
}

/** Field-by-field differences between two snapshots: [field => [old, new]]. */
function service_diff(array $old, array $new): array
{
    $out = [];
    foreach (SERVICE_FIELDS as $k) {
        $a = $old[$k] ?? null;
        $b = $new[$k] ?? null;
        $na = is_array($a) ? json_encode($a, JSON_UNESCAPED_UNICODE) : trim((string) $a);
        $nb = is_array($b) ? json_encode($b, JSON_UNESCAPED_UNICODE) : trim((string) $b);
        if ($k === 'requires_id') { $na = (string) (int) $a; $nb = (string) (int) $b; }
        if (in_array($na, ['', '[]', 'null'], true) && in_array($nb, ['', '[]', 'null'], true)) continue;
        if ($na !== $nb) $out[$k] = [$a, $b];
    }
    return $out;
}

/** Plain-language value for display in diffs and review screens. */
function field_text(string $k, $v): string
{
    if ($v === null || $v === '' || $v === []) return '—';
    if ($k === 'fees') return implode("\n", array_map(fn($f) => ($f['label'] ?? 'Fee') . ': ' . (($f['amount'] ?? null) === null ? 'varies' : (($f['amount'] ?? 0) == 0 ? 'no fee' : money((float) $f['amount']))), (array) $v));
    if ($k === 'request_fields') return implode("\n", array_map(fn($f) => $f['label'] . ' (' . (FIELD_TYPES[$f['type']] ?? $f['type']) . ($f['required'] ? ', required' : '') . ')', (array) $v));
    if ($k === 'mode') return SERVICE_MODES[$v] ?? (string) $v;
    if ($k === 'requires_id') return $v ? 'Yes' : 'No';
    if (is_array($v)) return implode("\n", array_map(fn($x) => '• ' . (is_array($x) ? json_encode($x) : $x), $v));
    return (string) $v;
}

const FIELD_LABELS = [
    'title' => 'Title', 'summary' => 'Summary', 'description' => 'More information', 'eligibility' => 'Who can apply', 'requirements' => 'What you need',
    'fees' => 'Fees', 'processing_time' => 'How long it takes', 'steps' => 'How to apply', 'hours' => 'Opening hours', 'location' => 'Where to go',
    'contact' => 'Contact', 'online_url' => 'Online link', 'form_url' => 'Form link', 'mode' => 'How people apply', 'keywords' => 'Search keywords',
    'requires_id' => 'Needs a verified ID', 'request_fields' => 'Request form',
];

/* ---------- requests ---------- */

function new_request_ref(): string
{
    $prefix = preg_replace('/[^A-Z]/', '', strtoupper((string) setting('request_prefix', 'SLU'))) ?: 'SLU';
    do {
        $ref = $prefix . '-' . date('y') . '-' . strtoupper(substr(base_convert(bin2hex(random_bytes(5)), 16, 36), 0, 6));
    } while (val('SELECT 1 FROM requests WHERE ref = ?', [$ref]));
    return $ref;
}

function request_badge(string $status): string
{
    $tone = in_array($status, ['action_needed'], true) ? 'hot' : (in_array($status, ['approved', 'ready', 'completed'], true) ? 'done' : (in_array($status, ['rejected', 'withdrawn'], true) ? 'off' : ''));
    return '<span class="tag ' . $tone . '">' . h(REQUEST_STATUS[$status] ?? $status) . '</span>';
}

/** Default form when a request-mode service has no fields defined. */
function request_fields_for(array $s): array
{
    return $s['request_fields'] ?: [
        ['key' => 'details', 'label' => 'Tell the agency what you need', 'type' => 'textarea', 'required' => true, 'options' => []],
        ['key' => 'document', 'label' => 'Supporting document (optional)', 'type' => 'file', 'required' => false, 'options' => []],
    ];
}

function render_request_field(array $f, $value = null): string
{
    $name = 'f_' . $f['key'];
    $req = !empty($f['required']) ? ' required' : '';
    $label = '<span>' . h($f['label']) . (!empty($f['required']) ? '' : ' <small>optional</small>') . '</span>';
    $v = is_string($value) ? $value : '';
    switch ($f['type']) {
        case 'textarea': return '<label class="field">' . $label . '<textarea name="' . h($name) . '" rows="4"' . $req . '>' . h($v) . '</textarea></label>';
        case 'select':
            $html = '<label class="field">' . $label . '<select name="' . h($name) . '"' . $req . '><option value="">Choose…</option>';
            foreach ($f['options'] ?? [] as $o) $html .= '<option' . ($o === $v ? ' selected' : '') . '>' . h($o) . '</option>';
            return $html . '</select></label>';
        case 'checkbox': return '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . ($v === '1' ? ' checked' : '') . '> ' . h($f['label']) . '</label>';
        case 'file': return '<label class="field">' . $label . '<input type="file" name="' . h($name) . '" accept="image/jpeg,image/png,image/webp,application/pdf"' . $req . '><small class="muted">JPG, PNG or PDF, up to 8 MB.</small></label>';
        case 'number': case 'date': case 'email':
            return '<label class="field">' . $label . '<input type="' . $f['type'] . '" name="' . h($name) . '" value="' . h($v) . '"' . $req . '></label>';
        case 'phone': return '<label class="field">' . $label . '<input type="tel" name="' . h($name) . '" value="' . h($v) . '"' . $req . ' autocomplete="tel"></label>';
    }
    return '<label class="field">' . $label . '<input name="' . h($name) . '" value="' . h($v) . '"' . $req . ' maxlength="500"></label>';
}

/** Reads and validates a submitted request form. Returns [values, file_ids, errors]. */
function collect_request_fields(array $fields, int $userId): array
{
    $vals = [];
    $files = [];
    $errors = [];
    foreach ($fields as $f) {
        $name = 'f_' . $f['key'];
        if ($f['type'] === 'file') {
            [$fid, $err] = accept_upload($_FILES[$name] ?? null, 'request', $userId, UPLOAD_TYPES, 8388608, !empty($f['required']));
            if ($err) $errors[] = $f['label'] . ': ' . $err;
            elseif ($fid) { $files[$f['key']] = $fid; $vals[$f['key']] = ['file' => $fid]; }
            continue;
        }
        $v = $f['type'] === 'checkbox' ? (input($name) === '1' ? '1' : '') : mb_substr(input($name), 0, 5000);
        if (!empty($f['required']) && $v === '') { $errors[] = $f['label'] . ' is required.'; continue; }
        if ($v !== '' && $f['type'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $errors[] = $f['label'] . ': enter a valid email.';
        if ($v !== '' && $f['type'] === 'date' && !DateTimeImmutable::createFromFormat('!Y-m-d', $v)) $errors[] = $f['label'] . ': enter a valid date.';
        if ($v !== '' && $f['type'] === 'number' && !is_numeric($v)) $errors[] = $f['label'] . ': enter a number.';
        if ($v !== '' && $f['type'] === 'select' && !in_array($v, $f['options'] ?? [], true)) $errors[] = $f['label'] . ': choose one of the options.';
        $vals[$f['key']] = $v;
    }
    return [$vals, $files, $errors];
}

function add_request_event(int $requestId, ?array $actor, string $side, string $kind, ?string $body = null, ?string $statusTo = null, ?int $fileId = null, bool $internal = false): void
{
    insert('request_events', ['request_id' => $requestId, 'actor_id' => $actor['id'] ?? null, 'side' => $side, 'kind' => $kind, 'body' => $body,
        'status_to' => $statusTo, 'file_id' => $fileId, 'internal' => $internal ? 1 : 0, 'created_at' => now_utc()]);
    q('UPDATE requests SET updated_at = ? WHERE id = ?', [now_utc(), $requestId]);
}

/** Who may download a stored file: request files go to the applicant and the handling agency, intake files to the agency (or whoever sent them). */
function can_view_file(array $u, array $f): bool
{
    $id = (int) $f['id'];
    if ($f['purpose'] === 'request') {
        // the requests it belongs to: an answer on the application form, or an attachment on an update
        $rows = all('SELECT r.user_id, r.agency_id, 0 AS internal FROM requests r WHERE r.data LIKE ?
            UNION ALL SELECT r.user_id, r.agency_id, e.internal FROM request_events e JOIN requests r ON r.id = e.request_id WHERE e.file_id = ?', ['%"file":' . $id . '}%', $id]);
        foreach ($rows as $r) {
            if (can_manage_agency($u, (int) $r['agency_id'])) return true;
            if ((int) $r['user_id'] === (int) $u['id'] && !(int) $r['internal']) return true;  // the applicant, except internal notes
        }
        return !$rows && (int) $f['user_id'] === (int) $u['id'];
    }
    if ($f['purpose'] === 'intake') {
        if (is_admin($u)) return true;
        $item = one('SELECT agency_id, submitted_by FROM intake_items WHERE file_id = ?', [$id]);
        if (!$item) return false;
        return $item['agency_id'] ? can_manage_agency($u, (int) $item['agency_id']) : (int) $item['submitted_by'] === (int) $u['id'];
    }
    return false;  // ID photos open only through the verifier console
}

/* ---------- government clearance (gates ID linking) ---------- */

const CLEARANCE = [
    'none' => 'Not yet authorised',
    'pilot' => 'Pilot (testers and staff only)',
    'granted' => 'Authorised by the Government of Saint Lucia',
    'suspended' => 'Suspended',
];

function clearance(): string
{
    $c = (string) setting('clearance_status', 'none');
    return isset(CLEARANCE[$c]) ? $c : 'none';
}

/** Whether this person may submit an ID for verification right now. */
function id_linking_open(array $u): bool
{
    $c = clearance();
    if ($c === 'granted') return true;
    if ($c === 'pilot') return (int) $u['tester'] === 1 || is_staff($u);
    return false;
}
