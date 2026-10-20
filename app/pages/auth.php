<?php
defined('DS') || exit;

// Public pages: landing, sign in (with two-step), create account, email confirmation,
// password reset and the privacy notice.

function landing_page(): void
{
    $services = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published'");
    $agencies = (int) val('SELECT COUNT(*) FROM agencies WHERE active = 1');
    $events = (int) val("SELECT COUNT(*) FROM categories WHERE kind = 'life_event'");
    $confirmed = (int) val("SELECT COUNT(*) FROM services WHERE status = 'published' AND agency_verified = 1");
    public_open('Saint Lucia public services', 'public landing');
    echo '<section class="hero"><div class="hero-copy"><p class="micro">For residents of Saint Lucia</p>'
        . '<h1 class="display">Every public service in Saint Lucia, in one place.</h1>'
        . '<p class="lede">Find what you need from every ministry, department and statutory body. Apply online where you can, track your requests, and link your ID once.</p>'
        . '<form class="composite" method="get" action="' . h(url('register')) . '"><label class="sr-only" for="hero-email">Email address</label>'
        . '<input id="hero-email" type="email" name="email" placeholder="Your email address" autocomplete="email" required><button class="btn primary">Create account</button></form>'
        . '<p class="hint">Already have an account? <a href="' . h(url('login')) . '">Sign in</a></p></div>'
        . '<div class="shot" aria-hidden="true"><div class="shot-bar"><i></i><i></i><i></i><span>services.digitalsolucians.com</span></div><div class="shot-body">'
        . '<div class="shot-search">' . icon('search') . '<span>renew driver’s licence</span></div>'
        . '<div class="shot-card"><span class="micro">Division of Transport</span><strong>Renew a driver’s licence</strong><p>Renew before your licence expires, in person or online through digiGov.</p>'
        . '<div class="shot-meta"><span class="tag">EC$150–300</span><span class="tag">Same day</span></div><span class="btn primary sm">Apply on digiGov</span></div>'
        . '<div class="shot-card"><span class="micro">Request SLU-26-4F8K2Q</span><strong>Police certificate of character</strong><div class="shot-steps"><i class="on"></i><i class="on"></i><i></i><i></i></div><span class="tag hot">In review</span></div>'
        . '</div></div></section>';
    echo '<section class="ticker" aria-label="At a glance"><div class="wrap ticker-row">'
        . '<div><span class="micro">Services listed</span><strong>' . number_format($services) . '</strong></div>'
        . '<div><span class="micro">Agencies</span><strong>' . number_format($agencies) . '</strong></div>'
        . '<div><span class="micro">Life events</span><strong>' . number_format($events) . '</strong></div>'
        . '<div><span class="micro">Confirmed by agencies</span><strong>' . number_format($confirmed) . '</strong></div></div></section>';
    echo '<section class="band"><div class="cols3">'
        . '<article class="card"><span class="micro">01 · Find</span><h2>Search once, across government</h2><p>Plain-language pages for each service: who can apply, what to bring, fees, how long it takes and where to go. Browse by topic or by life event.</p></article>'
        . '<article class="card"><span class="micro">02 · Apply & track</span><h2>Requests with a reference number</h2><p>Where an agency accepts online requests, apply here and follow every step. Agencies message you when they need something.</p></article>'
        . '<article class="card"><span class="micro">03 · Link your ID</span><h2>Prove who you are once</h2><p>Link your national ID, passport or driver’s licence. Identity officers check it, and verified residents can use services that need ID. This opens once the Government of Saint Lucia authorises it.</p></article></div></section>';
    echo '<section class="band split"><div><p class="micro">For government agencies</p><h2 class="h-lg">Keep your services up to date, in whatever format you have.</h2>'
        . '<p class="lede">Send a spreadsheet, a Word or PDF document, a web page, an email or an API call. The app reads it, turns it into structured service pages and waits for your officer to approve before anything changes.</p></div>'
        . '<ul class="checklist"><li>' . icon('check') . 'Spreadsheets (CSV, Excel) and JSON</li><li>' . icon('check') . 'Word, PDF, web pages and scanned forms</li><li>' . icon('check') . 'A dedicated intake mailbox</li><li>' . icon('check') . 'A secure API for agency systems</li><li>' . icon('check') . 'Review, version history and audit log</li></ul></section>';
    $events = categories('life_event');
    echo '<section class="band"><p class="micro">Life events</p><h2 class="h-lg">Start from what’s happening in your life.</h2><div class="chip-grid">';
    foreach ($events as $e) echo '<a class="chip" href="' . h(url('topics/' . $e['slug'])) . '">' . icon((string) $e['icon']) . '<span>' . h($e['name']) . '</span></a>';
    echo '</div></section>';
    public_close();
}

function login_page(): void
{
    if (current_user()) redirect('');
    $next = safe_next(qs('next', input('next')));
    $email = mb_strtolower(input('email'));
    $error = '';
    if (is_post()) {
        if (login_throttled($email)) $error = 'Too many attempts. Wait 15 minutes and try again.';
        else {
            $u = check_password($email, (string) ($_POST['password'] ?? ''));
            if (!$u) $error = 'That email and password don’t match.';
            elseif ($u['totp_enabled']) {
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int) $u['id'];
                $_SESSION['pending_at'] = time();
                $_SESSION['pending_tries'] = 0;
                redirect('login/2fa', ['next' => $next]);
            } else {
                login_user($u);
                redirect($next);
            }
        }
    }
    public_open('Sign in', 'public auth');
    echo '<div class="auth"><h1>Sign in</h1><p class="lede">to ' . h(app_name()) . '</p>';
    if ($error) echo '<div class="flash err" role="alert">' . h($error) . '</div>';
    echo '<form method="post" class="form card">' . csrf_field() . '<input type="hidden" name="next" value="' . h($next) . '">'
        . '<label class="field"><span>Email</span><input type="email" name="email" value="' . h($email) . '" required autocomplete="username" autofocus></label>'
        . '<label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>'
        . '<button class="btn primary block">Sign in</button></form>'
        . '<p class="hint"><a href="' . h(url('forgot')) . '">Forgot your password?</a> · New here? <a href="' . h(url('register')) . '">Create an account</a></p></div>';
    public_close();
}

function twofa_challenge_page(): void
{
    $uid = (int) ($_SESSION['pending_uid'] ?? 0);
    if (!$uid || time() - (int) ($_SESSION['pending_at'] ?? 0) > 600) {
        unset($_SESSION['pending_uid']);
        flash('Sign in again to continue.', 'warn');
        redirect('login');
    }
    $u = one('SELECT * FROM users WHERE id = ? AND active = 1', [$uid]);
    if (!$u) redirect('login');
    $next = safe_next(qs('next', input('next')));
    $error = '';
    $locked = $u['totp_locked_until'] && strtotime($u['totp_locked_until'] . ' UTC') > time();
    if (is_post()) {
        $_SESSION['pending_tries'] = (int) ($_SESSION['pending_tries'] ?? 0) + 1;
        $code = input('code');
        if ($locked || $_SESSION['pending_tries'] > 6) {
            unset($_SESSION['pending_uid']);
            flash($locked ? 'Too many wrong codes for this account. Try again in 15 minutes.' : 'Too many wrong codes. Sign in again.', 'err');
            redirect('login');
        }
        // authenticator codes are 6 digits; recovery codes look like ABC12-3DE45
        $isTotp = (bool) preg_match('/^\d{3}\s?\d{3}$/', trim($code));
        $ok = $isTotp ? totp_check($u, $code) : use_recovery_code($u, $code);
        if ($ok) {
            if (!$isTotp) flash('You used a recovery code. Set up two-step sign-in again if you lost your phone.', 'warn');
            login_user($u);
            redirect($next);
        }
        // failures count per account (not per session), so signing in again doesn't reset them
        record_failed_login($u['email']);
        q('UPDATE users SET totp_failures = totp_failures + 1 WHERE id = ?', [$u['id']]);
        if ((int) $u['totp_failures'] + 1 >= 5) {
            q('UPDATE users SET totp_failures = 0, totp_locked_until = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 900), $u['id']]);
            audit($u, '2fa.locked', 'user', (int) $u['id'], 'IP ' . client_ip());
            send_mail($u['email'], 'Wrong sign-in codes on your ' . app_name() . ' account', "Someone entered your password correctly but then several wrong two-step codes. Sign-in is paused for 15 minutes.\n\nIf this wasn't you, change your password now: " . absolute_url('forgot'));
            unset($_SESSION['pending_uid']);
            flash('Too many wrong codes for this account. Try again in 15 minutes.', 'err');
            redirect('login');
        }
        $error = 'That code didn’t work. Use the 6-digit code from your authenticator app, or a recovery code.';
    }
    public_open('Two-step sign-in', 'public auth');
    echo '<div class="auth"><h1>Enter your code</h1><p class="lede">Open your authenticator app and enter the 6-digit code for ' . h(app_name()) . '.</p>';
    if ($error) echo '<div class="flash err" role="alert">' . h($error) . '</div>';
    echo '<form method="post" class="form card">' . csrf_field() . '<input type="hidden" name="next" value="' . h($next) . '">'
        . '<label class="field"><span>Code</span><input name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus class="code-input"></label>'
        . '<button class="btn primary block">Continue</button></form><p class="hint">Lost your phone? Enter one of your recovery codes instead.</p></div>';
    public_close();
}

function register_page(): void
{
    if (current_user()) redirect('');
    $v = ['name' => input('name'), 'email' => mb_strtolower(input('email', qs('email'))), 'phone' => input('phone'), 'district' => input('district')];
    $errors = [];
    if (is_post()) {
        $pw = (string) ($_POST['password'] ?? '');
        if (mb_strlen($v['name']) < 2) $errors[] = 'Enter your full name.';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
        if ($v['phone'] !== '' && !preg_match('/^[+()\d\s-]{7,20}$/', $v['phone'])) $errors[] = 'Enter a valid phone number, or leave it empty.';
        if ($v['district'] !== '' && !in_array($v['district'], DISTRICTS, true)) $errors[] = 'Choose your district from the list.';
        if ($p = password_problem($pw, $v['email'])) $errors[] = $p;
        if ($pw !== (string) ($_POST['password2'] ?? '')) $errors[] = 'The two passwords don’t match.';
        if (input('agree') !== '1') $errors[] = 'Please read and accept how we use your information.';
        if (login_throttled($v['email'])) $errors[] = 'Too many attempts. Try again in 15 minutes.';
        if (!$errors && val('SELECT 1 FROM users WHERE email = ?', [$v['email']])) {
            // don't reveal that the account exists; tell the owner by email instead
            record_failed_login($v['email']);
            send_mail($v['email'], 'Someone tried to create an account with your email', "Someone (maybe you) tried to create a " . app_name() . " account with this email. You already have one.\n\nSign in: " . absolute_url('login') . "\nForgot your password? " . absolute_url('forgot'));
            flash('Check your email to continue.', 'ok');
            redirect('login');
        }
        if (!$errors) {
            $id = insert('users', ['name' => mb_substr($v['name'], 0, 120), 'email' => $v['email'], 'phone' => $v['phone'] ?: null, 'district' => $v['district'] ?: null,
                'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => 'citizen', 'created_at' => now_utc()]);
            $u = one('SELECT * FROM users WHERE id = ?', [$id]);
            login_user($u);
            send_email_code($u);
            redirect('verify-email');
        }
    }
    public_open('Create account', 'public auth');
    echo '<div class="auth wide"><h1>Create your account</h1><p class="lede">It’s free. You’ll confirm your email next.</p>';
    if ($errors) {
        echo '<div class="flash err" role="alert"><ul>';
        foreach ($errors as $e) echo '<li>' . h($e) . '</li>';
        echo '</ul></div>';
    }
    echo '<form method="post" class="form card">' . csrf_field()
        . '<label class="field"><span>Full name <small>as on your ID</small></span><input name="name" value="' . h($v['name']) . '" required autocomplete="name" maxlength="120"></label>'
        . '<label class="field"><span>Email</span><input type="email" name="email" value="' . h($v['email']) . '" required autocomplete="email"></label>'
        . '<div class="two"><label class="field"><span>Phone <small>optional</small></span><input type="tel" name="phone" value="' . h($v['phone']) . '" autocomplete="tel" placeholder="(758) 000-0000"></label>'
        . '<label class="field"><span>District <small>optional</small></span><select name="district"><option value="">Choose…</option>';
    foreach (DISTRICTS as $d) echo '<option' . ($v['district'] === $d ? ' selected' : '') . '>' . h($d) . '</option>';
    echo '</select></label></div>'
        . '<div class="two"><label class="field"><span>Password <small>10+ characters</small></span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<label class="field"><span>Password again</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label></div>'
        . '<label class="check"><input type="checkbox" name="agree" value="1" required> I’ve read <a href="' . h(url('privacy')) . '" target="_blank">how my information is used</a>.</label>'
        . '<button class="btn primary block">Create account</button></form>'
        . '<p class="hint">Already registered? <a href="' . h(url('login')) . '">Sign in</a></p></div>';
    public_close();
}

function verify_email_page(): void
{
    $u = require_login();
    $next = safe_next(qs('next', input('next')));
    if ($u['email_verified_at']) redirect($next);
    $error = '';
    if (is_post()) {
        if (input('act') === 'resend') {
            $last = val("SELECT created_at FROM user_tokens WHERE user_id = ? AND kind = 'email' ORDER BY id DESC LIMIT 1", [$u['id']]);
            if ($last && strtotime($last . ' UTC') > time() - 60) flash('Wait a minute before asking for another code.', 'warn');
            else { send_email_code($u); flash('We sent a new code to ' . $u['email'] . '.'); }
            redirect('verify-email', ['next' => $next]);
        }
        if (use_token((int) $u['id'], 'email', input('code'))) {
            q('UPDATE users SET email_verified_at = ? WHERE id = ?', [now_utc(), $u['id']]);
            flash('Email confirmed. Welcome to ' . app_name() . '.');
            redirect($next);
        }
        $error = 'That code isn’t right or has expired. Check the latest email from us, or send a new code.';
    }
    page_open('Confirm your email');
    echo '<div class="auth"><h1>Confirm your email</h1><p class="lede">We sent a 6-digit code to <strong>' . h($u['email']) . '</strong>.</p>';
    if ($error) echo '<div class="flash err" role="alert">' . h($error) . '</div>';
    echo '<form method="post" class="form card">' . csrf_field() . '<input type="hidden" name="next" value="' . h($next) . '">'
        . '<label class="field"><span>Code</span><input name="code" inputmode="numeric" maxlength="6" required autocomplete="one-time-code" class="code-input" autofocus></label>'
        . '<button class="btn primary block">Confirm</button></form>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="resend"><input type="hidden" name="next" value="' . h($next) . '"><button class="btn ghost">Send a new code</button></form></div>';
    page_close();
}

function logout_action(): void
{
    $u = current_user();
    if ($u && is_staff($u)) audit($u, 'logout');
    logout_user();
    start_session();
    flash('You’re signed out.');
    redirect('login');
}

function forgot_page(): void
{
    if (is_post()) {
        $email = mb_strtolower(input('email'));
        // reset requests have their own limit (3 per address per 15 minutes) so they can't lock anyone out of signing in
        $key = mb_substr('reset:' . $email, 0, 190);
        $since = gmdate('Y-m-d H:i:s', time() - 900);
        $tooMany = (int) val('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > ?', [$key, $since]) >= 3
            || (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $since]) >= 20;
        if (!$tooMany && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            record_failed_login($key);
            $u = one('SELECT * FROM users WHERE email = ? AND active = 1', [$email]);
            if ($u) {
                $t = issue_token((int) $u['id'], 'reset', 3600);
                send_mail($u['email'], 'Reset your ' . app_name() . ' password', "Hi " . $u['name'] . ",\n\nUse this link within an hour to choose a new password:\n" . absolute_url('reset/' . $t) . "\n\nIf you didn't ask for this, ignore this email.");
            }
        }
        flash('If that email has an account, we sent a link to reset the password.');
        redirect('login');
    }
    public_open('Reset password', 'public auth');
    echo '<div class="auth"><h1>Reset your password</h1><p class="lede">We’ll email you a link.</p><form method="post" class="form card">' . csrf_field()
        . '<label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label><button class="btn primary block">Send link</button></form></div>';
    public_close();
}

function reset_page(string $token): void
{
    $hash = lookup_hash($token, 'token-reset');
    $t = one('SELECT * FROM user_tokens WHERE token_hash = ? AND kind = ? AND used_at IS NULL AND expires_at > ?', [$hash, 'reset', now_utc()]);
    if (!$t) { flash('That link has expired. Ask for a new one.', 'warn'); redirect('forgot'); }
    $u = one('SELECT * FROM users WHERE id = ?', [$t['user_id']]);
    $error = '';
    if (is_post()) {
        $pw = (string) ($_POST['password'] ?? '');
        if ($p = password_problem($pw, $u['email'])) $error = $p;
        elseif ($pw !== (string) ($_POST['password2'] ?? '')) $error = 'The two passwords don’t match.';
        elseif (use_token(null, 'reset', $token)) {
            q('UPDATE users SET password_hash = ?, must_change_password = 0, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), now_utc(), $u['id']]);
            audit($u, 'password.reset', 'user', (int) $u['id']);
            flash('Password changed. Sign in with your new password.');
            redirect('login');
        }
    }
    public_open('Choose a new password', 'public auth');
    echo '<div class="auth"><h1>Choose a new password</h1>';
    if ($error) echo '<div class="flash err" role="alert">' . h($error) . '</div>';
    echo '<form method="post" class="form card">' . csrf_field()
        . '<label class="field"><span>New password</span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
        . '<label class="field"><span>Again</span><input type="password" name="password2" required minlength="10" autocomplete="new-password"></label><button class="btn primary block">Save password</button></form></div>';
    public_close();
}

function privacy_page(): void
{
    $days = (int) setting('id_image_retention_days', '30');
    $c = clearance();
    $u = current_user();
    $u ? page_open('Privacy & ID data') : public_open('Privacy & ID data');
    echo page_header('Privacy', 'How your information is used', 'Plain language, no small print.');
    echo '<div class="prose card">'
        . '<h2>Status of this service</h2><p>' . h(CLEARANCE[$c]) . '. ' . ($c === 'granted'
            ? 'This service operates with the authorisation of the Government of Saint Lucia' . (setting('clearance_authority') ? ' (' . h((string) setting('clearance_authority')) . ', reference ' . h((string) setting('clearance_ref')) . ')' : '') . '.'
            : 'It is being built by Digital SoLucians together with the Government of Saint Lucia. Until the Government authorises it, ID linking stays closed (or open to testers only), and service information comes from public government sources marked “awaiting agency confirmation”.') . '</p>'
        . '<h2>Your account</h2><p>We keep your name, email, phone, district and address so agencies can process your requests and reach you. You can change them in your profile, download everything we hold under <em>Your data</em>, or close your account.</p>'
        . '<h2>Linking your ID</h2><p>When you link an ID, we store the ID number encrypted and only show its last four digits. The photos of your ID and your selfie are encrypted. Only identity verifiers authorised by the Government, and this service’s administrators, can open your ID details, and every time they do it is recorded. ' . $days . ' days after a decision we delete the photos, the full number, and the name and date of birth as written on the ID. We keep the result (verified or not), the ID type, its expiry, the last four digits, who checked it, and a one-way fingerprint of the number so the same ID can’t be linked to two accounts. Closing your account removes the fingerprint too.</p>'
        . '<h2>Requests</h2><p>What you send with a request goes only to the agency that provides that service. Agency officers see the requests for their own agency.</p>'
        . '<h2>Security</h2><p>Passwords are hashed, documents are encrypted at rest, the site only works over HTTPS, and government staff must use two-step sign-in. Staff actions are recorded in an audit log.</p>'
        . '<h2>Questions</h2><p>Write to the agency that handles your request, or to Digital SoLucians, which runs this service.</p></div>';
    $u ? page_close() : public_close();
}
