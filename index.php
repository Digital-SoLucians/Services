<?php
// SLU Services front controller: every request comes through here (see .htaccess).

// Local preview with `php -S 127.0.0.1:8100 index.php`: let the built-in server hand out real files.
if (PHP_SAPI === 'cli-server') {
    $file = ltrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    if (preg_match('~^(assets/|robots\.txt$)~', $file) && is_file(__DIR__ . '/' . $file)) return false;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

require __DIR__ . '/app/bootstrap.php';

$path = route_path();

// Installable app: manifest and service worker (no session needed)
if ($path === 'sw.js' || $path === 'manifest.webmanifest') {
    $path === 'sw.js' ? pwa_worker() : pwa_manifest();
    exit;
}

// Agency API: API-key auth, no browser session or CSRF
if (preg_match('#^api/v1(/.*)?$#', $path)) {
    if (!installed()) json_out(['ok' => false, 'error' => 'not_installed'], 503);
    migrate();
    require_once APP_DIR . '/pages/api.php';
    api_dispatch(substr($path, 6));
    exit;
}

start_session();
send_security_headers();
if (is_post()) csrf_check();

if (!installed()) {
    require APP_DIR . '/pages/setup.php';
    setup_page();
    exit;
}
migrate();

foreach (glob(APP_DIR . '/pages/*.php') as $file) {
    if (!in_array(basename($file), ['setup.php', 'api.php'], true)) require_once $file;
}

$routes = [
    // public
    ['GET',  '#^$#', fn() => current_user() ? home_page() : landing_page()],
    ['GET|POST', '#^login$#', fn() => login_page()],
    ['GET|POST', '#^login/2fa$#', fn() => twofa_challenge_page()],
    ['GET|POST', '#^register$#', fn() => register_page()],
    ['POST', '#^logout$#', fn() => logout_action()],
    ['GET|POST', '#^forgot$#', fn() => forgot_page()],
    ['GET|POST', '#^reset/([a-f0-9]{48})$#', fn($t) => reset_page($t)],
    ['GET',  '#^privacy$#', fn() => privacy_page()],
    ['GET|POST', '#^setup$#', fn() => redirect('')],

    // signed in
    ['GET|POST', '#^verify-email$#', fn() => verify_email_page()],
    ['GET',  '#^services$#', fn() => services_page()],
    ['GET',  '#^services/([a-z0-9-]+)$#', fn($s) => service_page($s)],
    ['POST', '#^services/([a-z0-9-]+)/save$#', fn($s) => service_save_toggle($s)],
    ['GET|POST', '#^services/([a-z0-9-]+)/apply$#', fn($s) => apply_page($s)],
    ['GET',  '#^topics/([a-z0-9-]+)$#', fn($s) => topic_page($s)],
    ['GET',  '#^agencies$#', fn() => agencies_page()],
    ['GET',  '#^agencies/([a-z0-9-]+)$#', fn($s) => agency_page($s)],
    ['GET',  '#^saved$#', fn() => saved_page()],
    ['GET',  '#^requests$#', fn() => my_requests_page()],
    ['GET|POST', '#^requests/([A-Z0-9-]+)$#', fn($r) => my_request_page($r)],
    ['GET',  '#^files/(\d+)$#', fn($id) => file_download((int) $id)],
    ['GET|POST', '#^id$#', fn() => identity_page()],
    ['GET|POST', '#^account$#', fn() => account_page()],
    ['GET|POST', '#^account/address$#', fn() => address_page()],
    ['GET|POST', '#^account/security$#', fn() => security_page()],
    ['GET|POST', '#^account/data$#', fn() => data_page()],
    ['GET',  '#^notifications$#', fn() => notifications_page()],
    ['GET',  '#^notifications/(\d+)$#', fn($id) => notification_open((int) $id)],
    ['POST', '#^notifications/read-all$#', fn() => notifications_read_all()],

    // government staff
    ['GET',  '#^console$#', fn() => console_home()],
    ['GET',  '#^console/requests$#', fn() => console_requests()],
    ['GET|POST', '#^console/requests/([A-Z0-9-]+)$#', fn($r) => console_request($r)],
    ['GET|POST', '#^console/notices$#', fn() => console_notices()],
    ['GET',  '#^console/services$#', fn() => console_services()],
    ['GET|POST', '#^console/services/new$#', fn() => console_service_edit(null)],
    ['GET|POST', '#^console/services/(\d+)$#', fn($id) => console_service_edit((int) $id)],
    ['GET|POST', '#^console/intake$#', fn() => console_intake()],
    ['GET|POST', '#^console/intake/(\d+)$#', fn($id) => console_intake_item((int) $id)],
    ['GET|POST', '#^console/drafts/(\d+)$#', fn($id) => console_draft((int) $id)],
    ['GET|POST', '#^console/api-keys$#', fn() => console_api_keys()],
    ['GET',  '#^console/verify$#', fn() => verify_queue()],
    ['GET|POST', '#^console/verify/(\d+)$#', fn($id) => verify_case((int) $id)],
    ['GET',  '#^console/verify/file/(\d+)$#', fn($id) => verify_file((int) $id)],
    ['GET|POST', '#^console/admin/agencies$#', fn() => admin_agencies()],
    ['GET|POST', '#^console/admin/agencies/(\d+|new)$#', fn($id) => admin_agency_edit($id === 'new' ? null : (int) $id)],
    ['GET|POST', '#^console/admin/users$#', fn() => admin_users()],
    ['GET|POST', '#^console/admin/clearance$#', fn() => admin_clearance()],
    ['GET|POST', '#^console/admin/settings$#', fn() => admin_settings()],
    ['GET',  '#^console/admin/audit$#', fn() => admin_audit()],
    ['GET',  '#^console/admin/data$#', fn() => admin_data()],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') $method = 'GET';
foreach ($routes as [$methods, $pattern, $handler]) {
    if (!preg_match($pattern, $path, $m)) continue;
    if (!in_array($method, explode('|', $methods), true)) {
        http_response_code(405);
        header('Allow: ' . str_replace('|', ', ', $methods));
        exit('Method not allowed');
    }
    $handler(...array_slice($m, 1));
    exit;
}
require_login();
not_found();
