<?php
/**
 * Plugin Name: Deploy Bridge
 * Description: REST API receiver for remote file pushes, DB operations, and site management.
 *              Install on staging/production sites. Authenticated via API key + HMAC signature.
 * Version: 2.4.0
 * Author: HypedUp Studios
 *
 * Admin dashboard: Tools > Deploy Bridge
 * REST namespace: deploy-bridge/v1
 */

defined('ABSPATH') || exit;

// =========================================================================
// CONSTANTS
// =========================================================================

define('DEPLOY_BRIDGE_VERSION', '2.4.0');
define('DEPLOY_BRIDGE_NS', 'deploy-bridge/v1');
define('DEPLOY_BRIDGE_LOG_TABLE', 'deploy_bridge_log');
define('DEPLOY_BRIDGE_BACKUP_DIR', WP_CONTENT_DIR . '/deploy-backups');
define('DEPLOY_BRIDGE_MAX_ZIP_SIZE', 50 * 1024 * 1024); // 50 MB
define('DEPLOY_BRIDGE_RATE_LIMIT', 10); // requests per minute

// =========================================================================
// 1. ACTIVATION — Create log table
// =========================================================================

add_action('init', 'dbr_maybe_create_table');

function dbr_maybe_create_table() {
    if (get_option('dbr_db_version') === DEPLOY_BRIDGE_VERSION) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . DEPLOY_BRIDGE_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        action_type VARCHAR(20) NOT NULL,
        source_ip VARCHAR(45) NOT NULL,
        summary TEXT,
        files_affected INT DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'success',
        response_time_ms INT DEFAULT 0,
        request_size INT DEFAULT 0,
        INDEX idx_timestamp (timestamp),
        INDEX idx_action (action_type),
        INDEX idx_status (status)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Auto-generate keys on first install if not set
    if (!get_option('dbr_api_key')) {
        update_option('dbr_api_key', wp_generate_password(32, false));
    }
    if (!get_option('dbr_api_secret')) {
        update_option('dbr_api_secret', wp_generate_password(64, false));
    }
    if (get_option('dbr_enabled') === false) {
        update_option('dbr_enabled', '1');
    }
    if (get_option('dbr_allowed_ops') === false) {
        update_option('dbr_allowed_ops', ['push', 'db', 'eval', 'status']);
    }

    update_option('dbr_db_version', DEPLOY_BRIDGE_VERSION);
}

// =========================================================================
// 2. SETTINGS HELPERS
// =========================================================================

function dbr_get_key() {
    // Options first, wp-config.php fallback
    $key = get_option('dbr_api_key', '');
    if (empty($key) && defined('DEPLOY_BRIDGE_KEY')) {
        $key = DEPLOY_BRIDGE_KEY;
    }
    return $key;
}

function dbr_get_secret() {
    $secret = get_option('dbr_api_secret', '');
    if (empty($secret) && defined('DEPLOY_BRIDGE_SECRET')) {
        $secret = DEPLOY_BRIDGE_SECRET;
    }
    return $secret;
}

// --- Rotation dual-accept (v2.2.0) --------------------------------------
// During a rotation grace window the receiver accepts BOTH the new (current)
// and the previous credentials, so a dropped response / partial write / 429
// mid-rotation never strands the sender: the OLD pair keeps working until the
// sender has verified the NEW pair and (optionally) dropped the previous.
function dbr_get_prev_key() {
    return (string) get_option('dbr_api_key_prev', '');
}

function dbr_get_prev_secret() {
    return (string) get_option('dbr_api_secret_prev', '');
}

// True only while a previous-credential grace window is set and unexpired.
function dbr_creds_prev_valid() {
    $expiry = (int) get_option('dbr_creds_prev_expiry', 0);
    return $expiry > time();
}

// Constant-time key match against current, plus previous during the grace window.
function dbr_key_matches($provided) {
    $provided = (string) $provided;
    if ($provided === '') {
        return false;
    }
    if (hash_equals(dbr_get_key(), $provided)) {
        return true;
    }
    if (dbr_creds_prev_valid()) {
        $prev = dbr_get_prev_key();
        if ($prev !== '' && hash_equals($prev, $provided)) {
            return true;
        }
    }
    return false;
}

// Constant-time HMAC match against current secret, plus previous during grace.
function dbr_signature_matches($body, $provided_sig) {
    $provided_sig = (string) $provided_sig;
    if ($provided_sig === '') {
        return false;
    }
    if (hash_equals(hash_hmac('sha256', $body, dbr_get_secret()), $provided_sig)) {
        return true;
    }
    if (dbr_creds_prev_valid()) {
        $prev = dbr_get_prev_secret();
        if ($prev !== '' && hash_equals(hash_hmac('sha256', $body, $prev), $provided_sig)) {
            return true;
        }
    }
    return false;
}

function dbr_is_enabled() {
    return get_option('dbr_enabled', '1') === '1';
}

function dbr_is_op_allowed($op) {
    $allowed = get_option('dbr_allowed_ops', ['push', 'db', 'eval', 'status']);
    return in_array($op, (array) $allowed, true);
}

function dbr_get_allowed_ips() {
    $ips = get_option('dbr_allowed_ips', '');
    if (empty($ips)) {
        return []; // empty = allow all
    }
    return array_filter(array_map('trim', explode(',', $ips)));
}

function dbr_get_blocked_ips() {
    $ips = get_option('dbr_blocked_ips', '');
    if (empty($ips)) {
        return [];
    }
    return array_filter(array_map('trim', explode(',', $ips)));
}

function dbr_get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            return $ip;
        }
    }
    return '0.0.0.0';
}

// =========================================================================
// 3. AUTHENTICATION
// =========================================================================

function dbr_authenticate($request) {
    // Master switch
    if (!dbr_is_enabled()) {
        return new WP_Error('disabled', 'Deploy Bridge is disabled.', ['status' => 503]);
    }

    $client_ip = dbr_get_client_ip();

    // IP blocklist
    $blocked = dbr_get_blocked_ips();
    if (!empty($blocked) && in_array($client_ip, $blocked, true)) {
        return new WP_Error('ip_blocked', 'IP address is blocked.', ['status' => 403]);
    }

    // IP allowlist
    $allowed = dbr_get_allowed_ips();
    if (!empty($allowed) && !in_array($client_ip, $allowed, true)) {
        return new WP_Error('ip_denied', 'IP address not in allowlist.', ['status' => 403]);
    }

    // Rate limiting
    $transient_key = 'dbr_rate_' . md5($client_ip);
    $count = (int) get_transient($transient_key);
    if ($count >= DEPLOY_BRIDGE_RATE_LIMIT) {
        return new WP_Error('rate_limited', 'Rate limit exceeded. Try again in 60 seconds.', ['status' => 429]);
    }
    set_transient($transient_key, $count + 1, 60);

    // API key check (accepts current key, plus previous during a rotation grace window)
    $provided_key = $request->get_header('X-Deploy-Key');
    if (!dbr_key_matches($provided_key)) {
        return new WP_Error('unauthorized', 'Invalid API key.', ['status' => 401]);
    }
    // Track whether the KEY matched the current credential (vs the grace previous).
    $key_is_current = hash_equals(dbr_get_key(), (string) $provided_key);

    // HMAC signature check (skip for GET requests)
    $sig_is_current = true; // GET carries no signature
    if ($request->get_method() !== 'GET') {
        $provided_sig = $request->get_header('X-Deploy-Signature');
        if (empty($provided_sig)) {
            return new WP_Error('unauthorized', 'Missing request signature.', ['status' => 401]);
        }

        // Determine the exact bytes the sender signed.
        // For multipart file uploads (/push), PHP consumes php://input into
        // $_FILES, so $request->get_body() is EMPTY — and the sender (Bridge
        // Engine / deploy.sh) signs the raw ZIP bytes, not the multipart body.
        // So for file uploads we must hash the uploaded file's contents.
        // JSON requests (/db, /eval, /sync-import) keep hashing the raw body.
        $upload_files = $request->get_file_params();
        if ( ! empty( $upload_files['file']['tmp_name'] ) && is_readable( $upload_files['file']['tmp_name'] ) ) {
            $body = file_get_contents( $upload_files['file']['tmp_name'] );
        } else {
            $body = $request->get_body();
        }
        // Accepts a signature from the current secret, plus the previous secret
        // during a rotation grace window (constant-time throughout).
        if (!dbr_signature_matches($body, $provided_sig)) {
            return new WP_Error('unauthorized', 'Invalid request signature.', ['status' => 401]);
        }
        $sig_is_current = hash_equals(hash_hmac('sha256', $body, dbr_get_secret()), (string) $provided_sig);
    }

    // Whether this request was authenticated wholly with the CURRENT credential
    // (not the grace "previous"). The sensitive rotate/drop ops require this so a
    // leaked/shared OLD credential cannot re-rotate or end the grace mid-window.
    $GLOBALS['dbr_authed_via_current'] = ($key_is_current && $sig_is_current);

    return true;
}

// =========================================================================
// 4. LOGGING
// =========================================================================

function dbr_log($action_type, $summary, $status = 'success', $files = 0, $response_ms = 0, $request_size = 0) {
    global $wpdb;
    $table = $wpdb->prefix . DEPLOY_BRIDGE_LOG_TABLE;

    $wpdb->insert($table, [
        'action_type'     => sanitize_key($action_type),
        'source_ip'       => dbr_get_client_ip(),
        'summary'         => sanitize_textarea_field(substr($summary, 0, 500)),
        'status'          => $status === 'success' ? 'success' : 'error',
        'files_affected'  => absint($files),
        'response_time_ms' => absint($response_ms),
        'request_size'    => absint($request_size),
    ]);
}

// =========================================================================
// 5. REST API ROUTES
// =========================================================================

add_action('rest_api_init', function () {

    // --- GET /status ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/status', [
        'methods'             => 'GET',
        'callback'            => 'dbr_endpoint_status',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /push ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/push', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_push',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /db ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/db', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_db',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /eval ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/eval', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_eval',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /pull ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/pull', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_pull',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /sync-export — return content+appearance payload ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/sync-export', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_sync_export',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /sync-import — apply content+appearance payload ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/sync-import', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_sync_import',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- POST /sync-restore — roll back the last sync-import ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/sync-restore', [
        'methods'             => 'POST',
        'callback'            => 'dbr_endpoint_sync_restore',
        'permission_callback' => 'dbr_authenticate',
    ]);

    // --- GET /uploads-manifest — file list with sha1 for diff sync ---
    register_rest_route(DEPLOY_BRIDGE_NS, '/uploads-manifest', [
        'methods'             => 'GET',
        'callback'            => 'dbr_endpoint_uploads_manifest',
        'permission_callback' => 'dbr_authenticate',
    ]);
});

// =========================================================================
// 5a. STATUS ENDPOINT
// =========================================================================

function dbr_endpoint_status($request) {
    if (!dbr_is_op_allowed('status')) {
        return new WP_Error('op_disabled', 'Status endpoint is disabled.', ['status' => 403]);
    }

    $start = microtime(true);
    $theme = wp_get_theme();

    $last_log = null;
    global $wpdb;
    $table = $wpdb->prefix . DEPLOY_BRIDGE_LOG_TABLE;
    $last_row = $wpdb->get_row("SELECT timestamp FROM {$table} WHERE status='success' ORDER BY id DESC LIMIT 1");

    $response = [
        'site_url'       => site_url(),
        'site_name'      => get_bloginfo('name'),
        'wp_version'     => get_bloginfo('version'),
        'php_version'    => PHP_VERSION,
        'active_theme'   => $theme->get_stylesheet(),
        'theme_name'     => $theme->get('Name'),
        'plugin_version' => DEPLOY_BRIDGE_VERSION,
        'disk_free'      => size_format(@disk_free_space(ABSPATH), 1),
        'last_deploy'    => $last_row ? $last_row->timestamp : null,
        'server_time'    => current_time('mysql'),
        'timezone'       => wp_timezone_string(),
    ];

    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('status', 'Status check', 'success', 0, $ms, 0);

    return rest_ensure_response($response);
}

// =========================================================================
// 5b. PUSH ENDPOINT — Upload files to wp-content/
// =========================================================================

function dbr_endpoint_push($request) {
    if (!dbr_is_op_allowed('push')) {
        return new WP_Error('op_disabled', 'File push is disabled.', ['status' => 403]);
    }

    $start = microtime(true);
    $files = $request->get_file_params();

    if (empty($files['file'])) {
        return new WP_Error('no_file', 'No file uploaded. Send as multipart with field name "file".', ['status' => 400]);
    }

    $upload = $files['file'];

    // Validate size
    if ($upload['size'] > DEPLOY_BRIDGE_MAX_ZIP_SIZE) {
        return new WP_Error('too_large', 'File exceeds ' . size_format(DEPLOY_BRIDGE_MAX_ZIP_SIZE) . ' limit.', ['status' => 413]);
    }

    // Validate it's a zip
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $upload['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
        return new WP_Error('invalid_type', 'Only zip files are accepted. Got: ' . $mime, ['status' => 400]);
    }

    // Extract
    $zip = new ZipArchive();
    if ($zip->open($upload['tmp_name']) !== true) {
        return new WP_Error('zip_error', 'Could not open zip file.', ['status' => 400]);
    }

    // Security: validate all paths are within wp-content
    $manifest = [];
    $rejected = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        // Block path traversal
        if (strpos($entry, '..') !== false || strpos($entry, '//') !== false || $entry[0] === '/') {
            $rejected[] = $entry;
            continue;
        }
        // Block dangerous file types
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, ['phar', 'sh', 'bash', 'py', 'exe', 'bat', 'cmd'])) {
            $rejected[] = $entry;
            continue;
        }
        $manifest[] = $entry;
    }

    if (!empty($rejected)) {
        $zip->close();
        return new WP_Error('path_rejected', 'Rejected files: ' . implode(', ', $rejected), ['status' => 400]);
    }

    // Backup existing files
    $backup_dir = DEPLOY_BRIDGE_BACKUP_DIR . '/' . date('Y-m-d_H-i-s');
    foreach ($manifest as $entry) {
        // Skip directories
        if (substr($entry, -1) === '/') continue;

        $target = WP_CONTENT_DIR . '/' . $entry;
        if (file_exists($target)) {
            $backup_path = $backup_dir . '/' . $entry;
            $backup_parent = dirname($backup_path);
            if (!is_dir($backup_parent)) {
                wp_mkdir_p($backup_parent);
            }
            @copy($target, $backup_path);
        }
    }

    // Extract to wp-content
    $zip->extractTo(WP_CONTENT_DIR);
    $zip->close();

    // Clean up
    @unlink($upload['tmp_name']);

    $file_count = count(array_filter($manifest, function ($e) {
        return substr($e, -1) !== '/';
    }));

    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('push', "Pushed {$file_count} files", 'success', $file_count, $ms, $upload['size']);

    return rest_ensure_response([
        'success'     => true,
        'files'       => $file_count,
        'manifest'    => $manifest,
        'backup_dir'  => str_replace(ABSPATH, '', $backup_dir),
        'response_ms' => $ms,
    ]);
}

// =========================================================================
// 5b2. PULL ENDPOINT — Download files from wp-content/ as zip
// =========================================================================

function dbr_endpoint_pull($request) {
    if (!dbr_is_op_allowed('push')) { // reuse push permission for pull
        return new WP_Error('op_disabled', 'File operations are disabled.', ['status' => 403]);
    }

    $start = microtime(true);
    $body = $request->get_json_params();
    $paths = $body['paths'] ?? [];
    $type = $body['type'] ?? 'files'; // 'files', 'dir', 'db_export', 'partials'

    // --- Pull specific files or directory ---
    if ($type === 'files' || $type === 'dir') {
        if (empty($paths) && $type === 'files') {
            return new WP_Error('no_paths', 'Specify "paths" array of files relative to wp-content/.', ['status' => 400]);
        }

        if ($type === 'dir') {
            $dir = sanitize_text_field($body['dir'] ?? '');
            if (empty($dir)) {
                return new WP_Error('no_dir', 'Specify "dir" relative to wp-content/.', ['status' => 400]);
            }
            // Security: block path traversal
            if (strpos($dir, '..') !== false || $dir[0] === '/') {
                return new WP_Error('invalid_path', 'Path traversal not allowed.', ['status' => 400]);
            }
            $full_dir = WP_CONTENT_DIR . '/' . $dir;
            if (!is_dir($full_dir)) {
                return new WP_Error('not_found', "Directory not found: $dir", ['status' => 404]);
            }
        }

        // Build zip
        $tmpzip = wp_tempnam('deploy-pull-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpzip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_error', 'Could not create zip file.', ['status' => 500]);
        }

        $file_count = 0;

        if ($type === 'dir') {
            // Recursively add directory
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($full_dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $real_path = $file->getRealPath();
                    $relative = $dir . '/' . substr($real_path, strlen($full_dir) + 1);
                    $zip->addFile($real_path, $relative);
                    $file_count++;
                }
            }
        } else {
            // Add specific files
            foreach ($paths as $path) {
                $path = sanitize_text_field($path);
                if (strpos($path, '..') !== false || $path[0] === '/') continue;
                $full = WP_CONTENT_DIR . '/' . $path;
                if (is_file($full)) {
                    $zip->addFile($full, $path);
                    $file_count++;
                }
            }
        }

        $zip->close();

        if ($file_count === 0) {
            @unlink($tmpzip);
            return new WP_Error('empty', 'No files found to pull.', ['status' => 404]);
        }

        $zip_size = filesize($tmpzip);
        $ms = round((microtime(true) - $start) * 1000);
        dbr_log('pull', "Pulled {$file_count} files ({$type})", 'success', $file_count, $ms, 0);

        // Send zip as response
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="deploy-pull-' . date('Ymd-His') . '.zip"');
        header('Content-Length: ' . $zip_size);
        header('X-Deploy-Files: ' . $file_count);
        header('X-Deploy-Response-Ms: ' . $ms);
        readfile($tmpzip);
        @unlink($tmpzip);
        exit;
    }

    // --- Pull all lc_partial posts (partials export) ---
    if ($type === 'partials') {
        $partials = get_posts([
            'post_type'      => 'lc_partial',
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
        ]);

        $data = [];
        foreach ($partials as $p) {
            $meta = get_post_meta($p->ID);
            $clean_meta = [];
            foreach ($meta as $key => $vals) {
                // Only include relevant meta
                if (in_array($key, ['is_header', 'is_footer', 'lc_partial_variant_type', 'lc_partial_variant_name', '_lc_livecanvas_enabled'])) {
                    $clean_meta[$key] = $vals[0];
                }
            }

            $data[] = [
                'ID'           => $p->ID,
                'post_title'   => $p->post_title,
                'post_content' => $p->post_content,
                'post_status'  => $p->post_status,
                'post_name'    => $p->post_name,
                'menu_order'   => $p->menu_order,
                'meta'         => $clean_meta,
            ];
        }

        $ms = round((microtime(true) - $start) * 1000);
        dbr_log('pull', 'Pulled ' . count($data) . ' partials', 'success', count($data), $ms, 0);

        return rest_ensure_response([
            'success'     => true,
            'type'        => 'partials',
            'count'       => count($data),
            'partials'    => $data,
            'response_ms' => $ms,
        ]);
    }

    // --- Pull DB export (options, posts, meta for a post type) ---
    if ($type === 'db_export') {
        $post_type = sanitize_key($body['post_type'] ?? 'page');
        $include_meta = !empty($body['include_meta']);

        $posts = get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft', 'private'],
        ]);

        $data = [];
        foreach ($posts as $p) {
            $entry = [
                'ID'           => $p->ID,
                'post_title'   => $p->post_title,
                'post_content' => $p->post_content,
                'post_status'  => $p->post_status,
                'post_name'    => $p->post_name,
                'post_date'    => $p->post_date,
                'post_type'    => $p->post_type,
                'menu_order'   => $p->menu_order,
            ];

            if ($include_meta) {
                $all_meta = get_post_meta($p->ID);
                $entry['meta'] = [];
                foreach ($all_meta as $key => $vals) {
                    // Skip internal WP meta
                    if ($key[0] === '_' && !in_array($key, ['_wp_page_template', '_lc_livecanvas_enabled', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_elementor_data'])) {
                        continue;
                    }
                    $entry['meta'][$key] = $vals[0];
                }
            }

            $data[] = $entry;
        }

        // Also grab relevant options
        $options = [];
        $option_keys = $body['options'] ?? [];
        foreach ($option_keys as $key) {
            $options[$key] = get_option(sanitize_key($key));
        }

        $ms = round((microtime(true) - $start) * 1000);
        dbr_log('pull', "Exported {$post_type}: " . count($data) . " posts", 'success', count($data), $ms, 0);

        return rest_ensure_response([
            'success'     => true,
            'type'        => 'db_export',
            'post_type'   => $post_type,
            'count'       => count($data),
            'posts'       => $data,
            'options'     => $options,
            'response_ms' => $ms,
        ]);
    }

    return new WP_Error('invalid_type', 'Unknown pull type. Use: files, dir, partials, db_export', ['status' => 400]);
}

// =========================================================================
// 5c. DB ENDPOINT — Structured database operations
// =========================================================================

function dbr_endpoint_db($request) {
    if (!dbr_is_op_allowed('db')) {
        return new WP_Error('op_disabled', 'Database operations are disabled.', ['status' => 403]);
    }

    $start = microtime(true);
    $body = $request->get_json_params();
    $operations = isset($body['operations']) ? $body['operations'] : [];

    if (empty($operations) || !is_array($operations)) {
        return new WP_Error('no_ops', 'No operations provided. Send {"operations": [...]}', ['status' => 400]);
    }

    $allowed_actions = [
        'get_option', 'update_option', 'delete_option',
        'get_post', 'update_post', 'insert_post', 'delete_post',
        'get_post_meta', 'update_post_meta', 'delete_post_meta',
        'query', 'flush_cache', 'flush_rewrite_rules',
        // Credential rotation (v2.2.0) — see dbr_execute_db_op.
        'rotate_credentials', 'credentials_fingerprint', 'drop_previous_credentials',
    ];

    $results = [];

    foreach ($operations as $i => $op) {
        $action = sanitize_key($op['action'] ?? '');

        if (!in_array($action, $allowed_actions, true)) {
            $results[] = ['index' => $i, 'action' => $action, 'status' => 'error', 'message' => 'Unknown action'];
            continue;
        }

        try {
            $result = dbr_execute_db_op($action, $op);
            $results[] = ['index' => $i, 'action' => $action, 'status' => 'success', 'result' => $result];
        } catch (Exception $e) {
            $results[] = ['index' => $i, 'action' => $action, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    $success_count = count(array_filter($results, function ($r) { return $r['status'] === 'success'; }));
    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('db', "{$success_count}/" . count($operations) . " operations succeeded", 'success', $success_count, $ms, strlen($request->get_body()));

    return rest_ensure_response([
        'success'     => true,
        'operations'  => count($operations),
        'succeeded'   => $success_count,
        'results'     => $results,
        'response_ms' => $ms,
    ]);
}

function dbr_execute_db_op($action, $op) {
    global $wpdb;

    switch ($action) {
        case 'get_option':
            return get_option($op['key'] ?? '', $op['default'] ?? null);

        case 'update_option':
            return update_option($op['key'] ?? '', $op['value'] ?? '');

        case 'delete_option':
            return delete_option($op['key'] ?? '');

        // --- Credential rotation (v2.2.0) -----------------------------------
        // Atomically swap the API key + secret in ONE op (never two separate
        // update_option round-trips, which could half-apply and strand the
        // sender). The just-superseded pair is stashed as the "previous"
        // credential with a grace expiry so it keeps authenticating until the
        // sender confirms the new pair works. Ordering matters: stash previous
        // FIRST, then promote new, so a crash mid-op always leaves the OLD
        // pair valid (previous == old, and current secret is only overwritten
        // last).
        case 'rotate_credentials': {
            if (empty($GLOBALS['dbr_authed_via_current'])) {
                throw new Exception('rotate_credentials must be authenticated with the CURRENT credential (the grace/previous credential is not permitted)');
            }
            $new_key    = isset($op['new_key'])    ? sanitize_text_field($op['new_key'])    : '';
            $new_secret = isset($op['new_secret']) ? sanitize_text_field($op['new_secret']) : '';
            if (strlen($new_key) < 16 || strlen($new_secret) < 32) {
                throw new Exception('rotate_credentials requires new_key (>=16 chars) and new_secret (>=32 chars)');
            }
            $grace = isset($op['grace_seconds']) ? (int) $op['grace_seconds'] : 600;
            $grace = max(0, min(3600, $grace)); // clamp 0..1h

            $cur_key    = (string) get_option('dbr_api_key', '');
            $cur_secret = (string) get_option('dbr_api_secret', '');

            // 1) stash current as previous (dual-accept) with expiry
            update_option('dbr_api_key_prev', $cur_key);
            update_option('dbr_api_secret_prev', $cur_secret);
            update_option('dbr_creds_prev_expiry', time() + $grace);
            // 2) promote new (key then secret — old pair still authenticates until secret lands)
            update_option('dbr_api_key', $new_key);
            update_option('dbr_api_secret', $new_secret);

            return [
                'rotated'     => true,
                'key_fp'      => substr(hash('sha256', $new_key), 0, 12),
                'secret_fp'   => substr(hash('sha256', $new_secret), 0, 12),
                'grace_until' => time() + $grace,
            ];
        }

        // Return non-reversible fingerprints of the CURRENT stored credentials
        // (never the secret itself). Called as a signed op with the NEW creds it
        // both proves the new secret authenticates AND lets the sender confirm
        // the receiver stored exactly what was sent (catches encoding mangling).
        case 'credentials_fingerprint':
            return [
                'key_fp'     => substr(hash('sha256', (string) get_option('dbr_api_key', '')), 0, 12),
                'secret_fp'  => substr(hash('sha256', (string) get_option('dbr_api_secret', '')), 0, 12),
                'prev_valid' => dbr_creds_prev_valid(),
                'version'    => DEPLOY_BRIDGE_VERSION,
            ];

        // End the grace window early once the sender has verified the new pair.
        case 'drop_previous_credentials':
            if (empty($GLOBALS['dbr_authed_via_current'])) {
                throw new Exception('drop_previous_credentials must be authenticated with the CURRENT credential');
            }
            delete_option('dbr_api_key_prev');
            delete_option('dbr_api_secret_prev');
            delete_option('dbr_creds_prev_expiry');
            return ['dropped' => true];

        case 'get_post':
            $post = get_post(absint($op['post_id'] ?? 0));
            return $post ? ['ID' => $post->ID, 'post_title' => $post->post_title, 'post_status' => $post->post_status] : null;

        case 'update_post':
            $data = $op['data'] ?? [];
            $data['ID'] = absint($op['post_id'] ?? ($data['ID'] ?? 0));
            if (!$data['ID']) throw new Exception('Missing post_id');
            // Sanitize post_content to prevent wp_slash issues
            if (isset($data['post_content'])) {
                $data['post_content'] = wp_slash($data['post_content']);
            }
            $result = wp_update_post($data, true);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            return $result;

        case 'insert_post':
            $data = $op['data'] ?? [];
            if (isset($data['post_content'])) {
                $data['post_content'] = wp_slash($data['post_content']);
            }
            $result = wp_insert_post($data, true);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            return $result;

        case 'delete_post':
            $force = !empty($op['force']);
            return wp_delete_post(absint($op['post_id'] ?? 0), $force) ? true : false;

        case 'get_post_meta':
            return get_post_meta(absint($op['post_id'] ?? 0), $op['key'] ?? '', true);

        case 'update_post_meta':
            return update_post_meta(absint($op['post_id'] ?? 0), $op['key'] ?? '', $op['value'] ?? '');

        case 'delete_post_meta':
            return delete_post_meta(absint($op['post_id'] ?? 0), $op['key'] ?? '');

        case 'query':
            $sql = $op['sql'] ?? '';
            // ONLY allow SELECT queries
            if (!preg_match('/^\s*SELECT\s/i', $sql)) {
                throw new Exception('Only SELECT queries are allowed via the query action.');
            }
            // Block dangerous keywords
            if (preg_match('/\b(DROP|DELETE|UPDATE|INSERT|ALTER|TRUNCATE|REPLACE|GRANT|REVOKE)\b/i', $sql)) {
                throw new Exception('Destructive SQL keywords are not allowed.');
            }
            return $wpdb->get_results($sql, ARRAY_A);

        case 'flush_cache':
            wp_cache_flush();
            return true;

        case 'flush_rewrite_rules':
            flush_rewrite_rules();
            return true;

        default:
            throw new Exception("Unhandled action: {$action}");
    }
}

// =========================================================================
// 5d. EVAL ENDPOINT — Predefined safe actions
// =========================================================================

function dbr_endpoint_eval($request) {
    if (!dbr_is_op_allowed('eval')) {
        return new WP_Error('op_disabled', 'Eval actions are disabled.', ['status' => 403]);
    }

    $start = microtime(true);
    $body = $request->get_json_params();
    $action = sanitize_key($body['action'] ?? '');
    $params = $body['params'] ?? [];

    $allowed_actions = [
        'activate_theme', 'activate_plugin', 'deactivate_plugin', 'delete_plugin',
        'flush_cache', 'flush_rewrite_rules', 'search_replace', 'search_replace_all',
        'import_partials', 'clear_lc_cache', 'list_themes', 'list_plugins',
        'get_partials', 'publish_drafts',
    ];

    if (!in_array($action, $allowed_actions, true)) {
        return new WP_Error('invalid_action', 'Unknown eval action: ' . $action, ['status' => 400]);
    }

    try {
        $result = dbr_execute_eval($action, $params);
        $ms = round((microtime(true) - $start) * 1000);
        dbr_log('eval', "Action: {$action}", 'success', 0, $ms, strlen($request->get_body()));

        return rest_ensure_response([
            'success'     => true,
            'action'      => $action,
            'result'      => $result,
            'response_ms' => $ms,
        ]);
    } catch (Exception $e) {
        $ms = round((microtime(true) - $start) * 1000);
        dbr_log('eval', "Action: {$action} — FAILED: " . $e->getMessage(), 'error', 0, $ms, strlen($request->get_body()));

        return new WP_Error('eval_failed', $e->getMessage(), ['status' => 500]);
    }
}

/**
 * Delete an INACTIVE plugin (files + its uninstall routine), the way Plugins > Delete does.
 * Refuses active plugins and anything that would need FTP credentials (the credentials form
 * would print HTML into the REST response), so it can never take a running feature away.
 */
function dbr_delete_plugin($plugin) {
    if (!$plugin || strpos($plugin, '..') !== false || substr($plugin, -4) !== '.php') {
        throw new Exception('Missing or invalid plugin parameter (expected folder/file.php)');
    }
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!array_key_exists($plugin, get_plugins())) {
        return ['deleted' => false, 'plugin' => $plugin, 'reason' => 'not installed'];
    }
    if (is_plugin_active($plugin) || is_plugin_active_for_network($plugin)) {
        throw new Exception("Refusing to delete an active plugin: $plugin (deactivate it first)");
    }
    if (get_filesystem_method() !== 'direct') {
        throw new Exception('Filesystem is not writable directly; delete it from wp-admin instead');
    }
    $result = delete_plugins([$plugin]);
    if (is_wp_error($result)) throw new Exception($result->get_error_message());
    if ($result !== true) throw new Exception('delete_plugins() did not complete');
    wp_clean_plugins_cache(false);
    return ['deleted' => true, 'plugin' => $plugin];
}

function dbr_execute_eval($action, $params) {
    switch ($action) {
        case 'activate_theme':
            $theme = sanitize_text_field($params['theme'] ?? '');
            if (!$theme) throw new Exception('Missing theme parameter');
            switch_theme($theme);
            return ['activated' => $theme];

        case 'activate_plugin':
            $plugin = sanitize_text_field($params['plugin'] ?? '');
            if (!$plugin) throw new Exception('Missing plugin parameter');
            $result = activate_plugin($plugin);
            if (is_wp_error($result)) throw new Exception($result->get_error_message());
            return ['activated' => $plugin];

        case 'deactivate_plugin':
            $plugin = sanitize_text_field($params['plugin'] ?? '');
            if (!$plugin) throw new Exception('Missing plugin parameter');
            deactivate_plugins($plugin);
            return ['deactivated' => $plugin];

        case 'delete_plugin':
            return dbr_delete_plugin(sanitize_text_field($params['plugin'] ?? ''));

        case 'flush_cache':
            wp_cache_flush();
            if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
            return ['flushed' => true];

        case 'flush_rewrite_rules':
            flush_rewrite_rules();
            return ['flushed' => true];

        case 'search_replace':
            $search = $params['search'] ?? '';
            $replace = $params['replace'] ?? '';
            if (empty($search)) throw new Exception('Missing search parameter');
            global $wpdb;
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
                $search, $replace, '%' . $wpdb->esc_like($search) . '%'
            ));
            return ['affected_rows' => $affected, 'search' => $search, 'replace' => $replace];

        case 'search_replace_all':
            // Multi-table literal search/replace — needed for un-tokenized templates (reverse)
            // WARNING: raw SQL REPLACE is NOT serialization-safe. A replacement that changes the
            // string length corrupts PHP-serialized postmeta/option values (Forminator forms,
            // brand_engine_data, widgets). Only use it for equal-length or unserialized targets.
            // where identity is hardcoded across post_content, titles, postmeta, options, and Yoast.
            $search = $params['search'] ?? '';
            $replace = $params['replace'] ?? '';
            if (empty($search)) throw new Exception('Missing search parameter');
            global $wpdb;
            $like = '%' . $wpdb->esc_like($search) . '%';
            $total = 0;
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", $search, $replace, $like));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_title = REPLACE(post_title, %s, %s) WHERE post_title LIKE %s", $search, $replace, $like));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_excerpt = REPLACE(post_excerpt, %s, %s) WHERE post_excerpt LIKE %s", $search, $replace, $like));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s", $search, $replace, $like));
            $total += (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s", $search, $replace, $like));
            $yoast_tbl = $wpdb->prefix . 'yoast_indexable';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $yoast_tbl)) === $yoast_tbl) {
                foreach (['title','description','breadcrumb_title','og_title','og_description','twitter_title','twitter_description'] as $col) {
                    $total += (int) $wpdb->query($wpdb->prepare("UPDATE `{$yoast_tbl}` SET `{$col}` = REPLACE(`{$col}`, %s, %s) WHERE `{$col}` LIKE %s", $search, $replace, $like));
                }
            }
            return ['affected_rows' => $total, 'search' => $search, 'replace' => $replace, 'multi_table' => true];

        case 'clear_lc_cache':
            // Clear LiveCanvas transients and caches
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%livecanvas%' AND option_name LIKE '%transient%'");
            wp_cache_flush();
            return ['cleared' => true];

        case 'list_themes':
            $themes = wp_get_themes();
            $list = [];
            foreach ($themes as $slug => $theme) {
                $list[$slug] = [
                    'name'    => $theme->get('Name'),
                    'version' => $theme->get('Version'),
                    'active'  => ($slug === get_stylesheet()),
                ];
            }
            return $list;

        case 'list_plugins':
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugins = get_plugins();
            $active = get_option('active_plugins', []);
            $list = [];
            foreach ($plugins as $file => $data) {
                $list[$file] = [
                    'name'    => $data['Name'],
                    'version' => $data['Version'],
                    'active'  => in_array($file, $active),
                ];
            }
            return $list;

        case 'get_partials':
            $partials = get_posts([
                'post_type'      => 'lc_partial',
                'posts_per_page' => -1,
                'post_status'    => ['publish', 'draft'],
            ]);
            $list = [];
            foreach ($partials as $p) {
                $list[] = [
                    'ID'          => $p->ID,
                    'title'       => $p->post_title,
                    'status'      => $p->post_status,
                    'is_header'   => get_post_meta($p->ID, 'is_header', true),
                    'is_footer'   => get_post_meta($p->ID, 'is_footer', true),
                    'variant_type' => get_post_meta($p->ID, 'lc_partial_variant_type', true),
                ];
            }
            return $list;

        case 'publish_drafts':
            $type = sanitize_key($params['post_type'] ?? 'lc_partial');
            $drafts = get_posts([
                'post_type'      => $type,
                'post_status'    => 'draft',
                'posts_per_page' => -1,
            ]);
            $published = 0;
            foreach ($drafts as $draft) {
                wp_update_post(['ID' => $draft->ID, 'post_status' => 'publish']);
                $published++;
            }
            return ['published' => $published, 'post_type' => $type];

        default:
            throw new Exception("Unhandled eval action: {$action}");
    }
}

// =========================================================================
// 5e. SYNC ENDPOINTS — full content + appearance, bidirectional
// =========================================================================
//
// Scope: posts, pages, attachments, menus, partials, reusable blocks,
// custom CSS, terms (categories/tags/menu containers), and a whitelisted
// set of "appearance + content config" options (theme_mods_*, widget_*,
// permalinks, page-builder settings).
//
// Locked out (never touched): siteurl, home, blogname, blogdescription,
// admin_email, active_plugins, users/usermeta, salts, transients, cron,
// rate-limit + rewrite rule caches.
//
// The same payload structure is used for export and import — Bridge Engine
// pulls JSON via /sync-export, applies locally; or builds it locally and
// posts to /sync-import. Snapshot is written to wp-content/uploads/bridge-backups/
// before any destructive write.

function dbr_sync_default_post_types() {
    return apply_filters('dbr_sync_post_types', [
        'post', 'page', 'attachment', 'nav_menu_item',
        'lc_partial', 'wp_block', 'custom_css',
    ]);
}

function dbr_sync_option_whitelist_patterns() {
    return apply_filters('dbr_sync_option_patterns', [
        // Active theme + Customizer
        'stylesheet', 'template', 'current_theme',
        'theme_mods_*',
        // Widgets
        'sidebars_widgets', 'widget_*', 'nav_menu_options',
        // Reading + permalinks
        'show_on_front', 'page_on_front', 'page_for_posts',
        'posts_per_page', 'default_category',
        'permalink_structure', 'category_base', 'tag_base',
        // Page builders
        'livecanvas_*', 'lc_*',
        'picostrap_*', 'picostrap5_*',
        'elementor_active_kit', 'elementor_cpt_support',
        'elementor_disable_color_schemes', 'elementor_disable_typography_schemes',
        'elementor_global_*', 'elementor_unique_widgets',
        'elementor_pro_*',
        // WPForms designs (forms themselves are CPT, already covered)
        'wpforms_settings',
    ]);
}

function dbr_sync_option_lockout_patterns() {
    return apply_filters('dbr_sync_option_lockout', [
        // Identity / URL — never touch
        'siteurl', 'home', 'blogname', 'blogdescription', 'admin_email',
        'users_can_register', 'default_role', 'WPLANG',
        // DB schema / install
        'db_version', 'db_upgraded', 'initial_db_version',
        // Salts / auth
        'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt',
        'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt',
        // Plugins / roles
        'active_plugins', 'recently_activated', 'wp_user_roles',
        // Runtime caches
        'cron', 'rewrite_rules',
        '_transient_*', '_site_transient_*',
        '*_session_*', '*_lock', '*_lockdown_*',
        // Elementor remote / install junk
        'elementor_remote_info_*', 'elementor_remote_feed',
        'elementor_install_history', 'elementor_install_time',
        'elementor_log',
    ]);
}

function dbr_sync_match_pattern($name, $patterns) {
    foreach ($patterns as $pat) {
        if (strpos($pat, '*') === false) {
            if ($name === $pat) return true;
        } else {
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pat, '/')) . '$/';
            if (preg_match($regex, $name)) return true;
        }
    }
    return false;
}

function dbr_sync_option_is_allowed($name) {
    if (dbr_sync_match_pattern($name, dbr_sync_option_lockout_patterns())) return false;
    return dbr_sync_match_pattern($name, dbr_sync_option_whitelist_patterns());
}

// Recursive walker for serialized data — handles nested arrays + objects.
function dbr_sync_replace_recursive($data, $from, $to) {
    if (is_string($data)) {
        return str_replace($from, $to, $data);
    }
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            $data[$k] = dbr_sync_replace_recursive($v, $from, $to);
        }
        return $data;
    }
    if (is_object($data)) {
        foreach (get_object_vars($data) as $k => $v) {
            $data->$k = dbr_sync_replace_recursive($v, $from, $to);
        }
        return $data;
    }
    return $data;
}

// Replace inside a value that may be plain string OR PHP-serialized.
function dbr_sync_replace_in_value($value, $from, $to) {
    if (!is_string($value)) {
        return dbr_sync_replace_recursive($value, $from, $to);
    }
    if (strpos($value, $from) === false) return $value;

    // Try unserialize first — only re-serialize if it round-trips cleanly.
    $unser = @unserialize($value);
    if ($unser !== false || $value === 'b:0;') {
        $replaced = dbr_sync_replace_recursive($unser, $from, $to);
        return serialize($replaced);
    }
    return str_replace($from, $to, $value);
}

// Serialized-aware search-replace across posts/postmeta/options/termmeta.
function dbr_sync_run_search_replace($from, $to) {
    if ($from === '' || $from === $to) return ['affected' => 0];
    global $wpdb;
    $affected = 0;

    $tables = [
        ['table' => $wpdb->posts,    'pk' => 'ID',        'cols' => ['post_content', 'post_excerpt', 'post_title', 'guid']],
        ['table' => $wpdb->postmeta, 'pk' => 'meta_id',   'cols' => ['meta_value']],
        ['table' => $wpdb->options,  'pk' => 'option_id', 'cols' => ['option_value']],
        ['table' => $wpdb->termmeta, 'pk' => 'meta_id',   'cols' => ['meta_value']],
    ];

    foreach ($tables as $t) {
        $select_cols = '`' . $t['pk'] . '`, `' . implode('`, `', $t['cols']) . '`';
        $like_clauses = [];
        foreach ($t['cols'] as $c) {
            $like_clauses[] = $wpdb->prepare("`{$c}` LIKE %s", '%' . $wpdb->esc_like($from) . '%');
        }
        $where = implode(' OR ', $like_clauses);
        $rows = $wpdb->get_results("SELECT {$select_cols} FROM {$t['table']} WHERE {$where}", ARRAY_A);

        foreach ($rows as $row) {
            $update = [];
            foreach ($t['cols'] as $c) {
                if ($row[$c] === null) continue;
                $new = dbr_sync_replace_in_value($row[$c], $from, $to);
                if ($new !== $row[$c]) $update[$c] = $new;
            }
            if (!empty($update)) {
                $wpdb->update($t['table'], $update, [$t['pk'] => $row[$t['pk']]]);
                $affected++;
            }
        }
    }
    return ['affected' => $affected];
}

// Snapshot the rows we're about to replace (scoped by post_type for posts/postmeta,
// full table for terms + options). Written as INSERT statements only — for restore
// you'd manually wipe + replay.
function dbr_sync_snapshot($file, $post_types) {
    global $wpdb;
    $fp = @fopen($file, 'w');
    if (!$fp) return false;
    fwrite($fp, "-- Bridge sync snapshot " . current_time('mysql') . "\n");
    fwrite($fp, "-- Post types: " . implode(',', $post_types) . "\n\n");

    $ph = implode(',', array_fill(0, count($post_types), '%s'));
    $post_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$ph})", $post_types
    ));
    $id_in = !empty($post_ids) ? '(' . implode(',', array_map('intval', $post_ids)) . ')' : '(0)';

    $sets = [
        ['name' => $wpdb->posts,              'sql' => $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type IN ({$ph})", $post_types)],
        ['name' => $wpdb->postmeta,           'sql' => "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN {$id_in}"],
        ['name' => $wpdb->terms,              'sql' => "SELECT * FROM {$wpdb->terms}"],
        ['name' => $wpdb->term_taxonomy,      'sql' => "SELECT * FROM {$wpdb->term_taxonomy}"],
        ['name' => $wpdb->term_relationships, 'sql' => "SELECT * FROM {$wpdb->term_relationships}"],
        ['name' => $wpdb->termmeta,           'sql' => "SELECT * FROM {$wpdb->termmeta}"],
        ['name' => $wpdb->options,            'sql' => "SELECT * FROM {$wpdb->options}"],
    ];

    foreach ($sets as $set) {
        $rows = $wpdb->get_results($set['sql'], ARRAY_A);
        fwrite($fp, "-- {$set['name']} (" . count($rows) . " rows)\n");
        foreach ($rows as $row) {
            $cols = array_keys($row);
            $vals = [];
            foreach ($row as $v) {
                $vals[] = ($v === null) ? 'NULL' : "'" . esc_sql($v) . "'";
            }
            fwrite($fp, "INSERT INTO `{$set['name']}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $vals) . ");\n");
        }
        fwrite($fp, "\n");
    }
    fclose($fp);
    return true;
}

// =========================================================================
// SYNC EXPORT
// =========================================================================

function dbr_endpoint_sync_export($request) {
    if (!dbr_is_op_allowed('db')) {
        return new WP_Error('op_disabled', 'Sync export is disabled.', ['status' => 403]);
    }
    $start = microtime(true);
    global $wpdb;

    $body = $request->get_json_params() ?: [];
    $pt_param = $body['post_types'] ?? null;
    $post_types = (is_array($pt_param) && !empty($pt_param))
        ? array_map('sanitize_key', $pt_param)
        : dbr_sync_default_post_types();

    // 1. Posts
    $ph = implode(',', array_fill(0, count($post_types), '%s'));
    $posts = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type IN ({$ph})", $post_types),
        ARRAY_A
    );
    $post_ids = wp_list_pluck($posts, 'ID');

    // 2. Postmeta (chunked to dodge IN-clause + memory issues)
    $postmeta = [];
    if (!empty($post_ids)) {
        foreach (array_chunk($post_ids, 500) as $chunk) {
            $cph = implode(',', array_fill(0, count($chunk), '%d'));
            $postmeta = array_merge($postmeta, $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$cph})", $chunk),
                ARRAY_A
            ));
        }
    }

    // 3. Terms — full export (categories, tags, menu containers all live here)
    $terms              = $wpdb->get_results("SELECT * FROM {$wpdb->terms}", ARRAY_A);
    $term_taxonomy      = $wpdb->get_results("SELECT * FROM {$wpdb->term_taxonomy}", ARRAY_A);
    $termmeta           = $wpdb->get_results("SELECT * FROM {$wpdb->termmeta}", ARRAY_A);
    $term_relationships = !empty($post_ids)
        ? $wpdb->get_results("SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN (" . implode(',', array_map('intval', $post_ids)) . ")", ARRAY_A)
        : [];

    // 4. Options — whitelisted only, raw values (may be serialized strings)
    $all_options = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options}", ARRAY_A);
    $options = [];
    foreach ($all_options as $opt) {
        if (dbr_sync_option_is_allowed($opt['option_name'])) {
            $options[$opt['option_name']] = $opt['option_value'];
        }
    }

    $payload = [
        'site_url'           => site_url(),
        'home_url'           => home_url(),
        'exported_at'        => current_time('mysql'),
        'wp_version'         => get_bloginfo('version'),
        'plugin_version'     => DEPLOY_BRIDGE_VERSION,
        'post_types'         => $post_types,
        'posts'              => $posts,
        'postmeta'           => $postmeta,
        'terms'              => $terms,
        'term_taxonomy'      => $term_taxonomy,
        'term_relationships' => $term_relationships,
        'termmeta'           => $termmeta,
        'options'            => $options,
    ];

    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('db', "Sync export: " . count($posts) . " posts, " . count($options) . " options", 'success', count($posts), $ms, 0);

    return rest_ensure_response($payload);
}

// =========================================================================
// SYNC IMPORT
// =========================================================================

function dbr_endpoint_sync_import($request) {
    if (!dbr_is_op_allowed('db')) {
        return new WP_Error('op_disabled', 'Sync import is disabled.', ['status' => 403]);
    }
    $start = microtime(true);
    global $wpdb;

    $body = $request->get_json_params() ?: [];
    $post_types         = $body['post_types']         ?? [];
    $posts              = $body['posts']              ?? [];
    $postmeta           = $body['postmeta']           ?? [];
    $terms              = $body['terms']              ?? [];
    $term_taxonomy      = $body['term_taxonomy']      ?? [];
    $term_relationships = $body['term_relationships'] ?? [];
    $termmeta           = $body['termmeta']           ?? [];
    $options            = $body['options']            ?? [];
    $replace_from       = isset($body['replace_url_from']) ? (string) $body['replace_url_from'] : '';
    $replace_to         = isset($body['replace_url_to'])   ? (string) $body['replace_url_to']   : '';
    $dry_run            = !empty($body['dry_run']);

    if (empty($post_types) || !is_array($post_types)) {
        return new WP_Error('no_post_types', 'post_types is required.', ['status' => 400]);
    }
    $post_types = array_map('sanitize_key', $post_types);

    $stats = [
        'dry_run'           => $dry_run,
        'snapshot'          => null,
        'posts_deleted'     => 0,
        'posts_inserted'    => 0,
        'postmeta_inserted' => 0,
        'terms_replaced'    => 0,
        'options_updated'   => 0,
        'options_skipped'   => [],
        'replace_affected'  => 0,
    ];

    // Dry-run: report counts only, do not touch DB.
    if ($dry_run) {
        $stats['posts_inserted']    = count($posts);
        $stats['postmeta_inserted'] = count($postmeta);
        $stats['terms_replaced']    = count($terms);
        foreach ($options as $name => $_) {
            if (dbr_sync_option_is_allowed($name)) $stats['options_updated']++;
            else $stats['options_skipped'][] = $name;
        }
        $ms = round((microtime(true) - $start) * 1000);
        return rest_ensure_response(['success' => true, 'stats' => $stats, 'response_ms' => $ms]);
    }

    // 1. Snapshot
    $snapshot_dir = WP_CONTENT_DIR . '/uploads/bridge-backups';
    wp_mkdir_p($snapshot_dir);
    // Hide directory contents from public listing
    if (!file_exists($snapshot_dir . '/.htaccess')) {
        @file_put_contents($snapshot_dir . '/.htaccess', "Deny from all\n");
    }
    if (!file_exists($snapshot_dir . '/index.php')) {
        @file_put_contents($snapshot_dir . '/index.php', "<?php // Silence is golden.\n");
    }
    $snapshot_file = $snapshot_dir . '/sync-snapshot-' . date('Y-m-d_H-i-s') . '.sql';
    if (!dbr_sync_snapshot($snapshot_file, $post_types)) {
        return new WP_Error('snapshot_failed', 'Could not write snapshot file. Check uploads/ permissions.', ['status' => 500]);
    }
    $stats['snapshot'] = str_replace(ABSPATH, '', $snapshot_file);

    // 1b. Parse-safe rollback point: capture the CURRENT (pre-import) content as
    // a re-importable JSON payload (reuse the export logic). Restored by
    // /sync-restore, which feeds it back through this same importer — so any
    // content (newlines, quotes, shortcodes) round-trips via $wpdb->insert
    // instead of fragile .sql parsing. Old rollbacks are pruned to the last 10.
    $rb_req = new WP_REST_Request('POST', '/deploy-bridge/v1/sync-export');
    $rb_req->set_header('content-type', 'application/json');
    $rb_req->set_body(wp_json_encode(['post_types' => $post_types]));
    $rb_resp = dbr_endpoint_sync_export($rb_req);
    if (!is_wp_error($rb_resp)) {
        $rb = $rb_resp->get_data();
        $rb['post_types'] = $post_types;
        $rb['dry_run']    = false;
        $rb_file = $snapshot_dir . '/rollback-' . date('Y-m-d_H-i-s') . '.json';
        @file_put_contents($rb_file, wp_json_encode($rb));
        $stats['rollback'] = str_replace(ABSPATH, '', $rb_file);
        // prune: keep the 10 newest rollback files
        $rb_all = glob($snapshot_dir . '/rollback-*.json');
        if (is_array($rb_all) && count($rb_all) > 10) {
            rsort($rb_all);
            foreach (array_slice($rb_all, 10) as $old) @unlink($old);
        }
    }

    // 2. Wipe + rebuild posts of the synced types
    $ph = implode(',', array_fill(0, count($post_types), '%s'));
    $existing_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$ph})", $post_types
    ));
    if (!empty($existing_ids)) {
        $id_list = implode(',', array_map('intval', $existing_ids));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list})");
        $wpdb->query("DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ({$id_list})");
        $stats['posts_deleted'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->posts} WHERE post_type IN ({$ph})", $post_types
        ));
    }
    foreach ($posts as $post) {
        if ($wpdb->insert($wpdb->posts, $post)) $stats['posts_inserted']++;
    }
    foreach ($postmeta as $meta) {
        if ($wpdb->insert($wpdb->postmeta, $meta)) $stats['postmeta_inserted']++;
    }

    // 3. Replace term tables wholesale (categories/tags/menu containers all sync together)
    $wpdb->query("DELETE FROM {$wpdb->term_relationships}");
    $wpdb->query("DELETE FROM {$wpdb->termmeta}");
    $wpdb->query("DELETE FROM {$wpdb->term_taxonomy}");
    $wpdb->query("DELETE FROM {$wpdb->terms}");
    foreach ($terms              as $row) { $wpdb->insert($wpdb->terms, $row); }
    foreach ($term_taxonomy      as $row) { $wpdb->insert($wpdb->term_taxonomy, $row); }
    foreach ($term_relationships as $row) { $wpdb->insert($wpdb->term_relationships, $row); }
    foreach ($termmeta           as $row) { $wpdb->insert($wpdb->termmeta, $row); }
    $stats['terms_replaced'] = count($terms);

    // 4. Whitelisted options
    foreach ($options as $name => $value) {
        if (!dbr_sync_option_is_allowed($name)) {
            $stats['options_skipped'][] = $name;
            continue;
        }
        // Stored value is raw — try to unserialize so update_option re-serializes correctly
        $unser = @unserialize($value);
        $to_save = ($unser !== false || $value === 'b:0;') ? $unser : $value;
        update_option($name, $to_save);
        $stats['options_updated']++;
    }

    // 5. URL search-replace (serialized-aware)
    if ($replace_from !== '' && $replace_to !== '' && $replace_from !== $replace_to) {
        $sr = dbr_sync_run_search_replace($replace_from, $replace_to);
        $stats['replace_affected'] = $sr['affected'];
    }

    // 6. Flush caches/rewrites so the new content is live
    flush_rewrite_rules(false);
    wp_cache_flush();

    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('db', "Sync import: posts={$stats['posts_inserted']} options={$stats['options_updated']}", 'success', $stats['posts_inserted'], $ms, 0);

    return rest_ensure_response([
        'success'     => true,
        'stats'       => $stats,
        'response_ms' => $ms,
    ]);
}

// =========================================================================
// SYNC RESTORE — roll back the last sync-import from its JSON rollback point
// =========================================================================

function dbr_endpoint_sync_restore($request) {
    if (!dbr_is_op_allowed('db')) {   // sync ops ride under the 'db' op (allowed by default)
        return new WP_Error('op_disabled', 'Sync restore is disabled.', ['status' => 403]);
    }
    $dir   = WP_CONTENT_DIR . '/uploads/bridge-backups';
    $files = glob($dir . '/rollback-*.json');
    if (empty($files)) {
        return new WP_Error('no_rollback', 'No rollback point found — nothing to restore.', ['status' => 404]);
    }
    rsort($files); // newest first (timestamped filenames)
    $file    = $files[0];
    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload) || empty($payload['post_types'])) {
        return new WP_Error('bad_rollback', 'Rollback file is unreadable or incomplete.', ['status' => 500]);
    }
    // Re-import the captured pre-import content on THIS site (no URL rewrite —
    // same host). The importer captures a fresh rollback first, so a restore is
    // itself reversible.
    unset($payload['replace_url_from'], $payload['replace_url_to']);
    $payload['dry_run'] = false;
    $req = new WP_REST_Request('POST', '/deploy-bridge/v1/sync-import');
    $req->set_header('content-type', 'application/json');
    $req->set_body(wp_json_encode($payload));
    $resp = dbr_endpoint_sync_import($req);
    if (is_wp_error($resp)) return $resp;
    $data = (array) $resp->get_data();
    $data['restored_from'] = basename($file);
    return rest_ensure_response($data);
}

// =========================================================================
// UPLOADS MANIFEST — for diff sync (sender skips files it already has)
// =========================================================================

function dbr_endpoint_uploads_manifest($request) {
    if (!dbr_is_op_allowed('push')) {
        return new WP_Error('op_disabled', 'File operations are disabled.', ['status' => 403]);
    }
    $start = microtime(true);

    $upload_dir = wp_upload_dir();
    $base = $upload_dir['basedir'] ?? (WP_CONTENT_DIR . '/uploads');
    if (!is_dir($base)) {
        return rest_ensure_response(['files' => [], 'count' => 0, 'base' => $base]);
    }

    // Skip our own backup dir + cache dirs.
    $skip_segments = ['/bridge-backups/', '/cache/', '/wpforms/cache/'];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    $files = [];
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        $real = $f->getRealPath();
        $rel  = substr($real, strlen($base) + 1);
        $rel_norm = '/' . str_replace(DIRECTORY_SEPARATOR, '/', $rel);

        $skip = false;
        foreach ($skip_segments as $seg) {
            if (strpos($rel_norm, $seg) !== false) { $skip = true; break; }
        }
        if ($skip) continue;

        $files[] = [
            'path'  => str_replace(DIRECTORY_SEPARATOR, '/', $rel),
            'size'  => $f->getSize(),
            'sha1'  => sha1_file($real),
            'mtime' => $f->getMTime(),
        ];
    }

    $ms = round((microtime(true) - $start) * 1000);
    dbr_log('status', "Uploads manifest: " . count($files) . " files", 'success', count($files), $ms, 0);

    return rest_ensure_response([
        'count'       => count($files),
        'base'        => $base,
        'files'       => $files,
        'response_ms' => $ms,
    ]);
}

// =========================================================================
// 6. ADMIN DASHBOARD
// =========================================================================

add_action('admin_menu', function () {
    add_management_page(
        'Deploy Bridge',
        'Deploy Bridge',
        'manage_options',
        'deploy-bridge',
        'dbr_render_admin_page'
    );
});

// Dashboard widget
add_action('wp_dashboard_setup', function () {
    wp_add_dashboard_widget(
        'dbr_dashboard_widget',
        'Deploy Bridge — Recent Activity',
        'dbr_render_dashboard_widget'
    );
});

function dbr_render_dashboard_widget() {
    global $wpdb;
    $table = $wpdb->prefix . DEPLOY_BRIDGE_LOG_TABLE;

    $last = $wpdb->get_row("SELECT * FROM {$table} ORDER BY id DESC LIMIT 1");
    $week_count = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $failed = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='error' AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)");

    echo '<div style="display:flex;gap:20px;align-items:center;">';
    echo '<div>';
    echo '<strong>Last deploy:</strong> ' . ($last ? esc_html($last->timestamp) . ' (' . esc_html($last->action_type) . ')' : 'Never') . '<br>';
    echo '<strong>This week:</strong> ' . intval($week_count) . ' deployments<br>';
    if ($failed > 0) {
        echo '<span style="color:red;font-weight:bold;">Failed: ' . intval($failed) . '</span>';
    } else {
        echo '<span style="color:green;">No failures</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '<p><a href="' . admin_url('tools.php?page=deploy-bridge') . '">View full log &rarr;</a></p>';
}

/**
 * Handle admin form submissions.
 */
add_action('admin_init', function () {
    // Settings save
    if (isset($_POST['dbr_save_settings']) && check_admin_referer('dbr_settings_nonce')) {
        if (isset($_POST['dbr_enabled'])) {
            update_option('dbr_enabled', '1');
        } else {
            update_option('dbr_enabled', '0');
        }

        $allowed_ops = [];
        foreach (['push', 'db', 'eval', 'status'] as $op) {
            if (!empty($_POST['dbr_op_' . $op])) {
                $allowed_ops[] = $op;
            }
        }
        update_option('dbr_allowed_ops', $allowed_ops);

        update_option('dbr_allowed_ips', sanitize_text_field($_POST['dbr_allowed_ips'] ?? ''));
        update_option('dbr_blocked_ips', sanitize_text_field($_POST['dbr_blocked_ips'] ?? ''));

        wp_safe_redirect(add_query_arg(['page' => 'deploy-bridge', 'tab' => 'settings', 'saved' => '1'], admin_url('tools.php')));
        exit;
    }

    // Regenerate key
    if (isset($_POST['dbr_regen_key']) && check_admin_referer('dbr_regen_nonce')) {
        update_option('dbr_api_key', wp_generate_password(32, false));
        wp_safe_redirect(add_query_arg(['page' => 'deploy-bridge', 'tab' => 'settings', 'regen' => 'key'], admin_url('tools.php')));
        exit;
    }

    // Regenerate secret
    if (isset($_POST['dbr_regen_secret']) && check_admin_referer('dbr_regen_nonce')) {
        update_option('dbr_api_secret', wp_generate_password(64, false));
        wp_safe_redirect(add_query_arg(['page' => 'deploy-bridge', 'tab' => 'settings', 'regen' => 'secret'], admin_url('tools.php')));
        exit;
    }

    // Clear log
    if (isset($_POST['dbr_clear_log']) && check_admin_referer('dbr_clear_log_nonce')) {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}" . DEPLOY_BRIDGE_LOG_TABLE);
        wp_safe_redirect(add_query_arg(['page' => 'deploy-bridge', 'tab' => 'logs', 'cleared' => '1'], admin_url('tools.php')));
        exit;
    }
});

/**
 * Main admin page renderer.
 */
function dbr_render_admin_page() {
    $tab = sanitize_key($_GET['tab'] ?? 'settings');
    $tabs = [
        'settings'   => 'Settings',
        'logs'       => 'Deploy Log',
        'connection' => 'Connection',
    ];

    echo '<div class="wrap">';
    echo '<h1>Deploy Bridge <small style="color:#999;">v' . DEPLOY_BRIDGE_VERSION . '</small></h1>';

    // Tab navigation
    echo '<nav class="nav-tab-wrapper">';
    foreach ($tabs as $slug => $label) {
        $class = ($tab === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
        $url = add_query_arg(['page' => 'deploy-bridge', 'tab' => $slug], admin_url('tools.php'));
        echo '<a href="' . esc_url($url) . '" class="' . $class . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    echo '<div style="margin-top:20px;">';

    switch ($tab) {
        case 'settings':
            dbr_render_settings_tab();
            break;
        case 'logs':
            dbr_render_logs_tab();
            break;
        case 'connection':
            dbr_render_connection_tab();
            break;
    }

    echo '</div></div>';
}

// --- Settings Tab ---
function dbr_render_settings_tab() {
    $enabled = dbr_is_enabled();
    $allowed_ops = get_option('dbr_allowed_ops', ['push', 'db', 'eval', 'status']);
    $allowed_ips = get_option('dbr_allowed_ips', '');
    $blocked_ips = get_option('dbr_blocked_ips', '');
    $api_key = dbr_get_key();
    $api_secret = dbr_get_secret();

    if (isset($_GET['saved'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }
    if (isset($_GET['regen'])) {
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html(ucfirst($_GET['regen'])) . ' regenerated. Update your local deploy-sites.json!</p></div>';
    }
    ?>
    <h2>API Credentials</h2>
    <table class="form-table">
        <tr>
            <th>API Key</th>
            <td>
                <code style="background:#f0f0f0;padding:4px 8px;font-size:13px;"><?php echo esc_html(substr($api_key, 0, 8) . '...' . substr($api_key, -4)); ?></code>
                <form method="post" style="display:inline;margin-left:10px;">
                    <?php wp_nonce_field('dbr_regen_nonce'); ?>
                    <button type="submit" name="dbr_regen_key" class="button button-small" onclick="return confirm('Regenerate API key? Your local config will need updating.');">Regenerate</button>
                </form>
            </td>
        </tr>
        <tr>
            <th>Shared Secret</th>
            <td>
                <code style="background:#f0f0f0;padding:4px 8px;font-size:13px;"><?php echo esc_html(substr($api_secret, 0, 8) . '...' . substr($api_secret, -4)); ?></code>
                <form method="post" style="display:inline;margin-left:10px;">
                    <?php wp_nonce_field('dbr_regen_nonce'); ?>
                    <button type="submit" name="dbr_regen_secret" class="button button-small" onclick="return confirm('Regenerate secret? Your local config will need updating.');">Regenerate</button>
                </form>
            </td>
        </tr>
    </table>

    <hr>

    <form method="post">
        <?php wp_nonce_field('dbr_settings_nonce'); ?>

        <h2>General Settings</h2>
        <table class="form-table">
            <tr>
                <th>Enable Deploy Bridge</th>
                <td>
                    <label>
                        <input type="checkbox" name="dbr_enabled" value="1" <?php checked($enabled); ?>>
                        Accept incoming deploy requests
                    </label>
                    <p class="description">Master kill switch. When disabled, ALL API requests are rejected.</p>
                </td>
            </tr>
            <tr>
                <th>Allowed Operations</th>
                <td>
                    <?php foreach (['push' => 'File Push', 'db' => 'Database Operations', 'eval' => 'Eval Actions', 'status' => 'Status Check'] as $op => $label) : ?>
                        <label style="display:block;margin-bottom:4px;">
                            <input type="checkbox" name="dbr_op_<?php echo $op; ?>" value="1" <?php checked(in_array($op, $allowed_ops)); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th>IP Allowlist</th>
                <td>
                    <input type="text" name="dbr_allowed_ips" value="<?php echo esc_attr($allowed_ips); ?>" class="regular-text" placeholder="Leave blank to allow all IPs">
                    <p class="description">Comma-separated IPs. Only these can connect. Leave blank for no restriction.</p>
                </td>
            </tr>
            <tr>
                <th>IP Blocklist</th>
                <td>
                    <input type="text" name="dbr_blocked_ips" value="<?php echo esc_attr($blocked_ips); ?>" class="regular-text" placeholder="IPs to block">
                    <p class="description">Comma-separated IPs. These are always blocked, even if in the allowlist.</p>
                </td>
            </tr>
        </table>

        <p><input type="submit" name="dbr_save_settings" class="button button-primary" value="Save Settings"></p>
    </form>
    <?php
}

// --- Logs Tab ---
function dbr_render_logs_tab() {
    global $wpdb;
    $table = $wpdb->prefix . DEPLOY_BRIDGE_LOG_TABLE;

    if (isset($_GET['cleared'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Log cleared.</p></div>';
    }

    // Filters
    $filter_action = sanitize_key($_GET['filter_action'] ?? '');
    $filter_status = sanitize_key($_GET['filter_status'] ?? '');

    $where = '1=1';
    if ($filter_action) $where .= $wpdb->prepare(' AND action_type = %s', $filter_action);
    if ($filter_status) $where .= $wpdb->prepare(' AND status = %s', $filter_status);

    $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT 100");
    $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");

    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;">';
    echo '<div>';
    echo '<strong>Filters:</strong> ';
    $base_url = add_query_arg(['page' => 'deploy-bridge', 'tab' => 'logs'], admin_url('tools.php'));
    foreach (['push', 'db', 'eval', 'status'] as $type) {
        $active = ($filter_action === $type) ? 'font-weight:bold;' : '';
        echo '<a href="' . esc_url(add_query_arg('filter_action', $type, $base_url)) . '" style="margin:0 5px;' . $active . '">' . $type . '</a>';
    }
    echo ' | <a href="' . esc_url($base_url) . '">All</a>';
    echo '</div>';
    echo '<div>';
    echo '<span style="color:#999;">Total: ' . intval($total) . ' entries</span> ';
    echo '<form method="post" style="display:inline;">';
    wp_nonce_field('dbr_clear_log_nonce');
    echo '<button type="submit" name="dbr_clear_log" class="button button-small" onclick="return confirm(\'Clear all log entries?\');">Clear Log</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Time</th><th>Action</th><th>Source IP</th><th>Summary</th><th>Files</th><th>Status</th><th>Response</th></tr></thead>';
    echo '<tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="7" style="text-align:center;color:#999;">No log entries.</td></tr>';
    }

    foreach ($rows as $row) {
        $status_color = $row->status === 'success' ? 'green' : 'red';
        echo '<tr>';
        echo '<td style="white-space:nowrap;">' . esc_html($row->timestamp) . '</td>';
        echo '<td><code>' . esc_html($row->action_type) . '</code></td>';
        echo '<td>' . esc_html($row->source_ip) . '</td>';
        echo '<td>' . esc_html($row->summary) . '</td>';
        echo '<td>' . intval($row->files_affected) . '</td>';
        echo '<td><span style="color:' . $status_color . ';font-weight:bold;">' . esc_html($row->status) . '</span></td>';
        echo '<td>' . intval($row->response_time_ms) . 'ms</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
}

// --- Connection Tab ---
function dbr_render_connection_tab() {
    $api_key    = dbr_get_key();
    $api_secret = dbr_get_secret();
    $rest_url   = rest_url( DEPLOY_BRIDGE_NS . '/status' );
    $site_slug  = sanitize_title( get_bloginfo( 'name' ) );
    $site_url   = site_url();

    $config_snippet = json_encode( [
        $site_slug => [
            'url'    => $site_url,
            'key'    => $api_key,
            'secret' => $api_secret,
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    ?>
    <h2>Register This Site With Your Deploy Bridge Dashboard</h2>
    <p>
        Open your <strong>Deploy Bridge dashboard</strong> on the local development master site,
        click <strong>Add Site</strong>, and paste the values below into the form.
        Use the <em>show</em> / <em>copy</em> buttons — values stay hidden until you click them.
    </p>

    <table class="form-table dbr-creds-table">
        <tr>
            <th>Site URL</th>
            <td>
                <code class="dbr-cred" data-value="<?php echo esc_attr( $site_url ); ?>"><?php echo esc_html( $site_url ); ?></code>
                <button type="button" class="button button-small dbr-copy" data-target-value="<?php echo esc_attr( $site_url ); ?>">Copy</button>
            </td>
        </tr>
        <tr>
            <th>API Key</th>
            <td>
                <code class="dbr-cred dbr-secret" data-value="<?php echo esc_attr( $api_key ); ?>" data-masked="<?php echo esc_attr( str_repeat( '•', 24 ) ); ?>"><?php echo esc_html( str_repeat( '•', 24 ) ); ?></code>
                <button type="button" class="button button-small dbr-toggle">Show</button>
                <button type="button" class="button button-small dbr-copy" data-target-value="<?php echo esc_attr( $api_key ); ?>">Copy</button>
            </td>
        </tr>
        <tr>
            <th>Shared Secret</th>
            <td>
                <code class="dbr-cred dbr-secret" data-value="<?php echo esc_attr( $api_secret ); ?>" data-masked="<?php echo esc_attr( str_repeat( '•', 24 ) ); ?>"><?php echo esc_html( str_repeat( '•', 24 ) ); ?></code>
                <button type="button" class="button button-small dbr-toggle">Show</button>
                <button type="button" class="button button-small dbr-copy" data-target-value="<?php echo esc_attr( $api_secret ); ?>">Copy</button>
            </td>
        </tr>
    </table>

    <hr>

    <h2>Connection Test</h2>
    <p>Verify this site is reachable and authenticating correctly. (This calls the status endpoint with the key above — does not require the dashboard.)</p>
    <p>
        <button type="button" class="button button-primary" id="dbr-test-btn">Test Connection</button>
        <span id="dbr-test-result" style="margin-left:10px;"></span>
    </p>

    <hr>

    <h2>API Endpoint Reference</h2>
    <table class="form-table">
        <tr>
            <th>Base URL</th>
            <td><code style="background:#f0f0f0;padding:4px 8px;font-size:13px;user-select:all;"><?php echo esc_html( rest_url( DEPLOY_BRIDGE_NS ) ); ?></code></td>
        </tr>
        <tr>
            <th>Status Endpoint</th>
            <td><code style="user-select:all;"><?php echo esc_html( $rest_url ); ?></code></td>
        </tr>
    </table>

    <hr>

    <h2>CLI Quick Reference</h2>
    <p>For users running deploys directly from the command line via <code>deploy.sh</code>.</p>
    <table class="widefat" style="max-width:700px;">
        <thead><tr><th>Command</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><code>deploy.sh status <?php echo esc_html( $site_slug ); ?></code></td><td>Check connection</td></tr>
            <tr><td><code>deploy.sh push <?php echo esc_html( $site_slug ); ?> --files mu-plugins/file.php</code></td><td>Push a file</td></tr>
            <tr><td><code>deploy.sh push <?php echo esc_html( $site_slug ); ?> --dir themes/my-child/</code></td><td>Push an entire directory</td></tr>
            <tr><td><code>deploy.sh eval <?php echo esc_html( $site_slug ); ?> --action flush_cache</code></td><td>Run a predefined action</td></tr>
            <tr><td><code>deploy.sh push-all --files mu-plugins/file.php</code></td><td>Push to ALL sites</td></tr>
        </tbody>
    </table>

    <hr>

    <details style="margin-top:24px;">
        <summary style="cursor:pointer;font-weight:600;font-size:14px;">Advanced: raw <code>deploy-sites.json</code> snippet</summary>
        <p style="margin-top:12px;">
            Only needed if you're configuring the dashboard by hand-editing its JSON file
            (instead of using the Add Site form). Most users should ignore this.
        </p>
        <textarea readonly rows="10" class="large-text code" style="font-family:monospace;font-size:12px;" onclick="this.select();"><?php echo esc_textarea( $config_snippet ); ?></textarea>
    </details>

    <style>
        .dbr-creds-table code.dbr-cred {
            background: #f0f0f0;
            padding: 4px 8px;
            font-size: 13px;
            user-select: all;
            display: inline-block;
            min-width: 280px;
            margin-right: 6px;
        }
        .dbr-creds-table .button-small { margin-right: 4px; }
        .dbr-copy.is-copied { background: #d4f4dd; border-color: #2a9d8f; }
    </style>

    <script>
    (function () {
        // Show / hide secret values
        document.querySelectorAll('.dbr-toggle').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var code = btn.parentElement.querySelector('code.dbr-cred');
                if (!code) return;
                var revealed = code.dataset.revealed === '1';
                if (revealed) {
                    code.textContent = code.dataset.masked;
                    code.dataset.revealed = '0';
                    btn.textContent = 'Show';
                } else {
                    code.textContent = code.dataset.value;
                    code.dataset.revealed = '1';
                    btn.textContent = 'Hide';
                }
            });
        });

        // Copy to clipboard
        document.querySelectorAll('.dbr-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var val = btn.dataset.targetValue || '';
                var done = function () {
                    var orig = btn.textContent;
                    btn.textContent = 'Copied!';
                    btn.classList.add('is-copied');
                    setTimeout(function () {
                        btn.textContent = orig;
                        btn.classList.remove('is-copied');
                    }, 1400);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(val).then(done).catch(function () {
                        fallbackCopy(val); done();
                    });
                } else {
                    fallbackCopy(val); done();
                }
            });
        });

        function fallbackCopy(text) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
        }

        // Connection test
        var testBtn = document.getElementById('dbr-test-btn');
        if (testBtn) {
            testBtn.addEventListener('click', function () {
                var result = document.getElementById('dbr-test-result');
                testBtn.disabled = true;
                result.innerHTML = 'Testing…';

                fetch('<?php echo esc_js( $rest_url ); ?>', {
                    headers: { 'X-Deploy-Key': '<?php echo esc_js( $api_key ); ?>' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.site_url) {
                        result.innerHTML = '<span style="color:green;font-weight:bold;">Connected!</span> WP ' + data.wp_version + ' / PHP ' + data.php_version;
                    } else {
                        result.innerHTML = '<span style="color:red;">Error: ' + (data.message || 'Unknown') + '</span>';
                    }
                    testBtn.disabled = false;
                })
                .catch(function (err) {
                    result.innerHTML = '<span style="color:red;">Failed: ' + err.message + '</span>';
                    testBtn.disabled = false;
                });
            });
        }
    })();
    </script>
    <?php
}
