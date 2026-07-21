<?php
/**
 * Plugin Name: Bridge Engine
 * Description: Local command center for managing staging site connections. Push/pull files, themes, mu-plugins, and DB content to staging sites via the Deploy Bridge API.
 * Version: 2.4.0
 * Author: HypedUp Studios
 * Text Domain: bridge-engine
 */

defined('ABSPATH') || exit;

define('DBC_VERSION', '2.4.0');

/**
 * Default path to the parent directory containing your local WordPress sites.
 * Examples:
 *   - Local by Flywheel (macOS): /Users/YOU/Local Sites
 *   - Local by Flywheel (Windows): C:/Users/YOU/Local Sites
 *   - MAMP: /Applications/MAMP/htdocs
 *   - Valet: /Users/YOU/Sites
 *
 * This can be overridden per-installation via Tools → Bridge Engine → Settings.
 */
if ( ! defined('DBC_LOCAL_SITES_DIR_DEFAULT') ) {
    // Detect layout and walk up the correct number of levels.
    // Flywheel: <root>/<site>/app/public/        → 3 dirname calls
    // Lightning: <root>/<site>/public/           → 2 dirname calls
    // Plain WP: <root>/<site>/                   → 1 dirname call
    $dbc_abs = untrailingslashit( ABSPATH );
    if ( substr( $dbc_abs, -11 ) === '/app/public' ) {
        $dbc_default = dirname( $dbc_abs, 3 );
    } elseif ( substr( $dbc_abs, -7 ) === '/public' ) {
        $dbc_default = dirname( $dbc_abs, 2 );
    } else {
        $dbc_default = dirname( $dbc_abs, 1 );
    }
    define('DBC_LOCAL_SITES_DIR_DEFAULT', $dbc_default);
    unset( $dbc_abs, $dbc_default );
}

class DeployBridgeCommander {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * The configured local sites directory. Reads from the `dbc_settings`
     * option, falls back to DBC_LOCAL_SITES_DIR_DEFAULT.
     */
    public static function local_sites_dir() {
        $settings = get_option('dbc_settings', []);
        $dir = isset($settings['local_sites_dir']) ? trim($settings['local_sites_dir']) : '';
        if (empty($dir)) {
            $dir = DBC_LOCAL_SITES_DIR_DEFAULT;
        }
        return rtrim($dir, '/\\');
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_dbc_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_dbc_save_site', [$this, 'ajax_save_site']);
        add_action('wp_ajax_dbc_save_sites_json', [$this, 'ajax_save_sites_json']);
        add_action('wp_ajax_dbc_delete_site', [$this, 'ajax_delete_site']);
        add_action('wp_ajax_dbc_push', [$this, 'ajax_push']);
        add_action('wp_ajax_dbc_pull', [$this, 'ajax_pull']);
        add_action('wp_ajax_dbc_quick_action', [$this, 'ajax_quick_action']);
        add_action('wp_ajax_dbc_rotate_site', [$this, 'ajax_rotate_site']);
        add_action('wp_ajax_dbc_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_dbc_save_settings', [$this, 'ajax_save_settings']);

        // Full content sync (bidirectional)
        add_action('wp_ajax_dbc_full_sync',         [$this, 'ajax_full_sync']);
        add_action('wp_ajax_dbc_full_sync_uploads', [$this, 'ajax_full_sync_uploads']);
        add_action('wp_ajax_dbc_sync_restore',      [$this, 'ajax_sync_restore']);
    }

    // =====================================================================
    // MENU & ASSETS
    // =====================================================================

    public function add_menu() {
        add_management_page(
            'Bridge Engine',
            'Bridge Engine',
            'manage_options',
            'bridge-engine',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_bridge-engine') return;

        // Style — register first, then enqueue + add inline CSS.
        wp_register_style('dbc-style', false, [], DBC_VERSION);
        wp_enqueue_style('dbc-style');
        wp_add_inline_style('dbc-style', $this->get_inline_css());

        // Script — must be registered (with jQuery dep) BEFORE enqueue/inline/localize,
        // otherwise WP silently drops the handle and no <script> tag is printed.
        wp_register_script('dbc-script', '', ['jquery'], DBC_VERSION, true);
        wp_enqueue_script('dbc-script');
        wp_localize_script('dbc-script', 'dbc', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dbc_nonce'),
        ]);
        wp_add_inline_script('dbc-script', $this->get_inline_js());
    }

    // =====================================================================
    // DATA HELPERS
    // =====================================================================

    private function get_sites() {
        return get_option('dbc_sites', []);
    }

    private function save_sites($sites) {
        update_option('dbc_sites', $sites);
        $this->write_cli_config($sites);
    }

    /**
     * Single source of truth: mirror the GUI connections (dbc_sites) into the
     * CLI's scripts/deploy-sites.json on every save/edit/delete, so the
     * deploy.sh tooling never drifts from the Bridge Engine. Writes only the
     * fields the CLI needs; preserves any non-"sites" top-level keys already in
     * the file. Best-effort — never blocks the save if the path isn't writable.
     */
    private function write_cli_config($sites) {
        $path = rtrim(self::local_sites_dir(), '/') . '/scripts/deploy-sites.json';
        if (!is_dir(dirname($path))) return;

        $config = [];
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (is_array($existing)) $config = $existing;
        }
        $config['sites'] = [];
        foreach ($sites as $alias => $c) {
            $config['sites'][$alias] = [
                'url'        => rtrim($c['url'] ?? '', '/'),
                'key'        => $c['key'] ?? '',
                'secret'     => $c['secret'] ?? '',
                'local_site' => $c['local_site'] ?? '',
                'theme'      => $c['theme'] ?? '',
            ];
        }
        @file_put_contents(
            $path,
            wp_json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function get_log() {
        return get_option('dbc_log', []);
    }

    private function add_log($direction, $site_alias, $summary, $files = 0, $status = 'success') {
        $log = $this->get_log();
        array_unshift($log, [
            'time'      => current_time('mysql'),
            'direction' => $direction,
            'site'      => $site_alias,
            'summary'   => $summary,
            'files'     => $files,
            'status'    => $status,
        ]);
        $log = array_slice($log, 0, 50); // keep last 50
        update_option('dbc_log', $log);
    }

    /**
     * Resolve the wp-content directory for a local site folder.
     * Supports Local by Flywheel (site/app/public/wp-content), plain WP
     * installs (site/wp-content), and the Local Lightning layout (site/public/wp-content).
     * Returns the first existing path or null if none found.
     */
    public static function resolve_wp_content($site_folder) {
        $base = self::local_sites_dir() . '/' . $site_folder;
        $candidates = [
            $base . '/app/public/wp-content',  // Local by Flywheel
            $base . '/public/wp-content',      // Local Lightning
            $base . '/wp-content',             // Plain WP install
        ];
        foreach ($candidates as $path) {
            if (is_dir($path)) return $path;
        }
        return null;
    }

    private function get_local_site_folders() {
        $folders = [];
        $dir = self::local_sites_dir();
        if (!is_dir($dir)) return $folders;

        foreach (scandir($dir) as $entry) {
            if ($entry[0] === '.') continue;
            if (self::resolve_wp_content($entry) !== null) {
                $folders[] = $entry;
            }
        }
        sort($folders);
        return $folders;
    }

    // =====================================================================
    // API CLIENT
    // =====================================================================

    private function api_request($site_config, $method, $endpoint, $body = null, $is_file = false, $timeout = 60) {
        $url = rtrim($site_config['url'], '/') . '/wp-json/deploy-bridge/v1/' . $endpoint;

        $headers = [
            'X-Deploy-Key' => $site_config['key'],
        ];

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => $timeout,
            'sslverify' => false, // local/staging certs
        ];

        if ($body !== null && !$is_file) {
            $json = is_string($body) ? $body : wp_json_encode($body);
            $headers['Content-Type'] = 'application/json';
            $headers['X-Deploy-Signature'] = hash_hmac('sha256', $json, $site_config['secret']);
            $args['headers'] = $headers;
            $args['body'] = $json;
        }

        if ($is_file && $body !== null) {
            // For file uploads we need a different approach
            $boundary = wp_generate_password(24, false);
            $file_contents = file_get_contents($body);

            $payload = '';
            $payload .= "--{$boundary}\r\n";
            $payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"deploy.zip\"\r\n";
            $payload .= "Content-Type: application/zip\r\n\r\n";
            $payload .= $file_contents . "\r\n";
            $payload .= "--{$boundary}--\r\n";

            $headers['Content-Type'] = "multipart/form-data; boundary={$boundary}";
            $headers['X-Deploy-Signature'] = hash_hmac('sha256', $file_contents, $site_config['secret']);
            $args['headers'] = $headers;
            $args['body'] = $payload;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_text = wp_remote_retrieve_body($response);

        // Check if response is a zip (for pull)
        $content_type = wp_remote_retrieve_header($response, 'content-type');
        if ($content_type === 'application/zip') {
            return ['zip' => $body_text, 'files' => (int) wp_remote_retrieve_header($response, 'x-deploy-files')];
        }

        $data = json_decode($body_text, true);

        if ($code >= 400) {
            return ['error' => $data['message'] ?? "HTTP $code", 'code' => $code];
        }

        return $data ?: ['error' => 'Empty response'];
    }

    private function package_files($wp_content_dir, $paths) {
        $tmpzip = wp_tempnam('dbc-push-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpzip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return false;
        }

        $count = 0;
        foreach ($paths as $relative_path) {
            $full = $wp_content_dir . '/' . $relative_path;
            if (is_file($full)) {
                $zip->addFile($full, $relative_path);
                $count++;
            } elseif (is_dir($full)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($full, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && $file->getFilename() !== '.DS_Store') {
                        $rel = $relative_path . '/' . substr($file->getRealPath(), strlen($full) + 1);
                        $zip->addFile($file->getRealPath(), $rel);
                        $count++;
                    }
                }
            }
        }

        $zip->close();
        return $count > 0 ? $tmpzip : false;
    }

    // =====================================================================
    // AJAX HANDLERS
    // =====================================================================

    public function ajax_test_connection() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['alias'] ?? '');
        $sites = $this->get_sites();

        if (empty($sites[$alias])) {
            wp_send_json_error('Site not found: ' . $alias);
        }

        $result = $this->api_request($sites[$alias], 'GET', 'status');

        if (isset($result['error'])) {
            $this->add_log('test', $alias, 'Connection failed: ' . $result['error'], 0, 'error');
            wp_send_json_error($result['error']);
        }

        $sites[$alias]['last_check'] = current_time('mysql');
        $sites[$alias]['status'] = 'connected';
        $this->save_sites($sites);

        wp_send_json_success($result);
    }

    // =====================================================================
    // CREDENTIAL ROTATION
    // =====================================================================
    //
    // Give each connection a UNIQUE key+secret so a leaked/backup credential
    // can't authenticate to the other sites (Plesk clones ship with the
    // template's shared creds). Safe by construction: we NEVER discard the OLD
    // credentials until a SIGNED request using the NEW secret has been proven
    // against the receiver, and (on v2.2.0+ receivers) the receiver keeps
    // accepting the OLD credential for a short grace window. Every failure mode
    // — dropped response, 429, partial write — therefore leaves the OLD pair
    // working so we can retry and converge, never a hard lockout.

    /** Probe whether a given {url,key,secret} authenticates a SIGNED op right now. */
    private function probe_creds($cfg) {
        $r = $this->api_request($cfg, 'POST', 'db', ['operations' => [['action' => 'get_option', 'key' => 'dbr_enabled']]], false, 20);
        if (!is_array($r)) return 'unknown';
        if (isset($r['error'])) {
            return ((int) ($r['code'] ?? 0) === 401) ? 'denied' : 'unknown'; // 429/5xx/network => unknown
        }
        return 'live';
    }

    /** True if a /db response reports every operation succeeded. */
    private function db_all_ok($resp) {
        if (!is_array($resp) || isset($resp['error']) || empty($resp['results'])) return false;
        foreach ($resp['results'] as $r) {
            if (($r['status'] ?? '') !== 'success') return false;
        }
        return true;
    }

    /**
     * Rotate one connection to a fresh unique key+secret. Returns a structured
     * result array (never throws). $grace = receiver dual-accept window seconds.
     */
    public function rotate_site($alias, $grace = 120) {
        // Per-alias lock — two concurrent rotations for one connection could
        // diverge the sender store from the receiver. Serialize them.
        $lock = 'dbc_rot_' . md5($alias);
        if (get_transient($lock)) {
            return ['ok' => false, 'state' => 'busy', 'message' => "A rotation for {$alias} is already in progress; try again shortly."];
        }
        set_transient($lock, 1, 180);
        try {
            return $this->rotate_site_locked($alias, $grace);
        } finally {
            delete_transient($lock);
        }
    }

    private function rotate_site_locked($alias, $grace) {
        $sites = $this->get_sites();
        if (empty($sites[$alias])) return ['ok' => false, 'state' => 'error', 'message' => "Unknown connection: {$alias}"];

        $site = $sites[$alias];
        $url = $site['url'] ?? '';
        $old_key = $site['key'] ?? '';
        $old_secret = $site['secret'] ?? '';
        if ($url === '' || $old_key === '' || $old_secret === '') {
            return ['ok' => false, 'state' => 'error', 'message' => 'Connection missing url/key/secret'];
        }
        $old_cfg = ['url' => $url, 'key' => $old_key, 'secret' => $old_secret];

        // RECOVERY FIRST: a prior attempt may have left a pending pair the receiver
        // actually committed (dropped ACK / transient verify). Fall FORWARD onto it
        // before generating anything, and NEVER clobber a pending pair we can't rule
        // out — this is what makes rotation impossible to permanently strand.
        if (!empty($site['pending_key']) && !empty($site['pending_secret'])) {
            $pending_cfg = ['url' => $url, 'key' => $site['pending_key'], 'secret' => $site['pending_secret']];
            $p = $this->probe_creds($pending_cfg);
            if ($p === 'unknown') { sleep(3); $p = $this->probe_creds($pending_cfg); }
            if ($p === 'live') {
                // Receiver is already on the pending pair → promote and finish.
                return $this->finish_rotation($alias, $old_key, $old_secret, $site['pending_key'], $site['pending_secret'], $pending_cfg, true, 'Recovered a previously-committed rotation');
            }
            if ($p === 'unknown') {
                // Can't confirm whether the receiver holds this pending pair — refuse
                // to overwrite it. Idempotent retry will self-heal once reachable.
                return ['ok' => false, 'state' => 'recovery_pending', 'message' => "Receiver unreachable while a pending rotation for {$alias} is unresolved; pending credentials kept. Retry when reachable."];
            }
            // 'denied' → receiver never adopted this pending pair; it is stale.
            unset($sites[$alias]['pending_key'], $sites[$alias]['pending_secret'], $sites[$alias]['pending_fp']);
            $this->save_sites($sites);
        }

        // Capability detect — atomic op requires receiver >= 2.2.0. A transient
        // /status failure must NOT downgrade a capable receiver; retry then abort
        // (nothing mutated and no pending written yet, so aborting is fully clean).
        $status = $this->api_request($old_cfg, 'GET', 'status');
        if (!is_array($status) || isset($status['error'])) { sleep(2); $status = $this->api_request($old_cfg, 'GET', 'status'); }
        if (!is_array($status) || isset($status['error'])) {
            return ['ok' => false, 'state' => 'precheck_failed', 'message' => 'Could not reach the receiver /status; rotation aborted before any change. Old credentials untouched — retry when reachable.'];
        }
        $version = (string) ($status['plugin_version'] ?? '');
        $atomic = $version !== '' && version_compare($version, '2.2.0', '>=');

        // Generate the new pair (alphanumeric — matches the receiver's own
        // wp_generate_password(,false) charset and survives sanitize_text_field).
        $new_key = wp_generate_password(32, false);
        $new_secret = wp_generate_password(64, false);
        $new_key_fp = substr(hash('sha256', $new_key), 0, 12);
        $new_secret_fp = substr(hash('sha256', $new_secret), 0, 12);
        $new_cfg = ['url' => $url, 'key' => $new_key, 'secret' => $new_secret];

        // Durably record the pending pair BEFORE the mutating switch — now genuinely
        // recoverable via the RECOVERY block at the top of a subsequent call.
        $sites = $this->get_sites();
        $sites[$alias]['pending_key'] = $new_key;
        $sites[$alias]['pending_secret'] = $new_secret;
        $sites[$alias]['pending_fp'] = $new_key_fp . '/' . $new_secret_fp;
        $sites[$alias]['rotation_state'] = 'rotating';
        $sites[$alias]['rotation_started'] = current_time('mysql');
        $this->save_sites($sites);

        // Switch (signed with the OLD secret — auth runs before ops execute).
        $switch_confirmed = false;
        if ($atomic) {
            $switch = $this->api_request($old_cfg, 'POST', 'db', ['operations' => [[
                'action' => 'rotate_credentials', 'new_key' => $new_key, 'new_secret' => $new_secret, 'grace_seconds' => $grace,
            ]]]);
            if ($this->db_all_ok($switch)) {
                $res = $switch['results'][0]['result'] ?? [];
                $switch_confirmed = !empty($res['rotated']) && ($res['key_fp'] ?? '') === $new_key_fp && ($res['secret_fp'] ?? '') === $new_secret_fp;
            }
        } else {
            $switch = $this->api_request($old_cfg, 'POST', 'db', ['operations' => [
                ['action' => 'update_option', 'key' => 'dbr_api_key', 'value' => $new_key],
                ['action' => 'update_option', 'key' => 'dbr_api_secret', 'value' => $new_secret],
            ]]);
        }

        // Verify with the NEW creds — the only source of truth is a SIGNED op that
        // authenticates under the new secret (never HTTP 200 or GET /status).
        $probe_new = $this->probe_creds($new_cfg);
        if ($probe_new === 'unknown') { sleep(3); $probe_new = $this->probe_creds($new_cfg); }
        if ($probe_new === 'live' || ($switch_confirmed && $probe_new !== 'denied')) {
            return $this->finish_rotation($alias, $old_key, $old_secret, $new_key, $new_secret, $new_cfg, $atomic, 'Rotated + verified');
        }

        // NEW not confirmed. On the atomic path the OLD pair authenticates during
        // the grace window EVEN IF the switch took — so "OLD is live" is NOT proof
        // of failure. Ask the receiver (signed with OLD, valid in grace) for its
        // CURRENT fingerprint and compare: == NEW ⇒ switch took ⇒ promote;
        // == OLD ⇒ switch didn't take ⇒ abandon pending, keep OLD.
        if ($atomic) {
            $fpresp = $this->api_request($old_cfg, 'POST', 'db', ['operations' => [['action' => 'credentials_fingerprint']]]);
            if ($this->db_all_ok($fpresp)) {
                $fp = $fpresp['results'][0]['result'] ?? [];
                if (($fp['key_fp'] ?? '') === $new_key_fp && ($fp['secret_fp'] ?? '') === $new_secret_fp) {
                    return $this->finish_rotation($alias, $old_key, $old_secret, $new_key, $new_secret, $new_cfg, true, 'Rotated + verified (via receiver fingerprint)');
                }
                if (($fp['key_fp'] ?? '') === substr(hash('sha256', $old_key), 0, 12)
                    && ($fp['secret_fp'] ?? '') === substr(hash('sha256', $old_secret), 0, 12)) {
                    return $this->abandon_pending($alias, 'Switch did not take; OLD credentials still active. No change — safe to retry.');
                }
            }
            // Inconclusive → keep pending for the top-of-call recovery next time.
            return $this->strand($alias);
        }

        // Fallback (pre-2.2.0) path: OLD has no grace window, so OLD-live truly
        // means the switch didn't take; otherwise detect a mixed pair and finish it.
        $probe_old = $this->probe_creds($old_cfg);
        if ($probe_old === 'unknown') { sleep(3); $probe_old = $this->probe_creds($old_cfg); }
        if ($probe_old === 'live') {
            return $this->abandon_pending($alias, 'Switch did not take; OLD credentials still active. No change — safe to retry.');
        }
        $mixed = null;
        if ($this->probe_creds(['url' => $url, 'key' => $new_key, 'secret' => $old_secret]) === 'live') {
            $mixed = ['url' => $url, 'key' => $new_key, 'secret' => $old_secret];
            $this->api_request($mixed, 'POST', 'db', ['operations' => [['action' => 'update_option', 'key' => 'dbr_api_secret', 'value' => $new_secret]]]);
        } elseif ($this->probe_creds(['url' => $url, 'key' => $old_key, 'secret' => $new_secret]) === 'live') {
            $mixed = ['url' => $url, 'key' => $old_key, 'secret' => $new_secret];
            $this->api_request($mixed, 'POST', 'db', ['operations' => [['action' => 'update_option', 'key' => 'dbr_api_key', 'value' => $new_key]]]);
        }
        if ($mixed !== null && $this->probe_creds($new_cfg) === 'live') {
            return $this->finish_rotation($alias, $old_key, $old_secret, $new_key, $new_secret, $new_cfg, false, 'Rotated + verified (recovered from partial apply)');
        }
        return $this->strand($alias);
    }

    /** Discard the pending pair, keep OLD active, mark failed_safe (retryable). */
    private function abandon_pending($alias, $message) {
        $sites = $this->get_sites();
        unset($sites[$alias]['pending_key'], $sites[$alias]['pending_secret'], $sites[$alias]['pending_fp']);
        $sites[$alias]['rotation_state'] = 'failed_safe';
        $this->save_sites($sites);
        $this->add_log('config', $alias, 'Rotation did not take — OLD credentials still active (safe, retryable)', 0, 'error');
        return ['ok' => false, 'state' => 'failed_safe', 'message' => $message];
    }

    /** Indeterminate this pass: KEEP the pending pair so the top-of-call recovery
     *  block promotes it automatically on the next attempt. Never a hard lockout. */
    private function strand($alias) {
        $sites = $this->get_sites();
        $sites[$alias]['rotation_state'] = 'stranded';
        $this->save_sites($sites);
        $this->add_log('config', $alias, 'Rotation inconclusive — pending creds preserved; a retry will auto-recover', 0, 'error');
        return [
            'ok' => false, 'state' => 'stranded',
            'message' => 'Could not confirm the rotation this pass. The pending credentials are preserved — just retry and it will fall forward onto them automatically. If it persists, break-glass on the receiver: `wp option update dbr_api_key <value>` / `dbr_api_secret <value>` to a known pair, then retry.',
        ];
    }

    /** Promote the new creds into the store, end the grace window, log. */
    private function finish_rotation($alias, $old_key, $old_secret, $new_key, $new_secret, $new_cfg, $atomic, $message) {
        $sites = $this->get_sites();
        $sites[$alias]['prev_key'] = $old_key;       // one-shot recovery record
        $sites[$alias]['prev_secret'] = $old_secret;
        $sites[$alias]['key'] = $new_key;
        $sites[$alias]['secret'] = $new_secret;
        unset($sites[$alias]['pending_key'], $sites[$alias]['pending_secret'], $sites[$alias]['pending_fp']);
        $sites[$alias]['rotation_state'] = 'rotated';
        $sites[$alias]['rotation_completed'] = current_time('mysql');
        $sites[$alias]['status'] = 'connected';
        $sites[$alias]['last_check'] = current_time('mysql');
        $this->save_sites($sites);

        // End the receiver grace window early now that NEW is proven (atomic path).
        if ($atomic) {
            $this->api_request($new_cfg, 'POST', 'db', ['operations' => [['action' => 'drop_previous_credentials']]]);
        }

        $fp = substr(hash('sha256', $new_key), 0, 12) . '/' . substr(hash('sha256', $new_secret), 0, 12);
        $this->add_log('config', $alias, "Credentials rotated (fp {$fp})");
        return ['ok' => true, 'state' => 'rotated', 'atomic' => $atomic, 'fp' => $fp, 'message' => $message];
    }

    public function ajax_rotate_site() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['alias'] ?? '');
        if ($alias === '') wp_send_json_error('Alias is required');

        $result = $this->rotate_site($alias);
        if (!empty($result['ok'])) {
            wp_send_json_success($result);
        }
        wp_send_json_error($result['message'] ?? 'Rotation failed');
    }

    public function ajax_save_site() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['alias'] ?? '');
        if (empty($alias)) wp_send_json_error('Alias is required');

        $sites = $this->get_sites();
        $existed = isset($sites[$alias]);
        $prev = $sites[$alias] ?? [];
        $sites[$alias] = [
            'url'        => esc_url_raw($_POST['url'] ?? ''),
            'key'        => sanitize_text_field($_POST['key'] ?? ''),
            'secret'     => sanitize_text_field($_POST['secret'] ?? ''),
            'local_site' => sanitize_text_field($_POST['local_site'] ?? ''),
            'theme'      => sanitize_text_field($_POST['theme'] ?? ''),
            // Preserve the last known connection state when editing an existing
            // connection; Test re-validates. New connections start unknown.
            'status'     => $prev['status'] ?? 'unknown',
            'last_check' => $prev['last_check'] ?? null,
        ];
        $this->save_sites($sites);

        $this->add_log('config', $alias, $existed ? 'Site configuration updated' : 'Site configuration saved');
        wp_send_json_success('Site saved');
    }

    /**
     * Bulk-edit every connection at once by replacing the dbc_sites option
     * with a hand-edited JSON document (the "JSON" tab). Each entry is
     * sanitized field-by-field; status/last_check are carried over from the
     * matching existing connection when the JSON omits them.
     */
    public function ajax_save_sites_json() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $raw = isset($_POST['json']) ? wp_unslash($_POST['json']) : '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            wp_send_json_error('Invalid JSON: ' . (json_last_error_msg() ?: 'expected an object of connections keyed by alias'));
        }

        $existing = $this->get_sites();
        $clean = [];
        foreach ($decoded as $alias => $cfg) {
            $alias = sanitize_key($alias);
            if ($alias === '' || !is_array($cfg)) continue;
            $prev = $existing[$alias] ?? [];
            $clean[$alias] = [
                'url'        => esc_url_raw($cfg['url'] ?? ($prev['url'] ?? '')),
                'key'        => sanitize_text_field($cfg['key'] ?? ($prev['key'] ?? '')),
                'secret'     => sanitize_text_field($cfg['secret'] ?? ($prev['secret'] ?? '')),
                'local_site' => sanitize_text_field($cfg['local_site'] ?? ($prev['local_site'] ?? '')),
                'theme'      => sanitize_text_field($cfg['theme'] ?? ($prev['theme'] ?? '')),
                'status'     => sanitize_text_field($cfg['status'] ?? ($prev['status'] ?? 'unknown')),
                'last_check' => array_key_exists('last_check', $cfg)
                    ? ($cfg['last_check'] !== null ? sanitize_text_field((string) $cfg['last_check']) : null)
                    : ($prev['last_check'] ?? null),
            ];
        }

        if (empty($clean)) {
            wp_send_json_error('No valid connections found. Each entry must be an object keyed by alias with at least a url.');
        }

        $this->save_sites($clean);
        $this->add_log('config', '(bulk)', 'Connections bulk-edited via JSON (' . count($clean) . ' ' . (count($clean) === 1 ? 'site' : 'sites') . ')');
        wp_send_json_success(['count' => count($clean)]);
    }

    public function ajax_delete_site() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['alias'] ?? '');
        $sites = $this->get_sites();
        unset($sites[$alias]);
        $this->save_sites($sites);

        $this->add_log('config', $alias, 'Site removed');
        wp_send_json_success('Deleted');
    }

    public function ajax_push() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['target'] ?? '');
        $sites = $this->get_sites();
        if (empty($sites[$alias])) wp_send_json_error('Target site not found');

        $config = $sites[$alias];
        $local_site = $config['local_site'] ?: '';
        if (empty($local_site)) {
            wp_send_json_error('No local site configured for this target. Edit the site in the Sites tab.');
        }
        $wp_content = self::resolve_wp_content($local_site);

        if (!$wp_content || !is_dir($wp_content)) {
            wp_send_json_error("Local wp-content not found: {$wp_content}");
        }

        $paths = [];
        $what = $_POST['what'] ?? [];
        if (!is_array($what)) $what = [$what];

        foreach ($what as $item) {
            switch ($item) {
                case 'mu-plugins':
                    $paths[] = 'mu-plugins';
                    break;
                case 'plugins':
                    $paths[] = 'plugins';
                    break;
                case 'theme':
                    $theme = $config['theme'] ?: '';
                    if ($theme) $paths[] = "themes/{$theme}";
                    break;
                case 'custom':
                    $custom = sanitize_text_field($_POST['custom_paths'] ?? '');
                    if ($custom) {
                        foreach (explode(',', $custom) as $p) {
                            $p = trim($p);
                            if ($p && strpos($p, '..') === false) {
                                $paths[] = $p;
                            }
                        }
                    }
                    break;
            }
        }

        if (empty($paths)) wp_send_json_error('Nothing to push — select at least one item');

        $zipfile = $this->package_files($wp_content, $paths);
        if (!$zipfile) wp_send_json_error('Failed to create zip package');

        $result = $this->api_request($config, 'POST', 'push', $zipfile, true);
        @unlink($zipfile);

        if (isset($result['error'])) {
            $this->add_log('push', $alias, 'Push failed: ' . $result['error'], 0, 'error');
            wp_send_json_error($result['error']);
        }

        $file_count = $result['files'] ?? 0;
        $this->add_log('push', $alias, "Pushed " . implode(', ', $paths), $file_count, 'success');
        wp_send_json_success([
            'files'    => $file_count,
            'manifest' => $result['manifest'] ?? [],
            'paths'    => $paths,
        ]);
    }

    public function ajax_pull() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['source'] ?? '');
        $sites = $this->get_sites();
        if (empty($sites[$alias])) wp_send_json_error('Source site not found');

        $config = $sites[$alias];
        $local_site = $config['local_site'] ?: '';
        if (empty($local_site)) {
            wp_send_json_error('No local site configured for this source. Edit the site in the Sites tab.');
        }
        $wp_content = self::resolve_wp_content($local_site);
        if (!$wp_content || !is_dir($wp_content)) {
            wp_send_json_error("Local wp-content not found for site folder: {$local_site}");
        }

        $what = sanitize_key($_POST['what'] ?? 'mu-plugins');

        if ($what === 'partials') {
            // Pull partials as JSON
            $result = $this->api_request($config, 'POST', 'pull', ['type' => 'partials']);

            if (isset($result['error'])) {
                $this->add_log('pull', $alias, 'Pull partials failed: ' . $result['error'], 0, 'error');
                wp_send_json_error($result['error']);
            }

            $count = $result['count'] ?? 0;
            // Save for reference — use WP uploads dir (works anywhere, not just macOS).
            $upload = wp_upload_dir();
            $log_dir = trailingslashit($upload['basedir']) . 'bridge-engine-logs';
            if (!is_dir($log_dir)) wp_mkdir_p($log_dir);
            $outfile = $log_dir . "/pull-partials-{$alias}-" . date('Ymd-His') . '.json';
            file_put_contents($outfile, wp_json_encode($result['partials'] ?? [], JSON_PRETTY_PRINT));

            $this->add_log('pull', $alias, "Pulled {$count} partials", $count, 'success');
            wp_send_json_success([
                'count'    => $count,
                'saved_to' => $outfile,
                'partials' => array_map(function ($p) {
                    return ['title' => $p['post_title'], 'status' => $p['post_status']];
                }, $result['partials'] ?? []),
            ]);
        }

        // Pull files
        $dir = '';
        switch ($what) {
            case 'mu-plugins': $dir = 'mu-plugins'; break;
            case 'plugins':   $dir = 'plugins'; break;
            case 'theme':
                $dir = 'themes/' . ($config['theme'] ?: '');
                break;
            default:
                $dir = sanitize_text_field($_POST['custom_dir'] ?? '');
        }

        if (empty($dir)) wp_send_json_error('No directory specified for pull');

        $result = $this->api_request($config, 'POST', 'pull', ['type' => 'dir', 'dir' => $dir]);

        if (isset($result['error'])) {
            $this->add_log('pull', $alias, "Pull failed: " . $result['error'], 0, 'error');
            wp_send_json_error($result['error']);
        }

        if (isset($result['zip'])) {
            // Save zip and extract
            $tmpzip = wp_tempnam('dbc-pull-') . '.zip';
            file_put_contents($tmpzip, $result['zip']);

            $zip = new ZipArchive();
            if ($zip->open($tmpzip) === true) {
                $zip->extractTo($wp_content);
                $file_count = $result['files'] ?? $zip->numFiles;
                $zip->close();
                @unlink($tmpzip);

                $this->add_log('pull', $alias, "Pulled {$dir}", $file_count, 'success');
                wp_send_json_success(['files' => $file_count, 'dir' => $dir]);
            } else {
                @unlink($tmpzip);
                wp_send_json_error('Failed to extract downloaded zip');
            }
        }

        wp_send_json_error('Unexpected response format');
    }

    public function ajax_quick_action() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['site'] ?? '');
        $action = sanitize_key($_POST['quick_action'] ?? '');
        $sites = $this->get_sites();

        if (empty($sites[$alias])) wp_send_json_error('Site not found');

        $config = $sites[$alias];

        switch ($action) {
            case 'flush_cache':
                $result = $this->api_request($config, 'POST', 'eval', [
                    'action' => 'flush_cache',
                    'params' => [],
                ]);
                break;
            case 'get_partials':
                $result = $this->api_request($config, 'POST', 'eval', [
                    'action' => 'get_partials',
                    'params' => [],
                ]);
                break;
            case 'list_themes':
                $result = $this->api_request($config, 'POST', 'eval', [
                    'action' => 'list_themes',
                    'params' => [],
                ]);
                break;
            case 'publish_drafts':
                $result = $this->api_request($config, 'POST', 'eval', [
                    'action' => 'publish_drafts',
                    'params' => ['post_type' => 'lc_partial'],
                ]);
                break;
            default:
                wp_send_json_error('Unknown action: ' . $action);
        }

        if (isset($result['error'])) {
            $this->add_log('eval', $alias, "Action '{$action}' failed: " . $result['error'], 0, 'error');
            wp_send_json_error($result['error']);
        }

        $this->add_log('eval', $alias, "Action: {$action}", 0, 'success');
        wp_send_json_success($result);
    }

    public function ajax_clear_log() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        update_option('dbc_log', []);
        wp_send_json_success('Log cleared');
    }

    public function ajax_save_settings() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $settings = get_option('dbc_settings', []);
        $dir      = isset($_POST['local_sites_dir']) ? trim(wp_unslash($_POST['local_sites_dir'])) : '';

        // Empty = revert to default (auto-detect).
        if ($dir === '') {
            unset($settings['local_sites_dir']);
            update_option('dbc_settings', $settings);
            wp_send_json_success([
                'message'  => 'Reverted to auto-detected default.',
                'using'    => self::local_sites_dir(),
                'is_default' => true,
            ]);
        }

        // Validate: must be absolute and must exist.
        $is_absolute = ($dir[0] === '/') || (strlen($dir) > 1 && $dir[1] === ':');
        if (!$is_absolute) {
            wp_send_json_error('Path must be absolute (e.g. /Users/you/Local Sites or C:/Users/you/Local Sites).');
        }
        if (!is_dir($dir)) {
            wp_send_json_error("Directory does not exist: {$dir}");
        }

        $settings['local_sites_dir'] = rtrim($dir, '/\\');
        update_option('dbc_settings', $settings);

        // Count detected sites to report back.
        $count = count($this->get_local_site_folders());

        wp_send_json_success([
            'message' => "Saved. Detected {$count} " . ($count === 1 ? 'site' : 'sites') . " in this directory.",
            'using'   => $settings['local_sites_dir'],
            'count'   => $count,
        ]);
    }

    // =====================================================================
    // FULL CONTENT SYNC (bidirectional, between any two configured sites)
    // =====================================================================

    /**
     * Pulls /sync-export from source, posts /sync-import to target.
     * Both sides must have deploy-bridge mu-plugin v2.0.0+ installed.
     */
    public function ajax_full_sync() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $source_alias = sanitize_key($_POST['source'] ?? '');
        $target_alias = sanitize_key($_POST['target'] ?? '');
        $dry_run      = !empty($_POST['dry_run']);

        $sites = $this->get_sites();
        if (empty($sites[$source_alias])) wp_send_json_error('Source site not found');
        if (empty($sites[$target_alias])) wp_send_json_error('Target site not found');
        if ($source_alias === $target_alias) wp_send_json_error('Source and target must differ');

        $source = $sites[$source_alias];
        $target = $sites[$target_alias];

        // Post-type selection — defaults to all whitelisted types if none supplied.
        $post_types_in = isset($_POST['post_types']) && is_array($_POST['post_types'])
            ? array_map('sanitize_key', $_POST['post_types'])
            : ['post', 'page', 'attachment', 'nav_menu_item', 'lc_partial', 'wp_block', 'custom_css'];

        $t0 = microtime(true);

        // 1. Pull content payload from source
        $export = $this->api_request(
            $source,
            'POST',
            'sync-export',
            ['post_types' => $post_types_in],
            false,
            300
        );

        if (isset($export['error'])) {
            $this->add_log('sync', $source_alias, "Export failed: " . $export['error'], 0, 'error');
            wp_send_json_error('Source export failed: ' . $export['error']);
        }
        if (empty($export['posts']) && empty($export['options'])) {
            wp_send_json_error('Source returned an empty payload — is sync-export endpoint up to date? (deploy-bridge v2.0.0 required)');
        }

        // 2. Build import payload — same shape, plus URL replacement + dry_run flag
        $payload = [
            'post_types'         => $export['post_types'] ?? $post_types_in,
            'posts'              => $export['posts'] ?? [],
            'postmeta'           => $export['postmeta'] ?? [],
            'terms'              => $export['terms'] ?? [],
            'term_taxonomy'      => $export['term_taxonomy'] ?? [],
            'term_relationships' => $export['term_relationships'] ?? [],
            'termmeta'           => $export['termmeta'] ?? [],
            'options'            => $export['options'] ?? [],
            'replace_url_from'   => rtrim($source['url'], '/'),
            'replace_url_to'     => rtrim($target['url'], '/'),
            'dry_run'            => $dry_run,
        ];

        // 3. Push to target
        $import = $this->api_request($target, 'POST', 'sync-import', $payload, false, 300);

        if (isset($import['error'])) {
            $this->add_log('sync', $target_alias, "Import failed: " . $import['error'], 0, 'error');
            wp_send_json_error('Target import failed: ' . $import['error']);
        }

        $stats = $import['stats'] ?? [];
        $elapsed = round(microtime(true) - $t0, 1);

        $verb = $dry_run ? 'Dry-run' : 'Synced';
        $summary = sprintf(
            '%s %s → %s (posts: %d, options: %d, terms: %d) in %ss',
            $verb,
            $source_alias,
            $target_alias,
            (int)($stats['posts_inserted'] ?? 0),
            (int)($stats['options_updated'] ?? 0),
            (int)($stats['terms_replaced'] ?? 0),
            $elapsed
        );

        $this->add_log('sync', "{$source_alias} → {$target_alias}", $summary,
            (int)($stats['posts_inserted'] ?? 0), 'success');

        wp_send_json_success([
            'source'      => $source_alias,
            'target'      => $target_alias,
            'dry_run'     => $dry_run,
            'stats'       => $stats,
            'elapsed_sec' => $elapsed,
            'summary'     => $summary,
        ]);
    }

    /**
     * Diffs uploads/ between source and target by sha1, then pulls the missing/changed
     * files from source and pushes them to target. Runs in batches to avoid PHP timeouts.
     */
    public function ajax_full_sync_uploads() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $source_alias = sanitize_key($_POST['source'] ?? '');
        $target_alias = sanitize_key($_POST['target'] ?? '');
        $batch_size   = max(1, min(200, intval($_POST['batch_size'] ?? 50)));

        $sites = $this->get_sites();
        if (empty($sites[$source_alias])) wp_send_json_error('Source site not found');
        if (empty($sites[$target_alias])) wp_send_json_error('Target site not found');

        $source = $sites[$source_alias];
        $target = $sites[$target_alias];

        $t0 = microtime(true);

        // 1. Get manifests from both sides
        $src_manifest = $this->api_request($source, 'GET', 'uploads-manifest', null, false, 120);
        if (isset($src_manifest['error'])) {
            wp_send_json_error('Source manifest failed: ' . $src_manifest['error']);
        }
        $tgt_manifest = $this->api_request($target, 'GET', 'uploads-manifest', null, false, 120);
        if (isset($tgt_manifest['error'])) {
            wp_send_json_error('Target manifest failed: ' . $tgt_manifest['error']);
        }

        // 2. Build diff: files in source that target doesn't have OR has with different sha1
        $tgt_index = [];
        foreach (($tgt_manifest['files'] ?? []) as $f) {
            $tgt_index[$f['path']] = $f['sha1'];
        }

        $to_transfer = [];
        foreach (($src_manifest['files'] ?? []) as $f) {
            if (!isset($tgt_index[$f['path']]) || $tgt_index[$f['path']] !== $f['sha1']) {
                $to_transfer[] = 'uploads/' . $f['path'];
            }
        }

        if (empty($to_transfer)) {
            $elapsed = round(microtime(true) - $t0, 1);
            $this->add_log('sync', "{$source_alias} → {$target_alias}", "Uploads already in sync ({$elapsed}s)", 0, 'success');
            wp_send_json_success([
                'transferred' => 0,
                'source_count' => count($src_manifest['files'] ?? []),
                'target_count' => count($tgt_manifest['files'] ?? []),
                'elapsed_sec'  => $elapsed,
                'summary'      => 'Uploads already in sync.',
            ]);
        }

        // 3. Transfer in batches: pull from source as zip → push to target
        $transferred = 0;
        $errors = [];
        foreach (array_chunk($to_transfer, $batch_size) as $batch) {
            $pull = $this->api_request($source, 'POST', 'pull', [
                'type'  => 'files',
                'paths' => $batch,
            ], false, 300);

            if (isset($pull['error'])) {
                $errors[] = 'Pull batch failed: ' . $pull['error'];
                break;
            }
            if (empty($pull['zip'])) {
                $errors[] = 'Pull batch returned no zip';
                break;
            }

            $tmpzip = wp_tempnam('dbc-uploads-sync-') . '.zip';
            if (false === file_put_contents($tmpzip, $pull['zip'])) {
                $errors[] = 'Failed to write temp zip';
                break;
            }

            $push = $this->api_request($target, 'POST', 'push', $tmpzip, true, 300);
            @unlink($tmpzip);

            if (isset($push['error'])) {
                $errors[] = 'Push batch failed: ' . $push['error'];
                break;
            }
            $transferred += (int)($push['files'] ?? count($batch));
        }

        $elapsed = round(microtime(true) - $t0, 1);
        if (!empty($errors)) {
            $this->add_log('sync', "{$source_alias} → {$target_alias}",
                "Uploads partial: {$transferred} transferred, errors: " . implode('; ', $errors),
                $transferred, 'error');
            wp_send_json_error([
                'message'     => implode('; ', $errors),
                'transferred' => $transferred,
                'elapsed_sec' => $elapsed,
            ]);
        }

        $this->add_log('sync', "{$source_alias} → {$target_alias}",
            "Uploads synced: {$transferred} files in {$elapsed}s",
            $transferred, 'success');

        wp_send_json_success([
            'transferred'  => $transferred,
            'source_count' => count($src_manifest['files'] ?? []),
            'target_count' => count($tgt_manifest['files'] ?? []),
            'elapsed_sec'  => $elapsed,
            'summary'      => "Transferred {$transferred} file(s) in {$elapsed}s.",
        ]);
    }

    // =====================================================================
    // PAGE RENDERER
    // =====================================================================

    public function ajax_sync_restore() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['target'] ?? '');
        $sites = $this->get_sites();
        if (empty($sites[$alias])) wp_send_json_error('Site not found: ' . $alias);

        // /sync-restore takes no params (it restores the target's latest rollback);
        // send an empty JSON body so the HMAC signature validates.
        $result = $this->api_request($sites[$alias], 'POST', 'sync-restore', '{}');

        if (isset($result['error'])) {
            $this->add_log('restore', $alias, 'Rollback failed: ' . $result['error'], 0, 'error');
            wp_send_json_error($result['error']);
        }
        $this->add_log('restore', $alias, 'Rolled back to ' . ($result['restored_from'] ?? 'last snapshot'), 0, 'success');
        wp_send_json_success($result);
    }

    public function render_page() {
        $tab = sanitize_key($_GET['tab'] ?? 'sites');
        $sites = $this->get_sites();
        $local_folders = $this->get_local_site_folders();
        ?>
        <div class="wrap" id="dbc-app">
            <h1>Bridge Engine <small style="color:#999;">v<?php echo DBC_VERSION; ?></small></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=bridge-engine&tab=sites" class="nav-tab <?php echo $tab === 'sites' ? 'nav-tab-active' : ''; ?>">Sites</a>
                <a href="?page=bridge-engine&tab=fullsync" class="nav-tab <?php echo $tab === 'fullsync' ? 'nav-tab-active' : ''; ?>">Full Sync</a>
                <a href="?page=bridge-engine&tab=sync" class="nav-tab <?php echo $tab === 'sync' ? 'nav-tab-active' : ''; ?>">Push / Pull</a>
                <a href="?page=bridge-engine&tab=log" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Log</a>
                <a href="?page=bridge-engine&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=bridge-engine&tab=json" class="nav-tab <?php echo $tab === 'json' ? 'nav-tab-active' : ''; ?>">JSON</a>
            </nav>

            <div class="dbc-tab-content" style="margin-top:20px;">
                <?php
                switch ($tab) {
                    case 'sites':    $this->render_sites_tab($sites, $local_folders); break;
                    case 'fullsync': $this->render_fullsync_tab($sites); break;
                    case 'sync':     $this->render_sync_tab($sites, $local_folders); break;
                    case 'log':      $this->render_log_tab(); break;
                    case 'settings': $this->render_settings_tab(); break;
                    case 'json':     $this->render_json_tab($sites); break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    // --- SITES TAB ---
    private function render_sites_tab($sites, $local_folders) {
        ?>
        <h2>Staging Sites</h2>

        <?php if (!empty($sites)) : ?>
        <table class="widefat striped" id="dbc-sites-table">
            <thead>
                <tr>
                    <th>Alias</th>
                    <th>URL</th>
                    <th>Local Site</th>
                    <th>Theme</th>
                    <th>Status</th>
                    <th>Last Check</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sites as $alias => $s) :
                    $status_color = ($s['status'] ?? 'unknown') === 'connected' ? '#2ecc40' : '#aaa';
                ?>
                <tr data-alias="<?php echo esc_attr($alias); ?>">
                    <td><strong><?php echo esc_html($alias); ?></strong></td>
                    <td><code style="font-size:11px;"><?php echo esc_html($s['url'] ?? ''); ?></code></td>
                    <td><?php echo esc_html($s['local_site'] ?? '-'); ?></td>
                    <td><?php echo esc_html($s['theme'] ?? '-'); ?></td>
                    <td><span class="dbc-status-dot" style="background:<?php echo $status_color; ?>;"></span> <?php echo esc_html($s['status'] ?? 'unknown'); ?></td>
                    <td><?php echo esc_html($s['last_check'] ?? 'Never'); ?></td>
                    <td>
                        <button class="button button-small dbc-test-btn" data-alias="<?php echo esc_attr($alias); ?>">Test</button>
                        <button class="button button-small dbc-rotate-btn" data-alias="<?php echo esc_attr($alias); ?>" title="Generate a fresh unique key+secret for this site and push it to the receiver (safe: old creds kept until the new pair is verified)">Rotate&nbsp;Keys</button>
                        <button class="button button-small dbc-edit-btn"
                                data-alias="<?php echo esc_attr($alias); ?>"
                                data-url="<?php echo esc_attr($s['url'] ?? ''); ?>"
                                data-key="<?php echo esc_attr($s['key'] ?? ''); ?>"
                                data-secret="<?php echo esc_attr($s['secret'] ?? ''); ?>"
                                data-local="<?php echo esc_attr($s['local_site'] ?? ''); ?>"
                                data-theme="<?php echo esc_attr($s['theme'] ?? ''); ?>">Edit</button>
                        <button class="button button-small dbc-delete-btn" data-alias="<?php echo esc_attr($alias); ?>" style="color:#c00;">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <hr>
        <h3 id="dbc-form-heading">Add / Edit Site <span id="dbc-edit-mode" style="font-size:13px;font-weight:400;"></span></h3>
        <table class="form-table" id="dbc-add-site-form">
            <tr><th>Alias</th><td><input type="text" id="dbc-alias" class="regular-text" placeholder="probate-v2"></td></tr>
            <tr><th>Staging URL</th><td><input type="url" id="dbc-url" class="regular-text" placeholder="https://probate-2026-v2.inetdemo1.com/staging"></td></tr>
            <tr><th>API Key</th><td><input type="text" id="dbc-key" class="regular-text" placeholder="From Tools > Deploy Bridge > Connection tab on staging"></td></tr>
            <tr><th>Shared Secret</th><td><input type="text" id="dbc-secret" class="large-text" placeholder="From Tools > Deploy Bridge > Connection tab on staging"></td></tr>
            <tr>
                <th>Local Site</th>
                <td>
                    <select id="dbc-local-site">
                        <option value="">-- Select --</option>
                        <?php foreach ($local_folders as $f) : ?>
                            <option value="<?php echo esc_attr($f); ?>"><?php echo esc_html($f); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr><th>Theme Slug</th><td><input type="text" id="dbc-theme" class="regular-text" placeholder="picostrap5-probate-child"></td></tr>
        </table>
        <p><button class="button button-primary" id="dbc-save-site-btn">Save Site</button> <span id="dbc-save-result"></span></p>
        <?php
    }

    // --- FULL SYNC TAB (content + appearance, bidirectional) ---
    private function render_fullsync_tab($sites) {
        if (count($sites) < 2) {
            echo '<div class="notice notice-warning"><p>You need at least <strong>two</strong> sites configured (a source and a target) to run a full sync. Add them in the Sites tab. Both sites must have <code>deploy-bridge.php</code> mu-plugin v2.0.0+ installed.</p></div>';
            return;
        }
        ?>
        <div style="max-width:760px;">
            <h2>Full Content Sync</h2>
            <p style="color:#555;">
                Pulls posts, pages, menus, partials, terms, and whitelisted appearance options
                (theme_mods, widgets, permalinks, page-builder settings) from the <strong>source</strong>
                site and applies them to the <strong>target</strong>. URLs are rewritten automatically
                (source → target). Target's DB is snapshotted to <code>uploads/bridge-backups/</code>
                before any change. Comments, users, plugin activation, and site identity options are never touched.
            </p>

            <table class="form-table">
                <tr>
                    <th><label for="dbc-fs-source">Source (pull from)</label></th>
                    <td>
                        <select id="dbc-fs-source" style="min-width:300px;">
                            <?php foreach ($sites as $alias => $s) : ?>
                                <option value="<?php echo esc_attr($alias); ?>"><?php echo esc_html($alias . ' — ' . ($s['url'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="dbc-fs-target">Target (push to)</label></th>
                    <td>
                        <select id="dbc-fs-target" style="min-width:300px;">
                            <?php $i = 0; foreach ($sites as $alias => $s) : ?>
                                <option value="<?php echo esc_attr($alias); ?>" <?php echo $i++ === 1 ? 'selected' : ''; ?>><?php echo esc_html($alias . ' — ' . ($s['url'] ?? '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Post types to sync</th>
                    <td>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="post" checked> Posts</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="page" checked> Pages</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="nav_menu_item" checked> Menus</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="lc_partial" checked> LC Partials</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="wp_block" checked> Reusable Blocks</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="custom_css" checked> Custom CSS</label>
                        <label style="display:inline-block;margin-right:14px;"><input type="checkbox" class="dbc-fs-pt" value="attachment" checked> Attachments (DB rows)</label>
                        <p class="description" style="margin-top:8px;">Categories, tags, term meta, and whitelisted appearance options (theme_mods, widgets, etc.) are always included.</p>
                    </td>
                </tr>
                <tr>
                    <th>Uploads files</th>
                    <td>
                        <label><input type="checkbox" id="dbc-fs-uploads" checked> Sync media files in <code>wp-content/uploads/</code> (diff by sha1, only changed files transferred)</label>
                    </td>
                </tr>
            </table>

            <p style="margin-top:20px;">
                <button class="button" id="dbc-fs-dryrun-btn">Dry Run</button>
                <button class="button button-primary" id="dbc-fs-run-btn" style="margin-left:8px;">Run Full Sync</button>
                <button class="button" id="dbc-fs-rollback-btn" style="margin-left:8px;color:#b32d2e;border-color:#b32d2e;" title="Restore the target to the state right before its last sync">↺ Roll Back Target</button>
                <span id="dbc-fs-result" style="margin-left:14px;"></span>
            </p>

            <div id="dbc-fs-progress" style="display:none;margin-top:16px;padding:14px 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;font-family:Menlo,Monaco,monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;"></div>
        </div>
        <?php
    }

    // --- SYNC TAB ---
    private function render_sync_tab($sites, $local_folders) {
        if (empty($sites)) {
            echo '<div class="notice notice-warning"><p>No staging sites configured. Add one in the Sites tab first.</p></div>';
            return;
        }
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;">
            <div>
                <h2>Push to Staging</h2>
                <table class="form-table">
                    <tr>
                        <th>Target Site</th>
                        <td>
                            <select id="dbc-push-target">
                                <?php foreach ($sites as $alias => $s) : ?>
                                    <option value="<?php echo esc_attr($alias); ?>"><?php echo esc_html($alias . ' — ' . ($s['url'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>What to Push</th>
                        <td>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" class="dbc-push-what" value="mu-plugins" checked> mu-plugins/</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" class="dbc-push-what" value="plugins"> plugins/ (full plugins folder)</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" class="dbc-push-what" value="theme"> Child theme</label>
                            <label style="display:block;margin-bottom:4px;"><input type="checkbox" class="dbc-push-what" value="custom"> Custom paths:</label>
                            <input type="text" id="dbc-push-custom" class="regular-text" placeholder="themes/picostrap5/file.php, plugins/my-plugin/" style="margin-top:4px;">
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" id="dbc-push-btn">Push to Staging</button>
                    <span id="dbc-push-result" style="margin-left:10px;"></span>
                </p>
            </div>

            <div>
                <h2>Pull from Staging</h2>
                <table class="form-table">
                    <tr>
                        <th>Source Site</th>
                        <td>
                            <select id="dbc-pull-source">
                                <?php foreach ($sites as $alias => $s) : ?>
                                    <option value="<?php echo esc_attr($alias); ?>"><?php echo esc_html($alias . ' — ' . ($s['url'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>What to Pull</th>
                        <td>
                            <select id="dbc-pull-what">
                                <option value="mu-plugins">mu-plugins/</option>
                                <option value="plugins">plugins/ (full plugins folder)</option>
                                <option value="theme">Child theme</option>
                                <option value="partials">LC Partials (DB content)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p>
                    <button class="button button-primary" id="dbc-pull-btn">Pull from Staging</button>
                    <span id="dbc-pull-result" style="margin-left:10px;"></span>
                </p>
            </div>
        </div>

        <hr>
        <h2>Quick Actions</h2>
        <p class="description">Select a staging site and run a one-click action:</p>
        <div style="display:flex;gap:8px;align-items:center;margin-top:10px;">
            <select id="dbc-qa-site">
                <?php foreach ($sites as $alias => $s) : ?>
                    <option value="<?php echo esc_attr($alias); ?>"><?php echo esc_html($alias); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button dbc-qa-btn" data-action="flush_cache">Flush Cache</button>
            <button class="button dbc-qa-btn" data-action="get_partials">List Partials</button>
            <button class="button dbc-qa-btn" data-action="list_themes">List Themes</button>
            <button class="button dbc-qa-btn" data-action="publish_drafts">Publish Draft Partials</button>
            <span id="dbc-qa-result" style="margin-left:10px;"></span>
        </div>
        <pre id="dbc-qa-output" style="background:#1d2327;color:#50c878;padding:15px;border-radius:4px;margin-top:15px;max-height:300px;overflow:auto;display:none;font-size:12px;"></pre>
        <?php
    }

    // --- LOG TAB ---
    private function render_log_tab() {
        $log = $this->get_log();
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2>Deploy Log</h2>
            <button class="button button-small" id="dbc-clear-log-btn">Clear Log</button>
        </div>
        <table class="widefat striped">
            <thead>
                <tr><th>Time</th><th>Direction</th><th>Site</th><th>Summary</th><th>Files</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php if (empty($log)) : ?>
                    <tr><td colspan="6" style="text-align:center;color:#999;">No operations logged yet.</td></tr>
                <?php else : ?>
                    <?php foreach ($log as $entry) :
                        $icon = ['push' => '&uarr;', 'pull' => '&darr;', 'test' => '&harr;', 'eval' => '&crarr;', 'config' => '&equiv;'][$entry['direction']] ?? '&bull;';
                        $status_color = $entry['status'] === 'success' ? 'green' : 'red';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html($entry['time']); ?></td>
                        <td><?php echo $icon; ?> <?php echo esc_html($entry['direction']); ?></td>
                        <td><strong><?php echo esc_html($entry['site']); ?></strong></td>
                        <td><?php echo esc_html($entry['summary']); ?></td>
                        <td><?php echo intval($entry['files']); ?></td>
                        <td><span style="color:<?php echo $status_color; ?>;font-weight:bold;"><?php echo esc_html($entry['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    // --- SETTINGS TAB ---
    private function render_settings_tab() {
        $settings          = get_option('dbc_settings', []);
        $current_dir       = self::local_sites_dir();
        $configured_dir    = $settings['local_sites_dir'] ?? '';
        $is_default        = empty($configured_dir);
        $dir_exists        = is_dir($current_dir);
        $detected_sites    = $this->get_local_site_folders();
        $site_count        = count($detected_sites);
        ?>
        <h2>⚙️ Bridge Engine Settings</h2>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="dbc-local-sites-dir">Local Sites Directory</label>
                </th>
                <td>
                    <input type="text" id="dbc-local-sites-dir" name="local_sites_dir"
                           class="large-text code"
                           value="<?php echo esc_attr($configured_dir); ?>"
                           placeholder="<?php echo esc_attr(DBC_LOCAL_SITES_DIR_DEFAULT); ?>">

                    <p class="description">
                        Absolute path to the directory containing your local WordPress sites.
                        Leave blank to use the auto-detected default.
                    </p>

                    <div style="margin-top:12px;padding:10px 14px;background:#f0f6fc;border-left:3px solid #2271b1;">
                        <strong>Currently using:</strong>
                        <code style="user-select:all;"><?php echo esc_html($current_dir); ?></code>
                        <?php if ($is_default) : ?>
                            <span style="color:#666;">(auto-detected default)</span>
                        <?php endif; ?>
                        <br>
                        <strong>Status:</strong>
                        <?php if ($dir_exists) : ?>
                            <span style="color:#00a32a;">✅ Directory exists.</span>
                            Detected <strong><?php echo $site_count; ?></strong>
                            WordPress <?php echo $site_count === 1 ? 'site' : 'sites'; ?>.
                        <?php else : ?>
                            <span style="color:#d63638;">❌ Directory not found.</span>
                            Push/pull will fail until this path is corrected.
                        <?php endif; ?>
                    </div>

                    <details style="margin-top:12px;">
                        <summary style="cursor:pointer;color:#2271b1;">Common paths for different environments</summary>
                        <ul style="margin:8px 0 0 20px;font-family:monospace;font-size:12px;">
                            <li><strong>Local by Flywheel (macOS):</strong> <code>/Users/YOU/Local Sites</code></li>
                            <li><strong>Local by Flywheel (Windows):</strong> <code>C:/Users/YOU/Local Sites</code></li>
                            <li><strong>Local Lightning:</strong> <code>/Users/YOU/Local Sites</code></li>
                            <li><strong>MAMP:</strong> <code>/Applications/MAMP/htdocs</code></li>
                            <li><strong>Laravel Valet:</strong> <code>/Users/YOU/Sites</code></li>
                            <li><strong>DDEV:</strong> <code>~/ddev-projects</code></li>
                            <li><strong>XAMPP:</strong> <code>/Applications/XAMPP/htdocs</code> (macOS) or <code>C:/xampp/htdocs</code> (Win)</li>
                        </ul>
                        <p style="margin:8px 0 0 20px;color:#666;">
                            Bridge Engine auto-detects the WordPress layout inside each site folder
                            (<code>site/app/public/wp-content</code>, <code>site/public/wp-content</code>,
                            or <code>site/wp-content</code>).
                        </p>
                    </details>

                    <?php if ($dir_exists && $site_count > 0) : ?>
                    <details style="margin-top:8px;">
                        <summary style="cursor:pointer;color:#2271b1;">
                            Detected sites (<?php echo $site_count; ?>)
                        </summary>
                        <ul style="margin:8px 0 0 20px;font-family:monospace;font-size:12px;columns:2;">
                            <?php foreach ($detected_sites as $s) : ?>
                                <li><?php echo esc_html($s); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <p>
            <button class="button button-primary" id="dbc-save-settings-btn">Save Settings</button>
            <span id="dbc-settings-result" style="margin-left:10px;"></span>
        </p>

        <hr style="margin:30px 0;">
        <h3>About Bridge Engine</h3>
        <p style="max-width:680px;">
            Bridge Engine is the local-side command center. It packages files from your local
            WordPress sites and pushes them to staging/production sites running the
            <strong>Deploy Bridge</strong> plugin via authenticated REST API calls.
        </p>
        <p style="max-width:680px;">
            <strong>Version:</strong> <?php echo esc_html(DBC_VERSION); ?><br>
            <strong>Plugin path:</strong> <code><?php echo esc_html(__FILE__); ?></code>
        </p>
        <?php
    }

    // --- JSON TAB (bulk edit every connection at once) ---
    private function render_json_tab($sites) {
        $json = wp_json_encode($sites, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) $json = "{\n}";
        ?>
        <h2>Connections JSON <small style="color:#999;">bulk edit</small></h2>
        <p style="max-width:820px;color:#555;">
            This is the raw <code>dbc_sites</code> option — every staging connection Bridge Engine knows about.
            Edit it directly to add, remove, rename, or bulk-update connections, then <strong>Save JSON</strong>.
            Each entry is keyed by its alias and needs <code>url</code>, <code>key</code>, <code>secret</code>,
            <code>local_site</code>, and <code>theme</code>. <code>status</code>/<code>last_check</code> are optional
            (carried over from the existing connection when omitted).
        </p>
        <div style="margin:0 0 14px;padding:10px 14px;background:#fcf9e8;border-left:3px solid #dba617;max-width:820px;">
            <strong>Heads up:</strong> saving <strong>replaces all connections</strong> with exactly what's below —
            an alias not present here is removed. This is separate from the CLI's
            <code>scripts/deploy-sites.json</code>; the two are not auto-synced.
        </div>
        <textarea id="dbc-json-editor" class="large-text code" spellcheck="false"
                  style="width:100%;max-width:820px;min-height:440px;font-family:Menlo,Monaco,monospace;font-size:12px;line-height:1.5;white-space:pre;overflow:auto;"><?php echo esc_textarea($json); ?></textarea>
        <p style="margin-top:12px;">
            <button class="button" id="dbc-json-format-btn">Reformat / Validate</button>
            <button class="button button-primary" id="dbc-json-save-btn" style="margin-left:8px;">Save JSON</button>
            <span id="dbc-json-result" style="margin-left:12px;"></span>
        </p>
        <?php
    }

    // =====================================================================
    // INLINE CSS
    // =====================================================================

    private function get_inline_css() {
        return '
        .dbc-status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:5px; }
        #dbc-app .form-table th { width:120px; padding:8px 10px; }
        #dbc-app .form-table td { padding:8px 10px; }
        ';
    }

    // =====================================================================
    // INLINE JS
    // =====================================================================

    private function get_inline_js() {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    var $ = jQuery;

    // Test connection
    $(document).on('click', '.dbc-test-btn', function() {
        var btn = $(this), alias = btn.data('alias');
        btn.prop('disabled', true).text('Testing...');
        $.post(dbc.ajax_url, { action: 'dbc_test_connection', nonce: dbc.nonce, alias: alias }, function(r) {
            btn.prop('disabled', false).text('Test');
            if (r.success) {
                var d = r.data;
                alert('Connected!\n\nWP ' + d.wp_version + ' / PHP ' + d.php_version + '\nTheme: ' + d.theme_name + '\nDisk: ' + d.disk_free);
                location.reload();
            } else {
                alert('Connection failed: ' + (r.data || 'Unknown error'));
            }
        }).fail(function() { btn.prop('disabled', false).text('Test'); alert('Request failed'); });
    });

    // Rotate keys — generate a fresh unique key+secret and push it to the receiver.
    $(document).on('click', '.dbc-rotate-btn', function() {
        var btn = $(this), alias = btn.data('alias');
        if (!confirm('Rotate credentials for "' + alias + '"?\n\nA fresh unique key+secret will be generated and pushed to the receiver. The OLD credentials are kept until the new pair is verified, so this is safe to retry.')) return;
        btn.prop('disabled', true).text('Rotating...');
        $.post(dbc.ajax_url, { action: 'dbc_rotate_site', nonce: dbc.nonce, alias: alias }, function(r) {
            btn.prop('disabled', false).html('Rotate&nbsp;Keys');
            if (r.success) {
                alert('✓ Rotated ' + alias + '\n\n' + (r.data.message || '') + '\nFingerprint: ' + (r.data.fp || '') + (r.data.atomic ? '\n(atomic, grace-window receiver)' : '\n(fallback path)'));
                location.reload();
            } else {
                alert('Rotation not completed for ' + alias + ':\n\n' + (r.data || 'Unknown error') + '\n\n(No credentials were lost — see the log.)');
            }
        }).fail(function(xhr) { btn.prop('disabled', false).html('Rotate&nbsp;Keys'); alert('Request failed (HTTP ' + xhr.status + '). The old credentials are unchanged.'); });
    });

    // Edit site — load an existing connection into the Add / Edit form.
    // Use .attr() (not .data()) so long numeric-looking keys/secrets are never
    // coerced to Numbers and lose precision.
    $(document).on('click', '.dbc-edit-btn', function() {
        var b = $(this), alias = b.attr('data-alias');
        $('#dbc-alias').val(alias).prop('readonly', true);
        $('#dbc-url').val(b.attr('data-url') || '');
        $('#dbc-key').val(b.attr('data-key') || '');
        $('#dbc-secret').val(b.attr('data-secret') || '');
        $('#dbc-local-site').val(b.attr('data-local') || '');
        $('#dbc-theme').val(b.attr('data-theme') || '');
        $('#dbc-save-site-btn').text('Update Site');
        $('#dbc-edit-mode').html('— editing <strong>' + alias + '</strong> <a href="#" id="dbc-cancel-edit" style="margin-left:6px;">cancel</a>');
        $('#dbc-save-result').empty();
        if ($('#dbc-add-site-form').length) {
            $('html,body').animate({ scrollTop: $('#dbc-add-site-form').offset().top - 60 }, 250);
        }
    });

    // Cancel edit — reset the form back to "add" mode
    $(document).on('click', '#dbc-cancel-edit', function(e) {
        e.preventDefault();
        $('#dbc-alias').val('').prop('readonly', false);
        $('#dbc-url, #dbc-key, #dbc-secret, #dbc-theme').val('');
        $('#dbc-local-site').val('');
        $('#dbc-save-site-btn').text('Save Site');
        $('#dbc-edit-mode').empty();
        $('#dbc-save-result').empty();
    });

    // Delete site
    $(document).on('click', '.dbc-delete-btn', function() {
        var alias = $(this).data('alias');
        if (!confirm('Remove staging site "' + alias + '"?')) return;
        $.post(dbc.ajax_url, { action: 'dbc_delete_site', nonce: dbc.nonce, alias: alias }, function() {
            location.reload();
        });
    });

    // JSON bulk edit — reformat/validate
    $('#dbc-json-format-btn').on('click', function() {
        var ta = $('#dbc-json-editor'), res = $('#dbc-json-result');
        try {
            ta.val(JSON.stringify(JSON.parse(ta.val()), null, 2));
            res.css('color', '#2271b1').text('Valid JSON ✓');
        } catch (e) {
            res.css('color', '#d63638').text('Invalid JSON: ' + e.message);
        }
    });

    // JSON bulk edit — save (replaces all connections)
    $('#dbc-json-save-btn').on('click', function() {
        var btn = $(this), ta = $('#dbc-json-editor'), res = $('#dbc-json-result');
        var val = ta.val();
        try { JSON.parse(val); }
        catch (e) { res.css('color', '#d63638').text('Invalid JSON: ' + e.message); return; }
        if (!confirm('Replace ALL Bridge Engine connections with this JSON?\n\nAny alias not present below will be removed.')) return;
        btn.prop('disabled', true);
        res.css('color', '#666').text('Saving…');
        $.post(dbc.ajax_url, { action: 'dbc_save_sites_json', nonce: dbc.nonce, json: val }, function(r) {
            btn.prop('disabled', false);
            if (r.success) {
                res.css('color', '#00a32a').text('Saved ' + r.data.count + ' connection' + (r.data.count === 1 ? '' : 's') + '. Reloading…');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                res.css('color', '#d63638').text(r.data || 'Save failed');
            }
        }).fail(function(xhr) {
            btn.prop('disabled', false);
            res.css('color', '#d63638').text('Request failed (HTTP ' + xhr.status + ')');
        });
    });

    // Save site
    $('#dbc-save-site-btn').on('click', function() {
        var btn = $(this);
        var result = $('#dbc-save-result');
        btn.prop('disabled', true);
        result.html('<span style="color:#666;">Saving...</span>');
        $.post(dbc.ajax_url, {
            action: 'dbc_save_site', nonce: dbc.nonce,
            alias: $('#dbc-alias').val(),
            url: $('#dbc-url').val(),
            key: $('#dbc-key').val(),
            secret: $('#dbc-secret').val(),
            local_site: $('#dbc-local-site').val(),
            theme: $('#dbc-theme').val(),
        }, function(r) {
            result.html(r.success
                ? '<span style="color:green;">Saved!</span>'
                : '<span style="color:red;">' + (r.data || 'Error') + '</span>');
            if (r.success) setTimeout(function() { location.reload(); }, 500);
        }).fail(function(xhr) {
            result.html('<span style="color:red;">Request failed (HTTP ' + xhr.status + '). Reload the page and try again — nonce may have expired.</span>');
        }).always(function() {
            btn.prop('disabled', false);
        });
    });

    // Push
    $('#dbc-push-btn').on('click', function() {
        var btn = $(this), result = $('#dbc-push-result');
        var what = [];
        $('.dbc-push-what:checked').each(function() { what.push($(this).val()); });
        if (!what.length) { result.html('<span style="color:red;">Select something to push</span>'); return; }

        btn.prop('disabled', true);
        result.html('<span style="color:#666;">Pushing...</span>');

        $.post(dbc.ajax_url, {
            action: 'dbc_push', nonce: dbc.nonce,
            target: $('#dbc-push-target').val(),
            what: what,
            custom_paths: $('#dbc-push-custom').val(),
        }, function(r) {
            btn.prop('disabled', false);
            if (r.success) {
                result.html('<span style="color:green;">Pushed ' + r.data.files + ' files</span>');
            } else {
                result.html('<span style="color:red;">Failed: ' + r.data + '</span>');
            }
        }).fail(function() { btn.prop('disabled', false); result.html('<span style="color:red;">Request failed</span>'); });
    });

    // Pull
    $('#dbc-pull-btn').on('click', function() {
        var btn = $(this), result = $('#dbc-pull-result');
        btn.prop('disabled', true);
        result.html('<span style="color:#666;">Pulling...</span>');

        $.post(dbc.ajax_url, {
            action: 'dbc_pull', nonce: dbc.nonce,
            source: $('#dbc-pull-source').val(),
            what: $('#dbc-pull-what').val(),
        }, function(r) {
            btn.prop('disabled', false);
            if (r.success) {
                var msg = r.data.files ? 'Pulled ' + r.data.files + ' files' : 'Pulled ' + (r.data.count || 0) + ' items';
                result.html('<span style="color:green;">' + msg + '</span>');
            } else {
                result.html('<span style="color:red;">Failed: ' + r.data + '</span>');
            }
        }).fail(function() { btn.prop('disabled', false); result.html('<span style="color:red;">Request failed</span>'); });
    });

    // Quick actions
    $(document).on('click', '.dbc-qa-btn', function() {
        var btn = $(this), actionName = btn.data('action');
        var result = $('#dbc-qa-result'), output = $('#dbc-qa-output');
        btn.prop('disabled', true);
        result.html('<span style="color:#666;">Running...</span>');

        $.post(dbc.ajax_url, {
            action: 'dbc_quick_action', nonce: dbc.nonce,
            site: $('#dbc-qa-site').val(),
            quick_action: actionName,
        }, function(r) {
            btn.prop('disabled', false);
            if (r.success) {
                result.html('<span style="color:green;">Done</span>');
                output.show().text(JSON.stringify(r.data.result || r.data, null, 2));
            } else {
                result.html('<span style="color:red;">Failed: ' + r.data + '</span>');
                output.hide();
            }
        }).fail(function() { btn.prop('disabled', false); result.html('<span style="color:red;">Request failed</span>'); });
    });

    // Clear log
    $('#dbc-clear-log-btn').on('click', function() {
        if (!confirm('Clear all log entries?')) return;
        $.post(dbc.ajax_url, { action: 'dbc_clear_log', nonce: dbc.nonce }, function() { location.reload(); });
    });

    // Save settings
    $('#dbc-save-settings-btn').on('click', function() {
        var btn    = $(this),
            result = $('#dbc-settings-result'),
            dir    = $('#dbc-local-sites-dir').val();
        btn.prop('disabled', true).text('Saving...');
        result.text('').css('color', '');
        $.post(dbc.ajax_url, {
            action: 'dbc_save_settings',
            nonce:  dbc.nonce,
            local_sites_dir: dir
        }, function(r) {
            btn.prop('disabled', false).text('Save Settings');
            if (r.success) {
                result.css('color', '#00a32a').text('✅ ' + r.data.message);
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                result.css('color', '#d63638').text('❌ ' + r.data);
            }
        }).fail(function() {
            btn.prop('disabled', false).text('Save Settings');
            result.css('color', '#d63638').text('❌ Network error.');
        });
    });

    // ---- Full Sync ----
    function fsCollectPostTypes() {
        var pts = [];
        $('.dbc-fs-pt:checked').each(function() { pts.push($(this).val()); });
        return pts;
    }

    function fsValidate() {
        var src = $('#dbc-fs-source').val(),
            tgt = $('#dbc-fs-target').val();
        if (!src || !tgt) return 'Pick source and target';
        if (src === tgt)  return 'Source and target must differ';
        if (fsCollectPostTypes().length === 0) return 'Select at least one post type';
        return null;
    }

    function fsLog(line) {
        var box = $('#dbc-fs-progress');
        box.show();
        box.append(line + '\n');
        box.scrollTop(box[0].scrollHeight);
    }

    function fsRun(dryRun) {
        var err = fsValidate();
        if (err) { $('#dbc-fs-result').html('<span style="color:#d63638;">' + err + '</span>'); return; }

        var src    = $('#dbc-fs-source').val(),
            tgt    = $('#dbc-fs-target').val(),
            pts    = fsCollectPostTypes(),
            doUpl  = $('#dbc-fs-uploads').is(':checked'),
            $btnD  = $('#dbc-fs-dryrun-btn'),
            $btnR  = $('#dbc-fs-run-btn'),
            $res   = $('#dbc-fs-result'),
            $box   = $('#dbc-fs-progress');

        $btnD.prop('disabled', true);
        $btnR.prop('disabled', true);
        $box.empty().show();
        $res.html('<span style="color:#666;">Running...</span>');

        fsLog((dryRun ? '[DRY RUN] ' : '') + 'Pulling content payload from ' + src + ' …');

        $.post(dbc.ajax_url, {
            action: 'dbc_full_sync',
            nonce:  dbc.nonce,
            source: src,
            target: tgt,
            post_types: pts,
            dry_run: dryRun ? 1 : 0,
        }, function(r) {
            if (!r.success) {
                fsLog('ERROR: ' + (r.data || 'unknown'));
                $res.html('<span style="color:#d63638;">Failed</span>');
                $btnD.prop('disabled', false);
                $btnR.prop('disabled', false);
                return;
            }
            var s = r.data.stats || {};
            fsLog('Posts inserted:    ' + (s.posts_inserted    || 0));
            fsLog('Postmeta inserted: ' + (s.postmeta_inserted || 0));
            fsLog('Terms replaced:    ' + (s.terms_replaced    || 0));
            fsLog('Options updated:   ' + (s.options_updated   || 0));
            if (s.options_skipped && s.options_skipped.length) {
                fsLog('Options skipped (' + s.options_skipped.length + '): ' + s.options_skipped.slice(0, 8).join(', ') + (s.options_skipped.length > 8 ? ' …' : ''));
            }
            fsLog('URL replace affected rows: ' + (s.replace_affected || 0));
            if (s.snapshot) fsLog('Snapshot saved: ' + s.snapshot);
            fsLog('Content stage done in ' + r.data.elapsed_sec + 's.');

            if (dryRun) {
                fsLog('[DRY RUN COMPLETE — no changes made]');
                $res.html('<span style="color:#2271b1;">Dry run OK</span>');
                $btnD.prop('disabled', false);
                $btnR.prop('disabled', false);
                return;
            }

            if (!doUpl) {
                fsLog('Skipping uploads sync (unchecked).');
                $res.html('<span style="color:#00a32a;">Sync complete</span>');
                $btnD.prop('disabled', false);
                $btnR.prop('disabled', false);
                return;
            }

            // Phase 2: uploads diff
            fsLog('---');
            fsLog('Diffing uploads …');
            $.post(dbc.ajax_url, {
                action: 'dbc_full_sync_uploads',
                nonce:  dbc.nonce,
                source: src,
                target: tgt,
                batch_size: 50,
            }, function(u) {
                if (!u.success) {
                    var msg = (u.data && u.data.message) || u.data || 'unknown';
                    fsLog('UPLOADS ERROR: ' + msg);
                    if (u.data && u.data.transferred) fsLog('Transferred before error: ' + u.data.transferred);
                    $res.html('<span style="color:#d63638;">Uploads failed</span>');
                } else {
                    fsLog('Source files: ' + u.data.source_count + ', Target files: ' + u.data.target_count);
                    fsLog('Transferred:  ' + u.data.transferred + ' files in ' + u.data.elapsed_sec + 's.');
                    fsLog('=== SYNC COMPLETE ===');
                    $res.html('<span style="color:#00a32a;">Sync complete</span>');
                }
                $btnD.prop('disabled', false);
                $btnR.prop('disabled', false);
            }).fail(function(xhr) {
                fsLog('UPLOADS REQUEST FAILED: HTTP ' + xhr.status);
                $res.html('<span style="color:#d63638;">Request failed</span>');
                $btnD.prop('disabled', false);
                $btnR.prop('disabled', false);
            });

        }).fail(function(xhr) {
            fsLog('REQUEST FAILED: HTTP ' + xhr.status);
            $res.html('<span style="color:#d63638;">Request failed</span>');
            $btnD.prop('disabled', false);
            $btnR.prop('disabled', false);
        });
    }

    $('#dbc-fs-rollback-btn').on('click', function() {
        var tgt = $('#dbc-fs-target').val();
        if (!tgt) { $('#dbc-fs-result').html('<span style="color:#d63638;">Pick a target first</span>'); return; }
        if (!confirm('Roll back "' + tgt + '" to the state right before its last sync?\n\nRestores the pre-sync snapshot on the target.')) return;
        var btn = $(this); btn.prop('disabled', true);
        $('#dbc-fs-result').html('<span style="color:#666;">Rolling back…</span>');
        $.post(dbc.ajax_url, { action: 'dbc_sync_restore', nonce: dbc.nonce, target: tgt }, function(r) {
            btn.prop('disabled', false);
            if (r.success) {
                var s = r.data.stats || {};
                $('#dbc-fs-result').html('<span style="color:#00a32a;">Rolled back — ' + (s.posts_inserted || 0) + ' posts restored (' + (r.data.restored_from || 'snapshot') + ')</span>');
            } else {
                $('#dbc-fs-result').html('<span style="color:#d63638;">Rollback failed: ' + (r.data || 'error') + '</span>');
            }
        }).fail(function(xhr) { btn.prop('disabled', false); $('#dbc-fs-result').html('<span style="color:#d63638;">Request failed (HTTP ' + xhr.status + ')</span>'); });
    });

    $('#dbc-fs-dryrun-btn').on('click', function() { fsRun(true); });
    $('#dbc-fs-run-btn').on('click', function() {
        var src = $('#dbc-fs-source').val(),
            tgt = $('#dbc-fs-target').val();
        if (!confirm('Sync content + appearance from "' + src + '" → "' + tgt + '"?\n\nTarget DB will be snapshotted to uploads/bridge-backups/ first, then synced post types will be wiped and replaced.')) return;
        fsRun(false);
    });
});
JS;
    }
}

// Initialize
DeployBridgeCommander::get_instance();
