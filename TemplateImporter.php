<?php

namespace CodexShaper\Framework\Foundation;

use WP_Error;

/**
 * Template Importer Class
 * Handles importing Elementor templates with reliable image processing
 *
 * @package CSMF_Template_Importer
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template Importer Class
 */
class TemplateImporter {
    /**
     * Image processor instance
     * @var ImageProcessor
     */
    protected $image_processor;

    /**
     * Plugin version
     * @var string
     */
    protected $version;

    /**
     * Translation strings
     * @var array
     */
    protected $i18n;

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = CSMF_VERSION;
        $this->setup_i18n();
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Setup internationalization strings
     */
    protected function setup_i18n() {
        $this->i18n = [
            'importing' => __('Importing...', 'elementor-template-importer'),
            'importing_images' => __('Importing images...', 'elementor-template-importer'),
            'success' => __('Success!', 'elementor-template-importer'),
            'error' => __('Error', 'elementor-template-importer'),
            'template_imported' => __('Template imported successfully!', 'elementor-template-importer'),
            'page_imported' => __('Page imported successfully!', 'elementor-template-importer'),
            'no_template_selected' => __('No template selected', 'elementor-template-importer'),
            'no_file_selected' => __('No file selected', 'elementor-template-importer'),
            'import_failed' => __('Import failed', 'elementor-template-importer'),
            'preview' => __('Preview', 'elementor-template-importer'),
            'edit' => __('Edit with Elementor', 'elementor-template-importer'),
            'converting' => __('Converting...', 'elementor-template-importer'),
            'conversionSuccess' => __('Conversion completed successfully!', 'elementor-template-importer'),
        ];
    }

    /**
     * Load dependencies
     */
    protected function load_dependencies() {
        // Load WP Background Processing library
        if (!class_exists('WP_Background_Process')) {
            require_once CSMF_PATH . 'vendor/autoload.php';
        }

        $this->image_processor = new ImageProcessor();
    }

    /**
     * Initialize hooks
     */
    protected function init_hooks() {
        // Admin hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_csmf_fetch_templates', [$this, 'ajax_fetch_templates']);
        add_action('wp_ajax_csmf_import_template', [$this, 'ajax_import_template']);
        add_action('wp_ajax_csmf_upload_template', [$this, 'ajax_upload_template']);
        add_action('wp_ajax_csmf_convert_template_to_page', [$this, 'ajax_convert_template_to_page']);
        add_action('wp_ajax_csmf_check_image_import_status', [$this, 'ajax_check_image_import_status']);

        // Handle completion of image imports
        add_action('csmf_image_import_completed', [$this, 'process_import_completion']);
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Template Importer', 'elementor-template-importer'),
            __('Template Importer', 'elementor-template-importer'),
            'manage_options',
            'elementor-template-importer',
            [$this, 'render_admin_page'],
            'dashicons-insert',
            100
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'toplevel_page_elementor-template-importer') {
            return;
        }

        wp_enqueue_style(
            'csmf-template-importer-css',
            CSMF_URL . 'assets/css/template-importer.css',
            [],
            $this->version
        );

        wp_enqueue_script(
            'csmf-template-importer-js',
            CSMF_URL . 'assets/js/template-importer.js',
            ['jquery'],
            $this->version,
            true
        );

        wp_localize_script(
            'csmf-template-importer-js',
            'csmfTemplateImporter',
            [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('csmf_template_importer_nonce'),
                'i18n' => $this->i18n,
            ]
        );
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        include_once CSMF_PATH . 'views/admin-page.php';
    }

    /**
     * AJAX fetch templates
     */
    public function ajax_fetch_templates() {
        // Check nonce
        if (!check_ajax_referer('csmf_template_importer_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'elementor-template-importer')
            ]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'elementor-template-importer')
            ]);
            return;
        }

        // Fetch templates from API or return cached
        $templates = $this->get_templates();

        if (!$templates) {
            wp_send_json_error([
                'message' => __('Error fetching templates', 'elementor-template-importer')
            ]);
            return;
        }

        wp_send_json_success([
            'templates' => $templates
        ]);
    }

    /**
     * Get templates from remote API or cache
     * 
     * @return array|false Templates array or false on failure
     */
    protected function get_templates() {
        // Check cache first
        $cached = get_transient('csmf_templates_cache');
        if ($cached !== false) {
            return $cached;
        }

        // Fetch from API
        $api_url = 'https://your-template-api.com/templates/'; // Replace with your API endpoint
        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!$data || !isset($data['templates'])) {
            return false;
        }

        // Cache the templates for 12 hours
        set_transient('csmf_templates_cache', $data['templates'], 12 * HOUR_IN_SECONDS);

        return $data['templates'];
    }

    /**
     * AJAX import template
     */
    public function ajax_import_template() {
        // Check nonce
        if (!check_ajax_referer('csmf_template_importer_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'elementor-template-importer')
            ]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'elementor-template-importer')
            ]);
            return;
        }

        // Get template ID, type, and title
        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : '';
        $template_title = isset($_POST['template_title']) ? sanitize_text_field($_POST['template_title']) : '';

        if (empty($template_id) || empty($import_type)) {
            wp_send_json_error([
                'message' => __('Missing required parameters', 'elementor-template-importer')
            ]);
            return;
        }

        // Validate import type
        if (!in_array($import_type, ['page', 'template'])) {
            wp_send_json_error([
                'message' => __('Invalid import type', 'elementor-template-importer')
            ]);
            return;
        }

        // Get cached templates
        $templates = get_transient('csmf_templates_cache');
        if (!$templates || !isset($templates[$template_id])) {
            wp_send_json_error([
                'message' => __('Template not found', 'elementor-template-importer')
            ]);
            return;
        }

        $template = $templates[$template_id];
        
        // Get template URL based on import type
        $template_url = ($import_type === 'page') 
            ? $template['page_import_url'] 
            : $template['template_import_url'];
        
        if (empty($template_url)) {
            wp_send_json_error([
                'message' => __('Template URL not found', 'elementor-template-importer')
            ]);
            return;
        }

        // Fetch template JSON
        $response = wp_remote_get($template_url, [
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message()
            ]);
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $template_data = json_decode($body, true);

        if (!$template_data) {
            wp_send_json_error([
                'message' => __('Invalid template data', 'elementor-template-importer')
            ]);
            return;
        }

        // Import the template
        $result = $this->import_template($template_data, $template_title, $import_type);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
            return;
        }

        // Return success with post ID and URLs
        $post_id = $result['post_id'];
        $job_id = isset($result['job_id']) ? $result['job_id'] : '';

        $response_data = [
            'message' => ($import_type === 'page')
                ? __('Page imported successfully!', 'elementor-template-importer')
                : __('Template imported successfully!', 'elementor-template-importer'),
            'template_id' => $post_id,
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'preview_url' => get_permalink($post_id),
            'has_images' => !empty($job_id),
            'job_id' => $job_id
        ];

        wp_send_json_success($response_data);
    }

    /**
     * Import template
     * 
     * @param array  $template_data Template data
     * @param string $title         Template title
     * @param string $import_type   Import type (page or template)
     * @return array|WP_Error Array with post ID and job ID if successful, WP_Error on failure
     */
    protected function import_template($template_data, $title, $import_type) {
        // Validate template data
        if (!isset($template_data['content'])) {
            return new WP_Error('invalid_data', __('Invalid template data format', 'elementor-template-importer'));
        }

        // Create post type based on import type
        $post_type = ($import_type === 'page') ? 'page' : 'elementor_library';
        
        // Post status
        $post_status = ($import_type === 'page') ? 'draft' : 'publish';
        
        // Prepare post data
        $post_data = [
            'post_title' => !empty($title) ? $title : __('Imported Template', 'elementor-template-importer'),
            'post_status' => $post_status,
            'post_type' => $post_type,
            'post_excerpt' => '',
            'post_content' => ''
        ];
        
        // Insert post
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Get Elementor data
        $elementor_data = $template_data['content'];
        
        // For string data, ensure it's properly decoded
        if (is_string($elementor_data)) {
            $decoded = json_decode($elementor_data, true);
            if ($decoded !== null) {
                $elementor_data = $decoded;
            }
        }
        
        // Re-encode to ensure proper format
        $elementor_data_json = wp_json_encode($elementor_data);
        
        // Save Elementor data
        update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data_json));
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
        
        // Set template type for Elementor library items
        if ($post_type === 'elementor_library') {
            $template_type = isset($template_data['type']) ? $template_data['type'] : 'page';
            update_post_meta($post_id, '_elementor_template_type', $template_type);
        }
        
        // Process page settings if available
        if (isset($template_data['page_settings'])) {
            update_post_meta($post_id, '_elementor_page_settings', $template_data['page_settings']);
        }
        
        // Extract image URLs for processing
        $image_urls = $this->extract_image_urls($elementor_data_json);
        
        // If there are images to process, schedule background processing
        if (!empty($image_urls)) {
            // Initialize progress tracking
            update_post_meta($post_id, '_csmf_image_import_status', 'processing');
            update_post_meta($post_id, '_csmf_images_total', count($image_urls));
            update_post_meta($post_id, '_csmf_images_processed', 0);
            update_post_meta($post_id, '_csmf_image_import_percentage', 0);
            
            // Add items to the background queue
            $job_id = 'csmf_img_' . uniqid();
            update_post_meta($post_id, '_csmf_image_import_job', $job_id);
            
            foreach ($image_urls as $url) {
                $this->image_processor->push_to_queue([
                    'post_id' => $post_id,
                    'url' => $url,
                    'job_id' => $job_id
                ]);
            }
            
            // Start the background processing
            $this->image_processor->save()->dispatch();
            
            return [
                'post_id' => $post_id,
                'job_id' => $job_id
            ];
        }
        
        return [
            'post_id' => $post_id,
            'job_id' => ''
        ];
    }

    /**
     * Extract image URLs from template data
     * 
     * @param string|array $elementor_data Elementor template data
     * @return array Image URLs
     */
    protected function extract_image_urls($elementor_data) {
        $is_string = is_string($elementor_data);
        $data = $is_string ? json_decode($elementor_data, true) : $elementor_data;
        
        if (!is_array($data)) {
            return [];
        }
        
        $image_urls = [];
        $this->find_image_urls_recursive($data, $image_urls);
        
        // Remove duplicates and filter valid URLs
        $image_urls = array_unique(array_filter($image_urls, function($url) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }));
        
        // Remove local URLs
        $site_url = site_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);
        
        return array_filter($image_urls, function($url) use ($site_host) {
            $url_host = parse_url($url, PHP_URL_HOST);
            return $url_host !== $site_host;
        });
    }

    /**
     * Recursively find image URLs in Elementor data
     * 
     * @param array $elements Elements array
     * @param array &$image_urls Array to store found image URLs
     */
    protected function find_image_urls_recursive($elements, &$image_urls) {
        if (!is_array($elements)) {
            return;
        }
        
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            
            // Check settings for images
            if (isset($element['settings'])) {
                $settings = $element['settings'];
                
                // Check for image URL in image widget
                if (isset($settings['image']) && is_array($settings['image']) && !empty($settings['image']['url'])) {
                    $image_urls[] = $settings['image']['url'];
                }
                
                // Direct image URL field
                if (isset($settings['image']) && is_string($settings['image']) && filter_var($settings['image'], FILTER_VALIDATE_URL)) {
                    $image_urls[] = $settings['image'];
                }
                
                // Check for background image
                if (isset($settings['background_image']) && is_array($settings['background_image']) && !empty($settings['background_image']['url'])) {
                    $image_urls[] = $settings['background_image']['url'];
                }
                
                // Check for gallery images
                if (isset($settings['gallery']) && is_array($settings['gallery'])) {
                    foreach ($settings['gallery'] as $gallery_item) {
                        if (is_array($gallery_item) && isset($gallery_item['url'])) {
                            $image_urls[] = $gallery_item['url'];
                        }
                    }
                }
                
                // Check other settings for image URLs
                foreach ($settings as $setting_key => $setting_value) {
                    if (is_string($setting_value) && preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $setting_value) && filter_var($setting_value, FILTER_VALIDATE_URL)) {
                        $image_urls[] = $setting_value;
                    }
                }
            }
            
            // Process child elements recursively
            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->find_image_urls_recursive($element['elements'], $image_urls);
            }
        }
    }

    /**
     * AJAX upload template
     */
    public function ajax_upload_template() {
        // Check nonce
        if (!check_ajax_referer('csmf_template_importer_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'elementor-template-importer')
            ]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'elementor-template-importer')
            ]);
            return;
        }

        // Check if file was uploaded
        if (!isset($_FILES['template_file']) || empty($_FILES['template_file']['tmp_name'])) {
            wp_send_json_error([
                'message' => __('No file uploaded', 'elementor-template-importer')
            ]);
            return;
        }

        // Get import type and title
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'template';
        $template_title = isset($_POST['template_title']) ? sanitize_text_field($_POST['template_title']) : '';

        // Validate file extension
        $file_extension = pathinfo($_FILES['template_file']['name'], PATHINFO_EXTENSION);
        if ($file_extension !== 'json') {
            wp_send_json_error([
                'message' => __('Invalid file format. Please upload a JSON file.', 'elementor-template-importer')
            ]);
            return;
        }

        // Read file contents
        $file_contents = file_get_contents($_FILES['template_file']['tmp_name']);
        $template_data = json_decode($file_contents, true);

        if (empty($template_data)) {
            wp_send_json_error([
                'message' => __('Invalid template data', 'elementor-template-importer')
            ]);
            return;
        }

        // Import the template
        $result = $this->import_template($template_data, $template_title, $import_type);

        if (is_wp_error($result)) {
            wp_send_json_error([
                'message' => $result->get_error_message()
            ]);
            return;
        }

        // Return success with post ID and URLs
        $post_id = $result['post_id'];
        $job_id = isset($result['job_id']) ? $result['job_id'] : '';

        $response_data = [
            'message' => ($import_type === 'page')
                ? __('Page imported successfully!', 'elementor-template-importer')
                : __('Template imported successfully!', 'elementor-template-importer'),
            'template_id' => $post_id,
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'preview_url' => get_permalink($post_id),
            'has_images' => !empty($job_id),
            'job_id' => $job_id
        ];

        wp_send_json_success($response_data);
    }

    /**
     * AJAX convert template to page
     */
    public function ajax_convert_template_to_page() {
        // Check nonce
        if (!check_ajax_referer('csmf_template_importer_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'elementor-template-importer')
            ]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'elementor-template-importer')
            ]);
            return;
        }

        // Get template ID
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;

        if (empty($template_id)) {
            wp_send_json_error([
                'message' => __('No template ID provided', 'elementor-template-importer')
            ]);
            return;
        }

        // Check if template exists and is an Elementor template
        $template = get_post($template_id);
        
        if (!$template || $template->post_type !== 'elementor_library') {
            wp_send_json_error([
                'message' => __('Invalid template', 'elementor-template-importer')
            ]);
            return;
        }

        // Convert the template to a page
        $page_id = $this->convert_template_to_page($template_id);

        if (is_wp_error($page_id)) {
            wp_send_json_error([
                'message' => $page_id->get_error_message()
            ]);
            return;
        }

        wp_send_json_success([
            'message' => __('Template converted to page successfully!', 'elementor-template-importer'),
            'page_id' => $page_id,
            'edit_url' => admin_url('post.php?post=' . $page_id . '&action=elementor'),
            'preview_url' => get_permalink($page_id)
        ]);
    }

    /**
     * Convert template to page
     * 
     * @param int $template_id Template ID
     * @return int|WP_Error Page ID on success, WP_Error on failure
     */
    protected function convert_template_to_page($template_id) {
        // Get template data
        $template = get_post($template_id);
        
        if (!$template) {
            return new WP_Error('invalid_template', __('Template not found', 'elementor-template-importer'));
        }

        // Create new page
        $page_data = [
            'post_title' => $template->post_title,
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_excerpt' => $template->post_excerpt,
            'post_content' => $template->post_content
        ];

        $page_id = wp_insert_post($page_data);

        if (is_wp_error($page_id)) {
            return $page_id;
        }

        // Copy Elementor data
        $elementor_data = get_post_meta($template_id, '_elementor_data', true);
        $page_settings = get_post_meta($template_id, '_elementor_page_settings', true);
        $template_type = get_post_meta($template_id, '_elementor_template_type', true);

        update_post_meta($page_id, '_elementor_data', $elementor_data);
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        
        if ($page_settings) {
            update_post_meta($page_id, '_elementor_page_settings', $page_settings);
        }

        return $page_id;
    }

    /**
     * AJAX check image import status
     */
    public function ajax_check_image_import_status() {
        // Check nonce
        if (!check_ajax_referer('csmf_template_importer_nonce', 'nonce', false)) {
            wp_send_json_error([
                'message' => __('Security check failed', 'elementor-template-importer')
            ]);
            return;
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action', 'elementor-template-importer')
            ]);
            return;
        }

        // Get job ID and post ID
        $job_id = isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : '';
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;

        if (empty($post_id) && empty($job_id)) {
            wp_send_json_error([
                'message' => __('Missing required parameters', 'elementor-template-importer')
            ]);
            return;
        }

        // If post ID is provided but job ID isn't, get job ID from post meta
        if (!empty($post_id) && empty($job_id)) {
            $job_id = get_post_meta($post_id, '_csmf_image_import_job', true);
        }

        // Get post ID from job ID if needed
        if (empty($post_id) && !empty($job_id)) {
            global $wpdb;
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_csmf_image_import_job' AND meta_value = %s",
                $job_id
            ));
            $post_id = intval($post_id);
        }

        if (empty($post_id)) {
            wp_send_json_error([
                'message' => __('Could not find associated post', 'elementor-template-importer')
            ]);
            return;
        }

        // Get status information
        $status = get_post_meta($post_id, '_csmf_image_import_status', true) ?: 'processing';
        $total = intval(get_post_meta($post_id, '_csmf_images_total', true)) ?: 0;
        $processed = intval(get_post_meta($post_id, '_csmf_images_processed', true)) ?: 0;
        $percentage = intval(get_post_meta($post_id, '_csmf_image_import_percentage', true)) ?: 0;
        
        // Check if processing is complete
        if ($processed >= $total && $total > 0) {
            $status = 'complete';
            update_post_meta($post_id, '_csmf_image_import_status', 'complete');
            $percentage = 100;
        }

        wp_send_json_success([
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'percentage' => $percentage,
            'message' => sprintf(
                __('Importing images: %d of %d (%d%%)', 'elementor-template-importer'),
                $processed,
                $total,
                $percentage
            ),
            'post_id' => $post_id,
            'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
            'preview_url' => get_permalink($post_id)
        ]);
    }

        /**
     * Process import completion
     * 
     * @param int $post_id Post ID
     */
    public function process_import_completion($post_id) {
        // Update status to complete
        update_post_meta($post_id, '_csmf_image_import_status', 'complete');
        update_post_meta($post_id, '_csmf_image_import_percentage', 100);
        
        // Log completion
        error_log(sprintf('Template import completed for post ID: %d', $post_id));
        
        // Update post modified date to ensure it appears as recently edited
        wp_update_post([
            'ID' => $post_id,
            'post_modified' => current_time('mysql'),
            'post_modified_gmt' => current_time('mysql', true)
        ]);
        
        // Get any failed images
        $failed_images = get_post_meta($post_id, '_csmf_failed_images', true);
        $has_failures = !empty($failed_images) && is_array($failed_images);
        
        // Add a post meta to indicate import is complete with summary
        update_post_meta($post_id, '_csmf_import_summary', [
            'completed_at' => current_time('mysql'),
            'total_images' => intval(get_post_meta($post_id, '_csmf_images_total', true)),
            'processed_images' => intval(get_post_meta($post_id, '_csmf_images_processed', true)),
            'failed_images' => $has_failures ? count($failed_images) : 0,
            'has_failures' => $has_failures
        ]);
        
        // Send an admin notification for large imports
        $total_images = intval(get_post_meta($post_id, '_csmf_images_total', true));
        if ($total_images > 10) {
            $this->send_completion_notification($post_id, $total_images, $has_failures);
        }
        
        // Trigger action for other plugins/themes to hook into
        do_action('csmf_template_import_fully_completed', $post_id, $total_images, $has_failures);
    }
    
    /**
     * Send notification about import completion
     * 
     * @param int  $post_id      Post ID
     * @param int  $total_images Total images processed
     * @param bool $has_failures Whether there were failures
     */
    protected function send_completion_notification($post_id, $total_images, $has_failures) {
        $post = get_post($post_id);
        if (!$post) {
            return;
        }
        
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        $post_title = $post->post_title;
        $post_type = get_post_type_object($post->post_type);
        $post_type_name = $post_type ? $post_type->labels->singular_name : $post->post_type;
        
        $edit_url = admin_url('post.php?post=' . $post_id . '&action=elementor');
        $preview_url = get_permalink($post_id);
        
        $subject = sprintf(
            __('[%s] Template Import Completed: %s', 'elementor-template-importer'),
            $site_name,
            $post_title
        );
        
        $message = sprintf(
            __("Hello,\n\nThe template import process has been completed for:\n\n%s: %s\n\nImport Summary:\n- Total images processed: %d\n- Import status: %s\n\nYou can now:\n- Edit with Elementor: %s\n- Preview: %s\n\nThank you for using Template Importer.\n\n%s", 'elementor-template-importer'),
            $post_type_name,
            $post_title,
            $total_images,
            $has_failures ? __('Completed with some image failures', 'elementor-template-importer') : __('Successfully completed', 'elementor-template-importer'),
            $edit_url,
            $preview_url,
            $site_name
        );
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Clean up old imports and temporary data
     */
    public function cleanup_old_imports() {
        // Get imports older than 30 days
        $thirty_days_ago = date('Y-m-d H:i:s', strtotime('-30 days'));
        
        global $wpdb;
        
        // Find posts with import meta that are older than 30 days
        $old_imports = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as job_id
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key = '_csmf_image_import_job'
            AND p.post_modified < %s
            LIMIT 100",
            $thirty_days_ago
        ));
        
        if (empty($old_imports)) {
            return;
        }
        
        foreach ($old_imports as $import) {
            // Clean up transients associated with this import
            delete_transient('csmf_image_status_' . $import->job_id);
            
            // Remove excess meta data but keep the summary
            delete_post_meta($import->ID, '_csmf_images_processed');
            delete_post_meta($import->ID, '_csmf_image_import_percentage');
            delete_post_meta($import->ID, '_csmf_image_import_job');
            
            // Keep the import summary and failed images list for reference
        }
        
        // Clean up old transients globally
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '%_transient_csmf_imported_image_%' 
            AND option_name NOT IN (
                SELECT option_name FROM {$wpdb->options} 
                WHERE option_name LIKE '%_transient_csmf_imported_image_%' 
                ORDER BY option_id DESC 
                LIMIT 1000
            )"
        );
    }
    
    /**
     * Register maintenance tasks
     */
    public function register_maintenance_tasks() {
        // Register daily cleanup event if not already scheduled
        if (!wp_next_scheduled('csmf_maintenance_cleanup')) {
            wp_schedule_event(time(), 'daily', 'csmf_maintenance_cleanup');
        }
        
        // Hook the cleanup function
        add_action('csmf_maintenance_cleanup', [$this, 'cleanup_old_imports']);
    }
    
    /**
     * Get import status for frontend display
     * 
     * @param int $post_id Post ID
     * @return array Status information
     */
    public function get_import_status_for_display($post_id) {
        // Check if this post has import data
        $has_import = get_post_meta($post_id, '_csmf_import_summary', true);
        
        if (!$has_import) {
            return [
                'has_import' => false
            ];
        }
        
        $status = get_post_meta($post_id, '_csmf_image_import_status', true) ?: 'complete';
        $total = intval(get_post_meta($post_id, '_csmf_images_total', true)) ?: 0;
        $processed = intval(get_post_meta($post_id, '_csmf_images_processed', true)) ?: $total;
        $percentage = ($total > 0) ? round(($processed / $total) * 100) : 100;
        $failed_images = get_post_meta($post_id, '_csmf_failed_images', true);
        $has_failures = !empty($failed_images) && is_array($failed_images);
        
        return [
            'has_import' => true,
            'status' => $status,
            'total' => $total,
            'processed' => $processed,
            'percentage' => $percentage,
            'has_failures' => $has_failures,
            'failed_count' => $has_failures ? count($failed_images) : 0,
            'completed_at' => get_post_meta($post_id, '_csmf_import_summary', true)['completed_at'] ?? '',
            'message' => ($status === 'complete')
                ? __('Import completed', 'elementor-template-importer')
                : sprintf(__('Importing: %d%%', 'elementor-template-importer'), $percentage)
        ];
    }
    
    /**
     * Get version information
     * 
     * @return array Version information
     */
    public function get_version_info() {
        return [
            'version' => $this->version,
            'wp_version' => get_bloginfo('version'),
            'php_version' => phpversion(),
            'elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : 'not_active',
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => ini_get('memory_limit')
        ];
    }
}