<?php
defined('DS') || exit;

// Account: profile, "tell us once" change of address, security (password, two-step sign-in),
// and your data (download everything, close the account).

function account_page(): void
{
    $u = require_login();
    if (is_post()) {
        $name = mb_substr(input('name'), 0, 120);
        $phone = input('phone');
        $district = input('district');
        if (mb_strlen($name) < 2) flash('Enter your full name.', 'err');
        elseif ($phone !== '' && !preg_match('/^[+()\d\s-]{7,20}$/', $phone)) flash('Enter a valid phone number.', 'err');
        elseif ($district !== '' && !in_array($district, DISTRICTS, true)) flash('Choose a district from the list.', 'err');
        else {
            // a verified identity fixes the legal name; changing it means verifying again
            if ($u['id_status'] === 'verified' && $name !== $u['name']) {
                flash('Your name is locked to your verified ID. Link your ID again if it changed.', 'warn');
                $name = $u['name'];
            }
            update('users', (int) $u['id'], ['name' => $name, 'phone' => $phone ?: null, 'district' => $district ?: null, 'notify_email' => input('notify_email') === '1' ? 1 : 0]);
            flash('Profile saved.');
        }
        redirect('account');
    }
    page_open('Profile', ['nav' => 'account']);
    echo page_header('Account', 'Your profile', 'Agencies use these details to process your requests.');
    echo '<div class="grid-side"><form method="post" class="card form">' . csrf_field()
        . '<label class="field"><span>Full name</span><input name="name" value="' . h($u['name']) . '" required maxlength="120"' . ($u['id_status'] === 'verified' ? ' readonly' : '') . '></label>'
        . '<label class="field"><span>Email</span><input value="' . h($u['email']) . '" disabled></label>'
        . '<div class="two"><label class="field"><span>Phone</span><input type="tel" name="phone" value="' . h((string) $u['phone']) . '" autocomplete="tel"></label>'
        . '<label class="field"><span>District</span><select name="district"><option value="">Choose…</option>';
    foreach (DISTRICTS as $d) echo '<option' . ($u['district'] === $d ? ' selected' : '') . '>' . h($d) . '</option>';
    echo '</select></label></div><label class="check"><input type="checkbox" name="notify_email" value="1"' . ($u['notify_email'] ? ' checked' : '') . '> Email me about my requests and saved services</label>'
        . '<button class="btn primary">Save</button></form><aside class="side"><div class="card facts"><p class="micro">Account</p>'
        . '<p>' . icon('user') . '<span>' . h(ROLES[$u['role']] ?? $u['role']) . '</span></p><p>' . icon('mail') . '<span>' . ($u['email_verified_at'] ? 'Email confirmed' : '<a href="' . h(url('verify-email')) . '">Confirm your email</a>') . '</span></p>'
        . '<p>' . icon('shield') . '<span>' . ($u['id_status'] === 'verified' ? 'ID verified ' . h(fmt_date($u['id_verified_at'])) : '<a href="' . h(url('id')) . '">Link your ID</a>') . '</span></p>'
        . '<p>' . icon('lock') . '<span>' . ($u['totp_enabled'] ? 'Two-step sign-in on' : '<a href="' . h(url('account/security')) . '">Turn on two-step sign-in</a>') . '</span></p>'
        . '<p>' . icon('pin') . '<span>' . ($u['address_line'] ? h($u['address_line'] . ($u['district'] ? ', ' . $u['district'] : '')) : '<a href="' . h(url('account/address')) . '">Add your address</a>') . '</span></p></div></aside></div>';
    page_close();
}

/** "Tell us once": update your address here and notify the agencies you choose. */
function address_page(): void
{
    $u = require_verified_email();
    $suggest = ['inland-revenue-department', 'national-insurance-corporation', 'electoral-department', 'division-of-transport', 'wasco'];
    if (is_post()) {
        $line = mb_substr(input('address_line'), 0, 255);
        $district = input('district');
        $agencyIds = array_map('intval', (array) ($_POST['agencies'] ?? []));
        if (mb_strlen($line) < 5) flash('Enter your full new address.', 'err');
        elseif (!in_array($district, DISTRICTS, true)) flash('Choose your district.', 'err');
        else {
            $old = trim($u['address_line'] . ($u['district'] ? ', ' . $u['district'] : ''));
            update('users', (int) $u['id'], ['address_line' => $line, 'district' => $district]);
            $n = 0;
            foreach (array_unique($agencyIds) as $aid) {
                if (!val('SELECT 1 FROM agencies WHERE id = ? AND active = 1', [$aid])) continue;
                insert('notices', ['user_id' => (int) $u['id'], 'agency_id' => $aid, 'kind' => 'address',
                    'body' => "Change of address for " . $u['name'] . ' (' . $u['email'] . ($u['id_status'] === 'verified' ? ', ID verified' : '') . ")\nNew: $line, $district" . ($old !== '' ? "\nPrevious: $old" : '') . "\nEffective: " . date('j M Y'),
                    'created_at' => now_utc()]);
                notify_agency($aid, $u['name'] . ' changed their address.', 'console/notices');
                $n++;
            }
            flash('Address updated' . ($n ? ' and ' . plural($n, 'agency', 'agencies') . ' notified.' : '.'));
            redirect('account/address');
        }
    }
    $agencies = agencies_list();
    $history = all('SELECT n.*, a.name AS agency FROM notices n JOIN agencies a ON a.id = n.agency_id WHERE n.user_id = ? AND n.kind = ? ORDER BY n.id DESC LIMIT 30', [$u['id'], 'address']);
    page_open('Change of address', ['nav' => 'account']);
    echo page_header('Tell us once', 'Change of address', 'Update your address once and choose which agencies to tell.');
    echo '<div class="grid-side"><form method="post" class="card form">' . csrf_field()
        . '<label class="field"><span>New address</span><input name="address_line" value="' . h((string) $u['address_line']) . '" required maxlength="255" autocomplete="street-address"></label>'
        . '<label class="field"><span>District</span><select name="district" required><option value="">Choose…</option>';
    foreach (DISTRICTS as $d) echo '<option' . ($u['district'] === $d ? ' selected' : '') . '>' . h($d) . '</option>';
    echo '</select></label><fieldset class="field"><legend>Tell these agencies</legend><div class="check-list">';
    $sorted = $agencies;
    usort($sorted, fn($a, $b) => (int) in_array($b['slug'], $suggest, true) <=> (int) in_array($a['slug'], $suggest, true) ?: strcmp($a['name'], $b['name']));
    foreach ($sorted as $i => $a) {
        if ($i === count($suggest)) echo '<details><summary>More agencies</summary>';
        echo '<label class="check"><input type="checkbox" name="agencies[]" value="' . (int) $a['id'] . '"' . (in_array($a['slug'], $suggest, true) ? ' checked' : '') . '> ' . h($a['name']) . '</label>';
    }
    if (count($sorted) > count($suggest)) echo '</details>';
    echo '</div></fieldset><button class="btn primary">Update and notify</button></form><aside class="side"><div class="card"><p class="micro">Sent before</p>';
    if ($history) {
        echo '<ul class="plain">';
        foreach ($history as $hst) echo '<li>' . h($hst['agency']) . ' <small class="muted">' . h(fmt_date($hst['created_at'])) . ' · ' . ($hst['status'] === 'acknowledged' ? 'acknowledged' : 'sent') . '</small></li>';
        echo '</ul>';
    } else echo '<p class="muted">No notices yet.</p>';
    echo '</div></aside></div>';
    page_close();
}

function security_page(): void
{
    $u = require_login();
    $pendingSecret = $_SESSION['totp_setup'] ?? null;
    if (is_post()) {
        $act = input('act');
        if ($act === 'password') {
            $pw = (string) ($_POST['password'] ?? '');
            if (!$u['must_change_password'] && !password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) flash('Your current password isn’t right.', 'err');
            elseif ($p = password_problem($pw, $u['email'])) flash($p, 'err');
            elseif ($pw !== (string) ($_POST['password2'] ?? '')) flash('The two passwords don’t match.', 'err');
            else {
                q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
                login_user(one('SELECT * FROM users WHERE id = ?', [$u['id']]));
                audit($u, 'password.change', 'user', (int) $u['id']);
                flash('Password changed. Other devices are signed out.');
            }
        } elseif ($act === 'totp_start' && !$u['totp_enabled']) {
            $_SESSION['totp_setup'] = base32_encode(random_bytes(20));
        } elseif ($act === 'totp_confirm' && $pendingSecret && !$u['totp_enabled']) {
            $fresh = one('SELECT * FROM users WHERE id = ?', [$u['id']]);
            if (totp_check($fresh, input('code'), $pendingSecret)) {
                q('UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [encrypt_secret($pendingSecret), $u['id']]);
                unset($_SESSION['totp_setup']);
                $_SESSION['show_codes'] = new_recovery_codes((int) $u['id']);
                audit($u, '2fa.enable', 'user', (int) $u['id']);
                flash('Two-step sign-in is on. Save your recovery codes somewhere safe.');
            } else flash('That code didn’t match. Check your phone’s time is correct and try the newest code.', 'err');
        } elseif ($act === 'totp_off' && $u['totp_enabled']) {
            if (is_staff($u) && setting('require_staff_2fa', '1') === '1') flash('Staff accounts must keep two-step sign-in on.', 'warn');
            elseif (!password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) flash('Enter your password to turn it off.', 'err');
            else {
                q('UPDATE users SET totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL WHERE id = ?', [$u['id']]);
                audit($u, '2fa.disable', 'user', (int) $u['id']);
                flash('Two-step sign-in is off.');
            }
        } elseif ($act === 'codes' && $u['totp_enabled']) {
            if (!password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) flash('Enter your password to make new recovery codes.', 'err');
            else {
                $_SESSION['show_codes'] = new_recovery_codes((int) $u['id']);
                audit($u, '2fa.codes', 'user', (int) $u['id']);
                flash('New recovery codes made. The old ones no longer work.');
            }
        }
        redirect('account/security');
    }
    $codes = $_SESSION['show_codes'] ?? null;
    unset($_SESSION['show_codes']);
    page_open('Security', ['nav' => 'account']);
    echo page_header('Account', 'Security', 'Your password and two-step sign-in.');
    echo '<div class="grid-2"><form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="password"><h2>Password</h2>'
        . ($u['must_change_password'] ? '<p class="muted">Choose your own password to continue.</p>' : '<label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>')
        . '<label class="field"><span>New password</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<label class="field"><span>Again</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label><button class="btn primary">Change password</button></form>';
    echo '<section class="card"><h2>Two-step sign-in</h2>';
    if ($codes) {
        echo '<div class="wash codes"><p class="micro">Recovery codes · shown once</p><ul>';
        foreach ($codes as $c) echo '<li><code>' . h($c) . '</code></li>';
        echo '</ul><p class="hint">Each works once if you lose your phone. Print them or store them in a password manager.</p></div>';
    }
    if ($u['totp_enabled']) {
        echo '<p class="id-ok">' . icon('shield') . ' On. You’ll enter a code from your authenticator app when you sign in.</p>'
            . '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="codes"><label class="field"><span>Password</span><input type="password" name="current" required autocomplete="current-password"></label><button class="btn outline">Make new recovery codes</button></form>';
        if (!is_staff($u)) echo '<form method="post" class="form inline">' . csrf_field() . '<input type="hidden" name="act" value="totp_off"><label class="field"><span>Password</span><input type="password" name="current" required></label><button class="btn ghost">Turn off</button></form>';
    } elseif ($pendingSecret) {
        $label = rawurlencode(app_name() . ':' . $u['email']);
        $uri = 'otpauth://totp/' . $label . '?secret=' . $pendingSecret . '&issuer=' . rawurlencode(app_name()) . '&digits=6&period=30';
        echo '<ol class="steps"><li>Open an authenticator app (Google Authenticator, Microsoft Authenticator, 1Password…).</li>'
            . '<li>Add an account using this setup key: <code class="key" id="totp-key">' . h(trim(chunk_split($pendingSecret, 4, ' '))) . '</code> <button type="button" class="btn ghost sm" data-copy="#totp-key">Copy</button><br><small class="muted">On a phone, <a href="' . h($uri) . '">tap here</a> to add it directly.</small></li>'
            . '<li>Enter the 6-digit code it shows.</li></ol><form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="totp_confirm">'
            . '<label class="field"><span>Code</span><input name="code" inputmode="numeric" autocomplete="one-time-code" required class="code-input"></label><button class="btn primary">Turn on</button></form>';
    } else {
        echo '<p>' . (is_staff($u) ? 'Required for government staff. ' : '') . 'Adds a code from your phone when you sign in, so a stolen password isn’t enough.</p>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="totp_start"><button class="btn primary">Set up two-step sign-in</button></form>';
    }
    echo '</section></div>';
    page_close();
}

function data_page(): void
{
    $u = require_login();
    if (is_post()) {
        if (input('act') === 'export') {
            $data = [
                'exported_at' => gmdate('c'), 'account' => array_intersect_key($u, array_flip(['name', 'email', 'phone', 'district', 'address_line', 'role', 'id_status', 'id_verified_at', 'created_at', 'last_login_at'])),
                'identities' => array_map(fn($i) => array_intersect_key($i, array_flip(['id_type', 'number_last4', 'full_name', 'dob', 'expiry', 'status', 'reviewed_at', 'reason', 'created_at'])), all('SELECT * FROM identities WHERE user_id = ?', [$u['id']])),
                'requests' => array_map(fn($r) => ['ref' => $r['ref'], 'service' => $r['title'], 'status' => $r['status'], 'created_at' => $r['created_at'], 'data' => json_decode((string) $r['data'], true),
                    'updates' => all('SELECT side, kind, body, status_to, created_at FROM request_events WHERE request_id = ? AND internal = 0 ORDER BY id', [$r['id']])],
                    all('SELECT r.*, s.title FROM requests r JOIN services s ON s.id = r.service_id WHERE r.user_id = ?', [$u['id']])),
                'notices' => all('SELECT kind, body, status, created_at FROM notices WHERE user_id = ?', [$u['id']]),
                'saved_services' => array_column(all('SELECT s.title FROM saved_services ss JOIN services s ON s.id = ss.service_id WHERE ss.user_id = ?', [$u['id']]), 'title'),
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="slu-services-my-data.json"');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        if (input('act') === 'close') {
            if ($u['role'] === 'owner') { flash('The owner account can’t be closed here.', 'warn'); redirect('account/data'); }
            if (!password_verify((string) ($_POST['current'] ?? ''), $u['password_hash'])) { flash('Enter your password to close your account.', 'err'); redirect('account/data'); }
            foreach (all('SELECT * FROM identities WHERE user_id = ?', [$u['id']]) as $i) {
                foreach (['front_file_id', 'back_file_id', 'selfie_file_id'] as $k) if ($i[$k]) vault_delete((int) $i[$k]);
            }
            // the fingerprint goes too, so the same ID can be linked again on a new account
            q("UPDATE identities SET status = 'closed', number_enc = '', number_hash = '', full_name = '', dob = NULL, images_purged_at = COALESCE(images_purged_at, ?) WHERE user_id = ?", [now_utc(), $u['id']]);
            q("UPDATE requests SET status = 'withdrawn', closed_at = ? WHERE user_id = ? AND status IN ('submitted','in_review','action_needed')", [now_utc(), $u['id']]);
            q("UPDATE users SET active = 0, name = 'Closed account', email = ?, phone = NULL, address_line = NULL, district = NULL, dob = NULL, id_status = 'none', id_verified_at = NULL,
                tester = 0, totp_secret = NULL, totp_enabled = 0, recovery_codes = NULL, notify_email = 0, password_hash = ? WHERE id = ?",
                ['closed-' . $u['id'] . '-' . bin2hex(random_bytes(4)) . '@invalid', password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $u['id']]);
            q('DELETE FROM saved_services WHERE user_id = ?', [$u['id']]);
            audit($u, 'account.close', 'user', (int) $u['id']);
            logout_user();
            start_session();
            flash('Your account is closed and your ID photos are deleted. Agencies keep records of requests they handled, as the law requires.');
            redirect('login');
        }
    }
    page_open('Your data', ['nav' => 'account']);
    echo page_header('Account', 'Your data', 'Download everything we hold about you, or close your account.');
    echo '<div class="grid-2"><form method="post" class="card">' . csrf_field() . '<input type="hidden" name="act" value="export"><h2>Download</h2><p>Your profile, ID verification results (not the photos), requests and messages, notices and saved services, as a JSON file.</p><button class="btn outline">' . icon('download') . 'Download my data</button></form>'
        . '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="close"><h2>Close account</h2><p>We delete your ID photos and personal details and withdraw open requests. Agencies keep records of requests they handled.</p>'
        . '<label class="field"><span>Password</span><input type="password" name="current" required autocomplete="current-password"></label><button class="btn ghost danger" data-confirm="Close your account? This can’t be undone.">Close my account</button></form></div>';
    page_close();
}
