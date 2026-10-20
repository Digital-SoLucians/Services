<?php
defined('DS') || exit;

// Database migrations. Add new steps at the end; never edit a step that has shipped.
function migrations(): array
{
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        1 => [
            "CREATE TABLE IF NOT EXISTS settings (
                k VARCHAR(64) NOT NULL PRIMARY KEY,
                v MEDIUMTEXT NOT NULL
            )$t",
            "CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL,
                phone VARCHAR(40) NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(12) NOT NULL DEFAULT 'citizen',
                agency_id INT UNSIGNED NULL,
                tester TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                email_verified_at DATETIME NULL,
                totp_secret TEXT NULL,
                totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
                totp_last_step BIGINT NOT NULL DEFAULT 0,
                totp_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                totp_locked_until DATETIME NULL,
                recovery_codes TEXT NULL,
                id_status VARCHAR(12) NOT NULL DEFAULT 'none',
                id_verified_at DATETIME NULL,
                dob DATE NULL,
                address_line VARCHAR(255) NULL,
                district VARCHAR(40) NULL,
                notify_email TINYINT(1) NOT NULL DEFAULT 1,
                must_change_password TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                last_login_at DATETIME NULL,
                UNIQUE KEY uq_email (email),
                INDEX idx_role (role, agency_id)
            )$t",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL,
                email VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ip (ip, created_at),
                INDEX idx_email (email, created_at)
            )$t",
            "CREATE TABLE IF NOT EXISTS user_tokens (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                kind VARCHAR(16) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                tries TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_user (user_id, kind),
                INDEX idx_hash (token_hash)
            )$t",
            "CREATE TABLE IF NOT EXISTS agencies (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(100) NOT NULL,
                name VARCHAR(255) NOT NULL,
                short_name VARCHAR(80) NULL,
                type VARCHAR(16) NOT NULL DEFAULT 'department',
                parent_id INT UNSIGNED NULL,
                description TEXT NULL,
                address VARCHAR(255) NULL,
                phone VARCHAR(120) NULL,
                email VARCHAR(190) NULL,
                website VARCHAR(255) NULL,
                hours VARCHAR(255) NULL,
                source_url VARCHAR(500) NULL,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_slug (slug),
                INDEX idx_parent (parent_id)
            )$t",
            "CREATE TABLE IF NOT EXISTS categories (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) NOT NULL,
                name VARCHAR(120) NOT NULL,
                kind VARCHAR(12) NOT NULL DEFAULT 'topic',
                description VARCHAR(500) NULL,
                icon VARCHAR(24) NULL,
                position INT NOT NULL DEFAULT 0,
                UNIQUE KEY uq_slug (slug)
            )$t",
            "CREATE TABLE IF NOT EXISTS services (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(120) NOT NULL,
                agency_id INT UNSIGNED NOT NULL,
                title VARCHAR(255) NOT NULL,
                summary VARCHAR(600) NULL,
                description TEXT NULL,
                eligibility TEXT NULL,
                requirements TEXT NULL,
                fees TEXT NULL,
                processing_time VARCHAR(255) NULL,
                steps TEXT NULL,
                hours VARCHAR(255) NULL,
                location TEXT NULL,
                contact TEXT NULL,
                online_url VARCHAR(500) NULL,
                form_url VARCHAR(500) NULL,
                mode VARCHAR(12) NOT NULL DEFAULT 'info',
                request_fields TEXT NULL,
                requires_id TINYINT(1) NOT NULL DEFAULT 0,
                keywords VARCHAR(500) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'published',
                source VARCHAR(12) NOT NULL DEFAULT 'manual',
                source_url VARCHAR(500) NULL,
                agency_verified TINYINT(1) NOT NULL DEFAULT 0,
                last_verified_at DATETIME NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                views INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_slug (slug),
                INDEX idx_agency (agency_id, status),
                INDEX idx_status (status, updated_at)
            )$t",
            "CREATE TABLE IF NOT EXISTS service_categories (
                service_id INT UNSIGNED NOT NULL,
                category_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (service_id, category_id),
                INDEX idx_cat (category_id)
            )$t",
            "CREATE TABLE IF NOT EXISTS service_versions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                service_id INT UNSIGNED NOT NULL,
                version INT UNSIGNED NOT NULL,
                data MEDIUMTEXT NOT NULL,
                actor_id INT UNSIGNED NULL,
                draft_id INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_service (service_id, version)
            )$t",
            "CREATE TABLE IF NOT EXISTS saved_services (
                user_id INT UNSIGNED NOT NULL,
                service_id INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY (user_id, service_id)
            )$t",
            "CREATE TABLE IF NOT EXISTS files (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                purpose VARCHAR(16) NOT NULL,
                storage_name CHAR(48) NOT NULL,
                mime VARCHAR(100) NOT NULL,
                size INT UNSIGNED NOT NULL,
                sha256 CHAR(64) NOT NULL,
                original_name VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                deleted_at DATETIME NULL,
                INDEX idx_user (user_id, purpose)
            )$t",
            "CREATE TABLE IF NOT EXISTS requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                ref VARCHAR(24) NOT NULL,
                user_id INT UNSIGNED NOT NULL,
                service_id INT UNSIGNED NOT NULL,
                agency_id INT UNSIGNED NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'submitted',
                data MEDIUMTEXT NULL,
                assigned_to INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                closed_at DATETIME NULL,
                UNIQUE KEY uq_ref (ref),
                INDEX idx_user (user_id, updated_at),
                INDEX idx_agency (agency_id, status, updated_at)
            )$t",
            "CREATE TABLE IF NOT EXISTS request_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                actor_id INT UNSIGNED NULL,
                side VARCHAR(8) NOT NULL,
                kind VARCHAR(12) NOT NULL,
                body TEXT NULL,
                status_to VARCHAR(16) NULL,
                file_id INT UNSIGNED NULL,
                internal TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                INDEX idx_request (request_id, id)
            )$t",
            "CREATE TABLE IF NOT EXISTS identities (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                id_type VARCHAR(20) NOT NULL,
                number_enc TEXT NOT NULL,
                number_hash CHAR(64) NOT NULL,
                number_last4 VARCHAR(4) NOT NULL,
                full_name VARCHAR(190) NOT NULL,
                dob DATE NULL,
                expiry DATE NULL,
                front_file_id INT UNSIGNED NULL,
                back_file_id INT UNSIGNED NULL,
                selfie_file_id INT UNSIGNED NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                reviewer_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                reason VARCHAR(500) NULL,
                checks TEXT NULL,
                consent_at DATETIME NOT NULL,
                images_purged_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_user (user_id),
                INDEX idx_status (status, created_at),
                INDEX idx_hash (number_hash)
            )$t",
            "CREATE TABLE IF NOT EXISTS notifications (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                text VARCHAR(500) NOT NULL,
                url VARCHAR(255) NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_user (user_id, read_at, id)
            )$t",
            "CREATE TABLE IF NOT EXISTS notices (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                agency_id INT UNSIGNED NOT NULL,
                kind VARCHAR(20) NOT NULL,
                body TEXT NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'sent',
                handled_by INT UNSIGNED NULL,
                handled_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_agency (agency_id, status)
            )$t",
            "CREATE TABLE IF NOT EXISTS intake_items (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agency_id INT UNSIGNED NULL,
                channel VARCHAR(10) NOT NULL,
                format VARCHAR(10) NOT NULL,
                title VARCHAR(255) NULL,
                original_name VARCHAR(255) NULL,
                file_id INT UNSIGNED NULL,
                source_url VARCHAR(500) NULL,
                raw_text MEDIUMTEXT NULL,
                payload MEDIUMTEXT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'received',
                method VARCHAR(12) NULL,
                error VARCHAR(500) NULL,
                submitted_by INT UNSIGNED NULL,
                api_key_id INT UNSIGNED NULL,
                submitter_email VARCHAR(190) NULL,
                sender_verified TINYINT(1) NULL,
                drafts INT UNSIGNED NOT NULL DEFAULT 0,
                attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                processed_at DATETIME NULL,
                INDEX idx_status (status, id),
                INDEX idx_agency (agency_id, id)
            )$t",
            "CREATE TABLE IF NOT EXISTS intake_drafts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                intake_id INT UNSIGNED NOT NULL,
                agency_id INT UNSIGNED NULL,
                service_id INT UNSIGNED NULL,
                data MEDIUMTEXT NOT NULL,
                method VARCHAR(12) NOT NULL,
                confidence TINYINT UNSIGNED NOT NULL DEFAULT 50,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                reviewer_id INT UNSIGNED NULL,
                reviewed_at DATETIME NULL,
                note VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_intake (intake_id),
                INDEX idx_status (status, agency_id)
            )$t",
            "CREATE TABLE IF NOT EXISTS api_keys (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                agency_id INT UNSIGNED NOT NULL,
                name VARCHAR(80) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                last4 CHAR(4) NOT NULL,
                auto_publish TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                UNIQUE KEY uq_hash (token_hash)
            )$t",
            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_id INT UNSIGNED NULL,
                action VARCHAR(64) NOT NULL,
                target VARCHAR(32) NULL,
                target_id INT UNSIGNED NULL,
                detail VARCHAR(1000) NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_created (created_at),
                INDEX idx_target (target, target_id)
            )$t",
            fn(PDO $pdo) => seed_catalog($pdo),
        ],
    ];
}

function run_migration_sql(PDO $pdo, string $sql): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // already applied: table/column/index exists
        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1050, 1060, 1061], true)) throw $e;
    }
}

function schema_version(): int
{
    try {
        return (int) (val("SELECT v FROM settings WHERE k = 'schema_version'") ?? 0);
    } catch (PDOException $e) {
        return 0;
    }
}

function migrate(): void
{
    $current = schema_version();
    $steps = migrations();
    if ($current >= max(array_keys($steps))) return;
    apply_migrations(db(), $current);
    settings_all(true);
}

function apply_migrations(PDO $pdo, int $current): void
{
    foreach (migrations() as $v => $sqls) {
        if ($v <= $current) continue;
        foreach ($sqls as $sql) is_callable($sql) ? $sql($pdo) : run_migration_sql($pdo, $sql);
        $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)")->execute([(string) $v]);
    }
    $st = $pdo->prepare('INSERT IGNORE INTO settings (k, v) VALUES (?, ?)');
    foreach (default_settings() as $k => $val) $st->execute([$k, $val]);
}

function default_settings(): array
{
    return [
        'app_name' => 'SLU Services',
        'tagline' => 'Every public service in Saint Lucia, in one place.',
        'clearance_status' => 'none',
        'require_staff_2fa' => '1',
        'id_image_retention_days' => '30',
        'ai_model' => 'claude-opus-5-5',
        'request_prefix' => 'SLU',
        'intake_email_domains' => 'govt.lc',
    ];
}

/** Agencies, topics, life events and the starter service catalogue (see seed.php). */
function seed_catalog(PDO $pdo): void
{
    require_once APP_DIR . '/seed.php';
    $now = gmdate('Y-m-d H:i:s');
    if ((int) $pdo->query('SELECT COUNT(*) FROM agencies')->fetchColumn() > 0) return;
    $cat = $pdo->prepare('INSERT INTO categories (slug, name, kind, description, icon, position) VALUES (?, ?, ?, ?, ?, ?)');
    foreach (array_values(seed_categories()) as $i => $c) $cat->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $i + 1]);
    $catIds = [];
    foreach ($pdo->query('SELECT id, slug FROM categories') as $r) $catIds[$r['slug']] = (int) $r['id'];

    $ag = $pdo->prepare('INSERT INTO agencies (slug, name, short_name, type, parent_id, description, address, phone, email, website, hours, source_url, verified, active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?)');
    $agIds = [];
    foreach (seed_agencies() as $a) {
        $a += ['short' => null, 'parent' => null, 'desc' => null, 'address' => null, 'phone' => null, 'email' => null, 'web' => null, 'hours' => null, 'src' => null];
        $ag->execute([$a['slug'], $a['name'], $a['short'], $a['type'], $a['parent'] ? ($agIds[$a['parent']] ?? null) : null, $a['desc'], $a['address'], $a['phone'], $a['email'], $a['web'], $a['hours'], $a['src'], $now, $now]);
        $agIds[$a['slug']] = (int) $pdo->lastInsertId();
    }

    $sv = $pdo->prepare('INSERT INTO services (slug, agency_id, title, summary, description, eligibility, requirements, fees, processing_time, steps, hours, location, contact, online_url, form_url, mode, request_fields, requires_id, keywords, status, source, source_url, agency_verified, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)');
    $link = $pdo->prepare('INSERT IGNORE INTO service_categories (service_id, category_id) VALUES (?, ?)');
    foreach (seed_services() as $s) {
        if (!isset($agIds[$s['agency']])) continue;
        $s += ['summary' => null, 'desc' => null, 'elig' => null, 'req' => [], 'fees' => [], 'time' => null, 'steps' => [], 'hours' => null, 'loc' => null, 'contact' => null,
            'online' => null, 'form' => null, 'mode' => 'info', 'fields' => null, 'id' => 0, 'kw' => null, 'src' => null, 'cats' => []];
        $sv->execute([
            slugify($s['title'], 110), $agIds[$s['agency']], $s['title'], $s['summary'], $s['desc'], $s['elig'],
            json_encode($s['req'], JSON_UNESCAPED_UNICODE), json_encode(array_map(fn($f) => ['label' => $f[0], 'amount' => $f[1]], $s['fees']), JSON_UNESCAPED_UNICODE),
            $s['time'], json_encode($s['steps'], JSON_UNESCAPED_UNICODE), $s['hours'], $s['loc'], $s['contact'], $s['online'], $s['form'], $s['mode'],
            $s['fields'] !== null ? json_encode($s['fields'], JSON_UNESCAPED_UNICODE) : null, $s['id'], $s['kw'], 'published', 'seed', $s['src'], $now, $now,
        ]);
        $sid = (int) $pdo->lastInsertId();
        foreach ($s['cats'] as $c) if (isset($catIds[$c])) $link->execute([$sid, $catIds[$c]]);
    }
}
