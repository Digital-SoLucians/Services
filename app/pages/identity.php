<?php
defined('DS') || exit;

// Linking a government ID: residents submit an ID with photos and a selfie; identity verifiers
// authorised by the Government check it. Only open when the platform has clearance (see Console ›
// Clearance). Photos are encrypted, every view is logged, and they are deleted after a decision.

const ID_TYPES = [
    'national_id' => 'National ID card',
    'passport' => 'Saint Lucia passport',
    'drivers_licence' => 'Driver’s licence',
    'nic_card' => 'National Insurance (NIC) card',
];
const ID_CHECKS = [
    'photo_match' => 'The selfie matches the photo on the ID',
    'name_match' => 'The name matches the account and the ID',
    'dob_match' => 'The date of birth matches the ID',
    'not_expired' => 'The ID is current (not expired)',
    'genuine' => 'The document looks genuine and unaltered',
];

function identity_page(): void
{
    $u = require_verified_email();
    $latest = one('SELECT * FROM identities WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$u['id']]);
    $open = id_linking_open($u);
    $errors = [];
    if (is_post() && $open && $u['id_status'] !== 'verified' && $u['id_status'] !== 'pending') {
        $type = input('id_type');
        $number = strtoupper(preg_replace('/[\s-]+/', '', input('id_number')));
        $name = mb_substr(input('full_name'), 0, 190);
        $dob = input('dob');
        $expiry = input('expiry');
        $dobD = DateTimeImmutable::createFromFormat('!Y-m-d', $dob);
        $expD = $expiry !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $expiry) : null;
        if (!isset(ID_TYPES[$type])) $errors[] = 'Choose the type of ID.';
        if (!preg_match('/^[A-Z0-9]{4,20}$/', $number)) $errors[] = 'Enter the ID number exactly as printed (letters and digits).';
        if (mb_strlen($name) < 3) $errors[] = 'Enter your full name as it appears on the ID.';
        if (!$dobD || $dobD > new DateTimeImmutable('-16 years')) $errors[] = 'Enter your date of birth (you must be 16 or older).';
        if ($expiry !== '' && (!$expD || $expD < new DateTimeImmutable('today'))) $errors[] = 'That ID has expired. Link a current ID.';
        if (input('consent') !== '1') $errors[] = 'Please agree to the identity check.';
        // submissions and "already linked" refusals both count, so the check can't be used to test ID numbers
        $since = gmdate('Y-m-d H:i:s', time() - 86400);
        $tries = (int) val('SELECT COUNT(*) FROM identities WHERE user_id = ? AND created_at > ?', [$u['id'], $since])
            + (int) val("SELECT COUNT(*) FROM audit_log WHERE action = 'id.duplicate' AND actor_id = ? AND created_at > ?", [$u['id'], $since]);
        if ($tries >= 3) $errors[] = 'You’ve tried several times today. Try again tomorrow.';
        $hash = lookup_hash($type . '|' . $number, 'id-number');
        if (!$errors && val("SELECT 1 FROM identities WHERE number_hash = ? AND user_id <> ? AND status IN ('pending','verified')", [$hash, $u['id']])) {
            $errors[] = 'This ID is already linked to another account. If that wasn’t you, contact the agency or Digital SoLucians.';
            audit($u, 'id.duplicate', 'user', (int) $u['id']);
        }
        $files = [];
        if (!$errors) {
            foreach (['front' => true, 'back' => $type !== 'passport', 'selfie' => true] as $k => $req) {
                [$fid, $err] = accept_upload($_FILES[$k] ?? null, 'id', (int) $u['id'], ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 8388608, $req);
                if ($err) $errors[] = ucfirst($k) . ': ' . $err;
                $files[$k] = $fid;
            }
            if ($errors) foreach ($files as $fid) if ($fid) vault_delete((int) $fid);
        }
        if (!$errors) {
            $iid = insert('identities', ['user_id' => (int) $u['id'], 'id_type' => $type, 'number_enc' => encrypt_secret($number), 'number_hash' => $hash, 'number_last4' => substr($number, -4),
                'full_name' => $name, 'dob' => $dob, 'expiry' => $expiry ?: null, 'front_file_id' => $files['front'], 'back_file_id' => $files['back'], 'selfie_file_id' => $files['selfie'],
                'status' => 'pending', 'consent_at' => now_utc(), 'created_at' => now_utc()]);
            q("UPDATE users SET id_status = 'pending' WHERE id = ?", [$u['id']]);
            audit($u, 'id.submit', 'identity', $iid, ID_TYPES[$type]);
            foreach (all("SELECT id FROM users WHERE active = 1 AND role IN ('verifier','admin','owner')") as $v) notify((int) $v['id'], 'New ID to check (' . ID_TYPES[$type] . ').', 'console/verify/' . $iid, false);
            flash('Thanks. An identity officer will check your ID, usually within two working days.');
            redirect('id');
        }
    }
    page_open('My ID', ['nav' => 'id']);
    echo page_header('Digital ID', 'My ID', 'Link a government ID once, and use services that need proof of who you are.');
    $status = $u['id_status'];
    if ($status === 'verified') {
        $i = one("SELECT * FROM identities WHERE user_id = ? AND status = 'verified' ORDER BY id DESC LIMIT 1", [$u['id']]);
        echo '<div class="id-card"><div class="id-top">' . logo_mark() . '<span class="micro">' . h(app_name()) . ' · verified resident</span></div>'
            . '<strong class="id-name">' . h($u['name']) . '</strong><dl><dt>ID</dt><dd>' . h(ID_TYPES[$i['id_type']] ?? 'ID') . ' ••••' . h($i['number_last4']) . '</dd>'
            . '<dt>Verified</dt><dd>' . h(fmt_date($u['id_verified_at'])) . '</dd>' . ($i['expiry'] ? '<dt>ID expires</dt><dd>' . h(fmt_date($i['expiry'])) . '</dd>' : '') . '</dl>'
            . '<span class="tag ok">' . icon('shield') . 'Verified</span></div>'
            . '<p class="hint">Your ID photos ' . ($i['images_purged_at'] ? 'were deleted on ' . h(fmt_date($i['images_purged_at'])) : 'will be deleted ' . (int) setting('id_image_retention_days', '30') . ' days after the check') . '. We keep only the result.</p>';
    } elseif ($status === 'pending') {
        echo '<div class="callout"><div><strong>With an identity officer</strong><p>You sent your ' . h(ID_TYPES[$latest['id_type']] ?? 'ID') . ' on ' . h(fmt_date($latest['created_at'])) . '. We’ll notify you when it’s checked.</p></div></div>';
    } elseif (!$open) {
        echo '<div class="callout"><div><strong>Not open yet</strong><p>' . (clearance() === 'suspended' ? 'ID linking is paused.' : 'ID linking opens once the Government of Saint Lucia authorises this service, so that identity checks are carried out by officers it appoints.') . ' You can still find services and apply where no ID is needed.</p></div></div>';
    } else {
        if ($status === 'rejected' && $latest) echo '<div class="callout hot"><div><strong>We couldn’t verify your last ID</strong><p>' . h((string) $latest['reason']) . ' You can try again below.</p></div></div>';
        if ($errors) {
            echo '<div class="flash err" role="alert"><ul>';
            foreach ($errors as $e) echo '<li>' . h($e) . '</li>';
            echo '</ul></div>';
        }
        echo '<div class="grid-side"><form method="post" enctype="multipart/form-data" class="card form" data-id-form>' . csrf_field()
            . '<label class="field"><span>Type of ID</span><select name="id_type" required data-id-type><option value="">Choose…</option>';
        foreach (ID_TYPES as $k => $l) echo '<option value="' . $k . '"' . (input('id_type') === $k ? ' selected' : '') . '>' . h($l) . '</option>';
        echo '</select></label><label class="field"><span>ID number</span><input name="id_number" value="' . h(input('id_number')) . '" required autocomplete="off" maxlength="30"></label>'
            . '<label class="field"><span>Full name as on the ID</span><input name="full_name" value="' . h(input('full_name', $u['name'])) . '" required maxlength="190"></label>'
            . '<div class="two"><label class="field"><span>Date of birth</span><input type="date" name="dob" value="' . h(input('dob')) . '" required></label>'
            . '<label class="field"><span>Expiry date <small>if shown</small></span><input type="date" name="expiry" value="' . h(input('expiry')) . '"></label></div>'
            . '<div class="uploads"><label class="upload"><span class="micro">Front of ID</span>' . icon('id') . '<input type="file" name="front" accept="image/jpeg,image/png,image/webp" capture="environment" required></label>'
            . '<label class="upload" data-back><span class="micro">Back of ID</span>' . icon('id') . '<input type="file" name="back" accept="image/jpeg,image/png,image/webp" capture="environment"></label>'
            . '<label class="upload"><span class="micro">Selfie holding the ID</span>' . icon('camera') . '<input type="file" name="selfie" accept="image/jpeg,image/png,image/webp" capture="user" required></label></div>'
            . '<label class="check"><input type="checkbox" name="consent" value="1" required> I agree that identity officers authorised by the Government of Saint Lucia may check this ID and photos. The photos are deleted ' . (int) setting('id_image_retention_days', '30') . ' days after the decision.</label>'
            . '<button class="btn primary">Send for checking</button></form>'
            . '<aside class="side"><div class="card"><p class="micro">Good photos</p><ul class="plain"><li>Flat surface, good light, no glare</li><li>All four corners visible</li><li>Selfie: hold the ID next to your face</li><li>JPG or PNG, up to 8 MB each</li></ul></div>'
            . '<div class="card"><p class="micro">Your data</p><p>The number is encrypted and only the last four digits are shown. <a href="' . h(url('privacy')) . '">How we protect it</a>.</p></div></aside></div>';
    }
    page_close();
}

/* ---------- verifier console ---------- */

function verify_queue(): void
{
    $u = require_staff(['verifier', 'admin', 'owner']);
    purge_id_images();
    $tab = in_array(qs('tab'), ['pending', 'decided'], true) ? qs('tab') : 'pending';
    $rows = $tab === 'pending'
        ? all("SELECT i.*, u.name AS account_name, u.email FROM identities i JOIN users u ON u.id = i.user_id WHERE i.status = 'pending' ORDER BY i.id")
        : all("SELECT i.*, u.name AS account_name, u.email FROM identities i JOIN users u ON u.id = i.user_id WHERE i.status <> 'pending' ORDER BY i.reviewed_at DESC LIMIT 100");
    page_open('ID checks', ['console' => 'verify']);
    echo page_header('Identity verification', 'ID checks', 'Check each ID against the selfie and the account. Every photo you open is recorded in the audit log.');
    echo '<nav class="seg"><a href="' . h(url('console/verify')) . '"' . ($tab === 'pending' ? ' class="on"' : '') . '>Waiting (' . (int) val("SELECT COUNT(*) FROM identities WHERE status = 'pending'") . ')</a><a href="' . h(url('console/verify', ['tab' => 'decided'])) . '"' . ($tab === 'decided' ? ' class="on"' : '') . '>Decided</a></nav>';
    if (!$rows) echo empty_state($tab === 'pending' ? 'Nothing waiting. New submissions appear here.' : 'No decisions yet.');
    else {
        echo '<div class="card flush"><table class="table"><thead><tr><th>Account</th><th>ID</th><th>Sent</th><th>Status</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td><a href="' . h(url('console/verify/' . $r['id'])) . '">' . h($r['account_name']) . '</a><small class="muted">' . h($r['email']) . '</small></td><td>' . h(ID_TYPES[$r['id_type']] ?? $r['id_type']) . ' ••••' . h($r['number_last4']) . '</td><td>' . h(time_ago($r['created_at'])) . '</td><td><span class="tag">' . h($r['status']) . '</span></td></tr>';
        echo '</tbody></table></div>';
    }
    page_close();
}

function verify_case(int $id): void
{
    $v = require_staff(['verifier', 'admin', 'owner']);
    $i = one('SELECT i.*, u.name AS account_name, u.email, u.created_at AS account_created FROM identities i JOIN users u ON u.id = i.user_id WHERE i.id = ?', [$id]);
    if (!$i) not_found();
    if (!is_post()) audit($v, 'id.view_case', 'identity', $id);
    if (is_post() && $i['status'] === 'pending') {
        if ((int) $i['user_id'] === (int) $v['id']) { flash('You can’t check your own ID.', 'warn'); redirect('console/verify/' . $id); }
        $checks = array_values(array_intersect(array_keys(ID_CHECKS), (array) ($_POST['checks'] ?? [])));
        $act = input('act');
        if ($act === 'approve') {
            if (count($checks) < count(ID_CHECKS)) { flash('Tick every check before approving.', 'warn'); redirect('console/verify/' . $id); }
            q("UPDATE identities SET status = 'verified', reviewer_id = ?, reviewed_at = ?, checks = ? WHERE id = ?", [$v['id'], now_utc(), json_encode($checks), $id]);
            q("UPDATE users SET id_status = 'verified', id_verified_at = ?, dob = ? WHERE id = ?", [now_utc(), $i['dob'], $i['user_id']]);
            audit($v, 'id.approve', 'identity', $id, ID_TYPES[$i['id_type']] ?? '');
            notify((int) $i['user_id'], 'Your ID is verified. You can now use services that need proof of identity.', 'id');
            flash('Verified.');
        } elseif ($act === 'reject') {
            $reason = mb_substr(input('reason'), 0, 500);
            if ($reason === '') { flash('Tell the resident why, so they can fix it.', 'warn'); redirect('console/verify/' . $id); }
            q("UPDATE identities SET status = 'rejected', reviewer_id = ?, reviewed_at = ?, reason = ?, checks = ? WHERE id = ?", [$v['id'], now_utc(), $reason, json_encode($checks), $id]);
            q("UPDATE users SET id_status = 'rejected' WHERE id = ?", [$i['user_id']]);
            audit($v, 'id.reject', 'identity', $id, $reason);
            notify((int) $i['user_id'], 'We couldn’t verify your ID: ' . $reason, 'id');
            flash('Sent back to the resident.');
        }
        redirect('console/verify');
    }
    if (is_post() && $i['status'] === 'verified' && input('act') === 'revoke' && is_admin($v)) {
        q("UPDATE identities SET status = 'revoked', reason = ? WHERE id = ?", [mb_substr(input('reason'), 0, 500) ?: 'Revoked', $id]);
        q("UPDATE users SET id_status = 'none', id_verified_at = NULL WHERE id = ?", [$i['user_id']]);
        audit($v, 'id.revoke', 'identity', $id, input('reason'));
        notify((int) $i['user_id'], 'Your ID verification was withdrawn. Link your ID again to continue.', 'id');
        flash('Verification revoked.');
        redirect('console/verify/' . $id);
    }
    page_open('ID check', ['console' => 'verify', 'crumbs' => [['ID checks', url('console/verify')]]]);
    echo page_header(ID_TYPES[$i['id_type']] ?? 'ID', $i['full_name'] !== '' ? $i['full_name'] : $i['account_name'] . ' (ID details deleted)', 'Account: ' . $i['account_name'] . ' · ' . $i['email'] . ' · joined ' . fmt_date($i['account_created']), '<span class="tag">' . h($i['status']) . '</span>');
    echo '<div class="grid-side"><div><div class="id-photos">';
    foreach (['front_file_id' => 'Front', 'back_file_id' => 'Back', 'selfie_file_id' => 'Selfie'] as $k => $l) {
        echo '<figure class="card">' . ($i[$k] && !$i['images_purged_at'] ? '<img src="' . h(url('console/verify/file/' . $i[$k])) . '" alt="' . h($l) . ' of the ID" loading="lazy">' : '<div class="empty small">' . ($i['images_purged_at'] ? 'Deleted' : 'Not provided') . '</div>') . '<figcaption class="micro">' . h($l) . '</figcaption></figure>';
    }
    echo '</div></div><aside class="side"><div class="card"><dl class="answers"><dt>Name on ID</dt><dd>' . h($i['full_name'] !== '' ? $i['full_name'] : '—') . '</dd><dt>Account name</dt><dd>' . h($i['account_name']) . '</dd>'
        . '<dt>ID number</dt><dd>' . (can_verify($v) && $i['status'] === 'pending' ? h(decrypt_secret($i['number_enc'])) : '••••' . h($i['number_last4'])) . '</dd>'
        . '<dt>Date of birth</dt><dd>' . h(fmt_date($i['dob'])) . '</dd><dt>Expiry</dt><dd>' . h($i['expiry'] ? fmt_date($i['expiry']) : '—') . '</dd>'
        . '<dt>Consent</dt><dd>' . h(fmt_datetime($i['consent_at'])) . '</dd></dl></div>';
    if ($i['status'] === 'pending') {
        echo '<form method="post" class="card form">' . csrf_field() . '<p class="micro">Checks</p>';
        foreach (ID_CHECKS as $k => $l) echo '<label class="check"><input type="checkbox" name="checks[]" value="' . $k . '"> ' . h($l) . '</label>';
        echo '<button class="btn primary" name="act" value="approve">Approve</button>'
            . '<label class="field"><span>Or send back with a reason</span><textarea name="reason" rows="2" placeholder="e.g. The photo is blurry; please retake it in good light."></textarea></label>'
            . '<button class="btn outline" name="act" value="reject" formnovalidate>Send back</button></form>';
    } else {
        echo '<div class="card"><p>Decided by ' . h(user_name((int) $i['reviewer_id'])) . ' on ' . h(fmt_datetime($i['reviewed_at'])) . '.</p>' . ($i['reason'] ? '<p class="muted">' . h($i['reason']) . '</p>' : '') . '</div>';
        if ($i['status'] === 'verified' && is_admin($v)) echo '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="revoke"><label class="field"><span>Revoke reason</span><input name="reason" required></label><button class="btn ghost danger" data-confirm="Revoke this verification?">Revoke</button></form>';
    }
    echo '</aside></div>';
    page_close();
}

function verify_file(int $fileId): void
{
    $v = require_staff(['verifier', 'admin', 'owner']);
    $f = one("SELECT f.* FROM files f WHERE f.id = ? AND f.purpose = 'id'", [$fileId]);
    $i = $f ? one('SELECT * FROM identities WHERE ? IN (front_file_id, back_file_id, selfie_file_id)', [$fileId]) : null;
    if (!$f || !$i || (int) $i['user_id'] === (int) $v['id']) not_found();
    $bytes = vault_read($f);
    if ($bytes === null) not_found();
    audit($v, 'id.view_photo', 'identity', (int) $i['id'], 'file ' . $fileId);
    header_remove('Content-Security-Policy');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Type: ' . $f['mime']);
    header('Cache-Control: no-store, private');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

/** Deletes ID photos once the retention period after a decision has passed. */
function purge_id_images(): int
{
    $days = max(1, (int) setting('id_image_retention_days', '30'));
    $n = 0;
    foreach (all("SELECT * FROM identities WHERE status <> 'pending' AND images_purged_at IS NULL AND reviewed_at IS NOT NULL AND reviewed_at < ?", [gmdate('Y-m-d H:i:s', time() - $days * 86400)]) as $i) {
        foreach (['front_file_id', 'back_file_id', 'selfie_file_id'] as $k) if ($i[$k]) vault_delete((int) $i[$k]);
        // keep the result, type, expiry, last four digits and the fingerprint (to stop double-linking); drop the rest
        q("UPDATE identities SET images_purged_at = ?, number_enc = '', full_name = '', dob = NULL WHERE id = ?", [now_utc(), $i['id']]);
        $n++;
    }
    return $n;
}
