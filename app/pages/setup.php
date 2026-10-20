<?php
defined('DS') || exit;

// First-run setup: connects to the Hostinger MySQL database, creates the tables and starter
// catalogue, and the owner account. Only shown while data/config.php doesn't exist.
// Knowing the database password is what proves the visitor owns this hosting account.
function setup_page(): void
{
    if (route_path() !== 'setup') redirect('setup');
    $errors = [];
    $v = ['db_name' => input('db_name'), 'db_user' => input('db_user'), 'db_host' => input('db_host', 'localhost'), 'name' => input('name'), 'email' => mb_strtolower(input('email'))];
    if (is_post()) {
        $pass = (string) ($_POST['db_pass'] ?? '');
        $pw = (string) ($_POST['password'] ?? '');
        if (!in_array($v['db_host'], ['localhost', '127.0.0.1'], true)) $errors[] = 'The database host must be “localhost” (Hostinger’s own MySQL).';
        if ($v['db_name'] === '' || $v['db_user'] === '' || $pass === '') $errors[] = 'Fill in the database name, user and password from hPanel.';
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $v['db_name'])) $errors[] = 'The database name should look like u123456789_govt.';
        $pdo = null;
        if (!$errors) {
            try {
                $pdo = db_connect(['host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $pass]);
            } catch (PDOException $e) {
                $errors[] = 'Couldn’t connect to that database. Check the name, user and password in hPanel › Databases and try again.';
                log_error($e);
            }
        }
        $existing = false;
        if ($pdo) {
            try {
                $existing = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
            } catch (PDOException $e) {
            }
            if (!$existing) {
                if ($v['name'] === '') $errors[] = 'Enter your name.';
                if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
                if ($p = password_problem($pw, $v['email'])) $errors[] = $p;
                if ($pw !== (string) ($_POST['password2'] ?? '')) $errors[] = 'The two passwords don’t match.';
            }
        }
        if (!$errors && $pdo) {
            clearstatcache();
            if (is_file(CONFIG_FILE)) redirect('');
            $cfg = ['db' => ['host' => $v['db_host'], 'name' => $v['db_name'], 'user' => $v['db_user'], 'pass' => $pass], 'key' => bin2hex(random_bytes(32)), 'installed_at' => gmdate('c'),
                'url' => (is_https() ? 'https://' : 'http://') . preg_replace('/[^a-z0-9.:-]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'services.digitalsolucians.com'))];
            $current = 0;
            try {
                $current = (int) $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn();
            } catch (PDOException $e) {
            }
            apply_migrations($pdo, $current);
            if (!$existing) {
                $pdo->prepare("INSERT INTO users (name, email, password_hash, role, email_verified_at, created_at) VALUES (?, ?, ?, 'owner', ?, ?)")
                    ->execute([mb_substr($v['name'], 0, 120), $v['email'], password_hash($pw, PASSWORD_DEFAULT), now_utc(), now_utc()]);
            }
            write_config($cfg);
            flash($existing ? 'Reconnected to your existing database. Sign in with your usual account.' : 'SLU Services is ready. Sign in, then turn on two-step sign-in to open the console.');
            redirect('login');
        }
    }

    head_html('Set up', 'public');
    echo '<main class="wrap narrow setup"><div class="brand big">' . logo_mark() . '<span>SLU Services</span></div>'
        . '<h1>Set up SLU Services</h1><p class="lede">One time only. This page disappears once you finish.</p>';
    if ($errors) {
        echo '<div class="flash err" role="alert"><ul>';
        foreach ($errors as $e) echo '<li>' . h($e) . '</li>';
        echo '</ul></div>';
    }
    echo '<form method="post" class="card form" autocomplete="off">' . csrf_field()
        . '<fieldset><legend class="micro">1 · Hostinger database</legend><p class="hint">hPanel › Databases › Management. Use a new database for this app (not the CRM’s).</p>'
        . '<label class="field"><span>Database name</span><input name="db_name" value="' . h($v['db_name']) . '" placeholder="u123456789_govt" required></label>'
        . '<label class="field"><span>Database user</span><input name="db_user" value="' . h($v['db_user']) . '" placeholder="u123456789_govt" required></label>'
        . '<label class="field"><span>Database password</span><input type="password" name="db_pass" required autocomplete="new-password"></label>'
        . '<input type="hidden" name="db_host" value="' . h($v['db_host']) . '"></fieldset>'
        . '<fieldset><legend class="micro">2 · Owner account</legend><p class="hint">Skip this if you are reconnecting a database that already has accounts.</p>'
        . '<label class="field"><span>Your name</span><input name="name" value="' . h($v['name']) . '" autocomplete="name"></label>'
        . '<label class="field"><span>Email</span><input type="email" name="email" value="' . h($v['email']) . '" autocomplete="email"></label>'
        . '<label class="field"><span>Password <small>10+ characters</small></span><input type="password" name="password" autocomplete="new-password" minlength="10"></label>'
        . '<label class="field"><span>Password again</span><input type="password" name="password2" autocomplete="new-password" minlength="10"></label></fieldset>'
        . '<button class="btn primary block">Create SLU Services</button></form></main></body></html>';
}
