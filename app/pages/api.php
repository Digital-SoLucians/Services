<?php
// Agency API: lets an agency's own systems send service updates (the "api" intake channel) and read their listings.
// Auth is a per-agency key (Console › API keys) sent as "Authorization: Bearer slk_…".

const API_HOURLY_LIMIT = 60;

function api_error(string $code, string $message, int $status): void
{
    json_out(['ok' => false, 'error' => $code, 'message' => $message], $status);
}

function api_key_from_request(): ?array
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $hdr = $v;
    }
    if (!preg_match('/^Bearer\s+(slk_[a-f0-9]{40})$/i', trim($hdr), $m)) return null;
    $key = one('SELECT k.*, a.name AS agency_name, a.slug AS agency_slug FROM api_keys k JOIN agencies a ON a.id = k.agency_id
        WHERE k.token_hash = ? AND k.revoked_at IS NULL AND a.active = 1', [hash('sha256', strtolower($m[1]))]);
    if ($key) q('UPDATE api_keys SET last_used_at = ? WHERE id = ?', [now_utc(), $key['id']]);
    return $key;
}

/** $sub is the path after "api/v1", e.g. "", "/services", "/intake/12". */
function api_dispatch(string $sub): void
{
    header('X-Content-Type-Options: nosniff');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $key = api_key_from_request();
    if (!$key) api_error('unauthorized', 'Send a valid agency API key as "Authorization: Bearer slk_…".', 401);
    $sub = rtrim($sub, '/');

    if ($sub === '' && $method === 'GET') {
        json_out(['ok' => true, 'app' => app_name(), 'version' => APP_VERSION, 'agency' => ['name' => $key['agency_name'], 'slug' => $key['agency_slug']],
            'key' => $key['name'], 'publishes_without_review' => (bool) $key['auto_publish'],
            'endpoints' => ['GET /api/v1/services', 'POST /api/v1/intake', 'GET /api/v1/intake/{id}']]);
    }
    if ($sub === '/services' && $method === 'GET') {
        $out = [];
        foreach (list_services(['s.agency_id = ?'], [(int) $key['agency_id']]) as $s) {
            $out[] = ['id' => (int) $s['id'], 'slug' => $s['slug'], 'url' => absolute_url('services/' . $s['slug']), 'version' => (int) $s['version'],
                'agency_verified' => (bool) $s['agency_verified'], 'last_verified_at' => $s['last_verified_at']] + service_snapshot($s);
        }
        json_out(['ok' => true, 'count' => count($out), 'services' => $out]);
    }
    if (preg_match('#^/intake/(\d+)$#', $sub, $m) && $method === 'GET') {
        $item = one('SELECT * FROM intake_items WHERE id = ? AND agency_id = ?', [(int) $m[1], (int) $key['agency_id']]);
        if (!$item) api_error('not_found', 'No intake item with that id for your agency.', 404);
        json_out(['ok' => true] + api_item($item));
    }
    if ($sub === '/intake' && $method === 'POST') api_intake($key);
    if (in_array($sub, ['', '/services', '/intake'], true) || preg_match('#^/intake/\d+$#', $sub)) {
        header('Allow: ' . ($sub === '/intake' ? 'POST' : 'GET'));
        api_error('method_not_allowed', 'Method not allowed.', 405);
    }
    api_error('not_found', 'Unknown endpoint.', 404);
}

function api_item(array $item): array
{
    $drafts = all('SELECT id, service_id, status, confidence, note, data FROM intake_drafts WHERE intake_id = ? ORDER BY id', [$item['id']]);
    return ['intake_id' => (int) $item['id'], 'status' => $item['status'], 'method' => $item['method'], 'error' => $item['error'],
        'drafts' => array_map(function ($d) {
            $data = json_decode((string) $d['data'], true) ?: [];
            $slug = $d['service_id'] ? val('SELECT slug FROM services WHERE id = ?', [$d['service_id']]) : null;
            return ['id' => (int) $d['id'], 'title' => $data['title'] ?? null, 'status' => $d['status'], 'confidence' => (int) $d['confidence'],
                'service' => $slug ? absolute_url('services/' . $slug) : null, 'note' => $d['note']];
        }, $drafts)];
}

function api_intake(array $key): void
{
    $recent = (int) val('SELECT COUNT(*) FROM intake_items WHERE api_key_id = ? AND created_at > ?', [$key['id'], gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ($recent >= API_HOURLY_LIMIT) {
        header('Retry-After: 600');
        api_error('rate_limited', 'This key has sent ' . API_HOURLY_LIMIT . ' updates in the last hour. Try again later.', 429);
    }
    $agencyId = (int) $key['agency_id'];
    $extra = ['channel' => 'api', 'api_key_id' => (int) $key['id']];
    $type = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $id = null;

    if (str_starts_with($type, 'multipart/form-data')) {
        if (empty($_FILES['file'])) api_error('invalid', 'Send the document in a form field named "file".', 422);
        [$id, $err] = intake_from_upload($_FILES['file'], $agencyId, null, (string) ($_POST['title'] ?? ''), $extra);
        if ($err) api_error('invalid', $err, 422);
    } else {
        $raw = file_get_contents('php://input', false, null, 0, 5242881);
        if (strlen((string) $raw) > 5242880) api_error('too_large', 'JSON bodies are limited to 5 MB. Upload large documents as a file.', 413);
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) api_error('invalid', 'Send JSON: {"services": [...]}, {"text": "..."} or {"url": "https://..."}.', 422);
        $title = mb_substr(trim((string) ($body['title'] ?? '')), 0, 255);
        if (isset($body['services'])) {
            $list = is_array($body['services']) ? array_values(array_filter($body['services'], 'is_array')) : [];
            if (!$list) api_error('invalid', '"services" must be a non-empty list of service objects.', 422);
            if (count($list) > 200) api_error('invalid', 'Send up to 200 services per request.', 422);
            foreach ($list as $i => $s) {
                if (trim((string) ($s['title'] ?? $s['name'] ?? $s['service'] ?? '')) === '') api_error('invalid', 'services[' . $i . '] has no title.', 422);
            }
            $id = intake_create($extra + ['agency_id' => $agencyId, 'format' => 'json', 'title' => $title ?: plural(count($list), 'service', 'services') . ' via API',
                'payload' => json_encode($list, JSON_UNESCAPED_UNICODE)]);
        } elseif (isset($body['text'])) {
            $text = mb_substr((string) $body['text'], 0, 200000);
            if (mb_strlen(trim($text)) < 20) api_error('invalid', '"text" is too short to describe a service.', 422);
            $id = intake_create($extra + ['agency_id' => $agencyId, 'format' => 'txt', 'title' => $title ?: 'Text via API', 'raw_text' => $text]);
        } elseif (isset($body['url'])) {
            [$id, $err] = intake_from_url((string) $body['url'], $agencyId, null, $extra);
            if ($err) api_error('invalid', $err, 422);
        } else api_error('invalid', 'Send "services", "text" or "url".', 422);
    }
    audit(null, 'intake.api', 'intake', $id, $key['name']);

    // Same rule as the console: quick reads happen now, AI document reading runs after the response
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$id]);
    $quick = $item['payload'] || in_array($item['format'], ['csv', 'xlsx', 'json'], true) || !ai_ready();
    if ($quick) intake_process($id);
    else intake_queue($id);
    $item = one('SELECT * FROM intake_items WHERE id = ?', [$id]);
    json_out(['ok' => $item['status'] !== 'failed', 'status_url' => absolute_url('api/v1/intake/' . $id)] + api_item($item), $quick ? ($item['status'] === 'failed' ? 422 : 201) : 202);
}
