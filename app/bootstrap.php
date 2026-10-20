<?php
// SLU Services: loads configuration, the database and every helper. Included by index.php and cron.php.
declare(strict_types=1);

define('DS', true);
define('APP_VERSION', '1.0.0');
define('APP_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));
define('DATA_DIR', ROOT_DIR . '/data');
define('CONFIG_FILE', DATA_DIR . '/config.php');

mb_internal_encoding('UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require APP_DIR . '/helpers.php';
require APP_DIR . '/db.php';
require APP_DIR . '/schema.php';
require APP_DIR . '/support.php';
require APP_DIR . '/auth.php';
require APP_DIR . '/catalog.php';
require APP_DIR . '/intake.php';
require APP_DIR . '/imap.php';
require APP_DIR . '/layout.php';

set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($msg, 0, $no, $file, $line);
});

set_exception_handler(function (Throwable $e): void {
    log_error($e);
    if (!headers_sent()) http_response_code(500);
    if (is_api_request()) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Something went wrong. Please try again.']);
        return;
    }
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Something went wrong</title><body style="font-family:system-ui,sans-serif;padding:48px 20px;max-width:560px;margin:auto;color:#0c0a08;background:#f4f2f0">'
        . '<h1 style="font-size:24px;font-weight:400">Something went wrong</h1><p>The error was logged. Go back and try again.</p>'
        . '<p><a href="' . h(url('')) . '">Back to SLU Services</a></p></body>';
});

date_default_timezone_set('America/St_Lucia');
