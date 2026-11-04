<?php
/**
 * Plugin Name: Directorist Multi-Site Sync
 * Description: Sync Directorist listings across multiple standalone WordPress sites.
 * Version: 2.3.3
 * Author: Menumiz
 * License: GPL2
 */

if (!defined('ABSPATH')) exit;

class DMSS_Plugin {

    const OPTION_KEY       = 'dmss_settings';
    const ROUTE_NS         = 'dmss/v1';
    const META_SOURCE_SITE = '_dmss_source_site';
    const META_SOURCE_ID   = '_dmss_source_id';
    const META_SKIP_PUSH   = '_dmss_skip_push';

    public static function instance() {
        static $inst = null;
        if (!$inst) $inst = new self();
        return $inst;
    }

    private function __construct() {
        add_action('admin_menu',             [$this, 'admin_menu']);
        add_action('admin_init',             [$this, 'register_settings']);
        add_action('rest_api_init',          [$this, 'register_routes']);
        add_action('rest_api_init',          [$this, 'register_directorist_taxonomies'], 5);
        add_action('save_post',              [$this, 'on_save_post'], 20, 3);
        add_action('transition_post_status', [$this, 'on_status_change'], 20, 3);
        add_action('before_delete_post',     [$this, 'on_delete_post'], 20);
        add_action('wp_trash_post',          [$this, 'on_trash_post'], 20);
        add_action('dmss_delayed_sync',      [$this, 'on_delayed_sync']);
        add_action('dmss_fix_dir_cron',      [$this, 'fix_all_directory_types']);
        add_action('init',                   [$this, 'schedule_fix_cron']);
        add_action('init',                   [$this, 'handle_manual_fix']);
        foreach (['at_biz_dir-category', 'at_biz_dir-location', 'at_biz_dir-tags'] as $tax) {
            add_action("created_{$tax}", [$this, 'auto_assign_term_to_directories']);
        }
    }

    public function schedule_fix_cron() {
        if (!$this->is_receiver()) return;
        if (!wp_next_scheduled('dmss_fix_dir_cron')) {
            wp_schedule_event(time(), 'twicedaily', 'dmss_fix_dir_cron');
        }
    }

    public function handle_manual_fix() {
        if (!current_user_can('administrator')) return;
        if (!isset($_GET['dmss_fix_now'])) return;
        $fixed = $this->fix_all_directory_types();
        wp_redirect(admin_url('options-general.php?page=dmss-settings&fixed=' . $fixed));
        exit;
    }

    public function fix_all_directory_types() {
        if (!$this->is_receiver()) return 0;
        $listings = get_posts([
            'post_type'   => 'at_biz_dir',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        $fixed = 0;
        foreach ($listings as $post_id) {
            $before = get_post_meta($post_id, '_directory_type', true);
            $this->fix_local_directory_type($post_id);
            $after = get_post_meta($post_id, '_directory_type', true);
            if ($before != $after) $fixed++;
        }
        return $fixed;
    }

    /* ------------------------------------------------------------------ */
    /* Admin UI                                                             */
    /* ------------------------------------------------------------------ */

    public function admin_menu() {
        add_options_page('Directorist Sync', 'Directorist Sync', 'manage_options', 'dmss-settings', [$this, 'render_settings_page']);
    }

    public function register_settings() {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $output = $this->get_settings();
        $output['role'] = (isset($input['role']) && in_array($input['role'], ['master','receiver','both'], true)) ? $input['role'] : 'master';
        $output['post_type']     = !empty($input['post_type']) ? sanitize_key($input['post_type']) : 'at_biz_dir';
        $output['shared_secret'] = isset($input['shared_secret']) ? sanitize_text_field($input['shared_secret']) : '';
        $output['sync_statuses'] = [];
        if (!empty($input['sync_statuses']) && is_array($input['sync_statuses'])) {
            foreach ($input['sync_statuses'] as $s) {
                $s = sanitize_key($s);
                if (in_array($s, ['publish','draft','pending','private'], true)) $output['sync_statuses'][] = $s;
            }
        }
        if (empty($output['sync_statuses'])) $output['sync_statuses'] = ['publish'];
        $targets = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($input['targets_raw'] ?? '')) as $line) {
            $line = trim($line);
            if (!$line) continue;
            $p = strpos($line, '|');
            if ($p === false) {
                $url = esc_url_raw(untrailingslashit($line));
                $name = wp_parse_url($url, PHP_URL_HOST) ?: '';
            } else {
                $name = sanitize_text_field(trim(substr($line, 0, $p)));
                $url  = esc_url_raw(untrailingslashit(trim(substr($line, $p + 1))));
            }
            if ($name && $url) $targets[] = ['name' => $name, 'url' => $url];
        }
        $output['targets'] = $targets;
        return $output;
    }

    public function get_settings() {
        $defaults = ['role' => 'master', 'post_type' => 'at_biz_dir', 'shared_secret' => '', 'targets' => [], 'sync_statuses' => ['publish']];
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        $s = $this->get_settings();
        $raw = '';
        foreach ($s['targets'] as $t) $raw .= $t['name'] . ' | ' . $t['url'] . "\n";
        ?>
        <div class="wrap">
        <h1>Directorist Multi-Site Sync <small>v2.3.0</small></h1>
        <form method="post" action="options.php">
        <?php settings_fields(self::OPTION_KEY); ?>
        <table class="form-table">
        <tr><th>Site Role</th><td>
            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[role]">
                <option value="master"   <?php selected($s['role'],'master'); ?>>Master (pushes to others)</option>
                <option value="receiver" <?php selected($s['role'],'receiver'); ?>>Receiver (accepts from master)</option>
                <option value="both"     <?php selected($s['role'],'both'); ?>>Both</option>
            </select>
        </td></tr>
        <tr><th>Post Type</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[post_type]" value="<?php echo esc_attr($s['post_type']); ?>"></td></tr>
        <tr><th>Shared Secret</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_KEY); ?>[shared_secret]" value="<?php echo esc_attr($s['shared_secret']); ?>"></td></tr>
        <tr><th>Sync Statuses</th><td>
            <?php foreach (['publish','draft','pending','private'] as $st): ?>
            <label style="display:block;margin-bottom:4px;">
                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sync_statuses][]" value="<?php echo esc_attr($st); ?>" <?php checked(in_array($st, $s['sync_statuses'], true)); ?>>
                <?php echo esc_html(ucfirst($st)); ?>
            </label>
            <?php endforeach; ?>
        </td></tr>
        <tr><th>Target Sites</th><td>
            <textarea rows="8" class="large-text code" name="<?php echo esc_attr(self::OPTION_KEY); ?>[targets_raw]"><?php echo esc_textarea(trim($raw)); ?></textarea>
            <p class="description">One per line: <code>Site Name | https://example.com</code></p>
        </td></tr>
        </table>
        <?php submit_button(); ?>
        </form>
        <hr>
        <h2>Endpoints (on this site)</h2>
        <p><code><?php echo esc_html(rest_url(self::ROUTE_NS . '/listing')); ?></code></p>

        <?php if ($this->is_receiver()): ?>
        <hr>
        <h2>Fix Directory Types</h2>
        <p>If listings are not showing on frontend, click this button to fix directory type assignments.</p>
        <?php if (isset($_GET['fixed'])): ?>
        <div class="notice notice-success"><p>Fixed <?php echo intval($_GET['fixed']); ?> listings!</p></div>
        <?php endif; ?>
        <a href="<?php echo esc_url(admin_url('options-general.php?page=dmss-settings&dmss_fix_now=1')); ?>" class="button button-primary">Fix Directory Types Now</a>
        <p class="description">This also runs automatically twice daily via cron.</p>
        <?php endif; ?>

        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */

    private function is_master()   { return in_array($this->get_settings()['role'], ['master','both'], true); }
    private function is_receiver() { return in_array($this->get_settings()['role'], ['receiver','both'], true); }

    public function register_directorist_taxonomies() {
        foreach (['at_biz_dir-category' => [true], 'at_biz_dir-location' => [true], 'at_biz_dir-tags' => [false]] as $tax => $hier) {
            if (!taxonomy_exists($tax)) {
                register_taxonomy($tax, 'at_biz_dir', ['public' => true, 'hierarchical' => $hier[0], 'rewrite' => ['slug' => $tax]]);
            }
        }
    }

    public function auto_assign_term_to_directories($term_id) {
        global $wpdb;
        $dir_ids = array_map('intval', $wpdb->get_col(
            "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='atbdp_listing_types'"
        ));
        foreach ($dir_ids as $did) update_term_meta($term_id, '_directory_type_'.$did, 1);
        update_term_meta($term_id, '_directory_type', $dir_ids);
    }

    /* ------------------------------------------------------------------ */
    /* REST Routes                                                          */
    /* ------------------------------------------------------------------ */

    public function register_routes() {
        register_rest_route(self::ROUTE_NS, '/listing', [
            ['methods' => 'GET',  'callback' => fn() => new WP_REST_Response(['status' => 'DMSS receiver active', 'version' => '2.3.0'], 200), 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this, 'receive_listing'], 'permission_callback' => [$this, 'authorize_request']],
        ]);
        register_rest_route(self::ROUTE_NS, '/listing/delete', [
            ['methods' => 'GET',  'callback' => fn() => new WP_REST_Response(['status' => 'DMSS delete endpoint active'], 200), 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this, 'receive_delete'], 'permission_callback' => [$this, 'authorize_request']],
        ]);
    }

    public function authorize_request(WP_REST_Request $req) {
        if (!$this->is_receiver()) return new WP_Error('dmss_disabled', 'Receiver disabled.', ['status' => 403]);
        $s = $this->get_settings();
        $provided = $req->get_header('x-dmss-secret');
        if (empty($s['shared_secret']) || empty($provided) || !hash_equals($s['shared_secret'], $provided))
            return new WP_Error('dmss_unauthorized', 'Unauthorized.', ['status' => 401]);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Push (Master)                                                        */
    /* ------------------------------------------------------------------ */

    public function on_save_post($post_id, $post, $update) {
        if (!$this->is_master()) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        $s = $this->get_settings();
        if ($post->post_type !== $s['post_type']) return;
        if (!in_array($post->post_status, $s['sync_statuses'], true)) return;
        if (get_post_meta($post_id, self::META_SKIP_PUSH, true)) {
            delete_post_meta($post_id, self::META_SKIP_PUSH);
            return;
        }
        $this->push_to_targets('/listing', $this->build_payload($post_id));
    }

    public function on_status_change($new_status, $old_status, $post) {
        if (!$this->is_master()) return;
        if ($new_status === $old_status) return;
        $s = $this->get_settings();
        if ($post->post_type !== $s['post_type']) return;
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;
        if (get_post_meta($post->ID, self::META_SKIP_PUSH, true)) return;

        // Sync when status changes TO a sync-enabled status
        if (in_array($new_status, $s['sync_statuses'], true)) {
            $this->push_to_targets('/listing', $this->build_payload($post->ID));
        }

        // Delete from receiver when trashed or deleted
        if (in_array($new_status, ['trash'], true) && in_array($old_status, $s['sync_statuses'], true)) {
            $this->push_to_targets('/listing/delete', [
                'source_site'    => home_url(),
                'source_post_id' => $post->ID,
                'post_type'      => $s['post_type'],
            ]);
        }
    }

    public function on_delayed_sync($post_id) {
        if (!$this->is_master()) return;
        $post = get_post($post_id);
        if (!$post) return;
        $s = $this->get_settings();
        if ($post->post_type !== $s['post_type']) return;
        if (!in_array($post->post_status, $s['sync_statuses'], true)) return;
        $this->push_to_targets('/listing', $this->build_payload($post_id));
    }

    public function on_delete_post($post_id) {
        if (!$this->is_master()) return;
        $post = get_post($post_id);
        if (!$post) return;
        $s = $this->get_settings();
        if ($post->post_type !== $s['post_type']) return;
        $this->push_to_targets('/listing/delete', ['source_site' => home_url(), 'source_post_id' => $post_id, 'post_type' => $s['post_type']]);
    }

    public function on_trash_post($post_id) {
        $this->on_delete_post($post_id);
    }

    private function build_payload($post_id) {
        $post = get_post($post_id);
        $s    = $this->get_settings();

        // Taxonomies via direct DB
        global $wpdb;
        $taxonomies    = get_object_taxonomies($s['post_type'], 'names');
        $terms_payload = [];
        foreach ($taxonomies as $tax) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT t.name, t.slug FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
                 INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 WHERE tr.object_id=%d AND tt.taxonomy=%s", $post_id, $tax
            ));
            $terms_payload[$tax] = $rows ? array_map(fn($r) => ['name' => $r->name, 'slug' => $r->slug], $rows) : [];
        }

        // Meta — raw DB values to preserve serialized data
        $raw_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d", $post_id
        ));
        $meta = [];
        foreach ($raw_rows as $row) {
            if ($this->should_skip_meta_key($row->meta_key)) continue;
            $meta[$row->meta_key] = $row->meta_value;
        }

        // Directory type
        $dir_type = get_post_meta($post_id, '_directory', true);
        if (empty($dir_type)) {
            $dt = $wpdb->get_results($wpdb->prepare(
                "SELECT t.slug FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
                 INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id=tr.term_taxonomy_id
                 WHERE tr.object_id=%d AND tt.taxonomy='atbdp_listing_types' LIMIT 1", $post_id
            ));
            if (!empty($dt)) $dir_type = $dt[0]->slug;
        }
        if (empty($dir_type)) {
            $dt_id = get_post_meta($post_id, '_directory_type', true);
            if ($dt_id) {
                $t = get_term($dt_id, 'atbdp_listing_types');
                if ($t && !is_wp_error($t)) $dir_type = $t->slug;
            }
        }

        // Featured/preview image
        $prev_id  = get_post_meta($post_id, '_listing_prv_img', true);
        $prev_url = $prev_id ? wp_get_attachment_url($prev_id) : (has_post_thumbnail($post_id) ? get_the_post_thumbnail_url($post_id, 'full') : '');

        // Logo/gallery images
        $gallery_urls = [];
        $g = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_gallery_img' LIMIT 1", $post_id));
        if ($g) {
            $gd = maybe_unserialize($g);
            if (is_array($gd)) {
                foreach ($gd as $aid) {
                    $u = wp_get_attachment_url($aid);
                    if ($u) $gallery_urls[] = $u;
                }
            }
        }

        // Slider images
        $slider_urls = [];
        $sl = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_listing_img' LIMIT 1", $post_id));
        if ($sl) {
            $sd = maybe_unserialize($sl);
            if (is_array($sd)) {
                foreach ($sd as $aid) {
                    $u = wp_get_attachment_url($aid);
                    if ($u) $slider_urls[] = $u;
                }
            }
        }

        return [
            'source_site'     => home_url(),
            'source_post_id'  => $post_id,
            'post_type'       => $s['post_type'],
            'directory_type'  => $dir_type,
            'post'            => [
                'post_title'     => $post->post_title,
                'post_content'   => $post->post_content,
                'post_excerpt'   => $post->post_excerpt,
                'post_status'    => $post->post_status,
                'post_name'      => $post->post_name,
                'menu_order'     => $post->menu_order,
                'comment_status' => $post->comment_status,
                'ping_status'    => $post->ping_status,
            ],
            'meta'            => $meta,
            'taxonomies'      => $terms_payload,
            'preview_img_url' => $prev_url,
            'gallery_images'  => $gallery_urls,
            'slider_images'   => $slider_urls,
        ];
    }

    private function should_skip_meta_key($key) {
        static $skip = [
            '_dmss_source_site', '_dmss_source_id', '_dmss_skip_push',
            '_edit_lock', '_edit_last', '_thumbnail_id',
            '_listing_prv_img', '_listing_img', '_gallery_img',
        ];
        if (in_array($key, $skip, true)) return true;
        if (strpos($key, '_wp_old_') === 0) return true;
        return false;
    }

    private function push_to_targets($path, $payload) {
        $s = $this->get_settings();
        if (empty($s['targets']) || empty($s['shared_secret'])) return;
        foreach ($s['targets'] as $t) {
            wp_remote_post(trailingslashit($t['url']) . 'wp-json/' . self::ROUTE_NS . '/' . ltrim($path, '/'), [
                'timeout'   => 120,
                'sslverify' => false,
                'blocking'  => true,
                'headers'   => ['Content-Type' => 'application/json', 'X-DMSS-Secret' => $s['shared_secret']],
                'body'      => wp_json_encode($payload),
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Receive (Receiver)                                                   */
    /* ------------------------------------------------------------------ */

    public function receive_listing(WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (empty($payload['source_site']) || empty($payload['source_post_id']) || empty($payload['post']))
            return new WP_Error('dmss_invalid', 'Invalid payload.', ['status' => 400]);

        $s              = $this->get_settings();
        $source_site    = esc_url_raw(untrailingslashit($payload['source_site']));
        $source_post_id = absint($payload['source_post_id']);
        $existing_id    = $this->find_existing_post($source_site, $source_post_id, $s['post_type']);

        $admin_users = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        $admin_id    = !empty($admin_users[0]) ? (int)$admin_users[0] : 1;

        $postarr = [
            'post_type'      => $s['post_type'],
            'post_title'     => sanitize_text_field($payload['post']['post_title']    ?? ''),
            'post_content'   => wp_kses_post($payload['post']['post_content']         ?? ''),
            'post_excerpt'   => wp_kses_post($payload['post']['post_excerpt']         ?? ''),
            'post_status'    => 'publish',
            'post_name'      => sanitize_title($payload['post']['post_name']          ?? ''),
            'menu_order'     => intval($payload['post']['menu_order']                  ?? 0),
            'comment_status' => sanitize_key($payload['post']['comment_status']       ?? 'closed'),
            'ping_status'    => sanitize_key($payload['post']['ping_status']          ?? 'closed'),
            'post_author'    => $admin_id,
        ];

        if ($existing_id) {
            $postarr['ID'] = $existing_id;
            update_post_meta($existing_id, self::META_SKIP_PUSH, 1);
            $post_id = wp_update_post(wp_slash($postarr), true);
        } else {
            $post_id = wp_insert_post(wp_slash($postarr), true);
        }

        if (is_wp_error($post_id)) return $post_id;

        update_post_meta($post_id, self::META_SOURCE_SITE, $source_site);
        update_post_meta($post_id, self::META_SOURCE_ID,   $source_post_id);

        // 1. Sync all meta (raw values)
        if (!empty($payload['meta']) && is_array($payload['meta'])) {
            $this->replace_post_meta($post_id, $payload['meta']);
        }

        // 2. Sync directory type
        if (!empty($payload['directory_type'])) {
            $this->sync_directory_type($post_id, sanitize_text_field($payload['directory_type']));
        }

        // 3. Sync taxonomies
        if (!empty($payload['taxonomies']) && is_array($payload['taxonomies'])) {
            $this->replace_taxonomies($post_id, $payload['taxonomies']);
        }

        // 4. Fix category display
        $this->fix_directorist_term_display($post_id);

        // 5. Assign default directory if missing
        $this->ensure_directory_assigned($post_id);

        // 6. Sync preview/cover image
        if (!empty($payload['preview_img_url'])) {
            $aid = $this->sideload_image($payload['preview_img_url'], $post_id);
            if ($aid) {
                set_post_thumbnail($post_id, $aid);
                update_post_meta($post_id, '_listing_prv_img', $aid);
                update_post_meta($post_id, '_thumbnail_id', $aid);
            }
        }

        // 7. Sync logo/gallery images
        if (!empty($payload['gallery_images']) && is_array($payload['gallery_images'])) {
            $ids = [];
            foreach ($payload['gallery_images'] as $url) {
                $aid = $this->sideload_image($url, $post_id);
                if ($aid) $ids[] = $aid;
            }
            if (!empty($ids)) update_post_meta($post_id, '_gallery_img', $ids);
        }

        // 8. Sync slider images
        if (!empty($payload['slider_images']) && is_array($payload['slider_images'])) {
            $ids = [];
            foreach ($payload['slider_images'] as $url) {
                $aid = $this->sideload_image($url, $post_id);
                if ($aid) $ids[] = $aid;
            }
            if (!empty($ids)) {
                $meta = [];
                foreach ($ids as $i => $aid) $meta[$i + 1] = $aid;
                global $wpdb;
                $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => '_listing_img']);
                $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => '_listing_img', 'meta_value' => serialize($meta)]);
            }
        }

        // 9. Update term counts
        $this->update_term_counts();

        // 10. Clear all caches
        $this->clear_caches($post_id);

        return ['success' => true, 'post_id' => $post_id];
    }

    public function receive_delete(WP_REST_Request $request) {
        $payload        = $request->get_json_params();
        $s              = $this->get_settings();
        $source_site    = !empty($payload['source_site'])    ? esc_url_raw(untrailingslashit($payload['source_site'])) : '';
        $source_post_id = !empty($payload['source_post_id']) ? absint($payload['source_post_id']) : 0;
        if (!$source_site || !$source_post_id) return new WP_Error('dmss_invalid', 'Invalid delete payload.', ['status' => 400]);
        $existing = $this->find_existing_post($source_site, $source_post_id, $s['post_type']);
        if ($existing) wp_delete_post($existing, true);
        return ['success' => true, 'deleted_post_id' => $existing];
    }

    /* ------------------------------------------------------------------ */
    /* Helper Methods                                                       */
    /* ------------------------------------------------------------------ */

    private function find_existing_post($source_site, $source_post_id, $post_type) {
        $posts = get_posts([
            'post_type' => $post_type, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [
                'relation' => 'AND',
                ['key' => self::META_SOURCE_SITE, 'value' => $source_site],
                ['key' => self::META_SOURCE_ID,   'value' => $source_post_id],
            ],
        ]);
        return !empty($posts[0]) ? (int)$posts[0] : 0;
    }

    private function replace_post_meta($post_id, array $meta) {
        global $wpdb;
        foreach ($meta as $key => $value) {
            if ($this->should_skip_meta_key($key)) continue;
            $wpdb->delete($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key]);
            if (is_serialized($value)) {
                // Insert raw to avoid double serialization
                $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
            } else {
                $wpdb->insert($wpdb->postmeta, ['post_id' => $post_id, 'meta_key' => $key, 'meta_value' => $value]);
            }
        }
    }

    private function sync_directory_type($post_id, $slug) {
        if (empty($slug)) return;
        global $wpdb;

        $term = $wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
             WHERE t.slug=%s AND tt.taxonomy='atbdp_listing_types' LIMIT 1", $slug
        ));

        update_post_meta($post_id, '_directory', $slug);

        if ($term) {
            update_post_meta($post_id, '_directory_type', $term->term_id);
            $wpdb->replace($wpdb->term_relationships, [
                'object_id'        => $post_id,
                'term_taxonomy_id' => $term->term_taxonomy_id,
                'term_order'       => 0,
            ]);
        }

        // Fix _directory_type to always use correct local ID
        $this->fix_local_directory_type($post_id);
    }

    private function fix_local_directory_type($post_id) {
        global $wpdb;

        $local_dir_terms = $wpdb->get_results(
            "SELECT t.term_id, t.slug, tt.term_taxonomy_id 
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
             WHERE tt.taxonomy='atbdp_listing_types'"
        );

        if (empty($local_dir_terms)) return;

        $local_dir_ids = array_map(fn($d) => (int)$d->term_id, $local_dir_terms);
        $current_dir   = (int) get_post_meta($post_id, '_directory_type', true);

        // If current ID is not valid on this site, fix it
        if (!in_array($current_dir, $local_dir_ids)) {
            $dir_slug   = get_post_meta($post_id, '_directory', true);
            $correct    = null;
            $correct_tt = null;

            // Match by slug
            foreach ($local_dir_terms as $d) {
                if ($d->slug === $dir_slug) {
                    $correct    = (int) $d->term_id;
                    $correct_tt = (int) $d->term_taxonomy_id;
                    break;
                }
            }

            // Fallback to first
            if (!$correct) {
                $correct    = (int) $local_dir_terms[0]->term_id;
                $correct_tt = (int) $local_dir_terms[0]->term_taxonomy_id;
            }

            update_post_meta($post_id, '_directory_type', $correct);

            // Fix term relationship
            $wpdb->query($wpdb->prepare(
                "DELETE tr FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                 WHERE tr.object_id=%d AND tt.taxonomy='atbdp_listing_types'",
                $post_id
            ));
            $wpdb->replace($wpdb->term_relationships, [
                'object_id'        => $post_id,
                'term_taxonomy_id' => $correct_tt,
                'term_order'       => 0,
            ]);
        }
    }

    private function replace_taxonomies($post_id, array $payload) {
        global $wpdb;
        foreach ($payload as $taxonomy => $terms) {
            if (empty($terms)) continue;
            $tt_ids = [];
            foreach ($terms as $td) {
                $name = sanitize_text_field($td['name'] ?? '');
                $slug = sanitize_title($td['slug'] ?? '');
                if (!$name) continue;
                // Find by slug
                $tt_id = $slug ? $wpdb->get_var($wpdb->prepare(
                    "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                     WHERE t.slug=%s AND tt.taxonomy=%s LIMIT 1", $slug, $taxonomy
                )) : null;
                // Find by name
                if (!$tt_id) $tt_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->terms} t ON tt.term_id=t.term_id
                     WHERE t.name=%s AND tt.taxonomy=%s LIMIT 1", $name, $taxonomy
                ));
                // Create if not found
                if (!$tt_id) {
                    $wpdb->insert($wpdb->terms, ['name' => $name, 'slug' => $slug ?: sanitize_title($name)]);
                    $new_term_id = $wpdb->insert_id;
                    if ($new_term_id) {
                        $wpdb->insert($wpdb->term_taxonomy, ['term_id' => $new_term_id, 'taxonomy' => $taxonomy, 'count' => 0]);
                        $tt_id = $wpdb->insert_id;
                        $this->auto_assign_term_to_directories($new_term_id);
                    }
                }
                if ($tt_id) $tt_ids[] = (int)$tt_id;
            }
            if (empty($tt_ids)) continue;
            // Remove old
            $wpdb->query($wpdb->prepare(
                "DELETE tr FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                 WHERE tr.object_id=%d AND tt.taxonomy=%s", $post_id, $taxonomy
            ));
            // Insert new
            foreach ($tt_ids as $tt_id) {
                $wpdb->replace($wpdb->term_relationships, ['object_id' => $post_id, 'term_taxonomy_id' => $tt_id, 'term_order' => 0]);
            }
        }
    }

    private function fix_directorist_term_display($post_id) {
        global $wpdb;
        $dir_type_id = get_post_meta($post_id, '_directory_type', true);
        if (!$dir_type_id) return;
        $all_dir_ids = array_map('intval', $wpdb->get_col(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='atbdp_listing_types'"
        ));
        foreach (['at_biz_dir-category','at_biz_dir-location','at_biz_dir-tags'] as $tax) {
            $term_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
                 WHERE tr.object_id=%d AND tt.taxonomy=%s", $post_id, $tax
            ));
            foreach ($term_ids as $tid) {
                update_term_meta($tid, '_directory_type_'.$dir_type_id, 1);
                update_term_meta($tid, '_directory_type', $all_dir_ids);
            }
        }
    }

    private function ensure_directory_assigned($post_id) {
        global $wpdb;
        $has = $wpdb->get_var($wpdb->prepare(
            "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id=tt.term_taxonomy_id
             WHERE tr.object_id=%d AND tt.taxonomy='atbdp_listing_types' LIMIT 1", $post_id
        ));
        if (!$has) {
            $def = $wpdb->get_row(
                "SELECT t.term_id, tt.term_taxonomy_id FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id
                 WHERE tt.taxonomy='atbdp_listing_types' ORDER BY t.term_id ASC LIMIT 1"
            );
            if ($def) {
                $wpdb->replace($wpdb->term_relationships, ['object_id' => $post_id, 'term_taxonomy_id' => $def->term_taxonomy_id, 'term_order' => 0]);
                update_post_meta($post_id, '_directory_type', $def->term_id);
            }
        }
    }

    private function update_term_counts() {
        foreach (['at_biz_dir-category','at_biz_dir-location','at_biz_dir-tags','atbdp_listing_types'] as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $tt_ids = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'tt_ids']);
            if (!is_wp_error($tt_ids) && !empty($tt_ids)) wp_update_term_count_now($tt_ids, $tax);
        }
    }

    private function clear_caches($post_id) {
        clean_post_cache($post_id);
        foreach (['at_biz_dir-category','at_biz_dir-location','at_biz_dir-tags','atbdp_listing_types'] as $tax) {
            clean_object_term_cache($post_id, $tax);
        }
        wp_cache_delete($post_id, 'posts');
        wp_cache_delete($post_id, 'post_meta');
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_atbdp_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_atbdp_%'");
        if (function_exists('sg_cachepress_purge_cache')) sg_cachepress_purge_cache();
        if (class_exists('SiteGround_Optimizer\Supercacher\Supercacher'))
            \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
    }

    private function sideload_image($url, $post_id) {
        if (!$url) return null;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // Check if already downloaded
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_dmss_source_url' AND meta_value=%s LIMIT 1", $url
        ));
        if ($existing) return (int)$existing;
        // Retry up to 3 times
        for ($i = 0; $i < 3; $i++) {
            $att_id = media_sideload_image($url, $post_id, null, 'id');
            if (!is_wp_error($att_id)) {
                update_post_meta($att_id, '_dmss_source_url', $url);
                return $att_id;
            }
            if ($i < 2) sleep(2);
        }
        return null;
    }
}

DMSS_Plugin::instance();
