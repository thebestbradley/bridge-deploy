<?php
/**
 * Plugin Name: Bridge Engine
 * Description: Local command center for managing staging site connections. Push/pull files, themes, mu-plugins, and DB content to staging sites via the Deploy Bridge API.
 * Version: 1.1.0
 * Author: HypedUp Studios
 * Text Domain: bridge-engine
 */

defined('ABSPATH') || exit;

define('DBC_VERSION', '1.1.0');

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
        add_action('wp_ajax_dbc_delete_site', [$this, 'ajax_delete_site']);
        add_action('wp_ajax_dbc_push', [$this, 'ajax_push']);
        add_action('wp_ajax_dbc_pull', [$this, 'ajax_pull']);
        add_action('wp_ajax_dbc_quick_action', [$this, 'ajax_quick_action']);
        add_action('wp_ajax_dbc_clear_log', [$this, 'ajax_clear_log']);
        add_action('wp_ajax_dbc_save_settings', [$this, 'ajax_save_settings']);
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
        wp_enqueue_style('dbc-style', false);
        wp_add_inline_style('dbc-style', $this->get_inline_css());
        wp_enqueue_script('dbc-script', false);
        wp_add_inline_script('dbc-script', $this->get_inline_js());
        wp_localize_script('dbc-script', 'dbc', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('dbc_nonce'),
        ]);
    }

    // =====================================================================
    // DATA HELPERS
    // =====================================================================

    private function get_sites() {
        return get_option('dbc_sites', []);
    }

    private function save_sites($sites) {
        update_option('dbc_sites', $sites);
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

    private function api_request($site_config, $method, $endpoint, $body = null, $is_file = false) {
        $url = rtrim($site_config['url'], '/') . '/wp-json/deploy-bridge/v1/' . $endpoint;

        $headers = [
            'X-Deploy-Key' => $site_config['key'],
        ];

        $args = [
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 60,
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

    public function ajax_save_site() {
        check_ajax_referer('dbc_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_die('Forbidden');

        $alias = sanitize_key($_POST['alias'] ?? '');
        if (empty($alias)) wp_send_json_error('Alias is required');

        $sites = $this->get_sites();
        $sites[$alias] = [
            'url'        => esc_url_raw($_POST['url'] ?? ''),
            'key'        => sanitize_text_field($_POST['key'] ?? ''),
            'secret'     => sanitize_text_field($_POST['secret'] ?? ''),
            'local_site' => sanitize_text_field($_POST['local_site'] ?? ''),
            'theme'      => sanitize_text_field($_POST['theme'] ?? ''),
            'status'     => 'unknown',
            'last_check' => null,
        ];
        $this->save_sites($sites);

        $this->add_log('config', $alias, 'Site configuration saved');
        wp_send_json_success('Site saved');
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
    // PAGE RENDERER
    // =====================================================================

    public function render_page() {
        $tab = sanitize_key($_GET['tab'] ?? 'sites');
        $sites = $this->get_sites();
        $local_folders = $this->get_local_site_folders();
        ?>
        <div class="wrap" id="dbc-app">
            <h1>Bridge Engine <small style="color:#999;">v<?php echo DBC_VERSION; ?></small></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=bridge-engine&tab=sites" class="nav-tab <?php echo $tab === 'sites' ? 'nav-tab-active' : ''; ?>">Sites</a>
                <a href="?page=bridge-engine&tab=sync" class="nav-tab <?php echo $tab === 'sync' ? 'nav-tab-active' : ''; ?>">Push / Pull</a>
                <a href="?page=bridge-engine&tab=log" class="nav-tab <?php echo $tab === 'log' ? 'nav-tab-active' : ''; ?>">Log</a>
                <a href="?page=bridge-engine&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
            </nav>

            <div class="dbc-tab-content" style="margin-top:20px;">
                <?php
                switch ($tab) {
                    case 'sites':    $this->render_sites_tab($sites, $local_folders); break;
                    case 'sync':     $this->render_sync_tab($sites, $local_folders); break;
                    case 'log':      $this->render_log_tab(); break;
                    case 'settings': $this->render_settings_tab(); break;
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
                        <button class="button button-small dbc-delete-btn" data-alias="<?php echo esc_attr($alias); ?>" style="color:#c00;">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <hr>
        <h3>Add / Edit Site</h3>
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

    // Delete site
    $(document).on('click', '.dbc-delete-btn', function() {
        var alias = $(this).data('alias');
        if (!confirm('Remove staging site "' + alias + '"?')) return;
        $.post(dbc.ajax_url, { action: 'dbc_delete_site', nonce: dbc.nonce, alias: alias }, function() {
            location.reload();
        });
    });

    // Save site
    $('#dbc-save-site-btn').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);
        $.post(dbc.ajax_url, {
            action: 'dbc_save_site', nonce: dbc.nonce,
            alias: $('#dbc-alias').val(),
            url: $('#dbc-url').val(),
            key: $('#dbc-key').val(),
            secret: $('#dbc-secret').val(),
            local_site: $('#dbc-local-site').val(),
            theme: $('#dbc-theme').val(),
        }, function(r) {
            btn.prop('disabled', false);
            $('#dbc-save-result').html(r.success ? '<span style="color:green;">Saved!</span>' : '<span style="color:red;">' + r.data + '</span>');
            if (r.success) setTimeout(function() { location.reload(); }, 500);
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
});
JS;
    }
}

// Initialize
DeployBridgeCommander::get_instance();
