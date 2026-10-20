<?php
defined('DS') || exit;

// Platform administration: agencies, people and roles, government clearance, settings, the
// data dashboard and the audit log.

function admin_agencies(): void
{
    require_admin();
    $rows = agencies_list(false);
    page_open('Agencies', ['console' => 'agencies']);
    echo page_header('Directory', 'Agencies', 'Ministries, departments and statutory bodies residents see.', '<a class="btn primary" href="' . h(url('console/admin/agencies/new')) . '">' . icon('plus') . 'Add an agency</a>');
    echo '<div class="card flush"><table class="table"><thead><tr><th>Agency</th><th>Type</th><th>Services</th><th>Officers</th><th>Details</th></tr></thead><tbody>';
    foreach ($rows as $a) {
        $officers = (int) val("SELECT COUNT(*) FROM users WHERE role = 'officer' AND agency_id = ? AND active = 1", [$a['id']]);
        echo '<tr' . ($a['active'] ? '' : ' class="dim"') . '><td><a href="' . h(url('console/admin/agencies/' . $a['id'])) . '">' . h($a['name']) . '</a></td><td>' . h(AGENCY_TYPES[$a['type']] ?? $a['type']) . '</td><td>' . (int) $a['n'] . '</td><td>' . $officers . '</td>'
            . '<td>' . ($a['verified'] ? '<span class="tag ok">' . icon('check') . 'Confirmed</span>' : '<span class="tag">From public sources</span>') . '</td></tr>';
    }
    echo '</tbody></table></div>';
    page_close();
}

function admin_agency_edit(?int $id): void
{
    $u = require_admin();
    $a = $id ? one('SELECT * FROM agencies WHERE id = ?', [$id]) : null;
    if ($id && !$a) not_found();
    if (is_post()) {
        $name = mb_substr(input('name'), 0, 255);
        $type = isset(AGENCY_TYPES[input('type')]) ? input('type') : 'department';
        $parent = (int) input('parent_id') ?: null;
        if ($name === '') { flash('Enter the agency’s name.', 'err'); redirect($id ? 'console/admin/agencies/' . $id : 'console/admin/agencies/new'); }
        $web = input('website');
        $row = ['name' => $name, 'short_name' => mb_substr(input('short_name'), 0, 80) ?: null, 'type' => $type, 'parent_id' => $parent && $parent !== $id ? $parent : null,
            'description' => mb_substr(input('description'), 0, 3000) ?: null, 'address' => mb_substr(input('address'), 0, 255) ?: null, 'phone' => mb_substr(input('phone'), 0, 120) ?: null,
            'email' => filter_var(input('email'), FILTER_VALIDATE_EMAIL) ? input('email') : null, 'website' => preg_match('~^https?://~', $web) ? mb_substr($web, 0, 255) : null,
            'hours' => mb_substr(input('hours'), 0, 255) ?: null, 'verified' => input('verified') === '1' ? 1 : 0, 'active' => input('active') === '1' ? 1 : 0, 'updated_at' => now_utc()];
        if ($a) update('agencies', $id, $row);
        else {
            $slug = slugify($name, 90);
            for ($i = 2; val('SELECT 1 FROM agencies WHERE slug = ?', [$slug]); $i++) $slug = slugify($name, 85) . '-' . $i;
            $id = insert('agencies', $row + ['slug' => $slug, 'created_at' => now_utc()]);
        }
        audit($u, $a ? 'agency.update' : 'agency.create', 'agency', $id, $name);
        flash('Agency saved.');
        redirect('console/admin/agencies/' . $id);
    }
    page_open($a ? $a['name'] : 'Add an agency', ['console' => 'agencies', 'crumbs' => [['Agencies', url('console/admin/agencies')]]]);
    echo page_header('Agency', $a ? $a['name'] : 'Add an agency', $a && $a['source_url'] ? 'Source: ' . $a['source_url'] : '');
    $v = fn($k) => h((string) ($a[$k] ?? ''));
    echo '<form method="post" class="card form narrow-form">' . csrf_field()
        . '<label class="field"><span>Name</span><input name="name" value="' . $v('name') . '" required maxlength="255"></label>'
        . '<div class="two"><label class="field"><span>Short name</span><input name="short_name" value="' . $v('short_name') . '" maxlength="80"></label><label class="field"><span>Type</span><select name="type">';
    foreach (AGENCY_TYPES as $k => $l) echo '<option value="' . $k . '"' . (($a['type'] ?? 'department') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select></label></div><label class="field"><span>Part of</span><select name="parent_id"><option value="">—</option>';
    foreach (all("SELECT id, name FROM agencies WHERE type = 'ministry' ORDER BY name") as $p) echo '<option value="' . (int) $p['id'] . '"' . ((int) ($a['parent_id'] ?? 0) === (int) $p['id'] ? ' selected' : '') . '>' . h($p['name']) . '</option>';
    echo '</select></label><label class="field"><span>About</span><textarea name="description" rows="3">' . $v('description') . '</textarea></label>'
        . '<div class="two"><label class="field"><span>Address</span><input name="address" value="' . $v('address') . '"></label><label class="field"><span>Opening hours</span><input name="hours" value="' . $v('hours') . '"></label></div>'
        . '<div class="two"><label class="field"><span>Phone</span><input name="phone" value="' . $v('phone') . '"></label><label class="field"><span>Email</span><input type="email" name="email" value="' . $v('email') . '"></label></div>'
        . '<label class="field"><span>Website</span><input type="url" name="website" value="' . $v('website') . '"></label>'
        . '<label class="check"><input type="checkbox" name="verified" value="1"' . (!empty($a['verified']) ? ' checked' : '') . '> Details confirmed by the agency</label>'
        . '<label class="check"><input type="checkbox" name="active" value="1"' . (!$a || $a['active'] ? ' checked' : '') . '> Shown to residents</label><button class="btn primary">Save</button></form>';
    page_close();
}

function admin_users(): void
{
    $u = require_admin();
    if (is_post()) {
        if (input('act') === 'invite') {
            $email = mb_strtolower(input('email'));
            $role = input('role');
            $agency = (int) input('agency_id') ?: null;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(input('name')) < 2) flash('Enter a name and a valid email.', 'err');
            elseif (!in_array($role, ['officer', 'verifier', 'admin'], true)) flash('Choose a staff role.', 'err');
            elseif ($role === 'officer' && !$agency) flash('Officers belong to an agency. Choose one.', 'err');
            elseif (val('SELECT 1 FROM users WHERE email = ?', [$email])) flash('That email already has an account. Change its role in the list below.', 'warn');
            else {
                $tmp = temp_password();
                $id = insert('users', ['name' => mb_substr(input('name'), 0, 120), 'email' => $email, 'password_hash' => password_hash($tmp, PASSWORD_DEFAULT), 'role' => $role,
                    'agency_id' => $role === 'officer' ? $agency : null, 'must_change_password' => 1, 'email_verified_at' => now_utc(), 'created_at' => now_utc()]);
                audit($u, 'user.invite', 'user', $id, $role);
                send_mail($email, 'Your ' . app_name() . ' staff account', "Hello " . input('name') . ",\n\n" . $u['name'] . " added you to " . app_name() . " as " . ROLES[$role] . ".\n\nSign in: " . absolute_url('login') . "\nEmail: $email\nTemporary password: $tmp\n\nYou'll choose your own password and set up two-step sign-in when you first sign in.");
                $_SESSION['invited'] = [$email, $tmp];
            }
        } else {
            $t = one('SELECT * FROM users WHERE id = ?', [(int) input('id')]);
            if ($t && $t['role'] !== 'owner' && (int) $t['id'] !== (int) $u['id']) {
                $role = isset(ROLES[input('role')]) && input('role') !== 'owner' ? input('role') : $t['role'];
                $agency = (int) input('agency_id') ?: null;
                if ($role === 'officer' && !$agency) { flash('Officers belong to an agency. Choose one for ' . $t['email'] . '.', 'err'); redirect('console/admin/users', ['q' => qs('q'), 'role' => qs('role')]); }
                update('users', (int) $t['id'], ['role' => $role, 'agency_id' => $role === 'officer' ? $agency : null, 'tester' => input('tester') === '1' ? 1 : 0, 'active' => input('active') === '1' ? 1 : 0]);
                audit($u, 'user.update', 'user', (int) $t['id'], "role $role" . ($agency ? " agency $agency" : '') . (input('active') === '1' ? '' : ' (deactivated)'));
                flash('Saved ' . $t['email'] . '.');
            }
        }
        redirect('console/admin/users', ['q' => qs('q'), 'role' => qs('role')]);
    }
    $invited = $_SESSION['invited'] ?? null;
    unset($_SESSION['invited']);
    $q = qs('q');
    $roleF = isset(ROLES[qs('role')]) ? qs('role') : '';
    $where = [];
    $params = [];
    if ($q !== '') { $where[] = '(name LIKE ? OR email LIKE ?)'; $params[] = '%' . addcslashes($q, '\\%_') . '%'; $params[] = $params[0]; }
    if ($roleF !== '') { $where[] = 'role = ?'; $params[] = $roleF; } elseif ($q === '') $where[] = "role <> 'citizen'";
    $rows = all('SELECT * FROM users' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY role, name LIMIT 200', $params);
    $agencies = agencies_list(false);
    page_open('People', ['console' => 'users']);
    echo page_header('Access', 'People and roles', 'Staff accounts, agency assignments and pilot testers. Residents create their own accounts.');
    if ($invited) echo '<div class="callout"><div><strong>Account created for ' . h($invited[0]) . '</strong><p>We emailed their sign-in details. Temporary password (shown once): <code class="key" id="tmp">' . h($invited[1]) . '</code></p></div><button type="button" class="btn outline" data-copy="#tmp">Copy</button></div>';
    echo '<div class="grid-side"><div><form method="get" class="filters"><input name="q" value="' . h($q) . '" placeholder="Search name or email"><select name="role"><option value="">Staff</option>';
    foreach (ROLES as $k => $l) echo '<option value="' . $k . '"' . ($roleF === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select><button class="btn outline">Search</button></form><div class="card flush"><table class="table"><thead><tr><th>Person</th><th>Role & agency</th><th>Tester</th><th>Active</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $locked = $r['role'] === 'owner' || (int) $r['id'] === (int) $u['id'];
        echo '<tr><td>' . h($r['name']) . '<small class="muted">' . h($r['email']) . ($r['totp_enabled'] ? ' · 2-step on' : '') . ($r['id_status'] === 'verified' ? ' · ID verified' : '') . '</small></td>';
        if ($locked) { echo '<td>' . h(ROLES[$r['role']]) . '</td><td></td><td></td><td></td></tr>'; continue; }
        $fid = 'u' . (int) $r['id'];
        echo '<td><form method="post" id="' . $fid . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"></form><select name="role" form="' . $fid . '" aria-label="Role">';
        foreach (ROLES as $k => $l) if ($k !== 'owner') echo '<option value="' . $k . '"' . ($r['role'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        echo '</select><select name="agency_id" form="' . $fid . '" aria-label="Agency"><option value="">No agency</option>';
        foreach ($agencies as $a) echo '<option value="' . (int) $a['id'] . '"' . ((int) $r['agency_id'] === (int) $a['id'] ? ' selected' : '') . '>' . h($a['short_name'] ?: $a['name']) . '</option>';
        echo '</select></td><td class="c"><input type="checkbox" name="tester" value="1" form="' . $fid . '"' . ($r['tester'] ? ' checked' : '') . ' aria-label="Pilot tester"></td>'
            . '<td class="c"><input type="checkbox" name="active" value="1" form="' . $fid . '"' . ($r['active'] ? ' checked' : '') . ' aria-label="Active"></td><td><button class="btn outline sm" form="' . $fid . '">Save</button></td></tr>';
    }
    if (!$rows) echo '<tr><td colspan="5" class="muted">No one found.</td></tr>';
    echo '</tbody></table></div></div><aside class="side"><form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="invite"><p class="micro">Add a staff member</p>'
        . '<label class="field"><span>Name</span><input name="name" required></label><label class="field"><span>Email</span><input type="email" name="email" required></label>'
        . '<label class="field"><span>Role</span><select name="role"><option value="officer">Agency officer</option><option value="verifier">Identity verifier</option><option value="admin">Platform admin</option></select></label>'
        . '<label class="field"><span>Agency <small>for officers</small></span>' . agency_select('agency_id', null, false) . '</label><button class="btn primary">Create account</button></form>'
        . '<div class="card"><p class="micro">Roles</p><ul class="plain small"><li><strong>Agency officer</strong>: their agency’s requests, services, intake and API keys.</li><li><strong>Identity verifier</strong>: checks linked IDs. Appointed by the Government.</li><li><strong>Platform admin</strong>: everything, including clearance.</li><li><strong>Tester</strong>: may link an ID during the pilot.</li></ul></div></aside></div>';
    page_close();
}

function admin_clearance(): void
{
    $u = require_admin();
    if (is_post()) {
        $status = isset(CLEARANCE[input('status')]) ? input('status') : 'none';
        $ref = mb_substr(input('ref'), 0, 120);
        $authority = mb_substr(input('authority'), 0, 190);
        $date = input('date');
        if ($status === 'granted' && ($ref === '' || $authority === '' || !DateTimeImmutable::createFromFormat('!Y-m-d', $date))) {
            flash('To mark the service as authorised, record the approving authority, its reference and the date.', 'err');
            redirect('console/admin/clearance');
        }
        foreach (['clearance_status' => $status, 'clearance_ref' => $ref, 'clearance_authority' => $authority, 'clearance_date' => $date, 'clearance_scope' => mb_substr(input('scope'), 0, 2000)] as $k => $val) set_setting($k, $val);
        audit($u, 'clearance.set', null, null, "$status · $authority · $ref · $date");
        flash('Clearance updated: ' . CLEARANCE[$status] . '.');
        redirect('console/admin/clearance');
    }
    $c = clearance();
    $history = all("SELECT * FROM audit_log WHERE action = 'clearance.set' ORDER BY id DESC LIMIT 20");
    page_open('Government clearance', ['console' => 'clearance']);
    echo page_header('Clearance', 'Government of Saint Lucia authorisation', 'This platform links residents’ IDs only with the Government’s approval. Record the decision here; it controls what residents can do.');
    echo '<div class="grid-side"><form method="post" class="card form">' . csrf_field() . '<fieldset class="field"><legend>Status</legend>';
    $help = ['none' => 'Directory and requests only. ID linking is closed. Residents see a preview notice.', 'pilot' => 'ID linking open to staff and testers you mark in People. Residents see a pilot notice.',
        'granted' => 'ID linking open to every resident. The pilot notice disappears.', 'suspended' => 'ID linking paused; existing verifications stay.'];
    foreach (CLEARANCE as $k => $l) echo '<label class="radio-card"><input type="radio" name="status" value="' . $k . '"' . ($c === $k ? ' checked' : '') . '><span><strong>' . h($l) . '</strong><small>' . h($help[$k]) . '</small></span></label>';
    echo '</fieldset><label class="field"><span>Approving authority</span><input name="authority" value="' . h((string) setting('clearance_authority', '')) . '" placeholder="e.g. Cabinet of Ministers / Department of Public Sector Modernization"></label>'
        . '<div class="two"><label class="field"><span>Reference</span><input name="ref" value="' . h((string) setting('clearance_ref', '')) . '" placeholder="Cabinet conclusion or letter reference"></label>'
        . '<label class="field"><span>Date</span><input type="date" name="date" value="' . h((string) setting('clearance_date', '')) . '"></label></div>'
        . '<label class="field"><span>Scope and conditions</span><textarea name="scope" rows="4" placeholder="What is authorised, by whom identity checks are done, data retention, review date…">' . h((string) setting('clearance_scope', '')) . '</textarea></label>'
        . '<button class="btn primary">Save</button></form><aside class="side"><div class="card"><p class="micro">History</p>';
    if ($history) {
        echo '<ul class="plain small">';
        foreach ($history as $hst) echo '<li>' . h(fmt_datetime($hst['created_at'])) . ' · ' . h(user_name($hst['actor_id'] ? (int) $hst['actor_id'] : null)) . '<br><span class="muted">' . h((string) $hst['detail']) . '</span></li>';
        echo '</ul>';
    } else echo '<p class="muted">No changes yet.</p>';
    echo '</div></aside></div>';
    page_close();
}

function admin_settings(): void
{
    $u = require_admin();
    if (is_post()) {
        $act = input('act');
        if ($act === 'general') {
            set_setting('app_name', mb_substr(input('app_name'), 0, 60) ?: 'SLU Services');
            set_setting('tagline', mb_substr(input('tagline'), 0, 160));
            set_setting('mail_from', filter_var(input('mail_from'), FILTER_VALIDATE_EMAIL) ? input('mail_from') : '');
            set_setting('request_prefix', preg_replace('/[^A-Z]/', '', strtoupper(input('request_prefix'))) ?: 'SLU');
            set_setting('id_image_retention_days', (string) max(1, min(365, (int) input('retention'))));
            set_setting('require_staff_2fa', input('staff_2fa') === '1' ? '1' : '0');
            audit($u, 'settings.update');
            flash('Settings saved.');
        } elseif ($act === 'ai') {
            if (input('remove') === '1') set_setting('ai_key', '');
            elseif (input('ai_key') !== '') set_setting('ai_key', encrypt_secret(input('ai_key')));
            set_setting('ai_model', isset(AI_MODELS[input('ai_model')]) ? input('ai_model') : 'claude-opus-5-5');
            audit($u, 'settings.ai');
            flash('AI reading settings saved.');
        } elseif ($act === 'mailbox') {
            set_setting('intake_imap_host', preg_match('/^[a-z0-9.-]*$/i', input('host')) ? mb_strtolower(input('host')) : '');
            set_setting('intake_imap_port', in_array((int) input('port'), [143, 993], true) ? input('port') : '993');
            set_setting('intake_imap_security', in_array(input('security'), ['ssl', 'starttls'], true) ? input('security') : 'ssl');
            set_setting('intake_imap_user', input('user'));
            if (input('pass') !== '') set_setting('intake_imap_pass', encrypt_secret((string) $_POST['pass']));
            set_setting('intake_email_domains', mb_strtolower(preg_replace('/[^a-z0-9.,\s-]/i', '', input('domains'))));
            audit($u, 'settings.mailbox');
            flash('Mailbox saved.');
        } elseif ($act === 'check_mailbox') {
            @set_time_limit(120);
            flash(intake_poll_mailbox(60));
        }
        redirect('console/admin/settings');
    }
    $hasKey = decrypt_secret((string) setting('ai_key', '')) !== '';
    page_open('Settings', ['console' => 'settings']);
    echo page_header('Platform', 'Settings');
    echo '<div class="grid-2"><form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="general"><h2>General</h2>'
        . '<label class="field"><span>App name</span><input name="app_name" value="' . h(app_name()) . '" maxlength="60"></label>'
        . '<label class="field"><span>Tagline</span><input name="tagline" value="' . h((string) setting('tagline', '')) . '" maxlength="160"></label>'
        . '<div class="two"><label class="field"><span>Email “from” address</span><input type="email" name="mail_from" value="' . h((string) setting('mail_from', '')) . '" placeholder="no-reply@digitalsolucians.com"></label>'
        . '<label class="field"><span>Request reference prefix</span><input name="request_prefix" value="' . h((string) setting('request_prefix', 'SLU')) . '" maxlength="6"></label></div>'
        . '<label class="field"><span>Delete ID photos this many days after a decision</span><input type="number" name="retention" min="1" max="365" value="' . (int) setting('id_image_retention_days', '30') . '"></label>'
        . '<label class="check"><input type="checkbox" name="staff_2fa" value="1"' . (setting('require_staff_2fa', '1') === '1' ? ' checked' : '') . '> Staff must use two-step sign-in</label><button class="btn primary">Save</button></form>';
    echo '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="ai"><h2>AI reading for intake</h2><p>Claude reads documents, PDFs and photos of forms and turns them into drafts. Officers still review everything. Get a key at console.anthropic.com.</p>'
        . '<label class="field"><span>Anthropic API key</span><input type="password" name="ai_key" autocomplete="off" placeholder="' . ($hasKey ? 'Saved (enter a new key to replace it)' : 'sk-ant-…') . '"></label>'
        . '<label class="field"><span>Model</span><select name="ai_model">';
    foreach (AI_MODELS as $k => $l) echo '<option value="' . $k . '"' . (setting('ai_model', 'claude-opus-5-5') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    echo '</select></label>' . ($hasKey ? '<label class="check"><input type="checkbox" name="remove" value="1"> Remove the saved key</label>' : '') . '<button class="btn primary">Save</button></form>';
    echo '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="mailbox"><h2>Intake mailbox</h2><p>Agencies email documents to this mailbox; new mail is read every 15 minutes (cron) or when you press “Check now”.</p>'
        . '<div class="two"><label class="field"><span>IMAP server</span><input name="host" value="' . h((string) setting('intake_imap_host', '')) . '" placeholder="imap.hostinger.com"></label>'
        . '<label class="field"><span>Port</span><input type="number" name="port" value="' . (int) setting('intake_imap_port', '993') . '"></label></div>'
        . '<div class="two"><label class="field"><span>Security</span><select name="security"><option value="ssl">SSL/TLS</option><option value="starttls"' . (setting('intake_imap_security') === 'starttls' ? ' selected' : '') . '>STARTTLS</option></select></label>'
        . '<label class="field"><span>Mailbox (email)</span><input name="user" value="' . h((string) setting('intake_imap_user', '')) . '" placeholder="services@digitalsolucians.com"></label></div>'
        . '<label class="field"><span>Password</span><input type="password" name="pass" autocomplete="new-password" placeholder="' . (setting('intake_imap_pass') ? 'Saved' : '') . '"></label>'
        . '<label class="field"><span>Accept email from these domains</span><input name="domains" value="' . h((string) setting('intake_email_domains', 'govt.lc')) . '"><small class="muted">Plus any staff account’s or agency’s email address.</small></label>'
        . '<div class="row"><button class="btn primary">Save</button><button class="btn outline" name="act" value="check_mailbox" formnovalidate>Check now</button></div>'
        . (setting('intake_imap_last') ? '<p class="muted small">Last check: ' . h((string) setting('intake_imap_last')) . '</p>' : '') . '</form>';
    echo '<div class="card"><h2>Background tasks</h2><p>Add this cron job in hPanel › Advanced › Cron Jobs, every 15 minutes. It reads the intake mailbox, finishes queued intake and deletes expired ID photos.</p><pre class="code">php ' . h(ROOT_DIR) . '/cron.php</pre>'
        . '<p class="muted small">Last run: ' . h((string) setting('cron_last', 'never')) . '</p></div></div>';
    page_close();
}

function admin_data(): void
{
    require_admin();
    $total = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published'");
    $conf = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published' AND agency_verified = 1");
    $fields = ['summary', 'eligibility', 'requirements', 'fees', 'processing_time', 'steps', 'hours', 'contact'];
    $complete = [];
    foreach ($fields as $f) {
        $complete[$f] = $total ? round(100 * (int) val("SELECT COUNT(*) FROM services WHERE status = 'published' AND $f IS NOT NULL AND $f NOT IN ('', '[]')") / $total) : 0;
    }
    $byAgency = all("SELECT a.id, a.name, COUNT(s.id) n, SUM(s.agency_verified) c, MAX(s.updated_at) last FROM agencies a LEFT JOIN services s ON s.agency_id = a.id AND s.status = 'published' WHERE a.active = 1 GROUP BY a.id ORDER BY n DESC, a.name");
    $intake = all('SELECT channel, COUNT(*) n, SUM(drafts) d FROM intake_items GROUP BY channel');
    $reqs = all('SELECT status, COUNT(*) n FROM requests GROUP BY status');
    page_open('Data', ['console' => 'data']);
    echo page_header('Digest', 'Catalogue health', 'How complete and current the information residents see is.');
    echo '<div class="stats">' . stat_tile('Published services', $total) . stat_tile('Confirmed by agencies', $total ? round(100 * $conf / $total) . '%' : '—', '', $conf . ' of ' . $total)
        . stat_tile('Intake submissions', (int) val('SELECT COUNT(*) FROM intake_items')) . stat_tile('Requests', (int) val('SELECT COUNT(*) FROM requests')) . '</div>';
    echo '<div class="grid-2"><section class="card"><h2>Field completeness</h2><ul class="bars">';
    foreach ($complete as $f => $pct) echo '<li><span>' . h(FIELD_LABELS[$f]) . '</span><span class="meter" style="--v:' . $pct . '%"><i></i></span><strong>' . $pct . '%</strong></li>';
    echo '</ul></section><section class="card"><h2>Intake by channel</h2><ul class="bars">';
    foreach ($intake as $i) echo '<li><span>' . h(INTAKE_CHANNELS[$i['channel']] ?? $i['channel']) . '</span><strong>' . (int) $i['n'] . ' · ' . (int) $i['d'] . ' drafts</strong></li>';
    if (!$intake) echo '<li class="muted">Nothing yet.</li>';
    echo '</ul><h2>Requests by status</h2><ul class="bars">';
    foreach ($reqs as $r) echo '<li><span>' . h(REQUEST_STATUS[$r['status']] ?? $r['status']) . '</span><strong>' . (int) $r['n'] . '</strong></li>';
    if (!$reqs) echo '<li class="muted">None yet.</li>';
    echo '</ul></section></div><section class="card flush"><table class="table"><thead><tr><th>Agency</th><th>Services</th><th>Confirmed</th><th>Last update</th></tr></thead><tbody>';
    foreach ($byAgency as $a) echo '<tr><td><a href="' . h(url('console/services', ['agency' => $a['id']])) . '">' . h($a['name']) . '</a></td><td>' . (int) $a['n'] . '</td><td>' . ((int) $a['n'] ? round(100 * (int) $a['c'] / (int) $a['n']) . '%' : '—') . '</td><td>' . h($a['last'] ? time_ago($a['last']) : '—') . '</td></tr>';
    echo '</tbody></table></section>';
    page_close();
}

function admin_audit(): void
{
    require_admin();
    $action = qs('action');
    $rows = all('SELECT * FROM audit_log' . ($action !== '' ? ' WHERE action LIKE ?' : '') . ' ORDER BY id DESC LIMIT 300', $action !== '' ? [addcslashes($action, '\\%_') . '%'] : []);
    page_open('Audit log', ['console' => 'audit']);
    echo page_header('Accountability', 'Audit log', 'Staff sign-ins, changes, ID decisions and every time an ID photo is opened.');
    echo '<form method="get" class="filters"><select name="action" data-autosubmit><option value="">All actions</option>';
    foreach (['id.' => 'ID checks', 'service.' => 'Services', 'intake.' => 'Intake', 'request.' => 'Requests', 'user.' => 'People', 'clearance.' => 'Clearance', 'login' => 'Sign-ins', 'apikey.' => 'API keys', 'file.' => 'File views'] as $k => $l) {
        echo '<option value="' . h($k) . '"' . ($action === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    echo '</select></form><div class="card flush"><table class="table"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
    foreach ($rows as $r) echo '<tr><td>' . h(fmt_datetime($r['created_at'])) . '</td><td>' . h(user_name($r['actor_id'] ? (int) $r['actor_id'] : null)) . '<small class="muted">' . h((string) $r['ip']) . '</small></td><td><code>' . h($r['action']) . '</code></td><td>' . h(($r['target'] ? $r['target'] . ' #' . $r['target_id'] . ' ' : '') . $r['detail']) . '</td></tr>';
    if (!$rows) echo '<tr><td colspan="4" class="muted">Nothing recorded.</td></tr>';
    echo '</tbody></table></div>';
    page_close();
}
