<?php
/**
 * Plugin Name: Deploy Bridge
 * Description: Staging/production receiver for Bridge Engine. Accepts pushed files and pull requests from your local Bridge Engine. Install this plugin on every staging or production site.
 * Version: 1.0.0
 * Author: HypedUp Studios
 * Text Domain: deploy-bridge
 */

defined('ABSPATH') || exit;

define('DB_BRIDGE_VERSION', '1.0.0');
define('DB_BRIDGE_OPTION',  'deploy_bridge_config');

class DeployBridgeReceiver {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init',  [$this, 'register_routes']);
        add_action('admin_menu',     [$this, 'add_menu']);
        add_action('admin_init',     [$this, 'handle_config_save']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // =========================================================================
    // REST API ROUTES
    // =========================================================================

    public function register_routes() {
        $ns = 'deploy-bridge/v1';

        register_rest_route($ns, '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'route_status'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        register_rest_route($ns, '/push', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_push'],
            'permission_callback' => [$this, 'check_api_key_and_signature'],
        ]);

        register_rest_route($ns, '/pull', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_pull'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        register_rest_route($ns, '/eval', [
            'methods'             => 'POST',
            'callback'            => [$this, 'route_eval'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);
    }

    // =========================================================================
    // AUTH HELPERS
    // =========================================================================

    private function get_config() {
        return get_option(DB_BRIDGE_OPTION, [
            'api_key' => '',
            'secret'  => '',
            'allowed_paths' => ['themes', 'mu-plugins', 'plugins'],
        ]);
    }

    public function check_api_key(WP_REST_Request $request) {
        $config  = $this->get_config();
        $api_key = $config['api_key'] ?? '';

        if (empty($api_key)) {
            return new WP_Error('not_configured', 'Deploy Bridge is not configured. Add an API key in Settings → Deploy Bridge.', ['status' => 503]);
        }

        $provided = $request->get_header('X-Deploy-Key');
        if (empty($provided) || !hash_equals($api_key, $provided)) {
            return new WP_Error('forbidden', 'Invalid API key.', ['status' => 403]);
        }

        return true;
    }

    public function check_api_key_and_signature(WP_REST_Request $request) {
        $key_check = $this->check_api_key($request);
        if (is_wp_error($key_check)) return $key_check;

        $config = $this->get_config();
        $secret = $config['secret'] ?? '';

        // Signature check only if a secret is configured.
        if (!empty($secret)) {
            $provided_sig = $request->get_header('X-Deploy-Signature');
            if (empty($provided_sig)) {
                return new WP_Error('forbidden', 'Missing X-Deploy-Signature header.', ['status' => 403]);
            }

            $body     = $request->get_body();
            $expected = hash_hmac('sha256', $body, $secret);

            if (!hash_equals($expected, $provided_sig)) {
                return new WP_Error('forbidden', 'Signature mismatch. Check your shared secret.', ['status' => 403]);
            }
        }

        return true;
    }

    // =========================================================================
    // ROUTE HANDLERS
    // =========================================================================

    public function route_status(WP_REST_Request $request) {
        $config  = $this->get_config();
        $allowed = $config['allowed_paths'] ?? ['themes', 'mu-plugins'];

        return rest_ensure_response([
            'status'           => 'ok',
            'plugin_version'   => DB_BRIDGE_VERSION,
            'wp_version'       => get_bloginfo('version'),
            'site_url'         => get_site_url(),
            'allowed_paths'    => $allowed,
            'php_version'      => PHP_VERSION,
            'wp_content_dir'   => WP_CONTENT_DIR,
            'active_theme'     => get_stylesheet(),
        ]);
    }

    public function route_push(WP_REST_Request $request) {
        // The zip comes as multipart file upload OR raw body.
        $files = $request->get_file_params();

        if (!empty($files['file']['tmp_name'])) {
            $zip_path = $files['file']['tmp_name'];
            $cleanup  = false; // PHP manages temp file
        } else {
            // Raw body — write to temp file.
            $body = $request->get_body();
            if (empty($body)) {
                return new WP_Error('no_file', 'No file received. Ensure Bridge Engine sent a zip.', ['status' => 400]);
            }
            $zip_path = wp_tempnam('db-push-') . '.zip';
            file_put_contents($zip_path, $body);
            $cleanup = true;
        }

        if (!class_exists('ZipArchive')) {
            if ($cleanup) @unlink($zip_path);
            return new WP_Error('no_zip', 'ZipArchive PHP extension not available on this server.', ['status' => 500]);
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            if ($cleanup) @unlink($zip_path);
            return new WP_Error('bad_zip', 'Could not open zip archive.', ['status' => 400]);
        }

        $config        = $this->get_config();
        $allowed_paths = $config['allowed_paths'] ?? ['themes', 'mu-plugins', 'plugins'];
        $wp_content    = WP_CONTENT_DIR;
        $manifest      = [];
        $skipped       = [];
        $count         = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Security: reject path traversal and absolute paths.
            if (strpos($name, '..') !== false || $name[0] === '/') {
                $skipped[] = $name . ' (path traversal rejected)';
                continue;
            }

            // Security: must start with an allowed path.
            $allowed = false;
            foreach ($allowed_paths as $allowed_path) {
                if (strpos($name, $allowed_path . '/') === 0 || $name === $allowed_path) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                $skipped[] = $name . ' (not in allowed paths)';
                continue;
            }

            $dest = $wp_content . '/' . $name;

            // Ensure directory exists.
            $dest_dir = dirname($dest);
            if (!is_dir($dest_dir)) {
                wp_mkdir_p($dest_dir);
            }

            $data = $zip->getFromIndex($i);
            if ($data !== false) {
                file_put_contents($dest, $data);
                $manifest[] = $name;
                $count++;
            }
        }

        $zip->close();
        if ($cleanup) @unlink($zip_path);

        // Log the push.
        $this->add_log('push', "Received {$count} files", $count, 'success', $skipped);

        // Auto-flush caches after push.
        $this->flush_caches();

        return rest_ensure_response([
            'success'  => true,
            'files'    => $count,
            'manifest' => $manifest,
            'skipped'  => $skipped,
            'message'  => "Deployed {$count} files successfully.",
        ]);
    }

    public function route_pull(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $type   = sanitize_key($params['type'] ?? 'dir');

        if ($type === 'partials') {
            return $this->pull_partials();
        }

        if ($type === 'dir') {
            $dir = sanitize_text_field($params['dir'] ?? '');
            return $this->pull_directory($dir);
        }

        return new WP_Error('invalid_type', "Unknown pull type: {$type}", ['status' => 400]);
    }

    private function pull_partials() {
        if (!post_type_exists('lc_partial')) {
            return new WP_Error('no_post_type', 'lc_partial post type does not exist on this site.', ['status' => 404]);
        }

        $partials = get_posts([
            'post_type'      => 'lc_partial',
            'post_status'    => ['publish', 'draft'],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $data = array_map(function ($p) {
            return [
                'ID'           => $p->ID,
                'post_title'   => $p->post_title,
                'post_status'  => $p->post_status,
                'post_content' => $p->post_content,
                'post_name'    => $p->post_name,
                'post_modified' => $p->post_modified,
            ];
        }, $partials);

        $this->add_log('pull', 'Sent ' . count($data) . ' partials', count($data), 'success');

        return rest_ensure_response([
            'success'  => true,
            'count'    => count($data),
            'partials' => $data,
        ]);
    }

    private function pull_directory($dir) {
        if (empty($dir) || strpos($dir, '..') !== false || $dir[0] === '/') {
            return new WP_Error('invalid_dir', 'Invalid directory path.', ['status' => 400]);
        }

        $config        = $this->get_config();
        $allowed_paths = $config['allowed_paths'] ?? ['themes', 'mu-plugins'];

        $allowed = false;
        foreach ($allowed_paths as $allowed_path) {
            if (strpos($dir, $allowed_path) === 0) { $allowed = true; break; }
        }
        if (!$allowed) {
            return new WP_Error('forbidden_path', "Directory '{$dir}' is not in the allowed paths.", ['status' => 403]);
        }

        $full_path = WP_CONTENT_DIR . '/' . $dir;
        if (!is_dir($full_path)) {
            return new WP_Error('not_found', "Directory '{$dir}' does not exist.", ['status' => 404]);
        }

        if (!class_exists('ZipArchive')) {
            return new WP_Error('no_zip', 'ZipArchive PHP extension not available.', ['status' => 500]);
        }

        $tmpzip = wp_tempnam('db-pull-') . '.zip';
        $zip    = new ZipArchive();
        if ($zip->open($tmpzip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return new WP_Error('zip_fail', 'Could not create zip archive.', ['status' => 500]);
        }

        $count    = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($full_path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.DS_Store') {
                $rel = $dir . '/' . substr($file->getRealPath(), strlen($full_path) + 1);
                $zip->addFile($file->getRealPath(), $rel);
                $count++;
            }
        }
        $zip->close();

        $this->add_log('pull', "Sent directory '{$dir}' ({$count} files)", $count, 'success');

        // Stream zip as response.
        $zip_data = file_get_contents($tmpzip);
        @unlink($tmpzip);

        $response = new WP_REST_Response($zip_data);
        $response->header('Content-Type', 'application/zip');
        $response->header('X-Deploy-Files', $count);
        return $response;
    }

    public function route_eval(WP_REST_Request $request) {
        $params = $request->get_json_params();
        $action = sanitize_key($params['action'] ?? '');
        $prms   = $params['params'] ?? [];

        switch ($action) {
            case 'flush_cache':
                return $this->action_flush_cache();

            case 'get_partials':
                return $this->pull_partials();

            case 'list_themes':
                $themes = wp_get_themes();
                $list   = [];
                foreach ($themes as $slug => $theme) {
                    $list[] = ['slug' => $slug, 'name' => $theme->get('Name'), 'version' => $theme->get('Version'), 'active' => ($slug === get_stylesheet())];
                }
                return rest_ensure_response(['success' => true, 'themes' => $list]);

            case 'publish_drafts':
                $post_type = sanitize_key($prms['post_type'] ?? 'lc_partial');
                return $this->action_publish_drafts($post_type);

            default:
                return new WP_Error('unknown_action', "Unknown eval action: {$action}", ['status' => 400]);
        }
    }

    private function action_flush_cache() {
        $flushed = [];

        // WP object cache
        wp_cache_flush();
        $flushed[] = 'wp_object_cache';

        // Transients (common cache patterns)
        global $wpdb;
        $deleted = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
        if ($deleted > 0) $flushed[] = 'transients';

        // WP Super Cache
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $flushed[] = 'wp_super_cache';
        }

        // W3 Total Cache
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $flushed[] = 'w3tc';
        }

        // LiteSpeed Cache
        if (class_exists('LiteSpeed_Cache_API')) {
            LiteSpeed_Cache_API::purge_all();
            $flushed[] = 'litespeed_cache';
        }

        // WP Rocket
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $flushed[] = 'wp_rocket';
        }

        // SG Optimizer
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $flushed[] = 'siteground_cache';
        }

        $this->add_log('eval', 'Flushed: ' . implode(', ', $flushed), 0, 'success');

        return rest_ensure_response(['success' => true, 'flushed' => $flushed]);
    }

    private function action_publish_drafts($post_type) {
        if (!post_type_exists($post_type)) {
            return new WP_Error('no_post_type', "Post type '{$post_type}' does not exist.", ['status' => 404]);
        }

        $posts   = get_posts(['post_type' => $post_type, 'post_status' => 'draft', 'posts_per_page' => -1]);
        $updated = [];
        foreach ($posts as $post) {
            wp_update_post(['ID' => $post->ID, 'post_status' => 'publish']);
            $updated[] = $post->post_title;
        }

        $this->add_log('eval', 'Published ' . count($updated) . " {$post_type} drafts", count($updated), 'success');

        return rest_ensure_response(['success' => true, 'published' => count($updated), 'titles' => $updated]);
    }

    private function flush_caches() {
        wp_cache_flush();

        if (function_exists('w3tc_flush_all'))          w3tc_flush_all();
        if (function_exists('wp_cache_clear_cache'))    wp_cache_clear_cache();
        if (function_exists('rocket_clean_domain'))     rocket_clean_domain();
        if (function_exists('sg_cachepress_purge_cache')) sg_cachepress_purge_cache();
        if (class_exists('LiteSpeed_Cache_API'))        LiteSpeed_Cache_API::purge_all();
    }

    // =========================================================================
    // LOG HELPERS
    // =========================================================================

    private function get_log() {
        return get_option('deploy_bridge_log', []);
    }

    private function add_log($direction, $summary, $files = 0, $status = 'success', $notes = []) {
        $log = $this->get_log();
        array_unshift($log, [
            'time'      => current_time('mysql'),
            'direction' => $direction,
            'summary'   => $summary,
            'files'     => $files,
            'status'    => $status,
            'notes'     => $notes,
        ]);
        $log = array_slice($log, 0, 100);
        update_option('deploy_bridge_log', $log);
    }

    // =========================================================================
    // ADMIN UI
    // =========================================================================

    public function add_menu() {
        add_management_page(
            'Deploy Bridge',
            'Deploy Bridge',
            'manage_options',
            'deploy-bridge',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_deploy-bridge') return;
        // Inline styles and JS — no external files needed.
    }

    public function handle_config_save() {
        if (!isset($_POST['deploy_bridge_save']) || !current_user_can('manage_options')) return;
        check_admin_referer('deploy_bridge_save');

        $config = $this->get_config();

        $new_key = sanitize_text_field($_POST['api_key'] ?? '');
        if (!empty($new_key)) $config['api_key'] = $new_key;

        $new_secret = sanitize_text_field($_POST['secret'] ?? '');
        if ($new_secret !== '***') $config['secret'] = $new_secret;

        $paths_raw = sanitize_textarea_field($_POST['allowed_paths'] ?? '');
        $paths     = array_filter(array_map('trim', explode("\n", $paths_raw)));
        if (!empty($paths)) $config['allowed_paths'] = $paths;

        update_option(DB_BRIDGE_OPTION, $config);
        add_settings_error('deploy_bridge', 'saved', 'Settings saved.', 'updated');
    }

    public function render_page() {
        $config = $this->get_config();
        $log    = $this->get_log();
        $tab    = sanitize_key($_GET['tab'] ?? 'settings');
        ?>
        <div class="wrap">
        <h1>🌉 Deploy Bridge <span style="font-size:14px;color:#888;font-weight:400;">v<?php echo DB_BRIDGE_VERSION; ?> &mdash; Staging/Production Receiver</span></h1>

        <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
            <a href="?page=deploy-bridge&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">⚙️ Settings</a>
            <a href="?page=deploy-bridge&tab=log"      class="nav-tab <?php echo $tab === 'log'      ? 'nav-tab-active' : ''; ?>">📋 Activity Log</a>
            <a href="?page=deploy-bridge&tab=help"     class="nav-tab <?php echo $tab === 'help'     ? 'nav-tab-active' : ''; ?>">❓ Setup Guide</a>
        </nav>

        <?php if ($tab === 'settings') : ?>

        <div style="background:#d1ecf1;border:1px solid #bee5eb;border-radius:6px;padding:14px 18px;margin-bottom:20px;max-width:700px;">
            <strong>📡 API Endpoint:</strong><br>
            <code style="user-select:all;font-size:13px;"><?php echo esc_url(get_rest_url(null, 'deploy-bridge/v1')); ?></code>
            <br><small style="color:#555;">Enter this URL (and the API key below) into Bridge Engine on your local dev site.</small>
        </div>

        <?php settings_errors('deploy_bridge'); ?>
        <form method="post" style="max-width:600px;">
            <?php wp_nonce_field('deploy_bridge_save'); ?>
            <input type="hidden" name="deploy_bridge_save" value="1">
            <table class="form-table">
                <tr>
                    <th><label for="db-api-key">API Key</label></th>
                    <td>
                        <input type="text" id="db-api-key" name="api_key" class="regular-text"
                               value="<?php echo esc_attr($config['api_key'] ?? ''); ?>"
                               placeholder="Generate a strong random key (min 32 chars)">
                        <p class="description">This key authenticates Bridge Engine. Copy it to the local Bridge Engine site configuration.</p>
                        <?php if (empty($config['api_key'])) : ?>
                        <p>
                            <button type="button" onclick="document.getElementById('db-api-key').value=Array.from(crypto.getRandomValues(new Uint8Array(32))).map(b=>b.toString(16).padStart(2,'0')).join('')" class="button">
                                Generate Random Key
                            </button>
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="db-secret">HMAC Secret</label></th>
                    <td>
                        <input type="text" id="db-secret" name="secret" class="regular-text"
                               value="<?php echo !empty($config['secret']) ? '***' : ''; ?>"
                               placeholder="Optional — leave blank to skip signature verification">
                        <p class="description">If set, all push requests must include a valid <code>X-Deploy-Signature</code> header. Must match the secret in Bridge Engine. Leave blank to disable.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="db-paths">Allowed Paths</label></th>
                    <td>
                        <textarea id="db-paths" name="allowed_paths" rows="5" class="regular-text"><?php echo esc_textarea(implode("\n", $config['allowed_paths'] ?? ['themes', 'mu-plugins', 'plugins'])); ?></textarea>
                        <p class="description">One path prefix per line (relative to <code>wp-content/</code>). Files outside these paths are rejected. Example: <code>themes/picostrap5-divorce-child</code></p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings', 'primary', 'submit'); ?>
        </form>

        <?php elseif ($tab === 'log') : ?>

        <div style="max-width:900px;">
            <?php if (empty($log)) : ?>
            <p style="color:#888;">No activity yet. Once Bridge Engine makes its first push or pull, it will appear here.</p>
            <?php else : ?>
            <table class="widefat fixed striped" style="font-size:13px;">
                <thead><tr>
                    <th width="170">Time</th>
                    <th width="80">Direction</th>
                    <th>Summary</th>
                    <th width="60">Files</th>
                    <th width="80">Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($log as $entry) : ?>
                <tr>
                    <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                    <td><strong><?php echo esc_html(strtoupper($entry['direction'] ?? '')); ?></strong></td>
                    <td>
                        <?php echo esc_html($entry['summary'] ?? ''); ?>
                        <?php if (!empty($entry['notes'])) : ?>
                        <br><small style="color:#888;"><?php echo esc_html(implode(', ', (array)$entry['notes'])); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($entry['files'] ?? 0); ?></td>
                    <td>
                        <span style="color:<?php echo ($entry['status'] ?? '') === 'success' ? '#2ecc71' : '#e74c3c'; ?>">
                            <?php echo esc_html($entry['status'] ?? ''); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <form method="post" style="margin-top:10px;">
                <?php wp_nonce_field('deploy_bridge_save'); ?>
                <input type="hidden" name="deploy_bridge_save" value="clear_log">
                <button type="button" onclick="if(confirm('Clear all log entries?')) this.closest('form').submit()" class="button">Clear Log</button>
            </form>
            <?php endif; ?>
        </div>

        <?php elseif ($tab === 'help') : ?>

        <div style="max-width:700px;line-height:1.7;">
            <h2>Setup Guide</h2>

            <h3>Step 1 — Install Deploy Bridge (this plugin) on staging/production</h3>
            <p>You've already done this. Do the same on every staging or production site you want to push to.</p>

            <h3>Step 2 — Generate an API key</h3>
            <p>Go to the <a href="?page=deploy-bridge&tab=settings">Settings tab</a>, click <strong>Generate Random Key</strong>, and save. Optionally set a shared secret for HMAC signature verification.</p>

            <h3>Step 3 — Configure Bridge Engine on your local dev site</h3>
            <ol>
                <li>On your local dev WordPress: go to <strong>Tools → Bridge Engine</strong>.</li>
                <li>Click <strong>Add Site</strong> and fill in:
                    <ul>
                        <li><strong>Alias</strong>: a short name (e.g. <code>staging</code>)</li>
                        <li><strong>URL</strong>: <code><?php echo esc_html(get_site_url()); ?></code></li>
                        <li><strong>API Key</strong>: the key you generated above</li>
                        <li><strong>Shared Secret</strong>: same secret you set above (or blank)</li>
                        <li><strong>Local Site Folder</strong>: which local site to push from (e.g. <code>divorce-credibility-v3</code>)</li>
                        <li><strong>Theme Slug</strong>: the child theme folder name to push (e.g. <code>picostrap5-divorce-child</code>)</li>
                    </ul>
                </li>
                <li>Click <strong>Test Connection</strong> — you should see a green success.</li>
            </ol>

            <h3>Step 4 — Push files</h3>
            <p>In Bridge Engine, go to the <strong>Push/Pull tab</strong>, select your staging site, choose what to push (mu-plugins, theme, or custom paths), and click <strong>Push</strong>. Files are zipped locally, sent over HTTPS, and extracted here instantly.</p>

            <h3>Security notes</h3>
            <ul>
                <li>All requests are authenticated via <code>X-Deploy-Key</code> header.</li>
                <li>Files are path-traversal checked and restricted to your Allowed Paths.</li>
                <li>HMAC signature verification prevents tampering in transit (recommended if pushing over HTTP).</li>
                <li>The plugin never exposes file contents without authentication.</li>
                <li>Log every operation for audit trail.</li>
            </ul>

            <h3>Allowed Paths</h3>
            <p>By default: <code>themes</code>, <code>mu-plugins</code>, <code>plugins</code>. You can restrict further — e.g. to only allow pushing a specific theme:</p>
            <pre style="background:#f0f0f0;padding:10px;border-radius:4px;">themes/picostrap5-divorce-child
mu-plugins</pre>
            <p>This prevents accidentally overwriting unintended files.</p>
        </div>

        <?php endif; ?>
        </div>

        <?php
        // Handle log clear (separate from main save).
        if (isset($_POST['deploy_bridge_save']) && $_POST['deploy_bridge_save'] === 'clear_log') {
            check_admin_referer('deploy_bridge_save');
            if (current_user_can('manage_options')) {
                update_option('deploy_bridge_log', []);
            }
        }
    }
}

// Boot.
add_action('plugins_loaded', function () {
    DeployBridgeReceiver::get_instance();
});
