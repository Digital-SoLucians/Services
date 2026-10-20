<?php
defined('DS') || exit;

// Resident pages: home, finding services (search, topics, life events, agencies), a service's
// page, applying online, tracking requests, saved services and notifications.

function greeting(string $name): string
{
    $hour = (int) date('G');
    return ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening')) . ', ' . explode(' ', trim($name))[0];
}

function service_card(array $s, bool $showAgency = true): string
{
    $fees = array_filter(array_column($s['fees'] ?? [], 'amount'), fn($a) => $a !== null);
    $fee = $fees ? (max($fees) == 0 ? 'No fee' : (min($fees) == max($fees) ? money((float) max($fees)) : money((float) min($fees)) . '–' . money((float) max($fees)))) : '';
    $mode = $s['mode'] === 'request' ? '<span class="tag hot">Apply here</span>' : ($s['mode'] === 'external' ? '<span class="tag">Online</span>' : '');
    return '<a class="svc-card" href="' . h(url('services/' . $s['slug'])) . '">' . ($showAgency ? '<span class="micro">' . h($s['agency_short'] ?: $s['agency_name']) . '</span>' : '')
        . '<strong>' . h($s['title']) . '</strong>' . ($s['summary'] ? '<p>' . h(mb_strimwidth($s['summary'], 0, 150, '…')) . '</p>' : '')
        . '<span class="svc-meta">' . $mode . ($fee !== '' ? '<span class="tag">' . h($fee) . '</span>' : '') . ($s['processing_time'] ? '<span class="tag">' . h(mb_strimwidth($s['processing_time'], 0, 28, '…')) . '</span>' : '')
        . ($s['agency_verified'] ? '<span class="tag ok" title="Confirmed by the agency">' . icon('check') . 'Confirmed</span>' : '') . '</span></a>';
}

function search_form(string $q = '', bool $big = false): string
{
    return '<form class="composite' . ($big ? ' big' : '') . '" method="get" action="' . h(url('services')) . '" role="search"><label class="sr-only" for="q">Search services</label>'
        . '<input id="q" name="q" value="' . h($q) . '" placeholder="Search, e.g. passport, birth certificate, trade licence" autocomplete="off"><button class="btn primary">' . icon('search') . 'Search</button></form>';
}

function home_page(): void
{
    $u = require_login();
    $open = all("SELECT r.*, s.title, s.slug FROM requests r JOIN services s ON s.id = r.service_id WHERE r.user_id = ? AND r.status IN ('submitted','in_review','action_needed','approved','ready') ORDER BY r.updated_at DESC LIMIT 5", [$u['id']]);
    $saved = list_services(['s.id IN (SELECT service_id FROM saved_services WHERE user_id = ?)'], [$u['id']], 's.title', 6);
    $recent = list_services(['s.agency_verified = 1'], [], 's.last_verified_at DESC', 4);
    $popular = list_services([], [], 's.views DESC, s.title', 6);
    page_open('Home', ['nav' => 'home']);
    echo page_header('Home', greeting((string) $u['name']), 'What do you need to do today?');
    echo search_form('', true);
    if (!$u['email_verified_at']) echo '<div class="callout"><div><strong>Confirm your email</strong><p>You need a confirmed email to apply for services and link your ID.</p></div><a class="btn primary" href="' . h(url('verify-email')) . '">Confirm now</a></div>';
    if (is_staff($u)) echo '<div class="callout dark"><div><strong>Government console</strong><p>' . h(ROLES[$u['role']]) . ' tools: requests, services, intake and ID checks.</p></div><a class="btn primary" href="' . h(url('console')) . '">Open console</a></div>';

    echo '<div class="grid-2">';
    echo '<section class="card"><div class="card-head"><h2>My requests</h2><a class="link" href="' . h(url('requests')) . '">All</a></div>';
    if ($open) {
        echo '<ul class="rows">';
        foreach ($open as $r) echo '<li><a href="' . h(url('requests/' . $r['ref'])) . '"><span><strong>' . h($r['title']) . '</strong><small class="muted">' . h($r['ref']) . ' · updated ' . h(time_ago($r['updated_at'])) . '</small></span>' . request_badge($r['status']) . '</a></li>';
        echo '</ul>';
    } else echo '<p class="muted">Nothing in progress. When you apply for a service here, you can follow it step by step.</p>';
    echo '</section>';
    $status = $u['id_status'];
    echo '<section class="card"><div class="card-head"><h2>My ID</h2><a class="link" href="' . h(url('id')) . '">Open</a></div>'
        . ($status === 'verified' ? '<p class="id-ok">' . icon('shield') . ' Your identity is verified.</p>'
            : ($status === 'pending' ? '<p>Your ID is with an identity officer. We’ll let you know when it’s checked.</p>'
                : (id_linking_open($u) ? '<p>Link your ID once to use services that need proof of identity.</p><a class="btn outline" href="' . h(url('id')) . '">Link my ID</a>'
                    : '<p class="muted">ID linking opens when the Government of Saint Lucia authorises it. You can still find services and apply where no ID is needed.</p>')))
        . '</section></div>';

    echo '<section class="section"><div class="section-head"><h2>Life events</h2><a class="link" href="' . h(url('services')) . '">All topics</a></div><div class="chip-grid">';
    foreach (categories('life_event') as $c) echo '<a class="chip" href="' . h(url('topics/' . $c['slug'])) . '">' . icon((string) $c['icon']) . '<span>' . h($c['name']) . '</span></a>';
    echo '</div></section>';
    if ($saved) {
        echo '<section class="section"><div class="section-head"><h2>Saved</h2><a class="link" href="' . h(url('saved')) . '">All</a></div><div class="cards">';
        foreach ($saved as $s) echo service_card($s);
        echo '</div></section>';
    }
    echo '<section class="section"><div class="section-head"><h2>' . ($recent ? 'Recently confirmed by agencies' : 'Popular services') . '</h2></div><div class="cards">';
    foreach ($recent ?: $popular as $s) echo service_card($s);
    echo '</div></section>';
    echo '<div class="callout"><div><strong>Moved house?</strong><p>Tell us once and we’ll notify the agencies you choose.</p></div><a class="btn outline" href="' . h(url('account/address')) . '">Update my address</a></div>';
    page_close();
}

function services_page(): void
{
    require_login();
    $q = qs('q');
    page_open($q !== '' ? 'Search: ' . $q : 'Services', ['nav' => 'services']);
    if ($q !== '') {
        $res = search_services($q);
        echo page_header('Search', 'Results for “' . $q . '”', plural(count($res), 'service', 'services') . ' found.');
        echo search_form($q);
        if ($res) {
            echo '<div class="cards">';
            foreach ($res as $s) echo service_card($s);
            echo '</div>';
        } else {
            echo empty_state('No services matched. Try fewer or different words, or browse by topic below.');
        }
    } else {
        echo page_header('Services', 'Find a service', 'Search across every ministry, department and statutory body, or browse by topic.');
        echo search_form('', true);
    }
    echo '<section class="section"><div class="section-head"><h2>Life events</h2></div><div class="chip-grid">';
    foreach (categories('life_event') as $c) echo '<a class="chip" href="' . h(url('topics/' . $c['slug'])) . '">' . icon((string) $c['icon']) . '<span>' . h($c['name']) . '</span><small>' . (int) $c['n'] . '</small></a>';
    echo '</div></section><section class="section"><div class="section-head"><h2>Topics</h2><a class="link" href="' . h(url('agencies')) . '">Browse by agency</a></div><div class="topic-grid">';
    foreach (categories('topic') as $c) {
        echo '<a class="topic" href="' . h(url('topics/' . $c['slug'])) . '">' . icon((string) $c['icon']) . '<strong>' . h($c['name']) . '</strong><p>' . h((string) $c['description']) . '</p><span class="micro">' . plural((int) $c['n'], 'service', 'services') . '</span></a>';
    }
    echo '</div></section>';
    page_close();
}

function topic_page(string $slug): void
{
    require_login();
    $c = one('SELECT * FROM categories WHERE slug = ?', [$slug]);
    if (!$c) not_found();
    $rows = list_services(['s.id IN (SELECT service_id FROM service_categories WHERE category_id = ?)'], [$c['id']]);
    page_open($c['name'], ['nav' => 'services', 'crumbs' => [['Services', url('services')]]]);
    echo page_header($c['kind'] === 'life_event' ? 'Life event' : 'Topic', (string) $c['name'], (string) $c['description']);
    if ($rows) {
        echo '<div class="cards">';
        foreach ($rows as $s) echo service_card($s);
        echo '</div>';
    } else echo empty_state('No services here yet. Agencies are adding more.');
    page_close();
}

function agencies_page(): void
{
    require_login();
    $all = agencies_list();
    page_open('Agencies', ['nav' => 'services', 'crumbs' => [['Services', url('services')]]]);
    echo page_header('Directory', 'Ministries, departments and statutory bodies', plural(count($all), 'agency', 'agencies') . ' of the Government of Saint Lucia.');
    foreach (AGENCY_TYPES as $type => $label) {
        $group = array_filter($all, fn($a) => $a['type'] === $type);
        if (!$group) continue;
        echo '<section class="section"><div class="section-head"><h2>' . h($label === 'Statutory body' ? 'Statutory bodies' : $label . 's') . '</h2></div><ul class="agency-list">';
        foreach ($group as $a) echo '<li><a href="' . h(url('agencies/' . $a['slug'])) . '"><span>' . h($a['name']) . '</span><small class="muted">' . plural((int) $a['n'], 'service', 'services') . '</small></a></li>';
        echo '</ul></section>';
    }
    page_close();
}

function agency_page(string $slug): void
{
    require_login();
    $a = one('SELECT * FROM agencies WHERE slug = ? AND active = 1', [$slug]);
    if (!$a) not_found();
    $parent = $a['parent_id'] ? one('SELECT * FROM agencies WHERE id = ?', [$a['parent_id']]) : null;
    $children = all('SELECT * FROM agencies WHERE parent_id = ? AND active = 1 ORDER BY name', [$a['id']]);
    $rows = list_services(['s.agency_id = ?'], [$a['id']]);
    page_open($a['name'], ['nav' => 'services', 'crumbs' => [['Agencies', url('agencies')]]]);
    echo page_header(AGENCY_TYPES[$a['type']] ?? 'Agency', (string) $a['name'], (string) $a['description']);
    echo '<div class="grid-side"><div>';
    if ($rows) {
        echo '<div class="cards one">';
        foreach ($rows as $s) echo service_card($s, false);
        echo '</div>';
    } else echo empty_state('This agency hasn’t listed services here yet.');
    echo '</div><aside class="card facts">';
    foreach ([['pin', $a['address']], ['phone', $a['phone']], ['mail', $a['email']], ['clock', $a['hours']]] as [$ic, $v]) if ($v) echo '<p>' . icon($ic) . '<span>' . h($v) . '</span></p>';
    if ($a['website']) echo '<p>' . icon('link') . '<a href="' . h($a['website']) . '" target="_blank" rel="noopener">' . h(parse_url($a['website'], PHP_URL_HOST) ?: $a['website']) . '</a></p>';
    if ($parent) echo '<p class="micro">Part of</p><p><a href="' . h(url('agencies/' . $parent['slug'])) . '">' . h($parent['name']) . '</a></p>';
    if ($children) {
        echo '<p class="micro">Departments</p><ul class="plain">';
        foreach ($children as $c) echo '<li><a href="' . h(url('agencies/' . $c['slug'])) . '">' . h($c['name']) . '</a></li>';
        echo '</ul>';
    }
    echo '<p class="micro">' . ($a['verified'] ? 'Details confirmed by the agency' : 'Details from public sources, awaiting confirmation') . '</p></aside></div>';
    page_close();
}

function service_page(string $slug): void
{
    $u = require_login();
    $s = service_by_slug($slug);
    if (!$s) not_found();
    q('UPDATE services SET views = views + 1 WHERE id = ?', [$s['id']]);
    $saved = (bool) val('SELECT 1 FROM saved_services WHERE user_id = ? AND service_id = ?', [$u['id'], $s['id']]);
    $agency = one('SELECT * FROM agencies WHERE id = ?', [$s['agency_id']]);
    $cats = all('SELECT c.* FROM categories c JOIN service_categories sc ON sc.category_id = c.id WHERE sc.service_id = ? ORDER BY c.kind DESC, c.position', [$s['id']]);
    $related = $cats ? list_services(['s.id <> ?', 's.id IN (SELECT service_id FROM service_categories WHERE category_id IN (' . in_list(array_column($cats, 'id')) . '))'], [$s['id'], ...array_column($cats, 'id')], 's.views DESC', 4) : [];
    $cta = match ($s['mode']) {
        'request' => '<a class="btn primary" href="' . h(url('services/' . $s['slug'] . '/apply')) . '">Apply online' . icon('arrow') . '</a>',
        'external' => '<a class="btn primary" href="' . h((string) $s['online_url']) . '" target="_blank" rel="noopener">Apply on ' . h(parse_url((string) $s['online_url'], PHP_URL_HOST) === 'digigov.govt.lc' ? 'digiGov' : 'the agency’s site') . icon('external') . '</a>',
        default => '',
    };
    $saveBtn = '<form method="post" action="' . h(url('services/' . $s['slug'] . '/save')) . '">' . csrf_field() . '<button class="btn outline' . ($saved ? ' on' : '') . '" aria-pressed="' . ($saved ? 'true' : 'false') . '">' . icon('bookmark') . ($saved ? 'Saved' : 'Save') . '</button></form>';
    page_open($s['title'], ['nav' => 'services', 'crumbs' => [['Services', url('services')], [$s['agency_short'] ?: $s['agency_name'], url('agencies/' . $s['agency_slug'])]]]);
    echo page_header($s['agency_name'], $s['title'], (string) $s['summary'], $cta . $saveBtn);
    echo '<div class="grid-side"><div class="svc-body">';
    $facts = [];
    if ($s['fees']) {
        $f = '<table class="fees"><tbody>';
        foreach ($s['fees'] as $fee) $f .= '<tr><td>' . h($fee['label']) . '</td><td class="num">' . h($fee['amount'] === null ? 'Varies' : ((float) $fee['amount'] == 0 ? 'No fee' : money((float) $fee['amount']))) . '</td></tr>';
        $facts[] = ['Fees', $f . '</tbody></table>'];
    }
    if ($s['processing_time']) $facts[] = ['How long it takes', '<p>' . h($s['processing_time']) . '</p>'];
    if ($facts) {
        echo '<div class="facts-row">';
        foreach ($facts as [$l, $v]) echo '<div class="wash"><span class="micro">' . h($l) . '</span>' . $v . '</div>';
        echo '</div>';
    }
    if ($s['eligibility']) echo '<section><h2>Who can apply</h2><p>' . nl2br(h($s['eligibility'])) . '</p></section>';
    if ($s['requirements']) {
        echo '<section><h2>What you need</h2><p class="hint">Tick things off as you gather them. Your ticks stay on this device.</p><ul class="checks" data-checklist="svc-' . (int) $s['id'] . '">';
        // ticks are keyed by the item's text, so they stay right when the agency edits the list
        foreach ($s['requirements'] as $r) echo '<li><label><input type="checkbox" data-i="' . hash('crc32b', $r) . '"><span>' . h($r) . '</span></label></li>';
        echo '</ul></section>';
    }
    if ($s['steps']) {
        echo '<section><h2>How to apply</h2><ol class="steps">';
        foreach ($s['steps'] as $st) echo '<li>' . h($st) . '</li>';
        echo '</ol></section>';
    }
    if ($s['description']) echo '<section><h2>More information</h2><div class="prose-sm">' . autolink((string) $s['description']) . '</div></section>';
    if ($s['mode'] === 'request') {
        echo '<section class="callout"><div><strong>Apply online</strong><p>' . ($s['requires_id'] ? 'This service needs a verified ID. ' : '') . 'Send your request here and follow its progress.</p></div>' . $cta . '</section>';
    }
    if ($s['form_url']) echo '<p><a class="btn outline" href="' . h((string) $s['form_url']) . '" target="_blank" rel="noopener">' . icon('download') . 'Download the form</a></p>';
    echo '</div><aside class="side">';
    echo '<div class="card facts"><p class="micro">Where and when</p>';
    $loc = $s['location'] ?: $agency['address'];
    $hrs = $s['hours'] ?: $agency['hours'];
    $contact = $s['contact'] ?: implode("\n", array_filter([$agency['phone'], $agency['email']]));
    if ($loc) echo '<p>' . icon('pin') . '<span>' . nl2br(h($loc)) . '</span></p>';
    if ($hrs) echo '<p>' . icon('clock') . '<span>' . h($hrs) . '</span></p>';
    if ($contact) echo '<p>' . icon('phone') . '<span>' . autolink($contact) . '</span></p>';
    echo '<p>' . icon('building') . '<a href="' . h(url('agencies/' . $agency['slug'])) . '">' . h($agency['name']) . '</a></p></div>';
    echo '<div class="card facts"><p class="micro">About this page</p>'
        . ($s['agency_verified'] ? '<p>' . icon('check') . '<span>Confirmed by the agency ' . h(fmt_date($s['last_verified_at'])) . '</span></p>'
            : '<p>' . icon('clock') . '<span>From public government sources, awaiting agency confirmation. Check details with the agency before you go.</span></p>')
        . ($s['source_url'] ? '<p>' . icon('link') . '<a href="' . h((string) $s['source_url']) . '" target="_blank" rel="noopener">Source</a></p>' : '')
        . '<p class="muted small">Version ' . (int) $s['version'] . ' · updated ' . h(fmt_date($s['updated_at'])) . '</p>'
        . '<details class="feedback"><summary>Something wrong on this page?</summary><form method="post" action="' . h(url('services/' . $s['slug'] . '/save')) . '" class="form">' . csrf_field()
        . '<input type="hidden" name="act" value="feedback"><label class="field"><span>What should be fixed?</span><textarea name="feedback" rows="3" required maxlength="1000"></textarea></label><button class="btn outline sm">Send to the agency</button></form></details></div>';
    if ($cats) {
        echo '<div class="tags">';
        foreach ($cats as $c) echo '<a class="tag" href="' . h(url('topics/' . $c['slug'])) . '">' . h($c['name']) . '</a>';
        echo '</div>';
    }
    echo '</aside></div>';
    if ($related) {
        echo '<section class="section"><div class="section-head"><h2>Related services</h2></div><div class="cards">';
        foreach ($related as $r) echo service_card($r);
        echo '</div></section>';
    }
    page_close();
}

/** Save/unsave a service, or send page feedback to the agency. */
function service_save_toggle(string $slug): void
{
    $u = require_login();
    $s = service_by_slug($slug);
    if (!$s) not_found();
    if (input('act') === 'feedback') {
        $text = mb_substr(input('feedback'), 0, 1000);
        $recent = (int) val("SELECT COUNT(*) FROM notices WHERE user_id = ? AND kind = 'feedback' AND created_at > ?", [$u['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
        if (!$u['email_verified_at']) flash('Confirm your email before sending feedback.', 'warn');
        elseif ($recent >= 5) flash('You’ve sent a lot of feedback in the last hour. Try again later.', 'warn');
        elseif ($text !== '') {
            insert('notices', ['user_id' => (int) $u['id'], 'agency_id' => (int) $s['agency_id'], 'kind' => 'feedback', 'body' => 'About “' . $s['title'] . '” (' . absolute_url('services/' . $s['slug']) . "):\n" . $text, 'created_at' => now_utc()]);
            notify_agency((int) $s['agency_id'], 'Feedback on “' . $s['title'] . '”', 'console/notices');
            flash('Thanks. The agency will review the page.');
        }
        redirect('services/' . $slug);
    }
    if (val('SELECT 1 FROM saved_services WHERE user_id = ? AND service_id = ?', [$u['id'], $s['id']])) {
        q('DELETE FROM saved_services WHERE user_id = ? AND service_id = ?', [$u['id'], $s['id']]);
        flash('Removed from saved services.');
    } else {
        insert('saved_services', ['user_id' => (int) $u['id'], 'service_id' => (int) $s['id'], 'created_at' => now_utc()]);
        flash('Saved. We’ll tell you when the agency updates it.');
    }
    redirect('services/' . $slug);
}

function saved_page(): void
{
    $u = require_login();
    $rows = list_services(['s.id IN (SELECT service_id FROM saved_services WHERE user_id = ?)'], [$u['id']]);
    page_open('Saved services', ['nav' => 'services']);
    echo page_header('Saved', 'Saved services', 'You’ll get a notification when an agency updates one of these.');
    if ($rows) {
        echo '<div class="cards">';
        foreach ($rows as $s) echo service_card($s);
        echo '</div>';
    } else echo empty_state('Nothing saved yet. Use “Save” on a service page.', '<a class="btn outline" href="' . h(url('services')) . '">Find a service</a>');
    page_close();
}

/* ---------- applying and tracking ---------- */

function apply_page(string $slug): void
{
    $u = require_verified_email();
    $s = service_by_slug($slug);
    if (!$s || $s['mode'] !== 'request') not_found();
    $needsId = (bool) $s['requires_id'];
    $fields = request_fields_for($s);
    $errors = [];
    if (is_post() && !($needsId && $u['id_status'] !== 'verified')) {
        [$vals, $files, $errors] = collect_request_fields($fields, (int) $u['id']);
        if (input('confirm') !== '1') $errors[] = 'Confirm that the information is true.';
        if (!$errors) {
            $recent = (int) val('SELECT COUNT(*) FROM requests WHERE user_id = ? AND created_at > ?', [$u['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
            if ($recent >= 10) $errors[] = 'You’ve sent a lot of requests in the last hour. Try again later.';
        }
        if ($errors) foreach ($files as $fid) vault_delete((int) $fid);  // nothing is kept from a form that wasn't sent
        if (!$errors) {
            $data = ['fields' => array_map(fn($f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type']], $fields), 'values' => $vals,
                'applicant' => ['name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'], 'district' => $u['district'], 'id_verified' => $u['id_status'] === 'verified']];
            $ref = new_request_ref();
            $id = insert('requests', ['ref' => $ref, 'user_id' => (int) $u['id'], 'service_id' => (int) $s['id'], 'agency_id' => (int) $s['agency_id'], 'status' => 'submitted',
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            add_request_event($id, $u, 'citizen', 'submitted', null, 'submitted');
            notify((int) $u['id'], 'We sent your request for “' . $s['title'] . '” to ' . ($s['agency_short'] ?: $s['agency_name']) . '. Reference ' . $ref . '.', 'requests/' . $ref);
            notify_agency((int) $s['agency_id'], 'New request ' . $ref . ': ' . $s['title'], 'console/requests/' . $ref);
            redirect('requests/' . $ref);
        }
    }
    page_open('Apply: ' . $s['title'], ['nav' => 'services', 'crumbs' => [['Services', url('services')], [$s['title'], url('services/' . $s['slug'])]]]);
    echo page_header($s['agency_name'], 'Apply: ' . $s['title'], 'Your request goes to ' . ($s['agency_short'] ?: $s['agency_name']) . '. You’ll get a reference number and can follow it under My requests.');
    if ($needsId && $u['id_status'] !== 'verified') {
        echo '<div class="callout"><div><strong>This service needs a verified ID</strong><p>' . (id_linking_open($u) ? 'Link your ID first; an identity officer checks it.' : 'ID linking opens when the Government of Saint Lucia authorises it.') . '</p></div>'
            . (id_linking_open($u) ? '<a class="btn primary" href="' . h(url('id')) . '">Link my ID</a>' : '') . '</div>';
        page_close();
        return;
    }
    if ($errors) {
        echo '<div class="flash err" role="alert"><ul>';
        foreach ($errors as $e) echo '<li>' . h($e) . '</li>';
        echo '</ul></div>';
    }
    echo '<div class="grid-side"><form method="post" enctype="multipart/form-data" class="card form">' . csrf_field()
        . '<p class="micro">Your details</p><p>' . h($u['name']) . ' · ' . h($u['email']) . ($u['phone'] ? ' · ' . h($u['phone']) : '') . ($u['id_status'] === 'verified' ? ' · <span class="tag ok">' . icon('check') . 'ID verified</span>' : '') . '</p>';
    foreach ($fields as $f) echo render_request_field($f, $_POST['f_' . $f['key']] ?? null);
    echo '<label class="check"><input type="checkbox" name="confirm" value="1" required> The information I’ve given is true and complete.</label>'
        . '<button class="btn primary">Send request' . icon('arrow') . '</button></form><aside class="side">';
    if ($s['requirements']) {
        echo '<div class="card"><p class="micro">You may also need</p><ul class="plain">';
        foreach ($s['requirements'] as $r) echo '<li>' . h($r) . '</li>';
        echo '</ul></div>';
    }
    if ($s['fees']) echo '<div class="card"><p class="micro">Fees</p>' . nl2br(h(field_text('fees', $s['fees']))) . '<p class="hint">Pay as the agency tells you, at the counter or on digiGov.</p></div>';
    echo '</aside></div>';
    page_close();
}

function my_requests_page(): void
{
    $u = require_login();
    $rows = all('SELECT r.*, s.title, a.name AS agency_name FROM requests r JOIN services s ON s.id = r.service_id JOIN agencies a ON a.id = r.agency_id WHERE r.user_id = ? ORDER BY r.updated_at DESC', [$u['id']]);
    page_open('My requests', ['nav' => 'requests']);
    echo page_header('Requests', 'My requests', 'Everything you’ve applied for through ' . app_name() . '.');
    if (!$rows) {
        echo empty_state('No requests yet. Services marked “Apply here” take requests online.', '<a class="btn outline" href="' . h(url('services')) . '">Find a service</a>');
    } else {
        echo '<div class="card flush"><ul class="rows">';
        foreach ($rows as $r) echo '<li><a href="' . h(url('requests/' . $r['ref'])) . '"><span><strong>' . h($r['title']) . '</strong><small class="muted">' . h($r['ref']) . ' · ' . h($r['agency_name']) . ' · updated ' . h(time_ago($r['updated_at'])) . '</small></span>' . request_badge($r['status']) . '</a></li>';
        echo '</ul></div>';
    }
    page_close();
}

function request_progress(string $status): string
{
    $steps = ['submitted' => 'Sent', 'in_review' => 'In review', 'decision' => 'Decision', 'done' => 'Done'];
    $at = match ($status) { 'submitted' => 0, 'in_review', 'action_needed' => 1, 'approved', 'rejected', 'ready' => 2, default => 3 };
    $html = '<ol class="progress">';
    $i = 0;
    foreach ($steps as $label) {
        $html .= '<li class="' . ($i < $at ? 'done' : ($i === $at ? 'now' : '')) . '"><span>' . h($label) . '</span></li>';
        $i++;
    }
    return $html . '</ol>';
}

function request_timeline(array $r, bool $staff): string
{
    $events = all('SELECT * FROM request_events WHERE request_id = ?' . ($staff ? '' : ' AND internal = 0') . ' ORDER BY id', [$r['id']]);
    $html = '<ol class="timeline">';
    foreach ($events as $e) {
        $who = $e['side'] === 'citizen' ? ($staff ? user_name((int) $e['actor_id']) : 'You') : ($e['side'] === 'agency' ? ($staff ? user_name((int) $e['actor_id']) : 'Agency') : app_name());
        $what = match ($e['kind']) {
            'submitted' => 'sent the request',
            'status' => 'changed the status to <strong>' . h(REQUEST_STATUS[$e['status_to']] ?? $e['status_to']) . '</strong>',
            'note' => 'added an internal note',
            default => 'wrote',
        };
        $html .= '<li class="' . h($e['side']) . ($e['internal'] ? ' internal' : '') . '"><div class="tl-head"><strong>' . h($who) . '</strong> ' . $what . '<time>' . h(fmt_datetime($e['created_at'])) . '</time></div>'
            . ($e['body'] ? '<div class="tl-body">' . autolink((string) $e['body']) . '</div>' : '')
            . ($e['file_id'] ? '<a class="file" href="' . h(url('files/' . $e['file_id'])) . '">' . icon('doc') . 'Attachment</a>' : '') . '</li>';
    }
    return $html . '</ol>';
}

function request_answers(array $r): string
{
    $d = json_decode((string) $r['data'], true) ?: [];
    $html = '<dl class="answers">';
    foreach ($d['fields'] ?? [] as $f) {
        $v = $d['values'][$f['key']] ?? '';
        $html .= '<dt>' . h($f['label']) . '</dt><dd>' . (is_array($v) && isset($v['file']) ? '<a class="file" href="' . h(url('files/' . (int) $v['file'])) . '">' . icon('doc') . 'View file</a>'
            : ($f['type'] === 'checkbox' ? ($v === '1' ? 'Yes' : 'No') : h($v !== '' ? $v : '—'))) . '</dd>';
    }
    return $html . '</dl>';
}

function my_request_page(string $ref): void
{
    $u = require_login();
    $r = one('SELECT r.*, s.title, s.slug, a.name AS agency_name FROM requests r JOIN services s ON s.id = r.service_id JOIN agencies a ON a.id = r.agency_id WHERE r.ref = ? AND r.user_id = ?', [$ref, $u['id']]);
    if (!$r) not_found();
    $open = in_array($r['status'], OPEN_STATUSES, true);
    if (is_post() && $open) {
        if (input('act') === 'withdraw') {
            q("UPDATE requests SET status = 'withdrawn', closed_at = ? WHERE id = ?", [now_utc(), $r['id']]);
            add_request_event((int) $r['id'], $u, 'citizen', 'status', 'Withdrawn by the applicant.', 'withdrawn');
            notify_agency((int) $r['agency_id'], 'Request ' . $r['ref'] . ' was withdrawn.', 'console/requests/' . $r['ref']);
            flash('Request withdrawn.');
        } else {
            $msg = mb_substr(input('message'), 0, 5000);
            [$fid, $err] = accept_upload($_FILES['file'] ?? null, 'request', (int) $u['id']);
            if ($err) flash($err, 'err');
            elseif ($msg === '' && !$fid) flash('Write a message or attach a file.', 'warn');
            else {
                add_request_event((int) $r['id'], $u, 'citizen', 'message', $msg ?: null, null, $fid);
                if ($r['status'] === 'action_needed') {
                    q("UPDATE requests SET status = 'in_review' WHERE id = ?", [$r['id']]);
                    add_request_event((int) $r['id'], null, 'system', 'status', null, 'in_review');
                }
                notify_agency((int) $r['agency_id'], 'New message on ' . $r['ref'], 'console/requests/' . $r['ref']);
                flash('Sent to the agency.');
            }
        }
        redirect('requests/' . $ref);
    }
    page_open($r['title'], ['nav' => 'requests', 'crumbs' => [['My requests', url('requests')]]]);
    echo page_header($r['ref'] . ' · ' . $r['agency_name'], (string) $r['title'], '', request_badge($r['status']));
    echo request_progress($r['status']);
    if ($r['status'] === 'action_needed') echo '<div class="callout hot"><div><strong>The agency needs something from you</strong><p>Read their message below and reply or attach what they asked for.</p></div></div>';
    echo '<div class="grid-side"><div><section class="card"><h2>Updates</h2>' . request_timeline($r, false);
    if ($open) {
        echo '<form method="post" enctype="multipart/form-data" class="form reply">' . csrf_field()
            . '<label class="field"><span>Message the agency</span><textarea name="message" rows="3" maxlength="5000"></textarea></label>'
            . '<div class="row"><label class="file-pick">' . icon('upload') . '<span>Attach a file</span><input type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf"></label><button class="btn primary">Send</button></div></form>';
    }
    echo '</section></div><aside class="side"><div class="card"><p class="micro">What you sent</p>' . request_answers($r) . '<p class="muted small">Sent ' . h(fmt_datetime($r['created_at'])) . '</p></div>'
        . '<div class="card"><a href="' . h(url('services/' . $r['slug'])) . '">' . icon('doc') . ' About this service</a></div>';
    if ($open) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="withdraw"><button class="btn ghost" data-confirm="Withdraw this request? The agency will stop working on it.">Withdraw request</button></form>';
    echo '</aside></div>';
    page_close();
}

function file_download(int $id): void
{
    $u = require_login();
    $f = one('SELECT * FROM files WHERE id = ?', [$id]);
    if (!$f || $f['deleted_at'] || !can_view_file($u, $f)) not_found();
    $bytes = vault_read($f);
    if ($bytes === null) not_found();
    if ((int) $f['user_id'] !== (int) $u['id']) audit($u, 'file.view', 'file', $id, (string) $f['purpose']);
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Type: ' . $f['mime']);
    header('Content-Length: ' . strlen($bytes));
    $name = preg_replace('/[^\w.-]+/', '_', (string) ($f['original_name'] ?: 'file')) ?: 'file';
    header('Content-Disposition: ' . (str_starts_with($f['mime'], 'image/') ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    echo $bytes;
    exit;
}

/* ---------- notifications ---------- */

function notifications_page(): void
{
    $u = require_login();
    $rows = all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$u['id']]);
    page_open('Notifications');
    echo page_header('Inbox', 'Notifications', '', $rows ? '<form method="post" action="' . h(url('notifications/read-all')) . '">' . csrf_field() . '<button class="btn outline">Mark all read</button></form>' : '');
    if (!$rows) echo empty_state('Nothing yet. Updates on your requests and saved services appear here.');
    else {
        echo '<div class="card flush"><ul class="rows">';
        foreach ($rows as $n) echo '<li class="' . ($n['read_at'] ? '' : 'unread') . '"><a href="' . h(url('notifications/' . $n['id'])) . '"><span>' . h($n['text']) . '<small class="muted">' . h(time_ago($n['created_at'])) . '</small></span>' . icon('arrow') . '</a></li>';
        echo '</ul></div>';
    }
    page_close();
}

function notification_open(int $id): void
{
    $u = require_login();
    $n = one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $u['id']]);
    if (!$n) not_found();
    q('UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE id = ?', [now_utc(), $id]);
    redirect(safe_next((string) $n['url']) ?: 'notifications');
}

function notifications_read_all(): void
{
    $u = require_login();
    q('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now_utc(), $u['id']]);
    redirect('notifications');
}
