<?php
// Background tasks. Run every 15 minutes from hPanel › Advanced › Cron Jobs:  php /path/to/cron.php
// Reads the intake mailbox, finishes queued or failed intake, deletes expired ID photos and old tokens.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';
require_once APP_DIR . '/pages/identity.php';

if (!installed()) {
    fwrite(STDERR, "Not installed yet.\n");
    exit(1);
}
migrate();

$lock = fopen(DATA_DIR . '/cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another run is still going.\n";
    exit(0);
}
@set_time_limit(840);
$log = [];

// Privacy housekeeping first, so a bad intake document can never hold it up
$log[] = 'ID photos deleted: ' . purge_id_images();
q('DELETE FROM user_tokens WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
q('DELETE FROM login_attempts WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);

try {
    $log[] = 'Mailbox: ' . intake_poll_mailbox(240);
} catch (Throwable $e) {
    log_error($e);
    $log[] = 'Mailbox error';
}

// New items, items left behind by an interrupted request and failed ones: up to three tries each
$n = 0;
$stale = gmdate('Y-m-d H:i:s', time() - 600);
foreach (all("SELECT id, status FROM intake_items WHERE (status = 'received' AND created_at < ?)
    OR (status IN ('processing', 'failed') AND attempts < 3 AND COALESCE(processed_at, created_at) < ?) ORDER BY id LIMIT 20", [gmdate('Y-m-d H:i:s', time() - 120), $stale]) as $row) {
    q('UPDATE intake_items SET attempts = attempts + 1 WHERE id = ?', [$row['id']]);
    intake_process((int) $row['id']);
    $n++;
}
// ones that keep stopping mid-read (out of memory, for example) are given up on
q("UPDATE intake_items SET status = 'failed', error = 'Reading stopped unexpectedly. Try a smaller file or another format.', processed_at = ?
    WHERE status = 'processing' AND attempts >= 3 AND created_at < ?", [now_utc(), $stale]);
$log[] = 'Intake processed: ' . $n;

set_setting('cron_last', now_utc() . ' UTC · ' . implode(' · ', $log));
echo implode("\n", $log), "\n";
flock($lock, LOCK_UN);
