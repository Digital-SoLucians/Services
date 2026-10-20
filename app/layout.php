<?php
defined('DS') || exit;

function logo_mark(string $class = 'mark'): string
{
    return '<svg class="' . h($class) . '" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="#0c0a08"/>'
        . '<path d="M9 11.5h14M9 16h9M9 20.5h11" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/><circle cx="23" cy="20.5" r="2.2" fill="#e4f222"/></svg>';
}

function head_html(string $title, string $bodyClass = ''): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#1a1919">'
        . '<meta name="csrf" content="' . h(session_status() === PHP_SESSION_ACTIVE ? csrf_token() : '') . '"><meta name="base" content="' . h(base_path()) . '">'
        . '<title>' . h($title) . ' · ' . h(app_name()) . '</title>'
        . '<link rel="icon" href="' . h(asset('favicon.svg')) . '" type="image/svg+xml">'
        . '<link rel="manifest" href="' . h(url('manifest.webmanifest')) . '"><link rel="apple-touch-icon" href="' . h(asset('icon-192.png')) . '">'
        . '<link rel="preload" href="' . h(asset('fonts/inter-latin-var.woff2')) . '" as="font" type="font/woff2" crossorigin>'
        . '<link rel="stylesheet" href="' . h(asset('app.css')) . '">'
        . '<script src="' . h(asset('app.js')) . '" defer></script></head><body' . ($bodyClass ? ' class="' . h($bodyClass) . '"' : '') . '>';
}

/** The announcement strip: until the Government authorises the platform, everyone sees it's a pilot. */
function pilot_bar(): string
{
    if (!installed()) return '';
    $c = clearance();
    if ($c === 'granted') return '';
    $text = match ($c) {
        'pilot' => 'Pilot: this service is being tested with the Government of Saint Lucia. ID linking is open to testers only.',
        'suspended' => 'ID linking is paused. You can still find services and track your requests.',
        default => 'Preview: not yet an official Government of Saint Lucia service. Service details come from public government sources.',
    };
    return '<div class="announce" role="note"><div class="wrap">' . h($text) . ' <a href="' . h(url('privacy')) . '">Learn more</a></div></div>';
}

function flashes_html(): void
{
    $f = take_flashes();
    if (!$f) return;
    echo '<div class="flashes" role="status">';
    foreach ($f as [$type, $msg]) echo '<div class="flash ' . h($type) . '"><span>' . h($msg) . '</span><button type="button" class="flash-x" aria-label="Dismiss">' . icon('x') . '</button></div>';
    echo '</div>';
}

/** Public pages (landing, sign in, register). */
function public_open(string $title, string $bodyClass = 'public'): void
{
    head_html($title, $bodyClass);
    echo pilot_bar();
    echo '<header class="topnav"><div class="wrap nav-row"><a class="brand" href="' . h(url('')) . '">' . logo_mark() . '<span>' . h(app_name()) . '</span></a>'
        . '<nav class="nav-right">' . (current_user() ? '<a class="btn primary" href="' . h(url('')) . '">Open the app</a>' : '<a class="btn ghost" href="' . h(url('login')) . '">Sign in</a><a class="btn primary" href="' . h(url('register')) . '">Create account</a>') . '</nav></div></header>';
    echo '<main id="main" class="wrap public-main">';
    flashes_html();
}

function public_close(): void
{
    echo '</main>' . footer_html() . '</body></html>';
}

function footer_html(): string
{
    return '<footer class="foot"><div class="wrap foot-row"><span>' . h(app_name()) . ' · built by Digital SoLucians for residents of Saint Lucia</span>'
        . '<nav><a href="' . h(url('privacy')) . '">Privacy &amp; ID data</a><a href="' . h(url('agencies')) . '">Agencies</a><a href="https://digigov.govt.lc/portal/mvvmc/eservices/sldgPublicPortal.public.home" rel="noopener" target="_blank">digiGov</a></nav></div></footer>';
}

/**
 * Signed-in pages. $opts: nav (home|services|requests|id|account|console), console (tab key), crumbs [[label, url]], wide.
 */
function page_open(string $title, array $opts = []): void
{
    $u = current_user();
    head_html($title, 'app' . (!empty($opts['console']) ? ' is-console' : ''));
    echo '<a class="skip" href="#main">Skip to content</a>' . pilot_bar();
    $nav = $opts['nav'] ?? (!empty($opts['console']) ? 'console' : '');
    $links = [['', 'Home', 'home'], ['services', 'Services', 'services'], ['requests', 'My requests', 'requests'], ['id', 'My ID', 'id']];
    if (is_staff($u)) $links[] = ['console', 'Console', 'console'];
    $unread = $u ? unread_count((int) $u['id']) : 0;
    echo '<header class="topnav"><div class="wrap nav-row"><a class="brand" href="' . h(url('')) . '">' . logo_mark() . '<span>' . h(app_name()) . '</span></a><nav class="nav-links" aria-label="Main">';
    foreach ($links as [$path, $label, $key]) echo '<a href="' . h(url($path)) . '"' . ($nav === $key ? ' class="on" aria-current="page"' : '') . '>' . h($label) . '</a>';
    echo '</nav><div class="nav-right">'
        . '<a class="icon-btn" href="' . h(url('services')) . '#q" aria-label="Search services">' . icon('search') . '</a>'
        . '<a class="icon-btn bell" href="' . h(url('notifications')) . '" aria-label="Notifications' . ($unread ? ' (' . $unread . ' unread)' : '') . '">' . icon('bell') . ($unread ? '<span class="dot">' . min(99, $unread) . '</span>' : '') . '</a>'
        . '<details class="menu"><summary class="avatar-btn" aria-label="Account menu"><span class="avatar">' . h(initials((string) $u['name'])) . '</span></summary><div class="menu-pop">'
        . '<div class="menu-head"><strong>' . h($u['name']) . '</strong><span>' . h($u['email']) . '</span><span class="micro">' . h(ROLES[$u['role']] ?? $u['role']) . '</span></div>'
        . '<a href="' . h(url('account')) . '">' . icon('user') . 'Profile</a><a href="' . h(url('saved')) . '">' . icon('bookmark') . 'Saved services</a>'
        . '<a href="' . h(url('account/address')) . '">' . icon('pin') . 'Change of address</a><a href="' . h(url('account/security')) . '">' . icon('lock') . 'Security</a>'
        . '<a href="' . h(url('account/data')) . '">' . icon('download') . 'Your data</a>'
        . '<form method="post" action="' . h(url('logout')) . '">' . csrf_field() . '<button>' . icon('logout') . 'Sign out</button></form></div></details>'
        . '</div></div></header>';
    if (!empty($opts['console'])) echo console_tabs($u, (string) $opts['console']);
    echo '<main id="main" class="wrap app-main' . (!empty($opts['wide']) ? ' wide' : '') . '">';
    if (!empty($opts['crumbs'])) {
        echo '<nav class="crumbs" aria-label="Breadcrumb">';
        foreach ($opts['crumbs'] as [$label, $href]) echo '<a href="' . h($href) . '">' . h($label) . '</a><span aria-hidden="true">/</span>';
        echo '<span>' . h($title) . '</span></nav>';
    }
    flashes_html();
}

function page_close(): void
{
    $u = current_user();
    echo '</main>' . footer_html();
    if ($u) {
        $path = route_path();
        $tabs = [['', 'Home', 'home'], ['services', 'Services', 'grid'], ['requests', 'Requests', 'inbox'], ['id', 'My ID', 'id'], ['account', 'Account', 'user']];
        echo '<nav class="tabbar" aria-label="App">';
        foreach ($tabs as [$p, $l, $ic]) {
            $on = $p === '' ? $path === '' : str_starts_with($path, $p);
            echo '<a href="' . h(url($p)) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . icon($ic) . '<span>' . h($l) . '</span></a>';
        }
        echo '</nav>';
    }
    echo '</body></html>';
}

function console_tabs(array $u, string $active): string
{
    $tabs = [['console', 'Overview', 'overview']];
    if (in_array($u['role'], ['officer', 'admin', 'owner'], true)) {
        $tabs[] = ['console/requests', 'Requests', 'requests'];
        $tabs[] = ['console/services', 'Services', 'services'];
        $tabs[] = ['console/intake', 'Intake', 'intake'];
        $tabs[] = ['console/notices', 'Notices', 'notices'];
        $tabs[] = ['console/api-keys', 'API keys', 'api'];
    }
    if (can_verify($u)) $tabs[] = ['console/verify', 'ID checks', 'verify'];
    if (is_admin($u)) {
        $tabs[] = ['console/admin/agencies', 'Agencies', 'agencies'];
        $tabs[] = ['console/admin/users', 'People', 'users'];
        $tabs[] = ['console/admin/clearance', 'Clearance', 'clearance'];
        $tabs[] = ['console/admin/data', 'Data', 'data'];
        $tabs[] = ['console/admin/settings', 'Settings', 'settings'];
        $tabs[] = ['console/admin/audit', 'Audit log', 'audit'];
    }
    $html = '<div class="subnav"><div class="wrap"><span class="micro">Government console' . ($u['agency_id'] ? ' · ' . h((string) val('SELECT COALESCE(short_name, name) FROM agencies WHERE id = ?', [$u['agency_id']])) : '') . '</span><nav class="subnav-tabs">';
    foreach ($tabs as [$path, $label, $key]) $html .= '<a href="' . h(url($path)) . '"' . ($active === $key ? ' class="on" aria-current="page"' : '') . '>' . h($label) . '</a>';
    return $html . '</nav></div></div>';
}

/** Big editorial page header: micro label, headline, optional lede and actions. */
function page_header(string $micro, string $title, string $lede = '', string $actions = ''): string
{
    return '<header class="page-head"><div>' . ($micro !== '' ? '<p class="micro">' . h($micro) . '</p>' : '') . '<h1>' . h($title) . '</h1>'
        . ($lede !== '' ? '<p class="lede">' . h($lede) . '</p>' : '') . '</div>' . ($actions !== '' ? '<div class="head-actions">' . $actions . '</div>' : '') . '</header>';
}

function empty_state(string $text, string $action = ''): string
{
    return '<div class="empty">' . h($text) . ($action !== '' ? '<div>' . $action . '</div>' : '') . '</div>';
}

function not_found(): void
{
    http_response_code(404);
    if (current_user()) {
        page_open('Not found');
        echo page_header('404', 'We couldn’t find that page', 'It may have moved, or the link may be wrong.', '<a class="btn primary" href="' . h(url('services')) . '">Find a service</a>');
        page_close();
    } else {
        public_open('Not found');
        echo page_header('404', 'We couldn’t find that page', '', '<a class="btn primary" href="' . h(url('')) . '">Go home</a>');
        public_close();
    }
    exit;
}
