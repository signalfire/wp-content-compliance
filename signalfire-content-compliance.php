<?php
/**
 * Plugin Name: Signalfire Content Compliance
 * Plugin URI: https://wordpress.org/plugins/signalfire-content-compliance/
 * Description: Ensures content compliance with legal requirements by managing maintainer reviews and approvals on a scheduled basis.
 * Version: 1.0.0
 * Author: Signalfire
 * Author URI: https://signalfire.com
 * Text Domain: signalfire-content-compliance
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package SignalfireContentCompliance
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SCC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SCC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('SCC_VERSION', '1.0.0');

/**
 * Main plugin class for Signalfire Content Compliance
 * 
 * Note: This plugin uses custom database tables and direct database queries for:
 * 1. Complex compliance tracking data that doesn't fit WordPress post meta structure
 * 2. Efficient bulk operations for compliance management
 * 3. Custom reporting and analytics requirements
 * 4. Performance optimization with proper caching implementation
 * 
 * Direct database access is necessary and appropriate for this specialized functionality.
 */
class SignalfireContentCompliance {
    
    private $option_name = 'scc_settings';
    private $compliance_table = '';
    private $reviews_table = '';
    private $bulk_operations_table = '';
    
    public function __construct() {
        global $wpdb;
        $this->compliance_table = $wpdb->prefix . 'scc_compliance';
        $this->reviews_table = $wpdb->prefix . 'scc_reviews';
        $this->bulk_operations_table = $wpdb->prefix . 'scc_bulk_operations';
        
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_post_meta'), 10, 2);
        add_action('wp_ajax_nopriv_scc_review_form', array($this, 'handle_review_submission'));
        add_action('wp_ajax_scc_review_form', array($this, 'handle_review_submission'));
        add_action('scc_compliance_check', array($this, 'check_compliance'));
        add_action('wp', array($this, 'handle_review_page'));
        
        // Admin columns
        add_filter('manage_posts_columns', array($this, 'add_compliance_column'));
        add_filter('manage_pages_columns', array($this, 'add_compliance_column'));
        add_action('manage_posts_custom_column', array($this, 'display_compliance_column'), 10, 2);
        add_action('manage_pages_custom_column', array($this, 'display_compliance_column'), 10, 2);
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        load_plugin_textdomain('signalfire-content-compliance', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Schedule compliance check if not already scheduled
        if (!wp_next_scheduled('scc_compliance_check')) {
            $settings = get_option($this->option_name, array());
            $frequency = isset($settings['check_frequency']) ? $settings['check_frequency'] : 'monthly';
            wp_schedule_event(time(), $frequency, 'scc_compliance_check');
        }
    }
    
    public function activate() {
        $this->create_tables();
        $this->check_and_create_missing_tables();
        
        // Set default options
        $default_options = array(
            'enabled_post_types' => array('post', 'page'),
            'check_frequency' => 'monthly',
            'non_response_action' => 'nothing',
            'website_manager_email' => get_option('admin_email'),
            /* translators: {post_title} will be replaced with the actual post title */
            'email_subject' => __('Content Review Required: {post_title}', 'signalfire-content-compliance'),
            'email_template' => $this->get_default_email_template(),
            /* translators: {post_title} will be replaced with the actual post title */
            'manager_email_subject' => __('Content Update Submitted: {post_title}', 'signalfire-content-compliance'),
            'manager_email_template' => $this->get_default_manager_email_template()
        );
        
        add_option($this->option_name, $default_options);
        
        // Schedule compliance check
        if (!wp_next_scheduled('scc_compliance_check')) {
            wp_schedule_event(time(), 'monthly', 'scc_compliance_check');
        }
    }
    
    public function deactivate() {
        wp_clear_scheduled_hook('scc_compliance_check');
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Compliance tracking table
        $compliance_sql = "CREATE TABLE IF NOT EXISTS {$this->compliance_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_id int(11) NOT NULL,
            maintainer_email varchar(255) NOT NULL,
            last_review_date datetime DEFAULT NULL,
            next_review_date datetime NOT NULL,
            status varchar(20) DEFAULT 'pending',
            review_token varchar(64) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY maintainer_email (maintainer_email),
            KEY next_review_date (next_review_date),
            KEY status (status)
        ) $charset_collate;";
        
        // Review submissions table
        $reviews_sql = "CREATE TABLE IF NOT EXISTS {$this->reviews_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_id int(11) NOT NULL,
            review_token varchar(64) NOT NULL,
            maintainer_email varchar(255) NOT NULL,
            submission_data longtext NOT NULL,
            maintainer_notes text,
            action_taken varchar(20) NOT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime DEFAULT NULL,
            processed_by varchar(255) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY review_token (review_token),
            KEY submitted_at (submitted_at)
        ) $charset_collate;";
        
        // Bulk operations tracking table
        $bulk_operations_sql = "CREATE TABLE IF NOT EXISTS {$this->bulk_operations_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            operation_type varchar(50) NOT NULL,
            post_type varchar(50) NOT NULL,
            overdue_only tinyint(1) DEFAULT 0,
            total_posts int(11) NOT NULL,
            successful_emails int(11) DEFAULT 0,
            failed_emails int(11) DEFAULT 0,
            initiated_by varchar(255) NOT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'running',
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY operation_type (operation_type),
            KEY post_type (post_type),
            KEY initiated_by (initiated_by),
            KEY started_at (started_at),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($compliance_sql);
        dbDelta($reviews_sql);
        dbDelta($bulk_operations_sql);
    }
    
    private function check_and_create_missing_tables() {
        global $wpdb;
        
        // Check if bulk operations table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for table existence check during activation
        $bulk_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->bulk_operations_table));
        if (!$bulk_table_exists) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            $charset_collate = $wpdb->get_charset_collate();
            
            $bulk_operations_sql = "CREATE TABLE {$this->bulk_operations_table} (
                id int(11) NOT NULL AUTO_INCREMENT,
                operation_type varchar(50) NOT NULL,
                post_type varchar(50) NOT NULL,
                overdue_only tinyint(1) DEFAULT 0,
                total_posts int(11) NOT NULL,
                successful_emails int(11) DEFAULT 0,
                failed_emails int(11) DEFAULT 0,
                initiated_by varchar(255) NOT NULL,
                started_at datetime DEFAULT CURRENT_TIMESTAMP,
                completed_at datetime DEFAULT NULL,
                status varchar(20) DEFAULT 'running',
                error_message text DEFAULT NULL,
                PRIMARY KEY (id),
                KEY operation_type (operation_type),
                KEY post_type (post_type),
                KEY initiated_by (initiated_by),
                KEY started_at (started_at),
                KEY status (status)
            ) $charset_collate;";
            
            dbDelta($bulk_operations_sql);
        }
    }
    
    /**
     * Remove WordPress block editor comments for clean user editing
     */
    private function remove_block_comments($content) {
        // Remove block comments like <!-- wp:paragraph --> and <!-- /wp:paragraph -->
        $content = preg_replace('/<!-- wp:.*? -->/', '', $content);
        $content = preg_replace('/<!-- \/wp:.*? -->/', '', $content);
        
        // Clean up extra whitespace that might be left behind
        $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
        $content = trim($content);
        
        return $content;
    }
    
    /**
     * Restore block structure to edited content by comparing with original
     */
    private function restore_block_structure($edited_content, $original_content) {
        // If the content hasn't changed significantly, keep original structure
        $clean_original = $this->remove_block_comments($original_content);
        $clean_edited = trim($edited_content);
        
        // If content is essentially the same, return original with block comments
        if (trim($clean_original) === $clean_edited) {
            return $original_content;
        }
        
        // If content changed significantly, wrap in basic paragraph blocks
        $paragraphs = explode("\n\n", $clean_edited);
        $block_content = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                $block_content .= "<!-- wp:paragraph -->\n<p>" . $paragraph . "</p>\n<!-- /wp:paragraph -->\n\n";
            }
        }
        
        return trim($block_content);
    }
    
    public function add_meta_boxes() {
        $settings = get_option($this->option_name, array());
        $enabled_post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();
        
        foreach ($enabled_post_types as $post_type) {
            add_meta_box(
                'scc_compliance_settings',
                __('Content Compliance', 'signalfire-content-compliance'),
                array($this, 'meta_box_callback'),
                $post_type,
                'side',
                'high'
            );
        }
    }
    
    public function meta_box_callback($post) {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }
        
        wp_nonce_field('scc_save_compliance', 'scc_compliance_nonce');
        
        global $wpdb;
        
        // Try to get compliance data from cache first
        $cache_key = 'scc_compliance_' . $post->ID;
        $compliance_data = wp_cache_get($cache_key, 'signalfire_compliance');
        
        if (false === $compliance_data) {
            // Cache miss - query database
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for specialized compliance tracking with caching implemented
            $compliance_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scc_compliance WHERE post_id = %d",
                $post->ID
            ));
            
            // Cache the result for 12 hours
            wp_cache_set($cache_key, $compliance_data, 'signalfire_compliance', 12 * HOUR_IN_SECONDS);
        }
        
        $maintainer_email = $compliance_data ? $compliance_data->maintainer_email : '';
        $next_review_date = $compliance_data ? $compliance_data->next_review_date : '';
        $last_review_date = $compliance_data ? $compliance_data->last_review_date : '';
        $status = $compliance_data ? $compliance_data->status : 'pending';
        
        // Get default maintainer for this post type
        $settings = get_option($this->option_name, array());
        $default_maintainer_key = 'default_maintainer_' . $post->post_type;
        $default_maintainer = isset($settings[$default_maintainer_key]) ? $settings[$default_maintainer_key] : '';
        
        ?>
        <div class="scc-compliance-fields">
            <p>
                <label for="scc_maintainer_email">
                    <strong><?php echo esc_html__('Maintainer Email:', 'signalfire-content-compliance'); ?></strong>
                </label><br>
                <input type="email" 
                       id="scc_maintainer_email" 
                       name="scc_maintainer_email" 
                       value="<?php echo esc_attr($maintainer_email); ?>" 
                       class="widefat"
                       placeholder="<?php echo esc_attr($default_maintainer); ?>" />
                <small class="description">
                    <?php echo sprintf(
                        /* translators: %s is the default maintainer email address */
                        esc_html__('Leave blank to use default maintainer: %s', 'signalfire-content-compliance'),
                        esc_html($default_maintainer ?: __('Not set', 'signalfire-content-compliance'))
                    ); ?>
                </small>
            </p>
            
            <p>
                <label for="scc_next_review_date">
                    <strong><?php echo esc_html__('Next Review Date:', 'signalfire-content-compliance'); ?></strong>
                </label><br>
                <input type="datetime-local" 
                       id="scc_next_review_date" 
                       name="scc_next_review_date" 
                       value="<?php echo esc_attr($next_review_date ? wp_date('Y-m-d\TH:i', strtotime($next_review_date)) : ''); ?>" 
                       class="widefat" />
            </p>
            
            <?php if ($compliance_data): ?>
            <div class="scc-compliance-status">
                <p>
                    <strong><?php echo esc_html__('Status:', 'signalfire-content-compliance'); ?></strong>
                    <span class="scc-status scc-status-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(ucfirst($status)); ?>
                    </span>
                </p>
                
                <?php if ($last_review_date): ?>
                <p>
                    <strong><?php echo esc_html__('Last Review:', 'signalfire-content-compliance'); ?></strong>
                    <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_review_date))); ?>
                </p>
                <?php endif; ?>
                
                <p>
                    <button type="button" class="button" id="scc-send-review-now">
                        <?php echo esc_html__('Send Review Request Now', 'signalfire-content-compliance'); ?>
                    </button>
                    <div id="scc-send-result" style="margin-top: 10px;"></div>
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
        .scc-compliance-fields p {
            margin-bottom: 15px;
        }
        .scc-compliance-fields label {
            display: block;
            margin-bottom: 5px;
        }
        .scc-compliance-fields .description {
            display: block;
            margin-top: 5px;
            font-style: italic;
            color: #666;
        }
        .scc-status {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .scc-status-pending {
            background: #fff3cd;
            color: #856404;
        }
        .scc-status-compliant {
            background: #d4edda;
            color: #155724;
        }
        .scc-status-overdue {
            background: #f8d7da;
            color: #721c24;
        }
        .scc-compliance-status {
            border-top: 1px solid #ddd;
            padding-top: 10px;
            margin-top: 15px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#scc-send-review-now').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var postId = <?php echo intval($post->ID); ?>;
                var resultDiv = $('#scc-send-result');
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'signalfire-content-compliance')); ?>');
                resultDiv.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_send_review_now',
                        post_id: postId,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_send_review_' . $post->ID)); ?>'
                    },
                    success: function(response) {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Review Request Now', 'signalfire-content-compliance')); ?>');
                        
                        if (response.success) {
                            resultDiv.html('<span style="color: #46b450;">✓ ' + (response.data.message || '<?php echo esc_js(__('Review request sent successfully!', 'signalfire-content-compliance')); ?>') + '</span>');
                            if (response.data.maintainer) {
                                resultDiv.append('<br><small>Sent to: ' + response.data.maintainer + '</small>');
                            }
                        } else {
                            resultDiv.html('<span style="color: #dc3232;">✗ ' + (response.data || '<?php echo esc_js(__('Error sending review request.', 'signalfire-content-compliance')); ?>') + '</span>');
                        }
                    },
                    error: function(xhr, status, error) {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Review Request Now', 'signalfire-content-compliance')); ?>');
                        resultDiv.html('<span style="color: #dc3232;">✗ Network error: ' + error + '</span>');
                        console.log('AJAX Error:', xhr.responseText);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function save_post_meta($post_id, $post) {
        // Security checks
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        if (!isset($_POST['scc_compliance_nonce']) || !wp_verify_nonce(wp_unslash($_POST['scc_compliance_nonce']), 'scc_save_compliance')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        $settings = get_option($this->option_name, array());
        $enabled_post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();
        
        if (!in_array($post->post_type, $enabled_post_types)) {
            return;
        }
        
        // Get form data
        $maintainer_email = isset($_POST['scc_maintainer_email']) ? sanitize_email(wp_unslash($_POST['scc_maintainer_email'])) : '';
        $next_review_date = isset($_POST['scc_next_review_date']) ? sanitize_text_field(wp_unslash($_POST['scc_next_review_date'])) : '';
        
        // Use default maintainer if no specific email provided
        if (empty($maintainer_email)) {
            $default_maintainer_key = 'default_maintainer_' . $post->post_type;
            $maintainer_email = isset($settings[$default_maintainer_key]) ? $settings[$default_maintainer_key] : '';
        }
        
        if (empty($maintainer_email)) {
            return; // No maintainer set
        }
        
        // Convert datetime-local to MySQL datetime format
        if ($next_review_date) {
            $next_review_date = gmdate('Y-m-d H:i:s', strtotime($next_review_date));
        } else {
            // Set default based on frequency
            $frequency = isset($settings['check_frequency']) ? $settings['check_frequency'] : 'monthly';
            $next_review_date = $this->calculate_next_review_date($frequency);
        }
        
        global $wpdb;
        
        // Check if record exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance record management
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}scc_compliance WHERE post_id = %d",
            $post_id
        ));
        
        $review_token = wp_generate_uuid4();
        
        if ($existing) {
            // Update existing record
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for compliance data updates
            $wpdb->update(
                $this->compliance_table,
                array(
                    'maintainer_email' => $maintainer_email,
                    'next_review_date' => $next_review_date,
                    'review_token' => $review_token,
                    'updated_at' => current_time('mysql')
                ),
                array('post_id' => $post_id),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            // Clear cache after update
            wp_cache_delete('scc_compliance_' . $post_id, 'signalfire_compliance');
        } else {
            // Insert new record
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for compliance data inserts
            $wpdb->insert(
                $this->compliance_table,
                array(
                    'post_id' => $post_id,
                    'maintainer_email' => $maintainer_email,
                    'next_review_date' => $next_review_date,
                    'status' => 'pending',
                    'review_token' => $review_token
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
        
        // Clear cache when compliance data is updated
        wp_cache_delete('scc_compliance_' . $post_id, 'signalfire_compliance');
    }
    
    private function calculate_next_review_date($frequency) {
        switch ($frequency) {
            case 'monthly':
                return gmdate('Y-m-d H:i:s', strtotime('+1 month'));
            case 'quarterly':
                return gmdate('Y-m-d H:i:s', strtotime('+3 months'));
            case 'biannually':
                return gmdate('Y-m-d H:i:s', strtotime('+6 months'));
            case 'yearly':
                return gmdate('Y-m-d H:i:s', strtotime('+1 year'));
            default:
                return gmdate('Y-m-d H:i:s', strtotime('+1 month'));
        }
    }
    
    public function check_compliance() {
        global $wpdb;
        
        // Get all content that needs review
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance checking functionality
        $overdue_content = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scc_compliance 
             WHERE next_review_date <= %s 
             AND status != 'compliant'",
            current_time('mysql')
        ));
        
        foreach ($overdue_content as $compliance) {
            $this->send_review_request($compliance);
            
            // Update status to overdue
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance status updates
            $wpdb->update(
                $this->compliance_table,
                array('status' => 'overdue'),
                array('id' => $compliance->id),
                array('%s'),
                array('%d')
            );
        }
        
        // Handle non-responsive content based on settings
        $settings = get_option($this->option_name, array());
        $non_response_action = isset($settings['non_response_action']) ? $settings['non_response_action'] : 'nothing';
        
        if ($non_response_action === 'draft') {
            // Find content that's been overdue for more than the review period
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for overdue content identification
            $very_overdue = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scc_compliance 
                 WHERE status = 'overdue' 
                 AND next_review_date <= %s",
                gmdate('Y-m-d H:i:s', strtotime('-' . $this->get_frequency_interval($settings['check_frequency'])))
            ));
            
            foreach ($very_overdue as $compliance) {
                wp_update_post(array(
                    'ID' => $compliance->post_id,
                    'post_status' => 'draft'
                ));
                
                $this->log_action('Content drafted due to non-compliance', $compliance->post_id);
            }
        }
    }
    
    private function get_frequency_interval($frequency) {
        switch ($frequency) {
            case 'monthly':
                return '1 month';
            case 'quarterly':
                return '3 months';
            case 'biannually':
                return '6 months';
            case 'yearly':
                return '1 year';
            default:
                return '1 month';
        }
    }
    
    private function send_review_request($compliance) {
        $post = get_post($compliance->post_id);
        if (!$post) {
            $this->log_action('Failed to send review - post not found', $compliance->post_id);
            return false;
        }
        
        $settings = get_option($this->option_name, array());
        $subject = isset($settings['email_subject']) ? $settings['email_subject'] : 
            /* translators: {post_title} will be replaced with the actual post title */
            __('Content Review Required: {post_title}', 'signalfire-content-compliance');
        $template = isset($settings['email_template']) ? $settings['email_template'] : $this->get_default_email_template();
        
        // Replace placeholders
        $placeholders = array(
            '{post_title}' => $post->post_title,
            '{post_url}' => get_permalink($post->ID),
            '{review_url}' => $this->get_review_url($compliance->review_token),
            '{maintainer_email}' => $compliance->maintainer_email,
            '{site_name}' => get_bloginfo('name')
        );
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $message = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $result = wp_mail($compliance->maintainer_email, $subject, $message, $headers);
        
        if ($result) {
            $this->log_action('Review email sent successfully to ' . $compliance->maintainer_email, $compliance->post_id);
        } else {
            $this->log_action('Failed to send review email to ' . $compliance->maintainer_email, $compliance->post_id);
        }
        
        return $result;
    }
    
    private function get_review_url($token) {
        return home_url('/scc-review/' . $token . '/');
    }
    
    public function handle_review_page() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        
        if (preg_match('/\/scc-review\/([a-f0-9-]{36})\/?/', $request_uri, $matches)) {
            $token = $matches[1];
            $this->display_review_form($token);
            exit;
        }
    }
    
    private function display_review_form($token) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for review token validation
        $compliance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scc_compliance WHERE review_token = %s",
            $token
        ));
        
        if (!$compliance) {
            wp_die(esc_html__('Invalid review link.', 'signalfire-content-compliance'));
        }
        
        $post = get_post($compliance->post_id);
        if (!$post) {
            wp_die(esc_html__('Content not found.', 'signalfire-content-compliance'));
        }
        
        // Check for password protection
        $settings = get_option($this->option_name, array());
        $required_password = isset($settings['review_password']) ? $settings['review_password'] : '';
        
        if (!empty($required_password)) {
            // Check if password form was submitted
            if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scc_password_nonce'])) {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
                if (!wp_verify_nonce(wp_unslash($_POST['scc_password_nonce']), 'scc_password_' . $token)) {
                    wp_die(esc_html__('Security check failed.', 'signalfire-content-compliance'));
                }
                
                $entered_password = isset($_POST['review_password']) ? sanitize_text_field(wp_unslash($_POST['review_password'])) : '';
                
                if ($entered_password === $required_password) {
                    // Set session flag that password was verified
                    if (!session_id()) {
                        session_start();
                    }
                    $_SESSION['scc_password_verified_' . $token] = true;
                } else {
                    $this->render_password_form($compliance, $post, $token, __('Incorrect password. Please try again.', 'signalfire-content-compliance'));
                    return;
                }
            } else {
                // Check if password was already verified in session
                if (!session_id()) {
                    session_start();
                }
                
                if (!isset($_SESSION['scc_password_verified_' . $token])) {
                    $this->render_password_form($compliance, $post, $token);
                    return;
                }
            }
        }
        
        // Handle form submission
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['scc_review_nonce'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
            if (!wp_verify_nonce(wp_unslash($_POST['scc_review_nonce']), 'scc_review_' . $token)) {
                wp_die(esc_html__('Security check failed.', 'signalfire-content-compliance'));
            }
            
            $this->process_review_submission($compliance, $post, $_POST);
            return;
        }
        
        // Display form
        $this->render_review_form($compliance, $post, $token);
    }
    
    private function render_password_form($compliance, $post, $token, $error_message = '') {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(sprintf(
                /* translators: %s is the post title */
                __('Access Required: %s', 'signalfire-content-compliance'), 
                $post->post_title
            )); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    margin: 0;
                    padding: 40px 20px;
                    background-color: #f1f1f1;
                }
                .container {
                    max-width: 500px;
                    margin: 0 auto;
                    background: white;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                }
                h1 {
                    color: #333;
                    margin-bottom: 20px;
                    font-size: 24px;
                }
                .site-info {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    margin-bottom: 30px;
                    border-left: 4px solid #0073aa;
                }
                .form-group {
                    margin-bottom: 20px;
                }
                label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                    color: #333;
                }
                input[type="password"] {
                    width: 100%;
                    padding: 12px;
                    border: 2px solid #ddd;
                    border-radius: 4px;
                    font-size: 16px;
                    box-sizing: border-box;
                }
                input[type="password"]:focus {
                    outline: none;
                    border-color: #0073aa;
                    box-shadow: 0 0 0 1px #0073aa;
                }
                .submit-button {
                    background: #0073aa;
                    color: white;
                    padding: 12px 30px;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    cursor: pointer;
                    transition: background-color 0.2s;
                }
                .submit-button:hover {
                    background: #005a87;
                }
                .error-message {
                    background: #f8d7da;
                    color: #721c24;
                    padding: 12px;
                    border: 1px solid #f5c6cb;
                    border-radius: 4px;
                    margin-bottom: 20px;
                }
                .description {
                    color: #666;
                    font-size: 14px;
                    margin-top: 5px;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1><?php echo esc_html__('Content Review Access', 'signalfire-content-compliance'); ?></h1>
                
                <div class="site-info">
                    <strong><?php echo esc_html__('Content:', 'signalfire-content-compliance'); ?></strong> <?php echo esc_html($post->post_title); ?><br>
                    <strong><?php echo esc_html__('Site:', 'signalfire-content-compliance'); ?></strong> <?php echo esc_html(get_bloginfo('name')); ?>
                </div>
                
                <?php if (!empty($error_message)): ?>
                    <div class="error-message">
                        <?php echo esc_html($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <p><?php echo esc_html__('Please enter the website manager password to access the content review form.', 'signalfire-content-compliance'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('scc_password_' . $token, 'scc_password_nonce'); ?>
                    
                    <div class="form-group">
                        <label for="review_password"><?php echo esc_html__('Password:', 'signalfire-content-compliance'); ?></label>
                        <input type="password" name="review_password" id="review_password" required autofocus>
                        <div class="description"><?php echo esc_html__('Enter the password provided by your website manager.', 'signalfire-content-compliance'); ?></div>
                    </div>
                    
                    <button type="submit" class="submit-button">
                        <?php echo esc_html__('Access Review Form', 'signalfire-content-compliance'); ?>
                    </button>
                </form>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function render_review_form($compliance, $post, $token) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(sprintf(
                /* translators: %s is the post title */
                __('Review: %s', 'signalfire-content-compliance'), 
                $post->post_title
            )); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    margin: 0;
                    padding: 20px;
                    background: #f1f1f1;
                }
                .review-container {
                    max-width: 800px;
                    margin: 0 auto;
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                }
                .review-header {
                    border-bottom: 1px solid #eee;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                .review-header h1 {
                    margin: 0 0 10px;
                    color: #333;
                }
                .review-meta {
                    color: #666;
                    font-size: 14px;
                }
                .form-group {
                    margin-bottom: 25px;
                }
                .form-group label {
                    display: block;
                    margin-bottom: 8px;
                    font-weight: 600;
                    color: #333;
                }
                .form-group input[type="text"],
                .form-group input[type="email"],
                .form-group textarea {
                    width: 100%;
                    padding: 12px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-size: 14px;
                    font-family: inherit;
                    box-sizing: border-box;
                }
                .form-group textarea {
                    min-height: 120px;
                    resize: vertical;
                }
                .current-content {
                    background: #f9f9f9;
                    padding: 15px;
                    border-radius: 4px;
                    border-left: 4px solid #0073aa;
                    margin-bottom: 15px;
                }
                .current-content h4 {
                    margin: 0 0 10px;
                    color: #0073aa;
                }
                .action-buttons {
                    background: #f9f9f9;
                    padding: 20px;
                    border-radius: 4px;
                    margin: 30px 0;
                }
                .action-buttons h3 {
                    margin: 0 0 15px;
                    color: #333;
                }
                .action-buttons label {
                    display: block;
                    margin-bottom: 10px;
                    font-weight: normal;
                }
                .action-buttons input[type="radio"] {
                    margin-right: 8px;
                }
                .submit-section {
                    border-top: 1px solid #eee;
                    padding-top: 20px;
                    text-align: center;
                }
                .btn {
                    background: #0073aa;
                    color: white;
                    padding: 12px 30px;
                    border: none;
                    border-radius: 4px;
                    font-size: 16px;
                    cursor: pointer;
                    text-decoration: none;
                    display: inline-block;
                }
                .btn:hover {
                    background: #005a87;
                }
                .btn-secondary {
                    background: #666;
                    margin-left: 10px;
                }
                .btn-secondary:hover {
                    background: #555;
                }
                .description {
                    color: #666;
                    font-size: 13px;
                    margin-top: 5px;
                }
            </style>
            <script>
                function toggleFields() {
                    var action = document.querySelector('input[name="review_action"]:checked').value;
                    var editFields = document.getElementById('edit-fields');
                    
                    if (action === 'edit') {
                        editFields.style.display = 'block';
                    } else {
                        editFields.style.display = 'none';
                    }
                }
                
                document.addEventListener('DOMContentLoaded', function() {
                    var radios = document.querySelectorAll('input[name="review_action"]');
                    radios.forEach(function(radio) {
                        radio.addEventListener('change', toggleFields);
                    });
                    toggleFields();
                });
            </script>
        </head>
        <body>
            <div class="review-container">
                <div class="review-header">
                    <h1><?php echo esc_html(sprintf(
                        /* translators: %s is the post title */
                        __('Review Content: %s', 'signalfire-content-compliance'), 
                        $post->post_title
                    )); ?></h1>
                    <div class="review-meta">
                        <strong><?php echo esc_html__('Site:', 'signalfire-content-compliance'); ?></strong> <?php echo esc_html(get_bloginfo('name')); ?><br>
                        <strong><?php echo esc_html__('Content URL:', 'signalfire-content-compliance'); ?></strong> <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" target="_blank"><?php echo esc_url(get_permalink($post->ID)); ?></a><br>
                        <strong><?php echo esc_html__('Review Due:', 'signalfire-content-compliance'); ?></strong> <?php echo esc_html(wp_date(get_option('date_format'), strtotime($compliance->next_review_date))); ?>
                    </div>
                </div>
                
                <form method="post" action="">
                    <?php wp_nonce_field('scc_review_' . $token, 'scc_review_nonce'); ?>
                    
                    <div class="action-buttons">
                        <h3><?php echo esc_html__('Choose Action:', 'signalfire-content-compliance'); ?></h3>
                        <label>
                            <input type="radio" name="review_action" value="approve" checked>
                            <?php echo esc_html__('Approve content as-is (no changes needed)', 'signalfire-content-compliance'); ?>
                        </label>
                        <label>
                            <input type="radio" name="review_action" value="edit">
                            <?php echo esc_html__('Submit changes to website manager', 'signalfire-content-compliance'); ?>
                        </label>
                    </div>
                    
                    <div id="edit-fields" style="display: none;">
                        <div class="form-group">
                            <label><?php echo esc_html__('Title:', 'signalfire-content-compliance'); ?></label>
                            <div class="current-content">
                                <h4><?php echo esc_html__('Current:', 'signalfire-content-compliance'); ?></h4>
                                <?php echo esc_html($post->post_title); ?>
                            </div>
                            <input type="text" name="new_title" value="<?php echo esc_attr($post->post_title); ?>" />
                            <div class="description"><?php echo esc_html__('Edit the title if changes are needed', 'signalfire-content-compliance'); ?></div>
                        </div>
                        
                        <?php if (!empty($post->post_excerpt)): ?>
                        <div class="form-group">
                            <label><?php echo esc_html__('Excerpt:', 'signalfire-content-compliance'); ?></label>
                            <div class="current-content">
                                <h4><?php echo esc_html__('Current:', 'signalfire-content-compliance'); ?></h4>
                                <?php echo esc_html($post->post_excerpt); ?>
                            </div>
                            <textarea name="new_excerpt"><?php echo esc_textarea($post->post_excerpt); ?></textarea>
                            <div class="description"><?php echo esc_html__('Edit the excerpt if changes are needed', 'signalfire-content-compliance'); ?></div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="form-group">
                            <label><?php echo esc_html__('Content:', 'signalfire-content-compliance'); ?></label>
                            <div class="current-content">
                                <h4><?php echo esc_html__('Current:', 'signalfire-content-compliance'); ?></h4>
                                <?php 
                                $clean_content = $this->remove_block_comments($post->post_content);
                                echo esc_html(wp_trim_words(wp_strip_all_tags($clean_content), 50)); 
                                ?>
                                <?php if (strlen(wp_strip_all_tags($clean_content)) > 300): ?>
                                    <p><em><?php echo esc_html__('(Content truncated for display)', 'signalfire-content-compliance'); ?></em></p>
                                <?php endif; ?>
                            </div>
                            <textarea name="new_content" rows="15"><?php echo esc_textarea($this->remove_block_comments($post->post_content)); ?></textarea>
                            <div class="description"><?php echo esc_html__('Edit the content if changes are needed', 'signalfire-content-compliance'); ?></div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><?php echo esc_html__('Notes to Website Manager (Optional):', 'signalfire-content-compliance'); ?></label>
                        <textarea name="maintainer_notes" rows="4" placeholder="<?php echo esc_attr__('Add any notes or instructions for the website manager...', 'signalfire-content-compliance'); ?>"></textarea>
                        <div class="description"><?php echo esc_html__('These notes will be included in the email to the website manager', 'signalfire-content-compliance'); ?></div>
                    </div>
                    
                    <div class="submit-section">
                        <button type="submit" class="btn"><?php echo esc_html__('Submit Review', 'signalfire-content-compliance'); ?></button>
                        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>" class="btn btn-secondary" target="_blank"><?php echo esc_html__('View Content', 'signalfire-content-compliance'); ?></a>
                    </div>
                </form>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function process_review_submission($compliance, $post, $form_data) {
        global $wpdb;
        
        $action = isset($form_data['review_action']) ? sanitize_text_field($form_data['review_action']) : 'approve';
        $maintainer_notes = isset($form_data['maintainer_notes']) ? sanitize_textarea_field($form_data['maintainer_notes']) : '';
        
        $submission_data = array();
        
        if ($action === 'edit') {
            $raw_content = isset($form_data['new_content']) ? wp_kses_post($form_data['new_content']) : '';
            $restored_content = $this->restore_block_structure($raw_content, $post->post_content);
            
            $submission_data = array(
                'title' => isset($form_data['new_title']) ? sanitize_text_field($form_data['new_title']) : '',
                'excerpt' => isset($form_data['new_excerpt']) ? sanitize_textarea_field($form_data['new_excerpt']) : '',
                'content' => $restored_content
            );
        }
        
        // Save review submission
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for review submission storage
        $wpdb->insert(
            $this->reviews_table,
            array(
                'post_id' => $compliance->post_id,
                'review_token' => $compliance->review_token,
                'maintainer_email' => $compliance->maintainer_email,
                'submission_data' => json_encode($submission_data),
                'maintainer_notes' => $maintainer_notes,
                'action_taken' => $action
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
        
        if ($action === 'approve') {
            // Update compliance status
            $next_review_date = $this->calculate_next_review_date($this->get_settings_frequency());
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance status updates
            $wpdb->update(
                $this->compliance_table,
                array(
                    'status' => 'compliant',
                    'last_review_date' => current_time('mysql'),
                    'next_review_date' => $next_review_date
                ),
                array('id' => $compliance->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            
            $this->display_success_message(__('Thank you! Content has been approved and marked as compliant.', 'signalfire-content-compliance'));
        } else {
            // Send changes to website manager
            $this->send_manager_notification($compliance, $post, $submission_data, $maintainer_notes);
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance status update
            $wpdb->update(
                $this->compliance_table,
                array('status' => 'pending_changes'),
                array('id' => $compliance->id),
                array('%s'),
                array('%d')
            );
            
            $this->display_success_message(__('Thank you! Your changes have been submitted to the website manager for review.', 'signalfire-content-compliance'));
        }
    }
    
    private function send_manager_notification($compliance, $post, $submission_data, $maintainer_notes) {
        $settings = get_option($this->option_name, array());
        $manager_email = isset($settings['website_manager_email']) ? $settings['website_manager_email'] : get_option('admin_email');
        $subject = isset($settings['manager_email_subject']) ? $settings['manager_email_subject'] : 
            /* translators: {post_title} will be replaced with the actual post title */
            __('Content Update Submitted: {post_title}', 'signalfire-content-compliance');
        $template = isset($settings['manager_email_template']) ? $settings['manager_email_template'] : $this->get_default_manager_email_template();
        
        // Prepare changes summary
        $changes_summary = '';
        if (!empty($submission_data['title']) && $submission_data['title'] !== $post->post_title) {
            $changes_summary .= sprintf(
                /* translators: %s is the new title text */
                __("Title: %s\n\n", 'signalfire-content-compliance'), 
                esc_html($submission_data['title'])
            );
        }
        if (!empty($submission_data['excerpt']) && $submission_data['excerpt'] !== $post->post_excerpt) {
            $changes_summary .= sprintf(
                /* translators: %s is the new excerpt text */
                __("Excerpt: %s\n\n", 'signalfire-content-compliance'), 
                esc_html($submission_data['excerpt'])
            );
        }
        if (!empty($submission_data['content']) && $submission_data['content'] !== $post->post_content) {
            $changes_summary .= sprintf(
                /* translators: %s is the new content text */
                __("Content: %s\n\n", 'signalfire-content-compliance'), 
                esc_html($submission_data['content'])
            );
        }
        
        // Replace placeholders
        $placeholders = array(
            '{post_title}' => $post->post_title,
            '{post_url}' => get_permalink($post->ID),
            '{edit_url}' => get_edit_post_link($post->ID),
            '{maintainer_email}' => $compliance->maintainer_email,
            '{maintainer_notes}' => $maintainer_notes,
            '{changes_summary}' => $changes_summary,
            '{site_name}' => get_bloginfo('name')
        );
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $message = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        wp_mail($manager_email, $subject, $message, $headers);
    }
    
    private function display_success_message($message) {
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html__('Review Submitted', 'signalfire-content-compliance'); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    line-height: 1.6;
                    margin: 0;
                    padding: 20px;
                    background: #f1f1f1;
                }
                .success-container {
                    max-width: 600px;
                    margin: 100px auto;
                    background: white;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    text-align: center;
                }
                .success-icon {
                    font-size: 48px;
                    color: #4CAF50;
                    margin-bottom: 20px;
                }
                .success-message {
                    font-size: 18px;
                    color: #333;
                    margin-bottom: 30px;
                }
            </style>
        </head>
        <body>
            <div class="success-container">
                <div class="success-icon">✓</div>
                <div class="success-message"><?php echo esc_html($message); ?></div>
                <p><?php echo esc_html__('You can now close this window.', 'signalfire-content-compliance'); ?></p>
            </div>
        </body>
        </html>
        <?php
    }
    
    private function get_settings_frequency() {
        $settings = get_option($this->option_name, array());
        return isset($settings['check_frequency']) ? $settings['check_frequency'] : 'monthly';
    }
    
    public function add_admin_menu() {
        add_options_page(
            __('Content Compliance Settings', 'signalfire-content-compliance'),
            __('Content Compliance', 'signalfire-content-compliance'),
            'manage_options',
            'signalfire-content-compliance',
            array($this, 'admin_page')
        );
        
        add_management_page(
            __('Compliance Reports', 'signalfire-content-compliance'),
            __('Compliance Reports', 'signalfire-content-compliance'),
            'manage_options',
            'scc-compliance-reports',
            array($this, 'reports_page')
        );
    }
    
    public function admin_init() {
        register_setting('scc_settings_group', $this->option_name, array($this, 'sanitize_settings'));
        
        // General settings
        add_settings_section(
            'scc_general_section',
            __('General Settings', 'signalfire-content-compliance'),
            array($this, 'general_section_callback'),
            'signalfire-content-compliance'
        );
        
        add_settings_field(
            'enabled_post_types',
            __('Enabled Post Types', 'signalfire-content-compliance'),
            array($this, 'post_types_callback'),
            'signalfire-content-compliance',
            'scc_general_section'
        );
        
        add_settings_field(
            'check_frequency',
            __('Review Frequency', 'signalfire-content-compliance'),
            array($this, 'frequency_callback'),
            'signalfire-content-compliance',
            'scc_general_section'
        );
        
        add_settings_field(
            'non_response_action',
            __('Non-Response Action', 'signalfire-content-compliance'),
            array($this, 'non_response_callback'),
            'signalfire-content-compliance',
            'scc_general_section'
        );
        
        add_settings_field(
            'website_manager_email',
            __('Website Manager Email', 'signalfire-content-compliance'),
            array($this, 'manager_email_callback'),
            'signalfire-content-compliance',
            'scc_general_section'
        );
        
        // Default maintainers section
        add_settings_section(
            'scc_maintainers_section',
            __('Default Maintainers', 'signalfire-content-compliance'),
            array($this, 'maintainers_section_callback'),
            'signalfire-content-compliance'
        );
        
        $settings = get_option($this->option_name, array());
        $enabled_post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();
        
        foreach ($enabled_post_types as $post_type) {
            $post_type_obj = get_post_type_object($post_type);
            if ($post_type_obj) {
                add_settings_field(
                    'default_maintainer_' . $post_type,
                    sprintf(
                        /* translators: %s is the post type name (e.g., Posts, Pages) */
                        __('Default Maintainer for %s', 'signalfire-content-compliance'), 
                        $post_type_obj->label
                    ),
                    array($this, 'default_maintainer_callback'),
                    'signalfire-content-compliance',
                    'scc_maintainers_section',
                    array('post_type' => $post_type)
                );
            }
        }
        
        // Email templates section
        add_settings_section(
            'scc_email_section',
            __('Email Templates', 'signalfire-content-compliance'),
            array($this, 'email_section_callback'),
            'signalfire-content-compliance'
        );
        
        add_settings_field(
            'email_subject',
            __('Review Request Subject', 'signalfire-content-compliance'),
            array($this, 'email_subject_callback'),
            'signalfire-content-compliance',
            'scc_email_section'
        );
        
        add_settings_field(
            'email_template',
            __('Review Request Template', 'signalfire-content-compliance'),
            array($this, 'email_template_callback'),
            'signalfire-content-compliance',
            'scc_email_section'
        );
        
        add_settings_field(
            'manager_email_subject',
            __('Manager Notification Subject', 'signalfire-content-compliance'),
            array($this, 'manager_email_subject_callback'),
            'signalfire-content-compliance',
            'scc_email_section'
        );
        
        add_settings_field(
            'manager_email_template',
            __('Manager Notification Template', 'signalfire-content-compliance'),
            array($this, 'manager_email_template_callback'),
            'signalfire-content-compliance',
            'scc_email_section'
        );
        
        // Security section
        add_settings_section(
            'scc_security_section',
            __('Security Settings', 'signalfire-content-compliance'),
            array($this, 'security_section_callback'),
            'signalfire-content-compliance'
        );
        
        add_settings_field(
            'review_password',
            __('Review Form Password', 'signalfire-content-compliance'),
            array($this, 'review_password_callback'),
            'signalfire-content-compliance',
            'scc_security_section'
        );
    }
    
    public function general_section_callback() {
        echo '<p>' . esc_html__('Configure the basic compliance settings for your site.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function post_types_callback() {
        $settings = get_option($this->option_name, array());
        $enabled_post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();
        $post_types = get_post_types(array('public' => true), 'objects');
        
        echo '<fieldset>';
        foreach ($post_types as $post_type) {
            $checked = in_array($post_type->name, $enabled_post_types) ? 'checked' : '';
            printf(
                '<label><input type="checkbox" name="%s[enabled_post_types][]" value="%s" %s> %s</label><br>',
                esc_attr($this->option_name),
                esc_attr($post_type->name),
                esc_attr($checked),
                esc_html($post_type->label)
            );
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Select which post types should have compliance tracking enabled.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function frequency_callback() {
        $settings = get_option($this->option_name, array());
        $frequency = isset($settings['check_frequency']) ? $settings['check_frequency'] : 'monthly';
        
        $frequencies = array(
            'monthly' => __('Monthly', 'signalfire-content-compliance'),
            'quarterly' => __('Quarterly (3 months)', 'signalfire-content-compliance'),
            'biannually' => __('Bi-annually (6 months)', 'signalfire-content-compliance'),
            'yearly' => __('Yearly', 'signalfire-content-compliance')
        );
        
        echo '<select name="' . esc_attr($this->option_name) . '[check_frequency]">';
        foreach ($frequencies as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($frequency, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('How often should content be reviewed for compliance?', 'signalfire-content-compliance') . '</p>';
    }
    
    public function non_response_callback() {
        $settings = get_option($this->option_name, array());
        $action = isset($settings['non_response_action']) ? $settings['non_response_action'] : 'nothing';
        
        $actions = array(
            'nothing' => __('Do nothing', 'signalfire-content-compliance'),
            'draft' => __('Change to draft status', 'signalfire-content-compliance')
        );
        
        echo '<select name="' . esc_attr($this->option_name) . '[non_response_action]">';
        foreach ($actions as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($action, $value, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('What should happen if maintainers do not respond to review requests?', 'signalfire-content-compliance') . '</p>';
    }
    
    public function manager_email_callback() {
        $settings = get_option($this->option_name, array());
        $email = isset($settings['website_manager_email']) ? $settings['website_manager_email'] : get_option('admin_email');
        
        printf(
            '<input type="email" name="%s[website_manager_email]" value="%s" class="regular-text" />',
            esc_attr($this->option_name),
            esc_attr($email)
        );
        echo '<p class="description">' . esc_html__('Email address to receive maintainer submissions and notifications.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function maintainers_section_callback() {
        echo '<p>' . esc_html__('Set default maintainer email addresses for each post type. These will be used when no specific maintainer is assigned to individual content.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function default_maintainer_callback($args) {
        $settings = get_option($this->option_name, array());
        $post_type = $args['post_type'];
        $field_name = 'default_maintainer_' . $post_type;
        $value = isset($settings[$field_name]) ? $settings[$field_name] : '';
        
        printf(
            '<input type="email" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr($this->option_name),
            esc_attr($field_name),
            esc_attr($value)
        );
    }
    
    public function email_section_callback() {
        echo '<p>' . esc_html__('Customize the email templates sent to maintainers and website managers.', 'signalfire-content-compliance') . '</p>';
        echo '<p><strong>' . 
            /* translators: This introduces a list of template placeholders like {post_title}, {post_url}, etc. */
            esc_html__('Available placeholders:', 'signalfire-content-compliance') . 
            '</strong> {post_title}, {post_url}, {review_url}, {maintainer_email}, {site_name}, {edit_url}, {maintainer_notes}, {changes_summary}</p>';
    }
    
    public function email_subject_callback() {
        $settings = get_option($this->option_name, array());
        $subject = isset($settings['email_subject']) ? $settings['email_subject'] : 
            /* translators: {post_title} will be replaced with the actual post title */
            __('Content Review Required: {post_title}', 'signalfire-content-compliance');
        
        printf(
            '<input type="text" name="%s[email_subject]" value="%s" class="large-text" />',
            esc_attr($this->option_name),
            esc_attr($subject)
        );
    }
    
    public function email_template_callback() {
        $settings = get_option($this->option_name, array());
        $template = isset($settings['email_template']) ? $settings['email_template'] : $this->get_default_email_template();
        
        printf(
            '<textarea name="%s[email_template]" rows="10" class="large-text">%s</textarea>',
            esc_attr($this->option_name),
            esc_textarea($template)
        );
    }
    
    public function manager_email_subject_callback() {
        $settings = get_option($this->option_name, array());
        $subject = isset($settings['manager_email_subject']) ? $settings['manager_email_subject'] : 
            /* translators: {post_title} will be replaced with the actual post title */
            __('Content Update Submitted: {post_title}', 'signalfire-content-compliance');
        
        printf(
            '<input type="text" name="%s[manager_email_subject]" value="%s" class="large-text" />',
            esc_attr($this->option_name),
            esc_attr($subject)
        );
    }
    
    public function manager_email_template_callback() {
        $settings = get_option($this->option_name, array());
        $template = isset($settings['manager_email_template']) ? $settings['manager_email_template'] : $this->get_default_manager_email_template();
        
        printf(
            '<textarea name="%s[manager_email_template]" rows="10" class="large-text">%s</textarea>',
            esc_attr($this->option_name),
            esc_textarea($template)
        );
    }
    
    public function security_section_callback() {
        echo '<p>' . esc_html__('Configure security settings for content review forms.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function review_password_callback() {
        $settings = get_option($this->option_name, array());
        $password = isset($settings['review_password']) ? $settings['review_password'] : '';
        
        printf(
            '<input type="password" name="%s[review_password]" value="%s" class="regular-text" />',
            esc_attr($this->option_name),
            esc_attr($password)
        );
        echo '<p class="description">' . esc_html__('Password required for maintainers to access review forms. Leave empty to disable password protection.', 'signalfire-content-compliance') . '</p>';
    }
    
    public function sanitize_settings($input) {
        // Verify nonce
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(wp_unslash($_POST['_wpnonce']), 'scc_settings_group-options')) {
            wp_die(esc_html__('Security check failed.', 'signalfire-content-compliance'));
        }
        
        $sanitized = array();
        
        // Post types
        if (isset($input['enabled_post_types']) && is_array($input['enabled_post_types'])) {
            $sanitized['enabled_post_types'] = array_map('sanitize_key', $input['enabled_post_types']);
        } else {
            $sanitized['enabled_post_types'] = array();
        }
        
        // Frequency
        $allowed_frequencies = array('monthly', 'quarterly', 'biannually', 'yearly');
        $sanitized['check_frequency'] = isset($input['check_frequency']) && in_array($input['check_frequency'], $allowed_frequencies) ? $input['check_frequency'] : 'monthly';
        
        // Non-response action
        $allowed_actions = array('nothing', 'draft');
        $sanitized['non_response_action'] = isset($input['non_response_action']) && in_array($input['non_response_action'], $allowed_actions) ? $input['non_response_action'] : 'nothing';
        
        // Emails
        $sanitized['website_manager_email'] = isset($input['website_manager_email']) ? sanitize_email($input['website_manager_email']) : get_option('admin_email');
        
        // Default maintainers
        foreach ($sanitized['enabled_post_types'] as $post_type) {
            $field_name = 'default_maintainer_' . $post_type;
            if (isset($input[$field_name])) {
                $sanitized[$field_name] = sanitize_email($input[$field_name]);
            }
        }
        
        // Email templates
        $sanitized['email_subject'] = isset($input['email_subject']) ? sanitize_text_field($input['email_subject']) : '';
        $sanitized['email_template'] = isset($input['email_template']) ? wp_kses_post($input['email_template']) : '';
        $sanitized['manager_email_subject'] = isset($input['manager_email_subject']) ? sanitize_text_field($input['manager_email_subject']) : '';
        $sanitized['manager_email_template'] = isset($input['manager_email_template']) ? wp_kses_post($input['manager_email_template']) : '';
        
        // Security settings
        $sanitized['review_password'] = isset($input['review_password']) ? sanitize_text_field($input['review_password']) : '';
        
        // Reschedule cron if frequency changed
        $old_settings = get_option($this->option_name, array());
        if (isset($old_settings['check_frequency']) && $old_settings['check_frequency'] !== $sanitized['check_frequency']) {
            wp_clear_scheduled_hook('scc_compliance_check');
            wp_schedule_event(time(), $sanitized['check_frequency'], 'scc_compliance_check');
        }
        
        return $sanitized;
    }
    
    private function get_default_email_template() {
        return '<html><body>
<h2>Content Review Required</h2>
<p>Dear Maintainer,</p>
<p>The following content on <strong>{site_name}</strong> requires your review for compliance:</p>
<p><strong>Title:</strong> {post_title}<br>
<strong>URL:</strong> <a href="{post_url}">{post_url}</a></p>
<p>Please click the link below to review and approve or submit changes:</p>
<p><a href="{review_url}" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Review Content</a></p>
<p>If you have any questions, please contact the website manager.</p>
<p>Thank you,<br>{site_name} Team</p>
</body></html>';
    }
    
    private function get_default_manager_email_template() {
        return '<html><body>
<h2>Content Update Submitted</h2>
<p>A maintainer has submitted changes for the following content:</p>
<p><strong>Title:</strong> {post_title}<br>
<strong>URL:</strong> <a href="{post_url}">{post_url}</a><br>
<strong>Edit:</strong> <a href="{edit_url}">Edit in WordPress</a></p>
<p><strong>Maintainer:</strong> {maintainer_email}</p>
<h3>Submitted Changes:</h3>
<div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa;">
{changes_summary}
</div>
<h3>Maintainer Notes:</h3>
<div style="background: #f9f9f9; padding: 15px;">
{maintainer_notes}
</div>
<p>Please review and apply these changes as appropriate.</p>
</body></html>';
    }
    
    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'signalfire-content-compliance'));
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Content Compliance Settings', 'signalfire-content-compliance'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('scc_settings_group');
                do_settings_sections('signalfire-content-compliance');
                submit_button();
                ?>
            </form>
            
            <div style="margin-top: 40px; padding: 20px; background: #fff; border: 1px solid #ccc;">
                <h3><?php echo esc_html__('Manual Compliance Check', 'signalfire-content-compliance'); ?></h3>
                <p><?php echo esc_html__('Click the button below to run a compliance check immediately instead of waiting for the scheduled check.', 'signalfire-content-compliance'); ?></p>
                <button type="button" class="button button-secondary" id="scc-manual-check">
                    <?php echo esc_html__('Run Compliance Check Now', 'signalfire-content-compliance'); ?>
                </button>
                <div id="scc-check-result"></div>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccc;">
                <h3><?php echo esc_html__('Email Test', 'signalfire-content-compliance'); ?></h3>
                <p><?php echo esc_html__('Test if WordPress can send emails from your server.', 'signalfire-content-compliance'); ?></p>
                <input type="email" id="scc-test-email" placeholder="Enter test email address" style="width: 300px;" />
                <button type="button" class="button button-secondary" id="scc-test-email-btn">
                    <?php echo esc_html__('Send Test Email', 'signalfire-content-compliance'); ?>
                </button>
                <div id="scc-email-test-result"></div>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #fff; border: 1px solid #ccc;">
                <h3><?php echo esc_html__('Send Compliance Checks by Content Type', 'signalfire-content-compliance'); ?></h3>
                <p><?php echo esc_html__('Send compliance review requests to all content maintainers for a specific post type.', 'signalfire-content-compliance'); ?></p>
                
                <div style="margin-bottom: 15px;">
                    <label for="scc-bulk-post-type">
                        <strong><?php echo esc_html__('Select Content Type:', 'signalfire-content-compliance'); ?></strong>
                    </label><br>
                    <select id="scc-bulk-post-type" style="width: 300px; margin-top: 5px;">
                        <option value=""><?php echo esc_html__('Choose a content type...', 'signalfire-content-compliance'); ?></option>
                        <?php
                        $settings = get_option($this->option_name, array());
                        $enabled_post_types = isset($settings['enabled_post_types']) ? $settings['enabled_post_types'] : array();
                        foreach ($enabled_post_types as $post_type) {
                            $post_type_obj = get_post_type_object($post_type);
                            if ($post_type_obj) {
                                printf(
                                    '<option value="%s">%s</option>',
                                    esc_attr($post_type),
                                    esc_html($post_type_obj->label)
                                );
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label>
                        <input type="checkbox" id="scc-bulk-overdue-only" />
                        <?php echo esc_html__('Send only to overdue content (recommended)', 'signalfire-content-compliance'); ?>
                    </label>
                    <br><small style="color: #666; margin-left: 20px;">
                        <?php echo esc_html__('If unchecked, will send to ALL content of the selected type', 'signalfire-content-compliance'); ?>
                    </small>
                </div>
                
                <button type="button" class="button button-primary" id="scc-bulk-send-btn">
                    <?php echo esc_html__('Send Compliance Checks', 'signalfire-content-compliance'); ?>
                </button>
                <div id="scc-bulk-send-result" style="margin-top: 15px;"></div>
                
                <div id="scc-bulk-progress" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 4px;">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <div class="spinner is-active" style="float: none; margin: 0;"></div>
                        <span id="scc-progress-text"><?php echo esc_html__('Starting...', 'signalfire-content-compliance'); ?></span>
                    </div>
                    <div style="width: 100%; background-color: #ddd; border-radius: 4px; margin-top: 10px;">
                        <div id="scc-progress-bar" style="width: 0%; height: 20px; background-color: #0073aa; border-radius: 4px; transition: width 0.3s ease;"></div>
                    </div>
                    <small id="scc-progress-details" style="color: #666; margin-top: 5px; display: block;"></small>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#scc-manual-check').on('click', function() {
                var button = $(this);
                button.prop('disabled', true).text('<?php echo esc_js(__('Running...', 'signalfire-content-compliance')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_manual_check',
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_manual_check')); ?>'
                    },
                    success: function(response) {
                        $('#scc-check-result').html('<p style="color: green;"><?php echo esc_js(__('Compliance check completed successfully!', 'signalfire-content-compliance')); ?></p>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Run Compliance Check Now', 'signalfire-content-compliance')); ?>');
                    },
                    error: function() {
                        $('#scc-check-result').html('<p style="color: red;"><?php echo esc_js(__('Error running compliance check.', 'signalfire-content-compliance')); ?></p>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Run Compliance Check Now', 'signalfire-content-compliance')); ?>');
                    }
                });
            });
            
            $('#scc-test-email-btn').on('click', function() {
                var button = $(this);
                var email = $('#scc-test-email').val();
                
                if (!email) {
                    alert('Please enter an email address.');
                    return;
                }
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'signalfire-content-compliance')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_test_email',
                        email: email,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_test_email')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#scc-email-test-result').html('<p style="color: green;">✓ Test email sent successfully!</p>');
                        } else {
                            $('#scc-email-test-result').html('<p style="color: red;">✗ Failed to send email: ' + (response.data || 'Unknown error') + '</p>');
                        }
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Test Email', 'signalfire-content-compliance')); ?>');
                    },
                    error: function() {
                        $('#scc-email-test-result').html('<p style="color: red;">✗ Network error occurred</p>');
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Test Email', 'signalfire-content-compliance')); ?>');
                    }
                });
            });
            
            // Bulk compliance check functionality
            $('#scc-bulk-send-btn').on('click', function() {
                var button = $(this);
                var postType = $('#scc-bulk-post-type').val();
                var overdueOnly = $('#scc-bulk-overdue-only').is(':checked');
                
                if (!postType) {
                    alert('<?php echo esc_js(__('Please select a content type first.', 'signalfire-content-compliance')); ?>');
                    return;
                }
                
                var confirmMsg = overdueOnly 
                    ? '<?php echo esc_js(__('Send compliance checks to all overdue content of this type?', 'signalfire-content-compliance')); ?>'
                    : '<?php echo esc_js(__('Send compliance checks to ALL content of this type? This may send many emails.', 'signalfire-content-compliance')); ?>';
                
                if (!confirm(confirmMsg)) {
                    return;
                }
                
                button.prop('disabled', true);
                $('#scc-bulk-send-result').html('');
                $('#scc-bulk-progress').show();
                $('#scc-progress-text').text('<?php echo esc_js(__('Getting content list...', 'signalfire-content-compliance')); ?>');
                $('#scc-progress-bar').css('width', '0%');
                $('#scc-progress-details').text('');
                
                // Start the bulk process
                startBulkProcess(postType, overdueOnly, button);
            });
            
            function startBulkProcess(postType, overdueOnly, button) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_bulk_compliance_check',
                        post_type: postType,
                        overdue_only: overdueOnly ? 1 : 0,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_bulk_compliance')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reset counters for new operation
                            successCount = 0;
                            failCount = 0;
                            processBulkBatch(response.data.posts, 0, response.data.total, button, response.data.operation_id);
                        } else {
                            showBulkError(response.data || '<?php echo esc_js(__('Failed to get content list.', 'signalfire-content-compliance')); ?>', button);
                        }
                    },
                    error: function() {
                        showBulkError('<?php echo esc_js(__('Network error occurred.', 'signalfire-content-compliance')); ?>', button);
                    }
                });
            }
            
            var successCount = 0;
            var failCount = 0;
            
            function processBulkBatch(posts, currentIndex, total, button, operationId) {
                if (currentIndex >= posts.length) {
                    // Update operation status
                    updateBulkOperation(operationId, successCount, failCount, 'completed');
                    
                    // Completed
                    $('#scc-bulk-progress').hide();
                    $('#scc-bulk-send-result').html('<p style="color: #46b450;">✓ <?php echo esc_js(__('Bulk compliance check completed!', 'signalfire-content-compliance')); ?> ' + successCount + ' <?php echo esc_js(__('successful,', 'signalfire-content-compliance')); ?> ' + failCount + ' <?php echo esc_js(__('failed.', 'signalfire-content-compliance')); ?></p>');
                    button.prop('disabled', false);
                    return;
                }
                
                var post = posts[currentIndex];
                var progress = Math.round(((currentIndex + 1) / posts.length) * 100);
                
                $('#scc-progress-text').text('<?php echo esc_js(__('Sending email', 'signalfire-content-compliance')); ?> ' + (currentIndex + 1) + ' <?php echo esc_js(__('of', 'signalfire-content-compliance')); ?> ' + posts.length);
                $('#scc-progress-bar').css('width', progress + '%');
                $('#scc-progress-details').text('<?php echo esc_js(__('Current:', 'signalfire-content-compliance')); ?> ' + post.title + ' (' + post.maintainer + ')');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_send_review_now',
                        post_id: post.id,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_send_review_bulk')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            successCount++;
                        } else {
                            failCount++;
                        }
                        // Continue regardless of individual success/failure
                        setTimeout(function() {
                            processBulkBatch(posts, currentIndex + 1, total, button, operationId);
                        }, 500); // Small delay to prevent overwhelming the server
                    },
                    error: function() {
                        failCount++;
                        // Continue even on error
                        setTimeout(function() {
                            processBulkBatch(posts, currentIndex + 1, total, button, operationId);
                        }, 500);
                    }
                });
            }
            
            function updateBulkOperation(operationId, successCount, failCount, status) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_update_bulk_operation',
                        operation_id: operationId,
                        successful_emails: successCount,
                        failed_emails: failCount,
                        status: status,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_bulk_compliance')); ?>'
                    }
                });
            }
            
            function showBulkError(message, button) {
                $('#scc-bulk-progress').hide();
                $('#scc-bulk-send-result').html('<p style="color: #dc3232;">✗ ' + message + '</p>');
                button.prop('disabled', false);
            }
        });
        </script>
        <?php
    }
    
    public function reports_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'signalfire-content-compliance'));
        }
        
        // Ensure all tables exist before displaying reports
        $this->check_and_create_missing_tables();
        
        global $wpdb;
        
        // Get compliance statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance statistics reporting
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'compliant' THEN 1 ELSE 0 END) as compliant,
                SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'pending_changes' THEN 1 ELSE 0 END) as pending_changes
            FROM {$wpdb->prefix}scc_compliance
        ");
        
        // Get overdue content ordered by how long overdue
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for overdue content reporting
        $overdue_content = $wpdb->get_results("
            SELECT c.*, p.post_title, p.post_type, p.post_status,
                   DATEDIFF(NOW(), c.next_review_date) as days_overdue
            FROM {$wpdb->prefix}scc_compliance c
            JOIN {$wpdb->posts} p ON c.post_id = p.ID
            WHERE c.status = 'overdue'
            ORDER BY days_overdue DESC
            LIMIT 20
        ");
        
        // Get recent reviews (show all pending + processed within last 7 days)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for recent reviews reporting
        $recent_reviews = $wpdb->get_results("
            SELECT r.*, p.post_title, p.post_type
            FROM {$wpdb->prefix}scc_reviews r
            JOIN {$wpdb->posts} p ON r.post_id = p.ID
            WHERE (r.processed_at IS NULL 
                   OR r.processed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY))
            ORDER BY 
                r.processed_at IS NULL DESC,  -- Show pending reviews first
                r.submitted_at DESC
            LIMIT 10
        ");
        
        // Get recent bulk operations
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for bulk operations reporting
        $bulk_operations = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}scc_bulk_operations
            ORDER BY started_at DESC
            LIMIT 5
        ");
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Compliance Reports', 'signalfire-content-compliance'); ?></h1>
            
            <div class="scc-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0;">
                <div class="scc-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px; color: #333;"><?php echo esc_html__('Total Content', 'signalfire-content-compliance'); ?></h3>
                    <div style="font-size: 36px; font-weight: bold; color: #0073aa;"><?php echo intval($stats->total); ?></div>
                </div>
                
                <div class="scc-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px; color: #333;"><?php echo esc_html__('Compliant', 'signalfire-content-compliance'); ?></h3>
                    <div style="font-size: 36px; font-weight: bold; color: #46b450;"><?php echo intval($stats->compliant); ?></div>
                </div>
                
                <div class="scc-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px; color: #333;"><?php echo esc_html__('Overdue', 'signalfire-content-compliance'); ?></h3>
                    <div style="font-size: 36px; font-weight: bold; color: #dc3232;"><?php echo intval($stats->overdue); ?></div>
                </div>
                
                <div class="scc-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccc; border-radius: 4px;">
                    <h3 style="margin: 0 0 10px; color: #333;"><?php echo esc_html__('Pending', 'signalfire-content-compliance'); ?></h3>
                    <div style="font-size: 36px; font-weight: bold; color: #ffb900;"><?php echo intval($stats->pending); ?></div>
                </div>
            </div>
            
            <h2><?php echo esc_html__('Most Overdue Content', 'signalfire-content-compliance'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Title', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Type', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Maintainer', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Days Overdue', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Actions', 'signalfire-content-compliance'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($overdue_content)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #666;">
                                <?php echo esc_html__('No overdue content found.', 'signalfire-content-compliance'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($overdue_content as $item): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($item->post_id)); ?>">
                                            <?php echo esc_html($item->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html(get_post_type_object($item->post_type)->label); ?></td>
                                <td><?php echo esc_html($item->maintainer_email); ?></td>
                                <td style="color: #dc3232; font-weight: bold;"><?php echo intval($item->days_overdue); ?></td>
                                <td>
                                    <a href="<?php echo esc_url(get_permalink($item->post_id)); ?>" class="button button-small" target="_blank">
                                        <?php echo esc_html__('View', 'signalfire-content-compliance'); ?>
                                    </a>
                                    <button class="button button-small scc-send-review" data-post-id="<?php echo intval($item->post_id); ?>">
                                        <?php echo esc_html__('Send Review', 'signalfire-content-compliance'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <h2><?php echo esc_html__('Recent Review Activity', 'signalfire-content-compliance'); ?></h2>
            <p style="margin-bottom: 15px; color: #666; font-style: italic;">
                <?php echo esc_html__('Shows all pending reviews and processed reviews from the last 7 days. Older processed reviews are automatically hidden.', 'signalfire-content-compliance'); ?>
            </p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Title', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Maintainer', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Action', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Submitted', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Status', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Manage', 'signalfire-content-compliance'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_reviews)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #666;">
                                <?php echo esc_html__('No recent reviews found.', 'signalfire-content-compliance'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_reviews as $review): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url(get_edit_post_link($review->post_id)); ?>">
                                            <?php echo esc_html($review->post_title); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td><?php echo esc_html($review->maintainer_email); ?></td>
                                <td>
                                    <span class="scc-action scc-action-<?php echo esc_attr($review->action_taken); ?>">
                                        <?php echo esc_html(ucfirst(str_replace('_', ' ', $review->action_taken))); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($review->submitted_at))); ?></td>
                                <td>
                                    <span class="scc-review-status" id="status-<?php echo intval($review->id); ?>">
                                        <?php if ($review->processed_at): ?>
                                            <span style="color: #46b450;"><?php echo esc_html__('Processed', 'signalfire-content-compliance'); ?></span>
                                        <?php else: ?>
                                            <span style="color: #ffb900;"><?php echo esc_html__('Pending', 'signalfire-content-compliance'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!$review->processed_at): ?>
                                        <div class="scc-review-actions">
                                            <button class="button button-small scc-mark-processed" 
                                                    data-review-id="<?php echo intval($review->id); ?>"
                                                    data-post-id="<?php echo intval($review->post_id); ?>">
                                                <?php echo esc_html__('Mark Processed', 'signalfire-content-compliance'); ?>
                                            </button>
                                            <br><small style="margin-top: 5px; display: block;">
                                                <a href="#" class="scc-view-submission" data-review-id="<?php echo intval($review->id); ?>">
                                                    <?php echo esc_html__('View Details', 'signalfire-content-compliance'); ?>
                                                </a>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <small style="color: #666;">
                                            <?php echo sprintf(
                                                /* translators: %s is the name of the person who processed the review */
                                                esc_html__('Processed by %s', 'signalfire-content-compliance'),
                                                esc_html($review->processed_by ?: __('System', 'signalfire-content-compliance'))
                                            ); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Bulk Operations Section -->
        <div style="margin-top: 30px;">
            <h2><?php echo esc_html__('Recent Bulk Operations', 'signalfire-content-compliance'); ?></h2>
            <p style="margin-bottom: 15px; color: #666; font-style: italic;">
                <?php echo esc_html__('Shows recent bulk compliance operations and their results.', 'signalfire-content-compliance'); ?>
            </p>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Operation', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Post Type', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Total Posts', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Success/Failed', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Started', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('Status', 'signalfire-content-compliance'); ?></th>
                        <th><?php echo esc_html__('By', 'signalfire-content-compliance'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bulk_operations)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #666;">
                                <?php echo esc_html__('No bulk operations found.', 'signalfire-content-compliance'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bulk_operations as $operation): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html(ucfirst(str_replace('_', ' ', $operation->operation_type))); ?></strong>
                                    <?php if ($operation->overdue_only): ?>
                                        <br><small style="color: #d63384;"><?php echo esc_html__('(Overdue only)', 'signalfire-content-compliance'); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(ucfirst($operation->post_type)); ?></td>
                                <td><?php echo intval($operation->total_posts); ?></td>
                                <td>
                                    <span style="color: #46b450;"><?php echo intval($operation->successful_emails); ?></span> / 
                                    <span style="color: #dc3232;"><?php echo intval($operation->failed_emails); ?></span>
                                </td>
                                <td><?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($operation->started_at))); ?></td>
                                <td>
                                    <?php if ($operation->status === 'completed'): ?>
                                        <span style="color: #46b450; font-weight: bold;"><?php echo esc_html__('Completed', 'signalfire-content-compliance'); ?></span>
                                        <?php if ($operation->completed_at): ?>
                                            <br><small style="color: #666;">
                                                <?php echo esc_html(wp_date(get_option('time_format'), strtotime($operation->completed_at))); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php elseif ($operation->status === 'failed'): ?>
                                        <span style="color: #dc3232; font-weight: bold;"><?php echo esc_html__('Failed', 'signalfire-content-compliance'); ?></span>
                                        <?php if ($operation->error_message): ?>
                                            <br><small style="color: #dc3232;" title="<?php echo esc_attr($operation->error_message); ?>">
                                                <?php echo esc_html(__('Error occurred', 'signalfire-content-compliance')); ?>
                                            </small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #ffb900; font-weight: bold;"><?php echo esc_html__('Running', 'signalfire-content-compliance'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($operation->initiated_by); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <style>
        .scc-action {
            padding: 2px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .scc-action-approve {
            background: #d4edda;
            color: #155724;
        }
        .scc-action-edit {
            background: #fff3cd;
            color: #856404;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('.scc-send-review').on('click', function() {
                var button = $(this);
                var postId = button.data('post-id');
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Sending...', 'signalfire-content-compliance')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_send_review_now',
                        post_id: postId,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_send_review_bulk')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            button.text('<?php echo esc_js(__('Sent!', 'signalfire-content-compliance')); ?>').css('color', '#46b450');
                            if (response.data && response.data.maintainer) {
                                button.attr('title', 'Sent to: ' + response.data.maintainer);
                            }
                        } else {
                            button.prop('disabled', false).text('<?php echo esc_js(__('Send Review', 'signalfire-content-compliance')); ?>');
                            var errorMsg = response.data || '<?php echo esc_js(__('Unknown error occurred.', 'signalfire-content-compliance')); ?>';
                            alert('<?php echo esc_js(__('Error sending review request:', 'signalfire-content-compliance')); ?> ' + errorMsg);
                            console.log('Send review error:', response);
                        }
                    },
                    error: function(xhr, status, error) {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Send Review', 'signalfire-content-compliance')); ?>');
                        alert('<?php echo esc_js(__('Network error occurred:', 'signalfire-content-compliance')); ?> ' + error);
                        console.log('AJAX error:', xhr.responseText);
                    }
                });
            });
            
            // Mark review as processed
            $('.scc-mark-processed').on('click', function() {
                var button = $(this);
                var reviewId = button.data('review-id');
                var postId = button.data('post-id');
                
                if (!confirm('<?php echo esc_js(__('Mark this review as processed? This action cannot be undone.', 'signalfire-content-compliance')); ?>')) {
                    return;
                }
                
                button.prop('disabled', true).text('<?php echo esc_js(__('Processing...', 'signalfire-content-compliance')); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_mark_processed',
                        review_id: reviewId,
                        post_id: postId,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_mark_processed')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#status-' + reviewId).html('<span style="color: #46b450;"><?php echo esc_js(__('Processed', 'signalfire-content-compliance')); ?></span>');
                            button.closest('.scc-review-actions').html('<small style="color: #666;"><?php echo esc_js(__('Processed by you', 'signalfire-content-compliance')); ?></small>');
                        } else {
                            button.prop('disabled', false).text('<?php echo esc_js(__('Mark Processed', 'signalfire-content-compliance')); ?>');
                            alert('<?php echo esc_js(__('Error processing review:', 'signalfire-content-compliance')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error', 'signalfire-content-compliance')); ?>'));
                        }
                    },
                    error: function() {
                        button.prop('disabled', false).text('<?php echo esc_js(__('Mark Processed', 'signalfire-content-compliance')); ?>');
                        alert('<?php echo esc_js(__('Network error occurred', 'signalfire-content-compliance')); ?>');
                    }
                });
            });
            
            // View submission details
            $('.scc-view-submission').on('click', function(e) {
                e.preventDefault();
                var reviewId = $(this).data('review-id');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'scc_get_review_details',
                        review_id: reviewId,
                        nonce: '<?php echo esc_attr(wp_create_nonce('scc_get_review_details')); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            showReviewModal(response.data);
                        } else {
                            alert('<?php echo esc_js(__('Error loading review details:', 'signalfire-content-compliance')); ?> ' + (response.data || '<?php echo esc_js(__('Unknown error', 'signalfire-content-compliance')); ?>'));
                        }
                    },
                    error: function() {
                        alert('<?php echo esc_js(__('Network error occurred', 'signalfire-content-compliance')); ?>');
                    }
                });
            });
            
            function showReviewModal(data) {
                var modal = $('<div id="scc-review-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 100000;">');
                var content = $('<div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; max-width: 80%; max-height: 80%; overflow: auto;">');
                
                var html = '<h2><?php echo esc_js(__('Review Submission Details', 'signalfire-content-compliance')); ?></h2>';
                html += '<p><strong><?php echo esc_js(__('Post:', 'signalfire-content-compliance')); ?></strong> ' + data.post_title + '</p>';
                html += '<p><strong><?php echo esc_js(__('Maintainer:', 'signalfire-content-compliance')); ?></strong> ' + data.maintainer_email + '</p>';
                html += '<p><strong><?php echo esc_js(__('Action:', 'signalfire-content-compliance')); ?></strong> ' + data.action_taken + '</p>';
                html += '<p><strong><?php echo esc_js(__('Submitted:', 'signalfire-content-compliance')); ?></strong> ' + data.submitted_at + '</p>';
                
                if (data.maintainer_notes) {
                    html += '<h3><?php echo esc_js(__('Maintainer Notes:', 'signalfire-content-compliance')); ?></h3>';
                    html += '<div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px;">' + data.maintainer_notes + '</div>';
                }
                
                if (data.submission_data && data.submission_data.length > 0) {
                    html += '<h3><?php echo esc_js(__('Submitted Changes:', 'signalfire-content-compliance')); ?></h3>';
                    html += '<div style="background: #f9f9f9; padding: 15px; border-radius: 4px; margin-bottom: 20px;">';
                    
                    try {
                        var changes = JSON.parse(data.submission_data);
                        if (changes.title) {
                            html += '<p><strong><?php echo esc_js(__('New Title:', 'signalfire-content-compliance')); ?></strong><br>' + changes.title + '</p>';
                        }
                        if (changes.excerpt) {
                            html += '<p><strong><?php echo esc_js(__('New Excerpt:', 'signalfire-content-compliance')); ?></strong><br>' + changes.excerpt + '</p>';
                        }
                        if (changes.content) {
                            html += '<p><strong><?php echo esc_js(__('New Content:', 'signalfire-content-compliance')); ?></strong><br>' + changes.content.substring(0, 500) + (changes.content.length > 500 ? '...' : '') + '</p>';
                        }
                    } catch (e) {
                        html += '<p>' + data.submission_data + '</p>';
                    }
                    
                    html += '</div>';
                }
                
                html += '<div style="text-align: right; margin-top: 20px;">';
                html += '<button id="scc-close-modal" class="button button-secondary"><?php echo esc_js(__('Close', 'signalfire-content-compliance')); ?></button>';
                html += '</div>';
                
                content.html(html);
                modal.append(content);
                $('body').append(modal);
                
                $('#scc-close-modal, #scc-review-modal').on('click', function(e) {
                    if (e.target === this) {
                        modal.remove();
                    }
                });
            }
        });
        </script>
        <?php
    }
    
    public function add_compliance_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add compliance column after title
            if ($key === 'title') {
                $new_columns['compliance'] = __('Compliance', 'signalfire-content-compliance');
            }
        }
        
        return $new_columns;
    }
    
    public function display_compliance_column($column, $post_id) {
        if ($column !== 'compliance') {
            return;
        }
        
        global $wpdb;
        
        // Use cached compliance data for admin column display
        $cache_key = 'scc_compliance_' . $post_id;
        $compliance = wp_cache_get($cache_key, 'signalfire_compliance');
        
        if (false === $compliance) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for compliance data with caching implemented
            $compliance = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}scc_compliance WHERE post_id = %d",
                $post_id
            ));
            
            // Cache for 12 hours
            wp_cache_set($cache_key, $compliance, 'signalfire_compliance', 12 * HOUR_IN_SECONDS);
        }
        
        if (!$compliance) {
            echo '<span style="color: #999;">—</span>';
            return;
        }
        
        $status = $compliance->status;
        $next_review = wp_date('M j, Y', strtotime($compliance->next_review_date));
        
        $status_colors = array(
            'compliant' => '#46b450',
            'overdue' => '#dc3232',
            'pending' => '#ffb900',
            'pending_changes' => '#0073aa'
        );
        
        $status_labels = array(
            'compliant' => __('Compliant', 'signalfire-content-compliance'),
            'overdue' => __('Overdue', 'signalfire-content-compliance'),
            'pending' => __('Pending', 'signalfire-content-compliance'),
            'pending_changes' => __('Changes Submitted', 'signalfire-content-compliance')
        );
        
        $color = isset($status_colors[$status]) ? $status_colors[$status] : '#666';
        $label = isset($status_labels[$status]) ? $status_labels[$status] : ucfirst($status);
        
        echo '<span style="color: ' . esc_attr($color) . '; font-weight: bold;">' . esc_html($label) . '</span><br>';
        echo '<small style="color: #666;">' . sprintf(
            /* translators: %s is the next review date */
            esc_html__('Next: %s', 'signalfire-content-compliance'), 
            esc_html($next_review)
        ) . '</small><br>';
        echo '<small style="color: #666;">' . esc_html($compliance->maintainer_email) . '</small>';
    }
    
    private function log_action($message, $post_id = null) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[Signalfire Content Compliance] ' . $message;
            if ($post_id) {
                $log_message .= ' Post ID: ' . $post_id;
            }
            // Logging removed for production use
        }
    }
}

// AJAX handlers
add_action('wp_ajax_scc_manual_check', 'scc_handle_manual_check');
add_action('wp_ajax_scc_send_review_now', 'scc_handle_send_review_now');
add_action('wp_ajax_scc_test_email', 'scc_handle_test_email');
add_action('wp_ajax_scc_mark_processed', 'scc_handle_mark_processed');
add_action('wp_ajax_scc_get_review_details', 'scc_handle_get_review_details');
add_action('wp_ajax_scc_bulk_compliance_check', 'scc_handle_bulk_compliance_check');
add_action('wp_ajax_scc_update_bulk_operation', 'scc_handle_update_bulk_operation');

function scc_handle_manual_check() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Insufficient permissions.', 'signalfire-content-compliance'));
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_manual_check')) {
        wp_die(esc_html__('Security check failed.', 'signalfire-content-compliance'));
    }
    
    $compliance = new SignalfireContentCompliance();
    $compliance->check_compliance();
    
    wp_send_json_success();
}

function scc_handle_send_review_now() {
    try {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
            return;
        }
        
        if (!isset($_POST['post_id']) || !isset($_POST['nonce'])) {
            wp_send_json_error(__('Missing required parameters.', 'signalfire-content-compliance'));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        if ($post_id <= 0) {
            wp_send_json_error(__('Invalid post ID.', 'signalfire-content-compliance'));
            return;
        }
        
        $expected_nonce = 'scc_send_review_' . $post_id;
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        if (!wp_verify_nonce(wp_unslash($_POST['nonce']), $expected_nonce) && !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_send_review_bulk')) {
            wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
            return;
        }
        
        global $wpdb;
        $compliance_table = $wpdb->prefix . 'scc_compliance';
        
        // Check if table exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for table existence check
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $compliance_table));
        if (!$table_exists) {
            wp_send_json_error(__('Compliance database table not found. Please deactivate and reactivate the plugin.', 'signalfire-content-compliance'));
            return;
        }
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance data retrieval in AJAX
        $compliance = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}scc_compliance WHERE post_id = %d",
            $post_id
        ));
        
        if (!$compliance) {
            wp_send_json_error(__('No compliance data found for this post. Please set up compliance settings first.', 'signalfire-content-compliance'));
            return;
        }
        
        // Check if maintainer email is set
        if (empty($compliance->maintainer_email)) {
            wp_send_json_error(__('No maintainer email set for this content.', 'signalfire-content-compliance'));
            return;
        }
        
        // Validate email
        if (!is_email($compliance->maintainer_email)) {
            wp_send_json_error(__('Invalid maintainer email address.', 'signalfire-content-compliance'));
            return;
        }
        
        // Check if post exists
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(__('Post not found.', 'signalfire-content-compliance'));
            return;
        }
        
        // Send email directly instead of creating new instance
        $settings = get_option('scc_settings', array());
        $subject = isset($settings['email_subject']) ? $settings['email_subject'] : 
            /* translators: {post_title} will be replaced with the actual post title */
            __('Content Review Required: {post_title}', 'signalfire-content-compliance');
        $template = isset($settings['email_template']) ? $settings['email_template'] : scc_get_default_email_template();
        
        // Get review URL
        $review_url = home_url('/scc-review/' . $compliance->review_token . '/');
        
        // Replace placeholders
        $placeholders = array(
            '{post_title}' => $post->post_title,
            '{post_url}' => get_permalink($post->ID),
            '{review_url}' => $review_url,
            '{maintainer_email}' => $compliance->maintainer_email,
            '{site_name}' => get_bloginfo('name')
        );
        
        $subject = str_replace(array_keys($placeholders), array_values($placeholders), $subject);
        $message = str_replace(array_keys($placeholders), array_values($placeholders), $template);
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $sent = wp_mail($compliance->maintainer_email, $subject, $message, $headers);
        
        // Log the action
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = '[Signalfire Content Compliance] ';
            if ($sent) {
                $log_message .= 'Review email sent successfully to ' . $compliance->maintainer_email . ' for post ID: ' . $post_id;
            } else {
                $log_message .= 'Failed to send review email to ' . $compliance->maintainer_email . ' for post ID: ' . $post_id;
            }
            // Debug logging removed for production use
        }
        
        if ($sent) {
            wp_send_json_success(array(
                'message' => __('Review request sent successfully!', 'signalfire-content-compliance'),
                'maintainer' => $compliance->maintainer_email
            ));
        } else {
            wp_send_json_error(__('Failed to send email. Please check your WordPress email configuration.', 'signalfire-content-compliance'));
        }
        
    } catch (Exception $e) {
        // Debug logging removed for production use
        wp_send_json_error(__('An unexpected error occurred: ', 'signalfire-content-compliance') . $e->getMessage());
    }
}

function scc_get_default_email_template() {
    return '<html><body>
<h2>Content Review Required</h2>
<p>Dear Maintainer,</p>
<p>The following content on <strong>{site_name}</strong> requires your review for compliance:</p>
<p><strong>Title:</strong> {post_title}<br>
<strong>URL:</strong> <a href="{post_url}">{post_url}</a></p>
<p>Please click the link below to review and approve or submit changes:</p>
<p><a href="{review_url}" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;">Review Content</a></p>
<p>If you have any questions, please contact the website manager.</p>
<p>Thank you,<br>{site_name} Team</p>
</body></html>';
}

function scc_handle_test_email() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
        return;
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_test_email')) {
        wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
        return;
    }
    
    $test_email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    if (!is_email($test_email)) {
        wp_send_json_error(__('Invalid email address.', 'signalfire-content-compliance'));
        return;
    }
    
    $subject = '[' . get_bloginfo('name') . '] Test Email from Content Compliance Plugin';
    $message = '<html><body>';
    $message .= '<h2>Test Email Successful</h2>';
    $message .= '<p>This is a test email from the Signalfire Content Compliance plugin.</p>';
    $message .= '<p><strong>Site:</strong> ' . get_bloginfo('name') . '</p>';
    $message .= '<p><strong>URL:</strong> ' . home_url() . '</p>';
    $message .= '<p><strong>Time:</strong> ' . current_time('mysql') . '</p>';
    $message .= '<p>If you received this email, your WordPress installation can send emails successfully.</p>';
    $message .= '</body></html>';
    
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    $sent = wp_mail($test_email, $subject, $message, $headers);
    
    if ($sent) {
        wp_send_json_success(__('Test email sent successfully!', 'signalfire-content-compliance'));
    } else {
        wp_send_json_error(__('Failed to send test email. Please check your WordPress email configuration.', 'signalfire-content-compliance'));
    }
}

function scc_handle_mark_processed() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
        return;
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_mark_processed')) {
        wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
        return;
    }
    
    $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if ($review_id <= 0) {
        wp_send_json_error(__('Invalid review ID.', 'signalfire-content-compliance'));
        return;
    }
    
    global $wpdb;
    $reviews_table = $wpdb->prefix . 'scc_reviews';
    $compliance_table = $wpdb->prefix . 'scc_compliance';
    
    // Update the review as processed
    $current_user = wp_get_current_user();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for review processing
    $updated = $wpdb->update(
        $reviews_table,
        array(
            'processed_at' => current_time('mysql'),
            'processed_by' => $current_user->display_name
        ),
        array('id' => $review_id),
        array('%s', '%s'),
        array('%d')
    );
    
    if ($updated === false) {
        wp_send_json_error(__('Failed to update review status.', 'signalfire-content-compliance'));
        return;
    }
    
    // Update compliance status back to compliant since it was reviewed
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for compliance status update
    $wpdb->update(
        $compliance_table,
        array(
            'status' => 'compliant',
            'last_review_date' => current_time('mysql')
        ),
        array('post_id' => $post_id),
        array('%s', '%s'),
        array('%d')
    );
    
    wp_send_json_success(array(
        'message' => __('Review marked as processed successfully.', 'signalfire-content-compliance')
    ));
}

function scc_handle_get_review_details() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
        return;
    }
    
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
    if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_get_review_details')) {
        wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
        return;
    }
    
    $review_id = isset($_POST['review_id']) ? intval($_POST['review_id']) : 0;
    
    if ($review_id <= 0) {
        wp_send_json_error(__('Invalid review ID.', 'signalfire-content-compliance'));
        return;
    }
    
    global $wpdb;
    $reviews_table = $wpdb->prefix . 'scc_reviews';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for review details retrieval
    $review = $wpdb->get_row($wpdb->prepare(
        "SELECT r.*, p.post_title 
         FROM {$wpdb->prefix}scc_reviews r 
         JOIN {$wpdb->posts} p ON r.post_id = p.ID 
         WHERE r.id = %d",
        $review_id
    ));
    
    if (!$review) {
        wp_send_json_error(__('Review not found.', 'signalfire-content-compliance'));
        return;
    }
    
    $data = array(
        'post_title' => $review->post_title,
        'maintainer_email' => $review->maintainer_email,
        'action_taken' => ucfirst(str_replace('_', ' ', $review->action_taken)),
        'submitted_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($review->submitted_at)),
        'maintainer_notes' => $review->maintainer_notes,
        'submission_data' => $review->submission_data
    );
    
    wp_send_json_success($data);
}

function scc_handle_bulk_compliance_check() {
    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
            return;
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_bulk_compliance')) {
            wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
            return;
        }
        
        $post_type = isset($_POST['post_type']) ? sanitize_text_field(wp_unslash($_POST['post_type'])) : '';
        $overdue_only = isset($_POST['overdue_only']) ? intval($_POST['overdue_only']) : 0;
        
        if (empty($post_type)) {
            wp_send_json_error(__('Post type is required.', 'signalfire-content-compliance'));
            return;
        }
    
    global $wpdb;
    $compliance_table = $wpdb->prefix . 'scc_compliance';
    
    // Ensure bulk operations table exists
    $bulk_operations_table = $wpdb->prefix . 'scc_bulk_operations';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for table existence check
    $bulk_table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $bulk_operations_table));
    if (!$bulk_table_exists) {
        // Create the missing table
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset_collate = $wpdb->get_charset_collate();
        
        $bulk_operations_sql = "CREATE TABLE $bulk_operations_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            operation_type varchar(50) NOT NULL,
            post_type varchar(50) NOT NULL,
            overdue_only tinyint(1) DEFAULT 0,
            total_posts int(11) NOT NULL,
            successful_emails int(11) DEFAULT 0,
            failed_emails int(11) DEFAULT 0,
            initiated_by varchar(255) NOT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'running',
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY operation_type (operation_type),
            KEY post_type (post_type),
            KEY initiated_by (initiated_by),
            KEY started_at (started_at),
            KEY status (status)
        ) $charset_collate;";
        
        dbDelta($bulk_operations_sql);
    }
    
    // Build query based on overdue_only flag
    if ($overdue_only) {
        // Get only overdue content
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for bulk compliance checking
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.post_id, c.maintainer_email, p.post_title 
            FROM {$wpdb->prefix}scc_compliance c
            JOIN {$wpdb->posts} p ON c.post_id = p.ID
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND c.maintainer_email != ''
            AND c.next_review_date <= %s
            AND c.status != 'compliant'
            ORDER BY c.next_review_date ASC",
            $post_type, 
            current_time('mysql')
        ));
    } else {
        // Get ALL content with compliance settings
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for bulk compliance checking
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT c.post_id, c.maintainer_email, p.post_title 
            FROM {$wpdb->prefix}scc_compliance c
            JOIN {$wpdb->posts} p ON c.post_id = p.ID
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND c.maintainer_email != ''
            ORDER BY p.post_date DESC",
            $post_type
        ));
    }
    
    if (empty($posts)) {
        $message = $overdue_only 
            ? __('No overdue content found for this content type.', 'signalfire-content-compliance')
            : __('No content with compliance settings found for this content type.', 'signalfire-content-compliance');
        wp_send_json_error($message);
        return;
    }
    
    // Log the bulk operation
    $current_user = wp_get_current_user();
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for bulk operation logging
    $result = $wpdb->insert(
        $bulk_operations_table,
        array(
            'operation_type' => 'compliance_check',
            'post_type' => $post_type,
            'overdue_only' => $overdue_only,
            'total_posts' => count($posts),
            'initiated_by' => $current_user->display_name,
            'status' => 'running'
        ),
        array('%s', '%s', '%d', '%d', '%s', '%s')
    );
    
    if ($result === false) {
        wp_send_json_error(__('Failed to log bulk operation. Database error occurred.', 'signalfire-content-compliance'));
        return;
    }
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for getting insert ID
    $operation_id = $wpdb->insert_id;
    
    // Prepare posts data for the frontend
    $posts_data = array();
    foreach ($posts as $post) {
        $posts_data[] = array(
            'id' => intval($post->post_id),
            'title' => $post->post_title,
            'maintainer' => $post->maintainer_email
        );
    }
    
    wp_send_json_success(array(
        'posts' => $posts_data,
        'total' => count($posts_data),
        'operation_id' => $operation_id,
        'message' => sprintf(
            /* translators: %d is the number of posts found */
            __('Found %d posts ready for compliance check.', 'signalfire-content-compliance'),
            count($posts_data)
        )
    ));
    
    } catch (Exception $e) {
        // Debug logging removed for production use
        wp_send_json_error(__('An error occurred during bulk compliance check: ', 'signalfire-content-compliance') . $e->getMessage());
    }
}

function scc_handle_update_bulk_operation() {
    try {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Insufficient permissions.', 'signalfire-content-compliance'));
            return;
        }
        
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonces should not be sanitized
        if (!isset($_POST['nonce']) || !wp_verify_nonce(wp_unslash($_POST['nonce']), 'scc_bulk_compliance')) {
            wp_send_json_error(__('Security check failed.', 'signalfire-content-compliance'));
            return;
        }
        
        $operation_id = isset($_POST['operation_id']) ? intval($_POST['operation_id']) : 0;
        $successful_emails = isset($_POST['successful_emails']) ? intval($_POST['successful_emails']) : 0;
        $failed_emails = isset($_POST['failed_emails']) ? intval($_POST['failed_emails']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : '';
        
        if ($operation_id <= 0) {
            wp_send_json_error(__('Invalid operation ID.', 'signalfire-content-compliance'));
            return;
        }
    
    global $wpdb;
    $bulk_operations_table = $wpdb->prefix . 'scc_bulk_operations';
    
    $update_data = array(
        'successful_emails' => $successful_emails,
        'failed_emails' => $failed_emails,
        'status' => $status
    );
    
    $format = array('%d', '%d', '%s');
    
    if ($status === 'completed') {
        $update_data['completed_at'] = current_time('mysql');
        $format[] = '%s';
    }
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for bulk operation status update
    $updated = $wpdb->update(
        $bulk_operations_table,
        $update_data,
        array('id' => $operation_id),
        $format,
        array('%d')
    );
    
    if ($updated !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error(__('Failed to update operation status.', 'signalfire-content-compliance'));
    }
    
    } catch (Exception $e) {
        // Debug logging removed for production use
        wp_send_json_error(__('An error occurred updating operation status: ', 'signalfire-content-compliance') . $e->getMessage());
    }
}

// Initialize the plugin
new SignalfireContentCompliance();