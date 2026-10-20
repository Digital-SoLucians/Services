<?php
defined('DS') || exit;

// Government console for agency officers (and admins): overview, request inbox, notices,
// the service editor and agency API keys.

/** The agency an officer works for, or the one an admin picked (?agency=). Null = all (admins). */
function console_agency(array $u): ?int
{
    if ($u['role'] === 'officer') return $u['agency_id'] ? (int) $u['agency_id'] : -1;
    $a = (int) qs('agency', input('agency_scope'));
    return $a > 0 ? $a : null;
}

function agency_filter(array $u, string $path): string
{
    if ($u['role'] === 'officer') return '';
    $cur = console_agency($u);
    $html = '<form method="get" action="' . h(url($path)) . '" class="inline-filter"><label class="sr-only" for="ag">Agency</label><select id="ag" name="agency" data-autosubmit><option value="">All agencies</option>';
    foreach (agencies_list(false) as $a) $html .= '<option value="' . (int) $a['id'] . '"' . ($cur === (int) $a['id'] ? ' selected' : '') . '>' . h($a['name']) . '</option>';
    return $html . '</select>' . (qs('status') !== '' ? '<input type="hidden" name="status" value="' . h(qs('status')) . '">' : '') . '</form>';
}

function agency_select(string $name, ?int $current, bool $required = true): string
{
    $html = '<select name="' . h($name) . '"' . ($required ? ' required' : '') . '><option value="">Choose an agency…</option>';
    foreach (agencies_list(false) as $a) $html .= '<option value="' . (int) $a['id'] . '"' . ($current === (int) $a['id'] ? ' selected' : '') . '>' . h($a['name']) . '</option>';
    return $html . '</select>';
}

function stat_tile(string $label, $value, string $href = '', string $sub = ''): string
{
    $inner = '<span class="micro">' . h($label) . '</span><strong>' . h((string) $value) . '</strong>' . ($sub !== '' ? '<small>' . h($sub) . '</small>' : '');
    return $href !== '' ? '<a class="stat" href="' . h($href) . '">' . $inner . '</a>' : '<div class="stat">' . $inner . '</div>';
}

function console_home(): void
{
    $u = require_staff();
    $ag = console_agency($u);
    $w = $ag ? ' AND agency_id = ' . (int) $ag : '';
    page_open('Console', ['console' => 'overview']);
    echo page_header('Government console', $u['agency_id'] ? (string) val('SELECT name FROM agencies WHERE id = ?', [$u['agency_id']]) : 'Overview',
        is_admin($u) ? 'Platform status: ' . CLEARANCE[clearance()] . '.' : '');
    echo '<div class="stats">';
    if ($u['role'] !== 'verifier') {
        echo stat_tile('Open requests', (int) val("SELECT COUNT(*) FROM requests WHERE status IN ('submitted','in_review','action_needed','approved','ready')" . $w), url('console/requests'))
            . stat_tile('New, not yet reviewed', (int) val("SELECT COUNT(*) FROM requests WHERE status = 'submitted'" . $w), url('console/requests', ['status' => 'submitted']))
            . stat_tile('Drafts to review', (int) val("SELECT COUNT(*) FROM intake_drafts WHERE status = 'pending'" . $w), url('console/intake'))
            . stat_tile('Notices', (int) val("SELECT COUNT(*) FROM notices WHERE status = 'sent'" . $w), url('console/notices'));
        $total = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published'" . $w);
        $conf = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published' AND agency_verified = 1" . $w);
        echo stat_tile('Services listed', $total, url('console/services'), $total ? round(100 * $conf / $total) . '% confirmed by the agency' : '');
    }
    if (can_verify($u)) echo stat_tile('IDs to check', (int) val("SELECT COUNT(*) FROM identities WHERE status = 'pending'"), url('console/verify'));
    if (is_admin($u)) echo stat_tile('Residents', (int) val("SELECT COUNT(*) FROM users WHERE role = 'citizen' AND active = 1"), url('console/admin/users'), (int) val("SELECT COUNT(*) FROM users WHERE id_status = 'verified'") . ' ID-verified');
    echo '</div>';
    if ($u['role'] !== 'verifier') {
        $recent = all("SELECT r.*, s.title, us.name AS applicant FROM requests r JOIN services s ON s.id = r.service_id JOIN users us ON us.id = r.user_id WHERE 1=1" . str_replace('agency_id', 'r.agency_id', $w) . ' ORDER BY r.updated_at DESC LIMIT 8');
        echo '<div class="grid-2"><section class="card"><div class="card-head"><h2>Latest requests</h2><a class="link" href="' . h(url('console/requests')) . '">All</a></div>';
        if ($recent) {
            echo '<ul class="rows">';
            foreach ($recent as $r) echo '<li><a href="' . h(url('console/requests/' . $r['ref'])) . '"><span><strong>' . h($r['title']) . '</strong><small class="muted">' . h($r['ref']) . ' · ' . h($r['applicant']) . ' · ' . h(time_ago($r['updated_at'])) . '</small></span>' . request_badge($r['status']) . '</a></li>';
            echo '</ul>';
        } else echo '<p class="muted">No requests yet.</p>';
        echo '</section><section class="card"><h2>Keep your services current</h2><p>Send updates in any format: a spreadsheet, Word or PDF document, web page, email or API call. You review the result before it’s published.</p>'
            . '<div class="row"><a class="btn primary" href="' . h(url('console/intake')) . '">' . icon('upload') . 'Submit an update</a><a class="btn outline" href="' . h(url('console/services/new')) . '">' . icon('plus') . 'Add a service</a></div></section></div>';
    }
    page_close();
}

/* ---------- requests ---------- */

function console_requests(): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $ag = console_agency($u);
    $status = isset(REQUEST_STATUS[qs('status')]) ? qs('status') : '';
    $where = [];
    $params = [];
    if ($ag) { $where[] = 'r.agency_id = ?'; $params[] = $ag; }
    if ($status !== '') { $where[] = 'r.status = ?'; $params[] = $status; } elseif (qs('all') !== '1') $where[] = "r.status IN ('submitted','in_review','action_needed','approved','ready')";
    $rows = all('SELECT r.*, s.title, us.name AS applicant, us.id_status, a.short_name, a.name AS agency_name FROM requests r JOIN services s ON s.id = r.service_id JOIN users us ON us.id = r.user_id JOIN agencies a ON a.id = r.agency_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.status = \'submitted\' DESC, r.updated_at DESC LIMIT 300', $params);
    page_open('Requests', ['console' => 'requests']);
    echo page_header('Inbox', 'Requests', 'Applications sent to your agency through ' . app_name() . '.', agency_filter($u, 'console/requests'));
    echo '<nav class="seg"><a href="' . h(url('console/requests', ['agency' => qs('agency')])) . '"' . ($status === '' && qs('all') !== '1' ? ' class="on"' : '') . '>Open</a>';
    foreach (REQUEST_STATUS as $k => $l) echo '<a href="' . h(url('console/requests', ['status' => $k, 'agency' => qs('agency')])) . '"' . ($status === $k ? ' class="on"' : '') . '>' . h($l) . '</a>';
    echo '<a href="' . h(url('console/requests', ['all' => 1, 'agency' => qs('agency')])) . '"' . (qs('all') === '1' ? ' class="on"' : '') . '>All</a></nav>';
    if (!$rows) echo empty_state('No requests here.');
    else {
        echo '<div class="card flush"><table class="table"><thead><tr><th>Reference</th><th>Service</th><th>Applicant</th><th>Updated</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td><a href="' . h(url('console/requests/' . $r['ref'])) . '">' . h($r['ref']) . '</a></td><td>' . h($r['title']) . ($ag ? '' : '<small class="muted">' . h($r['short_name'] ?: $r['agency_name']) . '</small>') . '</td>'
            . '<td>' . h($r['applicant']) . ($r['id_status'] === 'verified' ? ' <span class="tag ok" title="ID verified">' . icon('check') . '</span>' : '') . '</td><td>' . h(time_ago($r['updated_at'])) . '</td><td>' . request_badge($r['status']) . '</td></tr>';
        echo '</tbody></table></div>';
    }
    page_close();
}

function console_request(string $ref): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $r = one('SELECT r.*, s.title, s.slug, a.name AS agency_name FROM requests r JOIN services s ON s.id = r.service_id JOIN agencies a ON a.id = r.agency_id WHERE r.ref = ?', [$ref]);
    if (!$r || !can_manage_agency($u, (int) $r['agency_id'])) not_found();
    $applicant = one('SELECT * FROM users WHERE id = ?', [$r['user_id']]);
    if (is_post()) {
        $act = input('act');
        $msg = mb_substr(input('message'), 0, 5000);
        [$fid, $err] = accept_upload($_FILES['file'] ?? null, 'request', (int) $u['id']);
        if ($err) { flash($err, 'err'); redirect('console/requests/' . $ref); }
        if ($act === 'assign') {
            q('UPDATE requests SET assigned_to = ? WHERE id = ?', [$u['id'], $r['id']]);
            flash('Assigned to you.');
        } elseif ($act === 'note') {
            if ($msg !== '' || $fid) add_request_event((int) $r['id'], $u, 'agency', 'note', $msg ?: null, null, $fid, true);
        } else {
            $to = input('status');
            $changed = isset(REQUEST_STATUS[$to]) && $to !== $r['status'] && $to !== 'withdrawn';
            if ($to === 'action_needed' && $msg === '') { flash('Tell the applicant what you need from them.', 'warn'); redirect('console/requests/' . $ref); }
            if ($changed) {
                q('UPDATE requests SET status = ?, closed_at = ? WHERE id = ?', [$to, in_array($to, ['completed', 'rejected'], true) ? now_utc() : null, $r['id']]);
                add_request_event((int) $r['id'], $u, 'agency', 'status', $msg ?: null, $to, $fid);
                notify((int) $r['user_id'], $r['ref'] . ' (' . $r['title'] . '): ' . REQUEST_STATUS[$to] . ($msg !== '' ? '. ' . mb_strimwidth($msg, 0, 160, '…') : ''), 'requests/' . $r['ref']);
            } elseif ($msg !== '' || $fid) {
                add_request_event((int) $r['id'], $u, 'agency', 'message', $msg ?: null, null, $fid);
                notify((int) $r['user_id'], 'New message from ' . $r['agency_name'] . ' about ' . $r['ref'] . '.', 'requests/' . $r['ref']);
            }
            if (!$r['assigned_to']) q('UPDATE requests SET assigned_to = ? WHERE id = ?', [$u['id'], $r['id']]);
            audit($u, 'request.update', 'request', (int) $r['id'], $changed ? 'status ' . $to : 'message');
            flash($changed ? 'Status updated and the applicant notified.' : 'Sent.');
        }
        redirect('console/requests/' . $ref);
    }
    page_open($r['ref'], ['console' => 'requests', 'crumbs' => [['Requests', url('console/requests')]]]);
    echo page_header($r['agency_name'], $r['title'], $r['ref'] . ' · sent ' . fmt_datetime($r['created_at']), request_badge($r['status']));
    echo request_progress($r['status']);
    echo '<div class="grid-side"><div><section class="card"><h2>Timeline</h2>' . request_timeline($r, true)
        . '<form method="post" enctype="multipart/form-data" class="form reply">' . csrf_field()
        . '<label class="field"><span>Message to the applicant</span><textarea name="message" rows="3" maxlength="5000"></textarea></label>'
        . '<div class="two"><label class="field"><span>Change status</span><select name="status"><option value="">Keep “' . h(REQUEST_STATUS[$r['status']]) . '”</option>';
    foreach (REQUEST_STATUS as $k => $l) if ($k !== 'withdrawn' && $k !== $r['status']) echo '<option value="' . $k . '">' . h($l) . '</option>';
    echo '</select></label><label class="file-pick">' . icon('upload') . '<span>Attach a file</span><input type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf"></label></div>'
        . '<div class="row"><button class="btn primary" name="act" value="send">Send to applicant</button><button class="btn outline" name="act" value="note">Save as internal note</button></div></form></section></div>'
        . '<aside class="side"><div class="card"><p class="micro">Applicant</p><p><strong>' . h($applicant['name']) . '</strong>' . ($applicant['id_status'] === 'verified' ? ' <span class="tag ok">' . icon('check') . 'ID verified</span>' : ' <span class="tag">ID not verified</span>') . '</p>'
        . '<p class="small">' . h($applicant['email']) . ($applicant['phone'] ? '<br>' . h($applicant['phone']) : '') . ($applicant['district'] ? '<br>' . h($applicant['district']) : '') . '</p></div>'
        . '<div class="card"><p class="micro">Answers</p>' . request_answers($r) . '</div>'
        . '<div class="card"><p class="micro">Handled by</p><p>' . ($r['assigned_to'] ? h(user_name((int) $r['assigned_to'])) : 'Nobody yet') . '</p>'
        . ((int) $r['assigned_to'] !== (int) $u['id'] ? '<form method="post">' . csrf_field() . '<button class="btn outline sm" name="act" value="assign">Assign to me</button></form>' : '') . '</div></aside></div>';
    page_close();
}

/* ---------- notices (tell us once, page feedback) ---------- */

function console_notices(): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $ag = console_agency($u);
    if (is_post()) {
        $n = one('SELECT * FROM notices WHERE id = ?', [(int) input('id')]);
        if ($n && can_manage_agency($u, (int) $n['agency_id'])) {
            q("UPDATE notices SET status = 'acknowledged', handled_by = ?, handled_at = ? WHERE id = ?", [$u['id'], now_utc(), $n['id']]);
            if ($n['kind'] === 'address') notify((int) $n['user_id'], (string) val('SELECT name FROM agencies WHERE id = ?', [$n['agency_id']]) . ' has updated your address.', 'account/address', false);
            audit($u, 'notice.ack', 'notice', (int) $n['id']);
        }
        redirect('console/notices', ['agency' => qs('agency')]);
    }
    $rows = all('SELECT n.*, us.name, us.email, a.name AS agency FROM notices n JOIN users us ON us.id = n.user_id JOIN agencies a ON a.id = n.agency_id'
        . ($ag ? ' WHERE n.agency_id = ' . (int) $ag : '') . " ORDER BY n.status = 'sent' DESC, n.id DESC LIMIT 200");
    page_open('Notices', ['console' => 'notices']);
    echo page_header('Tell us once', 'Notices', 'Changes of address and feedback on your service pages.', agency_filter($u, 'console/notices'));
    if (!$rows) echo empty_state('No notices.');
    foreach ($rows as $n) {
        echo '<article class="card notice' . ($n['status'] === 'sent' ? ' new' : '') . '"><div class="card-head"><span><span class="tag">' . ($n['kind'] === 'address' ? 'Change of address' : 'Page feedback') . '</span> <strong>' . h($n['name']) . '</strong> <small class="muted">' . h($n['email']) . ' · ' . h(time_ago($n['created_at'])) . ($ag ? '' : ' · ' . h($n['agency'])) . '</small></span>'
            . ($n['status'] === 'sent' ? '<form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $n['id'] . '"><button class="btn outline sm">' . icon('check') . 'Mark done</button></form>' : '<small class="muted">Done by ' . h(user_name((int) $n['handled_by'])) . '</small>') . '</div>'
            . '<p class="pre">' . autolink((string) $n['body']) . '</p></article>';
    }
    page_close();
}

/* ---------- services ---------- */

function console_services(): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $ag = console_agency($u);
    $rows = all('SELECT s.*, a.name AS agency_name, a.short_name FROM services s JOIN agencies a ON a.id = s.agency_id' . ($ag ? ' WHERE s.agency_id = ' . (int) $ag : '') . ' ORDER BY s.status, a.name, s.title');
    page_open('Services', ['console' => 'services']);
    echo page_header('Catalogue', 'Services', 'What residents see. Edits publish straight away and keep a version history.',
        agency_filter($u, 'console/services') . '<a class="btn primary" href="' . h(url('console/services/new', ['agency' => qs('agency')])) . '">' . icon('plus') . 'Add a service</a>');
    if (!$rows) echo empty_state('No services yet. Add one, or submit an update through Intake.');
    else {
        echo '<div class="card flush"><table class="table"><thead><tr><th>Service</th><th>How to apply</th><th>Confirmed</th><th>Updated</th></tr></thead><tbody>';
        foreach ($rows as $s) {
            echo '<tr' . ($s['status'] === 'archived' ? ' class="dim"' : '') . '><td><a href="' . h(url('console/services/' . $s['id'])) . '">' . h($s['title']) . '</a>' . ($ag ? '' : '<small class="muted">' . h($s['short_name'] ?: $s['agency_name']) . '</small>') . ($s['status'] === 'archived' ? ' <span class="tag off">Archived</span>' : '') . '</td>'
                . '<td>' . h(['info' => 'In person', 'request' => 'Online here', 'external' => 'Other website'][$s['mode']] ?? $s['mode']) . '</td>'
                . '<td>' . ($s['agency_verified'] ? '<span class="tag ok">' . icon('check') . h(fmt_date($s['last_verified_at'])) . '</span>' : '<span class="tag">Awaiting</span>') . '</td><td>v' . (int) $s['version'] . ' · ' . h(time_ago($s['updated_at'])) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    page_close();
}

/** Reads the service editor form into a service data array. */
function service_form_data(): array
{
    $fields = [];
    foreach ((array) ($_POST['rf_label'] ?? []) as $i => $label) {
        if (trim((string) $label) === '') continue;
        $fields[] = ['label' => (string) $label, 'type' => (string) ($_POST['rf_type'][$i] ?? 'text'), 'required' => in_array((string) $i, (array) ($_POST['rf_required'] ?? []), true),
            'options' => (string) ($_POST['rf_options'][$i] ?? ''), 'key' => (string) ($_POST['rf_key'][$i] ?? '')];
    }
    return [
        'title' => input('title'), 'summary' => input('summary'), 'description' => input('description'), 'eligibility' => input('eligibility'),
        'requirements' => input('requirements'), 'fees' => input('fees'), 'processing_time' => input('processing_time'), 'steps' => input('steps'),
        'hours' => input('hours'), 'location' => input('location'), 'contact' => input('contact'), 'online_url' => input('online_url'), 'form_url' => input('form_url'),
        'mode' => input('mode'), 'keywords' => input('keywords'), 'requires_id' => input('requires_id') === '1', 'request_fields' => $fields,
    ];
}

/** The service form, shared by the editor and the intake draft review. $diff marks changed fields. */
function service_form_fields(array $d, array $catIds, array $diff = [], array $current = []): string
{
    $mark = fn($k) => isset($diff[$k]) ? ' changed' : '';
    $was = fn($k) => isset($diff[$k]) && $current ? '<details class="was"><summary>Currently</summary><p class="pre">' . h(field_text($k, $current[$k] ?? null)) . '</p></details>' : '';
    $f = fn($k, $label, $input) => '<label class="field' . $mark($k) . '"><span>' . h($label) . (isset($diff[$k]) ? ' <em class="tag hot">changed</em>' : '') . '</span>' . $input . $was($k) . '</label>';
    $ta = fn($k, $rows, $val) => '<textarea name="' . $k . '" rows="' . $rows . '">' . h($val) . '</textarea>';
    $in = fn($k, $val, $extra = '') => '<input name="' . $k . '" value="' . h((string) $val) . '"' . $extra . '>';
    $html = $f('title', 'Title', $in('title', $d['title'] ?? '', ' required maxlength="255"'))
        . $f('summary', 'Summary (one or two sentences)', $ta('summary', 2, (string) ($d['summary'] ?? '')))
        . '<div class="two">' . $f('mode', 'How people apply', (function () use ($d) {
            $s = '<select name="mode" data-mode>';
            foreach (SERVICE_MODES as $k => $l) $s .= '<option value="' . $k . '"' . (($d['mode'] ?? 'info') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
            return $s . '</select>';
        })()) . $f('processing_time', 'How long it takes', $in('processing_time', $d['processing_time'] ?? '', ' maxlength="255" placeholder="e.g. 5 working days"')) . '</div>'
        . $f('eligibility', 'Who can apply', $ta('eligibility', 2, (string) ($d['eligibility'] ?? '')))
        . $f('requirements', 'What you need (one per line)', $ta('requirements', 5, implode("\n", (array) ($d['requirements'] ?? []))))
        . $f('fees', 'Fees (one per line, e.g. “Regular: EC$80”, “Search: free”)', $ta('fees', 3, fee_lines((array) ($d['fees'] ?? []))))
        . $f('steps', 'How to apply (one step per line)', $ta('steps', 4, implode("\n", (array) ($d['steps'] ?? []))))
        . '<div class="two">' . $f('hours', 'Opening hours', $in('hours', $d['hours'] ?? '', ' maxlength="255"')) . $f('contact', 'Contact', $ta('contact', 2, (string) ($d['contact'] ?? ''))) . '</div>'
        . $f('location', 'Where to go', $ta('location', 2, (string) ($d['location'] ?? '')))
        . '<div class="two">' . $f('online_url', 'Online link (e.g. digiGov)', $in('online_url', $d['online_url'] ?? '', ' type="url" placeholder="https://"')) . $f('form_url', 'Form to download', $in('form_url', $d['form_url'] ?? '', ' type="url" placeholder="https://"')) . '</div>'
        . $f('description', 'More information', $ta('description', 4, (string) ($d['description'] ?? '')))
        . $f('keywords', 'Search keywords', $in('keywords', $d['keywords'] ?? '', ' maxlength="500" placeholder="Words people might search for"'));
    $html .= '<fieldset class="field"><legend>Topics and life events</legend><div class="check-grid">';
    foreach (all('SELECT * FROM categories ORDER BY kind DESC, position') as $c) $html .= '<label class="check"><input type="checkbox" name="categories[]" value="' . (int) $c['id'] . '"' . (in_array((int) $c['id'], $catIds, true) ? ' checked' : '') . '> ' . h($c['name']) . '</label>';
    $html .= '</div></fieldset>';
    $html .= '<fieldset class="field request-builder' . $mark('request_fields') . '" data-request-builder><legend>Online request form' . (isset($diff['request_fields']) ? ' <em class="tag hot">changed</em>' : '') . '</legend>'
        . '<label class="check"><input type="checkbox" name="requires_id" value="1"' . (!empty($d['requires_id']) ? ' checked' : '') . '> Applicants need a verified ID</label>'
        . '<p class="hint">Used when people apply online here. Leave empty for a simple “details + document” form.</p><table class="table plain"><thead><tr><th>Question</th><th>Type</th><th>Options (for “Choose one”)</th><th>Required</th></tr></thead><tbody>';
    $rows = (array) ($d['request_fields'] ?? []);
    $rows[] = ['label' => '', 'type' => 'text', 'required' => false, 'options' => [], 'key' => ''];
    $rows[] = ['label' => '', 'type' => 'text', 'required' => false, 'options' => [], 'key' => ''];
    foreach ($rows as $i => $r) {
        $html .= '<tr><td><input type="hidden" name="rf_key[]" value="' . h((string) ($r['key'] ?? '')) . '"><input name="rf_label[]" value="' . h($r['label']) . '" aria-label="Question"></td><td><select name="rf_type[]" aria-label="Type">';
        foreach (FIELD_TYPES as $k => $l) $html .= '<option value="' . $k . '"' . ($r['type'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        $html .= '</select></td><td><input name="rf_options[]" value="' . h(implode(', ', (array) ($r['options'] ?? []))) . '" aria-label="Options" placeholder="Option A, Option B"></td><td class="c"><input type="checkbox" name="rf_required[]" value="' . $i . '"' . (!empty($r['required']) ? ' checked' : '') . ' aria-label="Required"></td></tr>';
    }
    $html .= '</tbody></table><button type="button" class="btn ghost sm" data-add-row>' . icon('plus') . 'Add a question</button></fieldset>';
    return $html;
}

function console_service_edit(?int $id): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $s = $id ? service_by_id($id) : null;
    if ($id && !$s) not_found();
    if ($s && !can_manage_agency($u, (int) $s['agency_id'])) not_found();
    $agencyId = $s ? (int) $s['agency_id'] : ($u['role'] === 'officer' ? (int) $u['agency_id'] : (int) qs('agency', input('agency_id')));
    if (is_post()) {
        if (input('act') === 'archive' && $s) {
            q("UPDATE services SET status = IF(status = 'archived', 'published', 'archived'), updated_at = ? WHERE id = ?", [now_utc(), $id]);
            audit($u, $s['status'] === 'archived' ? 'service.restore' : 'service.archive', 'service', $id, $s['title']);
            flash($s['status'] === 'archived' ? 'Service restored.' : 'Service archived. Residents no longer see it.');
            redirect('console/services/' . $id);
        }
        if ($u['role'] !== 'officer') $agencyId = (int) input('agency_id');
        if (!$agencyId || !can_manage_agency($u, $agencyId)) { flash('Choose an agency.', 'err'); redirect($id ? 'console/services/' . $id : 'console/services/new'); }
        try {
            $sid = publish_service(service_form_data(), ['service_id' => $id, 'agency_id' => $agencyId, 'categories' => (array) ($_POST['categories'] ?? []),
                'source' => $s ? null : 'manual', 'verified' => true, 'note' => input('note') ?: ($id ? 'Edited in the console' : 'Created in the console')], $u);
            flash('Published. Residents see the new version now.');
            redirect('console/services/' . $sid);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect($id ? 'console/services/' . $id : 'console/services/new');
        }
    }
    $d = $s ? service_snapshot($s) : ['mode' => 'info', 'request_fields' => []];
    page_open($s ? $s['title'] : 'Add a service', ['console' => 'services', 'crumbs' => [['Services', url('console/services')]]]);
    echo page_header($s ? $s['agency_name'] : 'New', $s ? $s['title'] : 'Add a service', $s ? 'Version ' . $s['version'] . ($s['agency_verified'] ? ' · confirmed ' . fmt_date($s['last_verified_at']) : ' · awaiting confirmation') : '',
        $s ? '<a class="btn outline" href="' . h(url('services/' . $s['slug'])) . '" target="_blank">' . icon('eye') . 'View</a>' : '');
    echo '<div class="grid-side"><form method="post" class="card form service-form">' . csrf_field();
    if ($u['role'] !== 'officer') echo '<label class="field"><span>Agency</span>' . agency_select('agency_id', $agencyId ?: null) . '</label>';
    echo service_form_fields($d, $s ? service_category_ids((int) $s['id']) : [])
        . '<label class="field"><span>Note for the version history <small>optional</small></span><input name="note" maxlength="255" placeholder="e.g. New fees from 1 November"></label>'
        . '<p class="hint">Publishing marks this service as confirmed by the agency.</p><button class="btn primary">Publish</button></form><aside class="side">';
    if ($s) {
        echo '<div class="card"><p class="micro">Versions</p><ol class="versions">';
        foreach (all('SELECT * FROM service_versions WHERE service_id = ? ORDER BY version DESC LIMIT 20', [$s['id']]) as $v) {
            echo '<li><strong>v' . (int) $v['version'] . '</strong> ' . h(fmt_datetime($v['created_at'])) . '<small class="muted">' . h(user_name($v['actor_id'] ? (int) $v['actor_id'] : null)) . ($v['note'] ? ' · ' . h($v['note']) : '') . '</small></li>';
        }
        echo '</ol><p class="muted small">Source: ' . h($s['source']) . ($s['source_url'] ? ' · <a href="' . h($s['source_url']) . '" target="_blank" rel="noopener">link</a>' : '') . '</p></div>'
            . '<form method="post" class="card">' . csrf_field() . '<input type="hidden" name="act" value="archive"><button class="btn ghost' . ($s['status'] === 'archived' ? '' : ' danger') . '"' . ($s['status'] === 'archived' ? '' : ' data-confirm="Archive this service? Residents will no longer see it."') . '>' . ($s['status'] === 'archived' ? 'Restore service' : 'Archive service') . '</button></form>';
    } else {
        echo '<div class="card"><p class="micro">Tip</p><p>Have many services, or a document? Use <a href="' . h(url('console/intake')) . '">Intake</a> to upload a spreadsheet, Word or PDF file instead.</p></div>';
    }
    echo '</aside></div>';
    page_close();
}

/* ---------- agency API keys ---------- */

function console_api_keys(): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $ag = $u['role'] === 'officer' ? (int) $u['agency_id'] : (int) qs('agency', input('agency_id'));
    if (is_post()) {
        if (input('act') === 'revoke') {
            $k = one('SELECT * FROM api_keys WHERE id = ?', [(int) input('id')]);
            if ($k && can_manage_agency($u, (int) $k['agency_id'])) {
                q('UPDATE api_keys SET revoked_at = ? WHERE id = ?', [now_utc(), $k['id']]);
                audit($u, 'apikey.revoke', 'api_key', (int) $k['id'], $k['name']);
                flash('Key revoked.');
            }
        } else {
            $agency = $u['role'] === 'officer' ? (int) $u['agency_id'] : (int) input('agency_id');
            $name = mb_substr(input('name'), 0, 80);
            if (!$agency || !can_manage_agency($u, $agency) || $name === '') flash('Name the key and choose its agency.', 'err');
            else {
                $token = 'slk_' . bin2hex(random_bytes(20));
                $kid = insert('api_keys', ['agency_id' => $agency, 'name' => $name, 'token_hash' => hash('sha256', $token), 'last4' => substr($token, -4),
                    'auto_publish' => is_admin($u) && input('auto_publish') === '1' ? 1 : 0, 'created_by' => (int) $u['id'], 'created_at' => now_utc()]);
                audit($u, 'apikey.create', 'api_key', $kid, $name);
                $_SESSION['new_key'] = $token;
            }
        }
        redirect('console/api-keys', $u['role'] === 'officer' ? [] : ['agency' => qs('agency')]);
    }
    $new = $_SESSION['new_key'] ?? null;
    unset($_SESSION['new_key']);
    $keys = all('SELECT k.*, a.name AS agency FROM api_keys k JOIN agencies a ON a.id = k.agency_id' . ($ag ? ' WHERE k.agency_id = ' . $ag : '') . ' ORDER BY k.revoked_at IS NULL DESC, k.id DESC');
    page_open('API keys', ['console' => 'api']);
    echo page_header('Agency API', 'API keys', 'Let your agency’s own systems send service updates and read your listings.');
    if ($new) echo '<div class="callout"><div><strong>Copy this key now. It won’t be shown again.</strong><p><code class="key" id="newkey">' . h($new) . '</code></p></div><button type="button" class="btn outline" data-copy="#newkey">Copy</button></div>';
    echo '<div class="grid-side"><div class="card flush">';
    if ($keys) {
        echo '<table class="table"><thead><tr><th>Key</th><th>Agency</th><th>Last used</th><th></th></tr></thead><tbody>';
        foreach ($keys as $k) echo '<tr' . ($k['revoked_at'] ? ' class="dim"' : '') . '><td>' . h($k['name']) . '<small class="muted">slk_…' . h($k['last4']) . ($k['auto_publish'] ? ' · publishes without review' : '') . '</small></td><td>' . h($k['agency']) . '</td><td>' . h($k['last_used_at'] ? time_ago($k['last_used_at']) : 'Never') . '</td>'
            . '<td>' . ($k['revoked_at'] ? 'Revoked' : '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="revoke"><input type="hidden" name="id" value="' . (int) $k['id'] . '"><button class="btn ghost sm danger" data-confirm="Revoke this key?">Revoke</button></form>') . '</td></tr>';
        echo '</tbody></table>';
    } else echo '<p class="muted pad">No keys yet.</p>';
    echo '</div><aside class="side"><form method="post" class="card form">' . csrf_field() . '<p class="micro">New key</p><label class="field"><span>Name</span><input name="name" required maxlength="80" placeholder="e.g. Registry website"></label>';
    if ($u['role'] !== 'officer') echo '<label class="field"><span>Agency</span>' . agency_select('agency_id', $ag ?: null) . '</label><label class="check"><input type="checkbox" name="auto_publish" value="1"> Publish structured updates without review</label>';
    echo '<button class="btn primary">Create key</button></form><div class="card"><p class="micro">How to use it</p><pre class="code">curl -X POST ' . h(absolute_url('api/v1/intake')) . " \\\n  -H 'Authorization: Bearer slk_…' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"services\":[{\"title\":\"…\",\"fees\":\"Regular: EC\$80\"}]}'</pre>"
        . '<p class="small">Also accepts <code>{"text": "…"}</code>, <code>{"url": "https://…"}</code> or a file upload (field <code>file</code>). <code>GET /api/v1/services</code> lists your published services.</p></div></aside></div>';
    page_close();
}
