<?php
defined('DS') || exit;

// Intake console: submit updates in any format, follow processing, and review drafts before
// they are published to residents.

const INTAKE_TEMPLATE_HEADERS = ['Service name', 'Summary', 'Who can apply', 'What you need', 'Fees', 'Processing time', 'How to apply', 'Opening hours', 'Where to go', 'Contact', 'Online link', 'Form link', 'Categories', 'Keywords'];

function intake_template(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="slu-services-intake-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, INTAKE_TEMPLATE_HEADERS, ',', '"', '');
    fputcsv($out, ['Apply for a fishing licence', 'Licence for recreational fishing by citizens and residents.', 'Citizens or residents of Saint Lucia', 'Completed form; Photo ID',
        'Licence: EC$50', '3–5 business days', 'Fill in the form; Pay the fee; Collect the licence', 'Mon–Fri 8:00 a.m.–4:30 p.m.', 'Department of Fisheries, Castries', '(758) 000-0000',
        '', '', 'agriculture-fisheries; farming-and-fishing', 'fishing, licence'], ',', '"', '');
    fclose($out);
    exit;
}

function console_intake(): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    if (qs('template') === 'csv') intake_template();
    $ag = console_agency($u);
    if (is_post()) {
        $agency = $u['role'] === 'officer' ? (int) $u['agency_id'] : ((int) input('agency_id') ?: null);
        if ($agency && !can_manage_agency($u, $agency)) $agency = null;
        $via = input('via');
        $id = null;
        $err = null;
        if ($via === 'upload') [$id, $err] = intake_from_upload($_FILES['file'] ?? null, $agency, $u, input('title'));
        elseif ($via === 'url') [$id, $err] = intake_from_url(input('url'), $agency, $u);
        elseif ($via === 'paste') {
            $text = mb_substr((string) ($_POST['text'] ?? ''), 0, 200000);
            if (mb_strlen(trim($text)) < 20) $err = 'Paste the service information (at least a few lines).';
            else {
                $id = intake_create(['agency_id' => $agency, 'channel' => 'paste', 'format' => 'txt', 'title' => mb_substr(input('title') ?: 'Pasted text', 0, 255), 'raw_text' => $text, 'submitted_by' => (int) $u['id']]);
                audit($u, 'intake.paste', 'intake', $id);
            }
        } else $err = 'Choose how to send the update.';
        if ($err) { flash($err, 'err'); redirect('console/intake'); }
        // read it now when it's quick (structured or rule-based); larger AI jobs run after the response
        $item = one('SELECT * FROM intake_items WHERE id = ?', [$id]);
        if (in_array($item['format'], ['csv', 'xlsx', 'json'], true) || !ai_ready()) intake_process($id);
        else intake_queue($id);
        flash('Received. ' . (ai_ready() && !in_array($item['format'], ['csv', 'xlsx', 'json'], true) ? 'AI reading is turning it into drafts; refresh in a moment.' : 'Review the drafts below.'));
        redirect('console/intake/' . $id);
    }
    $items = all('SELECT i.*, a.name AS agency FROM intake_items i LEFT JOIN agencies a ON a.id = i.agency_id' . ($ag ? ' WHERE i.agency_id = ' . (int) $ag : '') . ' ORDER BY i.id DESC LIMIT 100');
    $pending = (int) val("SELECT COUNT(*) FROM intake_drafts WHERE status = 'pending'" . ($ag ? ' AND agency_id = ' . (int) $ag : ''));
    page_open('Intake', ['console' => 'intake']);
    echo page_header('Government intake', 'Send service information in any format', 'We read it, turn it into service pages and wait for your approval before anything goes live.' . ($pending ? ' ' . plural($pending, 'draft is', 'drafts are') . ' waiting for review.' : ''), agency_filter($u, 'console/intake'));
    echo '<div class="grid-side"><div><div class="card form intake-box"><nav class="seg" data-tabs><a href="#via-upload" class="on">Upload a file</a><a href="#via-paste">Paste text</a><a href="#via-url">Web page</a></nav>';
    $agencyField = $u['role'] !== 'officer' ? '<label class="field"><span>Agency <small>leave empty to detect it from the content</small></span>' . agency_select('agency_id', $ag, false) . '</label>' : '';
    echo '<form method="post" enctype="multipart/form-data" id="via-upload" class="form tab-pane">' . csrf_field() . '<input type="hidden" name="via" value="upload">' . $agencyField
        . '<label class="dropzone">' . icon('upload') . '<strong>Choose a file</strong><span>CSV, Excel, JSON, Word, PDF, web page, text, or a photo of a form. Up to 20 MB.</span><input type="file" name="file" required accept=".csv,.xlsx,.json,.docx,.pdf,.html,.htm,.txt,.md,.jpg,.jpeg,.png"></label>'
        . '<label class="field"><span>Title <small>optional</small></span><input name="title" maxlength="255" placeholder="e.g. 2026 fee schedule"></label><button class="btn primary">Send</button></form>'
        . '<form method="post" id="via-paste" class="form tab-pane">' . csrf_field() . '<input type="hidden" name="via" value="paste">' . $agencyField
        . '<label class="field"><span>Title <small>optional</small></span><input name="title" maxlength="255"></label>'
        . '<label class="field"><span>Service information</span><textarea name="text" rows="10" required placeholder="Apply for a fishing licence&#10;Fee: EC$50&#10;Processing time: 3–5 business days&#10;Requirements:&#10;- Photo ID&#10;- Completed form"></textarea></label><button class="btn primary">Send</button></form>'
        . '<form method="post" id="via-url" class="form tab-pane">' . csrf_field() . '<input type="hidden" name="via" value="url">' . $agencyField
        . '<label class="field"><span>Page address</span><input type="url" name="url" required placeholder="https://www.govt.lc/services/…"></label><button class="btn primary">Fetch and read</button></form></div>';
    echo '<section class="card flush"><div class="card-head pad"><h2>Submissions</h2></div>';
    if ($items) {
        echo '<table class="table"><thead><tr><th>Submission</th><th>Channel</th><th>Drafts</th><th>Status</th></tr></thead><tbody>';
        foreach ($items as $i) {
            echo '<tr><td><a href="' . h(url('console/intake/' . $i['id'])) . '">' . h($i['title'] ?: ('#' . $i['id'])) . '</a><small class="muted">' . h($i['agency'] ?: 'Agency not set') . ' · ' . h(time_ago($i['created_at'])) . '</small></td>'
                . '<td>' . h(INTAKE_CHANNELS[$i['channel']] ?? $i['channel']) . ' <small class="muted">' . h(strtoupper($i['format'])) . '</small></td><td>' . (int) $i['drafts'] . '</td>'
                . '<td><span class="tag' . ($i['status'] === 'review' ? ' hot' : ($i['status'] === 'failed' ? ' off' : '')) . '">' . h(INTAKE_STATUS[$i['status']] ?? $i['status']) . '</span></td></tr>';
        }
        echo '</tbody></table>';
    } else echo '<p class="muted pad">Nothing submitted yet.</p>';
    echo '</section></div><aside class="side"><div class="card"><p class="micro">Spreadsheets</p><p>One row per service. Use our column names for the best result.</p><a class="btn outline sm" href="' . h(url('console/intake', ['template' => 'csv'])) . '">' . icon('download') . 'Download the template</a></div>'
        . '<div class="card"><p class="micro">By email</p>' . (setting('intake_imap_user') ? '<p>Send documents to <strong>' . h((string) setting('intake_imap_user')) . '</strong> from your government email. Attachments are read automatically.</p>' : '<p class="muted">An intake mailbox can be set up in Console › Settings.</p>') . '</div>'
        . '<div class="card"><p class="micro">From your systems</p><p>Use an <a href="' . h(url('console/api-keys')) . '">agency API key</a> to send updates automatically.</p></div>'
        . '<div class="card"><p class="micro">Reading</p><p>' . (ai_ready() ? 'AI reading is on: documents, PDFs and photos of forms are read by Claude, then you review.' : 'Rule-based reading is on. An admin can turn on AI reading for scanned and complex documents.') . '</p></div></aside></div>';
    page_close();
}

function console_intake_item(int $id): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $i = one('SELECT i.*, a.name AS agency FROM intake_items i LEFT JOIN agencies a ON a.id = i.agency_id WHERE i.id = ?', [$id]);
    if (!$i || ($i['agency_id'] && !can_manage_agency($u, (int) $i['agency_id'])) || (!$i['agency_id'] && !is_admin($u) && (int) $i['submitted_by'] !== (int) $u['id'])) not_found();
    if (is_post()) {
        if (input('act') === 'retry' && in_array($i['status'], ['failed', 'received', 'processing'], true)) {
            q("UPDATE intake_items SET status = 'received' WHERE id = ?", [$id]);
            intake_process($id);
            flash('Read again.');
        } elseif (input('act') === 'reject_all') {
            foreach (all("SELECT * FROM intake_drafts WHERE intake_id = ? AND status = 'pending'", [$id]) as $d) if (!$d['agency_id'] || can_manage_agency($u, (int) $d['agency_id'])) reject_draft($d, $u, 'Rejected with the whole submission');
            q("UPDATE intake_items SET status = 'rejected' WHERE id = ?", [$id]);
            flash('Submission rejected.');
        } elseif (input('act') === 'approve_all' && !($i['channel'] === 'email' && !$i['sender_verified'])) {
            $n = 0;
            foreach (all("SELECT * FROM intake_drafts WHERE intake_id = ? AND status = 'pending'", [$id]) as $d) {
                if (!$d['agency_id'] || !can_manage_agency($u, (int) $d['agency_id'])) continue;
                try { approve_draft($d, null, null, $u); $n++; } catch (RuntimeException $e) { flash($e->getMessage(), 'warn'); }
            }
            flash(plural($n, 'service', 'services') . ' published.');
        }
        redirect('console/intake/' . $id);
    }
    $drafts = all('SELECT d.*, s.title AS current_title, a.name AS agency FROM intake_drafts d LEFT JOIN services s ON s.id = d.service_id LEFT JOIN agencies a ON a.id = d.agency_id WHERE d.intake_id = ? ORDER BY d.id', [$id]);
    page_open($i['title'] ?: 'Submission #' . $id, ['console' => 'intake', 'crumbs' => [['Intake', url('console/intake')]]]);
    echo page_header((INTAKE_CHANNELS[$i['channel']] ?? $i['channel']) . ' · ' . strtoupper($i['format']) . ($i['method'] ? ' · read by ' . ['structured' => 'column mapping', 'ai' => 'AI', 'heuristic' => 'rules'][$i['method']] : ''),
        $i['title'] ?: 'Submission #' . $id, ($i['agency'] ?: 'Agency to be set') . ' · ' . fmt_datetime($i['created_at']) . ($i['submitter_email'] ? ' · from ' . $i['submitter_email'] . ($i['sender_verified'] ? ' (verified)' : '') : ''),
        '<span class="tag' . ($i['status'] === 'review' ? ' hot' : '') . '">' . h(INTAKE_STATUS[$i['status']] ?? $i['status']) . '</span>');
    $unverified = $i['channel'] === 'email' && !$i['sender_verified'];
    if ($unverified) echo '<div class="callout hot"><div><strong>Sender not verified</strong><p>Our mail server couldn’t confirm this email really came from ' . h((string) $i['submitter_email']) . ' (no DMARC or DKIM pass), so it may be forged. Check with the agency before approving anything, and review each draft on its own.</p></div></div>';
    if ($i['status'] === 'failed') echo '<div class="callout hot"><div><strong>Couldn’t read this submission</strong><p>' . h((string) $i['error']) . '</p></div><form method="post">' . csrf_field() . '<button class="btn outline" name="act" value="retry">Try again</button></form></div>';
    if (in_array($i['status'], ['received', 'processing'], true)) echo '<div class="callout"><div><strong>Reading…</strong><p>This takes a moment for long documents. Refresh to see the drafts.</p></div><form method="post">' . csrf_field() . '<button class="btn outline" name="act" value="retry">Read now</button></form></div>';
    echo '<div class="grid-side"><div><section class="card flush"><div class="card-head pad"><h2>Drafts</h2>';
    if (array_filter($drafts, fn($d) => $d['status'] === 'pending')) {
        echo '<form method="post" class="row">' . csrf_field() . ($unverified ? '' : '<button class="btn primary sm" name="act" value="approve_all" data-confirm="Publish every pending draft as it is?">Approve all</button>') . '<button class="btn ghost sm" name="act" value="reject_all" data-confirm="Reject this whole submission?">Reject all</button></form>';
    }
    echo '</div>';
    if ($drafts) {
        echo '<table class="table"><thead><tr><th>Service</th><th>Change</th><th>Confidence</th><th>Status</th></tr></thead><tbody>';
        foreach ($drafts as $d) {
            $data = json_decode((string) $d['data'], true) ?: [];
            echo '<tr><td><a href="' . h(url('console/drafts/' . $d['id'])) . '">' . h($data['title'] ?? 'Untitled') . '</a><small class="muted">' . h($d['agency'] ?: 'Agency not set') . '</small></td>'
                . '<td>' . ($d['service_id'] ? 'Updates “' . h((string) $d['current_title']) . '”' : 'New service') . '</td><td><span class="meter" style="--v:' . (int) $d['confidence'] . '%"><i></i></span> ' . (int) $d['confidence'] . '%</td>'
                . '<td><span class="tag' . ($d['status'] === 'pending' ? ' hot' : ($d['status'] === 'approved' ? ' ok' : ' off')) . '">' . h(ucfirst($d['status'])) . '</span></td></tr>';
        }
        echo '</tbody></table>';
    } else echo '<p class="muted pad">No drafts.</p>';
    echo '</section></div><aside class="side">';
    if ($i['file_id']) echo '<div class="card"><p class="micro">File</p><a href="' . h(url('files/' . $i['file_id'])) . '">' . icon('doc') . ' ' . h((string) ($i['original_name'] ?: 'Download')) . '</a></div>';
    if ($i['source_url']) echo '<div class="card"><p class="micro">Source</p><a href="' . h($i['source_url']) . '" target="_blank" rel="noopener">' . h(mb_strimwidth($i['source_url'], 0, 60, '…')) . '</a></div>';
    if ($i['raw_text']) echo '<div class="card"><p class="micro">Text read</p><pre class="source">' . h(mb_strimwidth((string) $i['raw_text'], 0, 4000, '…')) . '</pre></div>';
    echo '</aside></div>';
    page_close();
}

function console_draft(int $id): void
{
    $u = require_staff(['officer', 'admin', 'owner']);
    $d = one('SELECT * FROM intake_drafts WHERE id = ?', [$id]);
    if (!$d) not_found();
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$d['intake_id']]);
    $agencyId = $d['agency_id'] ? (int) $d['agency_id'] : null;
    if ($agencyId && !can_manage_agency($u, $agencyId)) not_found();
    if (!$agencyId && !is_admin($u) && (int) $item['submitted_by'] !== (int) $u['id']) not_found();
    if (is_post() && $d['status'] === 'pending') {
        $act = input('act');
        if ($act === 'reject') {
            reject_draft($d, $u, input('note'));
            flash('Draft rejected.');
            redirect('console/intake/' . $d['intake_id']);
        }
        $target = $u['role'] === 'officer' ? (int) $u['agency_id'] : ((int) input('agency_id') ?: $agencyId);
        if (!$target || !can_manage_agency($u, $target)) { flash('Choose which agency provides this service.', 'err'); redirect('console/drafts/' . $id); }
        $match = input('service_id');
        $d['service_id'] = $match === 'new' ? null : ((int) $match ?: $d['service_id']);
        if ($d['service_id']) {
            $svc = one('SELECT agency_id FROM services WHERE id = ?', [$d['service_id']]);
            if (!$svc || !can_manage_agency($u, (int) $svc['agency_id'])) { flash('You can only update your own agency’s services.', 'err'); redirect('console/drafts/' . $id); }
        }
        try {
            $sid = approve_draft($d, service_form_data(), (array) ($_POST['categories'] ?? []), $u, $target);
            flash('Published.');
            $next = val("SELECT id FROM intake_drafts WHERE intake_id = ? AND status = 'pending' ORDER BY id LIMIT 1", [$d['intake_id']]);
            redirect($next ? 'console/drafts/' . $next : 'console/services/' . $sid);
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect('console/drafts/' . $id);
        }
    }
    $data = draft_service_data($d);
    $current = $d['service_id'] ? service_by_id((int) $d['service_id']) : null;
    $snap = $current ? service_snapshot($current) : [];
    $diff = $current ? service_diff($snap, normalize_service_data($data)) : [];
    $cats = $data['categories'] ?? [];
    page_open('Review: ' . ($data['title'] ?? 'draft'), ['console' => 'intake', 'crumbs' => [['Intake', url('console/intake')], ['Submission #' . $d['intake_id'], url('console/intake/' . $d['intake_id'])]]]);
    echo page_header('Draft · ' . ['structured' => 'from columns', 'ai' => 'read by AI', 'heuristic' => 'read by rules'][$d['method']] . ' · ' . (int) $d['confidence'] . '% confidence',
        (string) ($data['title'] ?? 'Untitled'), $current ? 'Updates the live service “' . $current['title'] . '”: ' . plural(count($diff), 'field changes', 'fields change') . '.' : 'A new service.',
        '<span class="tag' . ($d['status'] === 'pending' ? ' hot' : '') . '">' . h(ucfirst($d['status'])) . '</span>');
    if ($item['channel'] === 'email' && !$item['sender_verified']) echo '<div class="callout hot"><div><strong>Sender not verified</strong><p>This came by email from ' . h((string) $item['submitter_email']) . ', which our mail server couldn’t confirm. Check with the agency before approving.</p></div></div>';
    if ($d['status'] !== 'pending') {
        echo '<div class="callout"><div><strong>' . h(ucfirst($d['status'])) . '</strong><p>By ' . h(user_name($d['reviewer_id'] ? (int) $d['reviewer_id'] : null)) . ' on ' . h(fmt_datetime($d['reviewed_at'])) . ($d['note'] ? ' · ' . h($d['note']) : '') . '</p></div>'
            . ($d['service_id'] ? '<a class="btn outline" href="' . h(url('console/services/' . $d['service_id'])) . '">Open service</a>' : '') . '</div>';
        page_close();
        return;
    }
    echo '<div class="grid-side"><form method="post" class="card form service-form">' . csrf_field();
    if ($u['role'] !== 'officer') echo '<label class="field"><span>Agency</span>' . agency_select('agency_id', $agencyId) . '</label>';
    // services this draft could update: the agency's own, or every agency's (grouped) when none is known yet
    $scope = $agencyId ?: ($u['role'] === 'officer' ? (int) $u['agency_id'] : null);
    $candidates = all("SELECT s.id, s.title, a.name AS agency FROM services s JOIN agencies a ON a.id = s.agency_id WHERE s.status <> 'archived'"
        . ($scope ? ' AND s.agency_id = ' . (int) $scope : '') . ' ORDER BY a.name, s.title');
    echo '<label class="field"><span>This draft…</span><select name="service_id"><option value="new"' . (!$d['service_id'] ? ' selected' : '') . '>adds a new service</option>';
    $group = null;
    foreach ($candidates as $c) {
        if (!$scope && $c['agency'] !== $group) echo ($group !== null ? '</optgroup>' : '') . '<optgroup label="' . h($group = $c['agency']) . '">';
        echo '<option value="' . (int) $c['id'] . '"' . ((int) $d['service_id'] === (int) $c['id'] ? ' selected' : '') . '>updates “' . h($c['title']) . '”</option>';
    }
    echo (!$scope && $group !== null ? '</optgroup>' : '') . '</select></label>' . service_form_fields(normalize_service_data($data), array_map('intval', $cats), $diff, $snap)
        . '<div class="row"><button class="btn primary" name="act" value="approve">Approve and publish</button></div></form>'
        . '<aside class="side"><form method="post" class="card form">' . csrf_field() . '<p class="micro">Not right?</p><label class="field"><span>Reason</span><input name="note" maxlength="500"></label><button class="btn outline" name="act" value="reject">Reject draft</button></form>';
    if ($item['raw_text']) echo '<div class="card"><p class="micro">Source text</p><pre class="source">' . h(mb_strimwidth((string) $item['raw_text'], 0, 6000, '…')) . '</pre></div>';
    elseif ($item['file_id']) echo '<div class="card"><p class="micro">Source</p><a href="' . h(url('files/' . $item['file_id'])) . '">' . icon('doc') . ' Open the original file</a></div>';
    echo '</aside></div>';
    page_close();
}
