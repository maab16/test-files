<?php
/**
 * Template Importer Extension File
 *
 * @category   Extension
 * @package    CodexShaper_Framework
 * @author     CodexShaper <info@codexshaper.com>
 * @license    https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://codexshaper.com
 * @since      1.0.0
 */

namespace CodexShaper\Framework\Extensions\Elementor;

use CodexShaper\Framework\Foundation\Extension;
use CodexShaper\Framework\Foundation\Traits\Hook;
use Exception;
use WP_Error;
use ZipArchive;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 *  Template Importer Extension Class
 *
 * @category   Class
 * @package    CodexShaper_Framework
 * @author     CodexShaper <info@codexshaper.com>
 * @license    https://www.gnu.org/licenses/gpl-2.0.html
 * @link       https://codexshaper.com
 * @since      1.0.0
 */
class TemplateImporterExtension extends Extension
{
    use Hook;

    /**
     * Base URL for remote templates
     *
     * @var string
     */
    private $templates_base_url = 'https://demo.hivetheme.com/templates/';
    
    /**
     * URL for template index
     *
     * @var string
     */
    private $template_index_url = 'https://demo.hivetheme.com/templates/dioexpress-templates.json';

    /**
     * Plugin version
     * 
     * @var string
     */
    private $version = '1.0.0';

    /**
     * Get extension name.
     *
     * @return string Extension name.
     */
    public function get_name() {
        return 'csmf-template-importer-extension';
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->version = CSMF_VERSION;
        $this->init();
    }

    /**
     * Initialize the extension
     */
    public function init() {
        // Check if Elementor is active
        if (!did_action('elementor/loaded')) {
            add_action('admin_notices', array($this, 'admin_notice_missing_elementor'));
            return;
        }

        // Add template importer menu
        add_action('admin_menu', array($this, 'template_importer_menu'));
        
        // Register assets
        add_action('admin_enqueue_scripts', array($this, 'register_assets'));
        
        // AJAX handlers - register outside of any hook for better reliability
        add_action('wp_ajax_csmf_fetch_templates', array($this, 'ajax_fetch_templates'));
        add_action('wp_ajax_csmf_import_template', array($this, 'ajax_import_template'));
        // add_action('wp_ajax_csmf_convert_template_to_page', [$this, 'ajax_convert_template_to_page']);
        add_action('wp_ajax_csmf_upload_template', array($this, 'ajax_upload_template'));

        // Process direct template import (non-AJAX)
        add_action('admin_init', array($this, 'init_template_processing'));

        // Add template converter functionality
        if (is_admin()) {
            // Register converter assets
            add_action('admin_enqueue_scripts', [$this, 'register_converter_assets']);
            
            // Add button to row actions instead of custom column
            add_filter('post_row_actions', [$this, 'add_template_row_actions'], 10, 2);
            
            // Register AJAX handler directly
            add_action('wp_ajax_csmf_convert_template_to_page', [$this, 'ajax_convert_template_to_page']);
        }
    }

    /**
     * Add a convert button to template row actions
     */
    public function add_template_row_actions($actions, $post) {
        // Only add for Elementor templates
        if ($post->post_type !== 'elementor_library') {
            return $actions;
        }
        
        // Add convert action
        $actions['csmf_convert'] = sprintf(
            '<a href="#" class="csmf-convert-template" data-template-id="%d">%s</a>',
            $post->ID,
            __('Convert to Page', 'elementor-template-importer')
        );
        
        return $actions;
    }

    /**
     * Initialization method for admin_init hook
     */
    public function init_template_processing() {
        // Only process AJAX requests separately, don't do template processing on every admin page
        
        // Register AJAX actions for frontend
        add_action('wp_ajax_csmf_import_template', array($this, 'ajax_import_template'));
    }
    
    /**
     * Admin notice for missing Elementor
     */
    public function admin_notice_missing_elementor() {
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }

        $message = sprintf(
            esc_html__('"%1$s" requires "%2$s" to be installed and activated.', 'elementor-template-importer'),
            '<strong>' . esc_html__('Elementor Template Importer', 'elementor-template-importer') . '</strong>',
            '<strong>' . esc_html__('Elementor', 'elementor-template-importer') . '</strong>'
        );

        printf('<div class="notice notice-warning is-dismissible"><p>%1$s</p></div>', $message);
    }
    
    /**
     * Register admin menu
     */
    public function template_importer_menu() {
        add_submenu_page(
            'codexshaper-framework',
            __('Template Importer', 'elementor-template-importer'),
            __('Template Importer', 'elementor-template-importer'),
            'manage_options',
            'csmf-template-importer',
            array($this, 'render_importer_page')
        );
        
        // Also add under Elementor templates
        add_submenu_page(
            'edit.php?post_type=elementor_library',
            __('Import Templates', 'elementor-template-importer'),
            __('Import Templates', 'elementor-template-importer'),
            'manage_options',
            'elementor-template-importer',
            array($this, 'render_importer_page')
        );
    }
    
    /**
     * Register and enqueue scripts and styles
     */
    public function register_assets() {
        // Register CSS
        wp_register_style(
            'csmf-template-importer-styles',
            CSMF_URL . 'assets/css/template-importer.css',
            [],
            CSMF_VERSION
        );

        // Register JS
        wp_register_script(
            'csmf-template-importer',
            CSMF_URL . 'assets/js/template-importer.js',
            ['jquery'],
            CSMF_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script('csmf-template-importer', 'csmfTemplateImporter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('csmf_template_importer_nonce'),
            'i18n' => [
                // translations...
            ]
        ]);
        
        // Enqueue on admin pages
        if (is_admin()) {
            wp_enqueue_style('csmf-template-importer-styles');
            wp_enqueue_script('csmf-template-importer');
        }
        
        // IMPORTANT: Enqueue in Elementor editor
        add_action('elementor/editor/after_enqueue_scripts', function() {
            wp_enqueue_style('csmf-template-importer-styles');
            wp_enqueue_script('csmf-template-importer');
        });
        
        // Also in preview mode
        add_action('elementor/preview/enqueue_styles', function() {
            wp_enqueue_style('csmf-template-importer-styles');
        });
    }

    /**
     * Register and enqueue converter assets
     */
    public function register_converter_assets($hook) {
        // Only enqueue on the Elementor library screen
        if ($hook !== 'edit.php' || 
            !isset($_GET['post_type']) || 
            $_GET['post_type'] !== 'elementor_library') {
            return;
        }
        
        // Register CSS
        wp_register_style(
            'csmf-template-converter',
            CSMF_URL . 'assets/css/template-converter.css',
            [],
            CSMF_VERSION
        );
        
        // Register JS
        wp_register_script(
            'csmf-template-converter',
            CSMF_URL . 'assets/js/template-converter.js',
            ['jquery'],
            CSMF_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('csmf-template-converter', 'csmfTemplateConverter', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('csmf_template_importer_nonce'),
            'i18n' => [
                'converting' => __('Converting...', 'elementor-template-importer'),
                'successTitle' => __('Success!', 'elementor-template-importer'),
                'preview' => __('Preview', 'elementor-template-importer'),
                'edit' => __('Edit with Elementor', 'elementor-template-importer'),
                'close' => __('Close', 'elementor-template-importer'),
                'errorConverting' => __('Error converting template to page', 'elementor-template-importer'),
                'errorServer' => __('Error connecting to the server', 'elementor-template-importer'),
                'noTemplateSelected' => __('No template selected', 'elementor-template-importer')
            ]
        ]);
        
        // Enqueue assets
        wp_enqueue_style('dashicons');
        wp_enqueue_style('csmf-template-converter');
        wp_enqueue_script('csmf-template-converter');
    }

    /**
     * Add custom column to the Elementor templates list
     * 
     * @param array $columns The columns array
     * @return array Modified columns array
     */
    public function add_template_convert_column($columns) {
        $new_columns = [];
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Add our column after title
            if ($key === 'title') {
                $new_columns['csmf_convert'] = __('Convert', 'elementor-template-importer');
            }
        }
        
        return $new_columns;
    }

    /**
     * Populate the custom column with a Convert button
     * 
     * @param string $column_name The name of the column
     * @param int $post_id The post ID
     */
    public function populate_template_convert_column($column_name, $post_id) {
        if ($column_name !== 'csmf_convert') {
            return;
        }
        
        // Only show button for Elementor templates
        if (get_post_type($post_id) !== 'elementor_library') {
            return;
        }
        
        // Output the button
        echo '<button type="button" class="button button-small csmf-convert-template" data-template-id="' . esc_attr($post_id) . '">' .
            '<span class="dashicons dashicons-download" style="font-size: 16px; vertical-align: text-bottom; margin-right: 3px;"></span> ' .
            __('Convert to Page', 'elementor-template-importer') .
        '</button>';
    }

    /**
     * Render the Importer page
     */
    public function render_importer_page() {
        ?>
        <div class="wrap csmf-template-importer-wrap">
            <h1 class="wp-heading-inline"><?php _e('Template Importer', 'elementor-template-importer'); ?></h1>
            
            <div class="csmf-template-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#remote-templates" class="nav-tab nav-tab-active"><?php _e('Remote Templates', 'elementor-template-importer'); ?></a>
                    <a href="#upload-template" class="nav-tab"><?php _e('Upload Template', 'elementor-template-importer'); ?></a>
                </nav>
                
                <div class="csmf-tab-content" id="remote-templates-tab">
                    <div class="csmf-templates-header">
                        <div class="csmf-search-container">
                            <input type="text" class="csmf-search-input" placeholder="<?php esc_attr_e('Search templates...', 'elementor-template-importer'); ?>">
                        </div>
                        
                        <div class="csmf-actions">
                            <button class="button csmf-refresh-button">
                                <span class="dashicons dashicons-update"></span> 
                                <?php _e('Refresh Templates', 'elementor-template-importer'); ?>
                            </button>
                        </div>
                    </div>
                    
                    <div class="csmf-templates-loader">
                        <div class="csmf-loader-message">
                            <span class="spinner is-active"></span>
                            <?php _e('Loading templates...', 'elementor-template-importer'); ?>
                        </div>
                    </div>
                    
                    <div class="csmf-filter-container"></div>
                    <div class="csmf-templates-grid" style="display:none;"></div>
                </div>
                
                <div class="csmf-tab-content" id="upload-template-tab" style="display:none;">
                    <div class="csmf-uploader-container">
                        <form id="csmf-template-upload-form" method="post" enctype="multipart/form-data">
                            <div class="csmf-upload-instructions">
                                <h3><?php _e('Upload Template File', 'elementor-template-importer'); ?></h3>
                                <p><?php _e('Select a template file exported from Elementor to import.', 'elementor-template-importer'); ?></p>
                                <p><?php _e('Supported file formats: JSON, XML or ZIP file containing both.', 'elementor-template-importer'); ?></p>
                            </div>
                            
                            <div class="csmf-upload-fields">
                                <div class="csmf-file-upload">
                                    <label for="csmf-template-file" class="csmf-file-label">
                                        <span class="dashicons dashicons-upload"></span>
                                        <?php _e('Choose File...', 'elementor-template-importer'); ?>
                                    </label>
                                    <input type="file" name="template_file" id="csmf-template-file" accept=".json,.xml,.zip" required />
                                    <span class="csmf-file-name"><?php _e('No file selected', 'elementor-template-importer'); ?></span>
                                </div>
                                
                                <div class="csmf-import-options">
                                    <label><?php _e('Import As:', 'elementor-template-importer'); ?></label>
                                    <div class="csmf-radio-buttons">
                                        <label class="csmf-radio">
                                            <input type="radio" name="import_type" value="page" checked>
                                            <span class="csmf-radio-label"><?php _e('Page', 'elementor-template-importer'); ?></span>
                                            <span class="csmf-radio-desc"><?php _e('Import as a regular WordPress page', 'elementor-template-importer'); ?></span>
                                        </label>
                                        <label class="csmf-radio">
                                            <input type="radio" name="import_type" value="template">
                                            <span class="csmf-radio-label"><?php _e('Template', 'elementor-template-importer'); ?></span>
                                            <span class="csmf-radio-desc"><?php _e('Import as an Elementor template in the Template Library', 'elementor-template-importer'); ?></span>
                                        </label>
                                    </div>
                                </div>
                                
                                <input type="hidden" name="action" value="csmf_upload_template" />
                                <?php wp_nonce_field('csmf_template_upload_nonce', 'csmf_nonce'); ?>
                                
                                <button type="submit" class="button button-primary csmf-upload-button">
                                    <span class="dashicons dashicons-upload"></span>
                                    <?php _e('Upload & Import', 'elementor-template-importer'); ?>
                                </button>
                            </div>
                            
                            <div class="csmf-upload-progress" style="display:none;">
                                <div class="csmf-progress-bar">
                                    <div class="csmf-progress-bar-inner"></div>
                                </div>
                                <div class="csmf-progress-status">0%</div>
                            </div>
                            
                            <div class="csmf-upload-response"></div>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="csmf-footer">
                <p>
                    <?php 
                    printf(
                        __('Template Importer v%s | Developed by %s', 'elementor-template-importer'),
                        $this->version,
                        '<a href="https://codexshaper.com/" target="_blank">CodexShaper</a>'
                    ); 
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX handler to fetch templates
     */
    public function ajax_fetch_templates() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csmf_template_importer_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'elementor-template-importer')));
        }

        // Get templates from remote URL
        $response = wp_remote_get($this->template_index_url, array(
            'timeout' => 60,
            'sslverify' => false,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => __('Error fetching templates: ', 'elementor-template-importer') . $response->get_error_message()
            ));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Error fetching templates. Server returned status code: %d', 'elementor-template-importer'),
                    $status_code
                )
            ));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => __('Invalid JSON response: ', 'elementor-template-importer') . json_last_error_msg()
            ));
        }
        
        if (!isset($data['templates']) || !is_array($data['templates'])) {
            wp_send_json_error(array(
                'message' => __('No templates found in response', 'elementor-template-importer')
            ));
        }
        
        // Process templates to ensure URLs are correctly formatted
        $processed_templates = array();
        
        foreach ($data['templates'] as $id => $template) {
            // Make sure each template has required fields
            if (!isset($template['title'])) {
                continue;
            }
            
            // Ensure ID is set
            $template_id = isset($template['id']) ? $template['id'] : $id;
            
            // Format preview URL if not set
            if (!isset($template['preview_url']) || empty($template['preview_url'])) {
                // Use thumbnail URL as preview URL if available
                $template['preview_url'] = isset($template['thumbnail']) && !empty($template['thumbnail']) 
                    ? $template['thumbnail'] 
                    : CSMF_URL . 'assets/images/template-placeholder.jpg';
            }
            
            // Format import URLs for page and template
            $template['page_import_url'] = $this->templates_base_url . 'csmf-page-' . $template_id . '.xml';
            $template['template_import_url'] = $this->templates_base_url . 'csmf-page-' . $template_id . '.json';
            
            // Set default thumbnail if missing
            if (!isset($template['thumbnail']) || empty($template['thumbnail'])) {
                $template['thumbnail'] = CSMF_URL . 'assets/images/template-placeholder.jpg';
            }
            
            // Add template to processed array
            $processed_templates[$template_id] = $template;
        }
        
        // Return the templates
        wp_send_json_success(array(
            'templates' => $processed_templates,
            'count' => count($processed_templates)
        ));
    }

    /**
     * AJAX handler for importing templates
     */
    public function ajax_import_template() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csmf_template_importer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Get parameters
        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'template';
        $template_title = isset($_POST['template_title']) ? sanitize_text_field($_POST['template_title']) : '';
        
        if (empty($template_id)) {
            wp_send_json_error(array('message' => 'Template ID is required'));
            return;
        }
        
        // Build template URL - try JSON first
        $template_url = 'https://demo.hivetheme.com/templates/csmf-page-' . $template_id . '.json';
        
        // Download the template
        $response = wp_remote_get($template_url, array(
            'timeout' => 60,
            'sslverify' => false
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error downloading template: ' . $response->get_error_message()));
            return;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            // Try alternative URL format with XML
            $template_url = 'https://demo.hivetheme.com/templates/csmf-page-' . $template_id . '.xml';
            
            $response = wp_remote_get($template_url, array(
                'timeout' => 60,
                'sslverify' => false
            ));
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                wp_send_json_error(array('message' => 'Template not found. Please check the template ID.'));
                return;
            }
        }
        
        // Get content and determine file type
        $content = wp_remote_retrieve_body($response);
        $file_extension = pathinfo($template_url, PATHINFO_EXTENSION);
        
        // Import the template
        if ($file_extension === 'json') {
            $result = $this->import_json_template($content, $template_title, $import_type);
        } else {
            $result = $this->import_xml_template($content, $template_title, $import_type);
        }
        
        // Check for errors
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Return success
        wp_send_json_success(array(
            'message' => 'Template imported successfully',
            'template_id' => $result,
            'edit_url' => admin_url('post.php?post=' . $result . '&action=elementor')
        ));
    }
    
    /**
     * AJAX handler for uploading templates
     */
    public function ajax_upload_template() {
        // Verify nonce
        if (!isset($_POST['csmf_nonce']) || !wp_verify_nonce($_POST['csmf_nonce'], 'csmf_template_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'elementor-template-importer')));
        }
        
        // Check file upload
        if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
            $error_message = isset($_FILES['template_file']) ? 
                            $this->get_upload_error_message($_FILES['template_file']['error']) : 
                            __('No file uploaded', 'elementor-template-importer');
            
            wp_send_json_error(array('message' => $error_message));
        }
        
        // Get import type
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'template';
        
        $file_tmp = $_FILES['template_file']['tmp_name'];
        $file_name = $_FILES['template_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Process the template based on file type
        $result = false;
        $template_title = pathinfo($file_name, PATHINFO_FILENAME);
        
        if ($file_ext === 'json') {
            $template_content = file_get_contents($file_tmp);
            $result = $this->import_json_template($template_content, $template_title, $import_type);
        } elseif ($file_ext === 'xml') {
            $template_content = file_get_contents($file_tmp);
            $result = $this->import_xml_template($template_content, $template_title, $import_type);
        } elseif ($file_ext === 'zip') {
            $result = $this->import_zip_template_from_file($file_tmp, $template_title, $import_type);
        } else {
            wp_send_json_error(array(
                'message' => __('Unsupported file format. Please upload JSON, XML, or ZIP files.', 'elementor-template-importer')
            ));
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
        }
        
        // Get post type for response information
        $post_type = get_post_type($result);
        $post_title = get_the_title($result);
        
        wp_send_json_success(array(
            'message' => sprintf(
                __('Template "%s" imported successfully as %s', 'elementor-template-importer'),
                $post_title,
                $post_type === 'page' ? __('Page', 'elementor-template-importer') : __('Template', 'elementor-template-importer')
            ),
            'template_id' => $result,
            'post_type' => $post_type,
            'edit_url' => admin_url('post.php?post=' . $result . '&action=elementor')
        ));
    }

    /**
     * Process template import
     * Public method that can be called from multiple places
     * 
     * @param string $content Template content
     * @param string $file_extension File extension (json, xml)
     * @param string $template_title Template title
     * @param string $import_type Import type (page or template)
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    public function process_template_import($content, $file_extension, $template_title, $import_type) {
        if ($file_extension === 'json') {
            return $this->import_json_template($content, $template_title, $import_type);
        } elseif ($file_extension === 'xml') {
            return $this->import_xml_template($content, $template_title, $import_type);
        } else {
            return new WP_Error('unsupported_format', 'Unsupported file format: ' . $file_extension);
        }
    }

    /**
     * Import JSON template
     * 
     * @param string $content JSON content
     * @param string $title Template title
     * @param string $import_type Import type (page or template)
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function import_json_template($content, $title, $import_type) {
        // Disable WordPress URL filters
        $this->disable_url_filters();
        
        try {
            // Decode JSON
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Invalid JSON format');
            }
            
            // Determine Elementor data format
            $elementor_data = '';
            if (isset($data[0]['elType'])) {
                // Direct Elementor data
                $elementor_data = $content; // Use raw content to preserve exact format
            } elseif (isset($data['content'])) {
                // Template export format
                if (is_array($data['content'])) {
                    $elementor_data = wp_json_encode($data['content']);
                } else {
                    $elementor_data = $data['content'];
                }
            } else {
                return new WP_Error('invalid_format', 'Unrecognized template format');
            }
            
            // Extract page settings
            $page_settings = array();
            if (isset($data['page_settings']) && is_array($data['page_settings'])) {
                $page_settings = $data['page_settings'];
            }
            
            // Create post
            $post_data = array(
                'post_title' => !empty($title) ? $title : 'Imported Template',
                'post_status' => 'publish',
                'post_type' => $import_type === 'page' ? 'page' : 'elementor_library',
                'post_content' => '',
            );
            
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id)) {
                return $post_id;
            }
            
            // Set Elementor edit mode
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            
            // Set template type for Elementor library
            if ($import_type === 'template') {
                $template_type = isset($data['type']) ? $data['type'] : 'page';
                update_post_meta($post_id, '_elementor_template_type', $template_type);
            }
            
            // Set page template
            if (isset($page_settings['template'])) {
                update_post_meta($post_id, '_wp_page_template', $page_settings['template']);
            } else {
                update_post_meta($post_id, '_wp_page_template', 'elementor_header_footer');
            }
            
            // Set page settings
            if (!empty($page_settings)) {
                update_post_meta($post_id, '_elementor_page_settings', $page_settings);
            }
            
            // Save Elementor data - CRITICAL: Use wp_slash to preserve backslashes
            update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
            
            // Enable URL filters
            $this->enable_url_filters();
            
            return $post_id;
        } catch (Exception $e) {
            $this->enable_url_filters();
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

    /**
     * AJAX handler for converting template to page
     */
    public function ajax_convert_template_to_page() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csmf_template_importer_nonce')) {
            wp_send_json_error(['message' => __('Security check failed', 'elementor-template-importer')]);
            return;
        }
        
        // Check template ID
        if (!isset($_POST['template_id']) || empty($_POST['template_id'])) {
            wp_send_json_error(['message' => __('No template selected', 'elementor-template-importer')]);
            return;
        }
        
        $template_id = intval($_POST['template_id']);
        $template = get_post($template_id);
        
        // Check if template exists
        if (!$template || $template->post_type !== 'elementor_library') {
            wp_send_json_error(['message' => __('Invalid template', 'elementor-template-importer')]);
            return;
        }
        
        // Get template data
        $elementor_data = get_post_meta($template_id, '_elementor_data', true);
        
        if (empty($elementor_data)) {
            wp_send_json_error(['message' => __('Template has no content', 'elementor-template-importer')]);
            return;
        }
        
        // Create new page
        $page_data = [
            'post_title' => $template->post_title . ' (Page)',
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_content' => '', // We'll use Elementor data instead
        ];
        
        $page_id = wp_insert_post($page_data);
        
        if (is_wp_error($page_id)) {
            wp_send_json_error(['message' => $page_id->get_error_message()]);
            return;
        }
        
        // Copy Elementor data
        update_post_meta($page_id, '_elementor_data', wp_slash($elementor_data));
        
        // Copy Elementor settings and configuration
        update_post_meta($page_id, '_elementor_edit_mode', 'builder');
        update_post_meta($page_id, '_elementor_template_type', 'wp-page');
        
        // Copy page template
        $page_template = get_post_meta($template_id, '_wp_page_template', true);
        if (!empty($page_template)) {
            update_post_meta($page_id, '_wp_page_template', $page_template);
        } else {
            update_post_meta($page_id, '_wp_page_template', 'elementor_header_footer');
        }
        
        // Copy any additional Elementor meta
        $page_settings = get_post_meta($template_id, '_elementor_page_settings', true);
        if (!empty($page_settings)) {
            update_post_meta($page_id, '_elementor_page_settings', $page_settings);
        }
        
        // Get URLs for the new page
        $preview_url = get_permalink($page_id);
        $edit_url = admin_url('post.php?post=' . $page_id . '&action=elementor');
        
        // Send success response with complete data
        wp_send_json_success([
            'message' => sprintf(
                __('Template "%s" successfully converted to page', 'elementor-template-importer'),
                esc_html($template->post_title)
            ),
            'title' => __('Success!', 'elementor-template-importer'),
            'page_id' => $page_id,
            'preview_url' => $preview_url,
            'edit_url' => $edit_url,
        ]);
    }

    /**
     * Examine a template file and display its structure
     * Helps diagnose import issues
     * 
     * @param string $url Template URL
     * @return string Debug information about the template
     */
    public function examine_template_file($url) {
        if (!current_user_can('manage_options')) {
            return 'Insufficient permissions';
        }
        
        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'sslverify' => false,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            return 'Error downloading template: ' . $response->get_error_message();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            return 'Error: Server returned status code ' . $status_code;
        }
        
        $content = wp_remote_retrieve_body($response);
        $file_extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        
        $output = "Template Examination Results:\n\n";
        $output .= "File type: " . strtoupper($file_extension) . "\n";
        $output .= "Content length: " . strlen($content) . " bytes\n\n";
        
        if ($file_extension === 'json') {
            // Parse JSON and show structure
            $data = json_decode($content, true);
            if ($data === null) {
                $output .= "INVALID JSON: " . json_last_error_msg() . "\n";
                return $output;
            }
            
            $output .= "JSON Structure:\n";
            $output .= "Top-level keys: " . implode(", ", array_keys($data)) . "\n\n";
            
            // Check for Elementor data
            if (isset($data[0]['id']) || isset($data[0]['elType'])) {
                $output .= "FORMAT: Direct Elementor data array\n";
                $output .= "Number of elements: " . count($data) . "\n";
                $output .= "First element type: " . (isset($data[0]['elType']) ? $data[0]['elType'] : 'unknown') . "\n";
            } else if (isset($data['content'])) {
                $output .= "FORMAT: Template export with content field\n";
                if (is_string($data['content'])) {
                    $output .= "Content field is string, length: " . strlen($data['content']) . "\n";
                    
                    // Try to parse the content
                    $content_data = json_decode($data['content'], true);
                    if ($content_data !== null) {
                        $output .= "Content field contains valid JSON\n";
                        if (is_array($content_data)) {
                            $output .= "Content elements: " . count($content_data) . "\n";
                        }
                    } else {
                        $output .= "Content field is not valid JSON\n";
                    }
                } else if (is_array($data['content'])) {
                    $output .= "Content field is array with " . count($data['content']) . " elements\n";
                } else {
                    $output .= "Content field has unknown type: " . gettype($data['content']) . "\n";
                }
            }
            
            // Check for page settings
            if (isset($data['page_settings'])) {
                $output .= "\nPage Settings:\n";
                $output .= "Settings keys: " . implode(", ", array_keys($data['page_settings'])) . "\n";
                if (isset($data['page_settings']['template'])) {
                    $output .= "Template: " . $data['page_settings']['template'] . "\n";
                }
            }
        } else if ($file_extension === 'xml') {
            // Parse XML and show structure
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            if ($xml === false) {
                $output .= "INVALID XML: \n";
                foreach(libxml_get_errors() as $error) {
                    $output .= "  " . $error->message . "\n";
                }
                libxml_clear_errors();
                return $output;
            }
            
            $output .= "XML Structure:\n";
            $output .= "Root element: " . $xml->getName() . "\n\n";
            
            // Check for WordPress structure
            if (isset($xml->channel)) {
                $output .= "FORMAT: WordPress export XML\n";
                $output .= "Items: " . (isset($xml->channel->item) ? count($xml->channel->item) : 0) . "\n\n";
                
                if (isset($xml->channel->item)) {
                    $output .= "Examining first item:\n";
                    $item = $xml->channel->item[0];
                    
                    if (isset($item->children('wp', true)->post_type)) {
                        $output .= "Post type: " . (string)$item->children('wp', true)->post_type . "\n";
                    }
                    
                    // Look for Elementor data
                    if (isset($item->children('wp', true)->postmeta)) {
                        $elementor_found = false;
                        foreach ($item->children('wp', true)->postmeta as $meta) {
                            $meta_key = (string)$meta->children('wp', true)->meta_key;
                            if ($meta_key === '_elementor_data') {
                                $elementor_found = true;
                                $elementor_data = (string)$meta->children('wp', true)->meta_value;
                                $output .= "Found _elementor_data, length: " . strlen($elementor_data) . "\n";
                                
                                // Check if it's valid JSON
                                $data = json_decode($elementor_data, true);
                                if ($data !== null) {
                                    $output .= "Elementor data is valid JSON\n";
                                } else {
                                    $output .= "Elementor data is NOT valid JSON: " . json_last_error_msg() . "\n";
                                }
                                break;
                            }
                        }
                        
                        if (!$elementor_found) {
                            $output .= "No _elementor_data found in postmeta\n";
                        }
                    }
                }
            } else {
                $output .= "Non-standard XML format\n";
            }
        }
        
        return $output;
    }

    /**
     * Import ZIP template
     * 
     * @param string $content ZIP file content
     * @param string $title   Template title
     * @param string $import_type Whether to import as 'page' or 'template'
     * @return int|WP_Error   Template ID on success, WP_Error on failure
     */
    private function import_zip_template($content, $title, $import_type = 'template') {
        if (!class_exists('ZipArchive')) {
            return new \WP_Error(
                'missing_zip_support', 
                __('ZIP support is not available on your server. Please contact your hosting provider.', 'elementor-template-importer')
            );
        }
        
        // Create a temporary file to save ZIP content
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/elementor-importer-' . uniqid();
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $temp_file = $temp_dir . '/template.zip';
        file_put_contents($temp_file, $content);
        
        // Extract the ZIP file
        $extract_dir = $temp_dir . '/extract';
        wp_mkdir_p($extract_dir);
        
        $zip = new \ZipArchive();
        $result = $zip->open($temp_file);
        
        if ($result !== true) {
            rmdir($extract_dir);
            unlink($temp_file);
            rmdir($temp_dir);
            
            return new \WP_Error(
                'invalid_zip', 
                __('Invalid or corrupted ZIP file.', 'elementor-template-importer')
            );
        }
        
        $zip->extractTo($extract_dir);
        $zip->close();
        
        // Find and process template files
        $files = scandir($extract_dir);
        $json_file = null;
        $xml_file = null;
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $file_ext = pathinfo($file, PATHINFO_EXTENSION);
            
            if ($file_ext === 'json') {
                $json_file = $extract_dir . '/' . $file;
            } elseif ($file_ext === 'xml') {
                $xml_file = $extract_dir . '/' . $file;
            }
        }
        
        // Import template
        $result = null;
        
        if ($json_file) {
            $content = file_get_contents($json_file);
            $result = $this->import_json_template($content, $title, $import_type);
        } elseif ($xml_file) {
            $content = file_get_contents($xml_file);
            $result = $this->import_xml_template($content, $title, $import_type);
        } else {
            $result = new \WP_Error(
                'missing_template', 
                __('No valid template file found in ZIP archive.', 'elementor-template-importer')
            );
        }
        
        // Clean up
        $this->recursive_delete_directory($extract_dir);
        unlink($temp_file);
        rmdir($temp_dir);
        
        return $result;
    }

    /**
     * Import ZIP template from uploaded file
     * 
     * @param string $file_path Path to the ZIP file
     * @param string $title     Template title
     * @param string $import_type Whether to import as 'page' or 'template'
     * @return int|WP_Error     Template ID on success, WP_Error on failure
     */
    private function import_zip_template_from_file($file_path, $title, $import_type = 'template') {
        if (!class_exists('ZipArchive')) {
            return new \WP_Error(
                'missing_zip_support', 
                __('ZIP support is not available on your server. Please contact your hosting provider.', 'elementor-template-importer')
            );
        }
        
        // Create a temporary directory for extraction
        $upload_dir = wp_upload_dir();
        $extract_dir = $upload_dir['basedir'] . '/elementor-importer-extract-' . uniqid();
        
        if (!file_exists($extract_dir)) {
            wp_mkdir_p($extract_dir);
        }
        
        // Extract the ZIP file
        $zip = new \ZipArchive();
        $result = $zip->open($file_path);
        
        if ($result !== true) {
            rmdir($extract_dir);
            
            return new \WP_Error(
                'invalid_zip', 
                __('Invalid or corrupted ZIP file.', 'elementor-template-importer')
            );
        }
        
        $zip->extractTo($extract_dir);
        $zip->close();
        
        // Find and process template files
        $files = scandir($extract_dir);
        $json_file = null;
        $xml_file = null;
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $file_ext = pathinfo($file, PATHINFO_EXTENSION);
            
            if ($file_ext === 'json') {
                $json_file = $extract_dir . '/' . $file;
            } elseif ($file_ext === 'xml') {
                $xml_file = $extract_dir . '/' . $file;
            }
        }
        
        // Import template
        $result = null;
        
        if ($json_file) {
            $content = file_get_contents($json_file);
            $result = $this->import_json_template($content, $title, $import_type);
        } elseif ($xml_file) {
            $content = file_get_contents($xml_file);
            $result = $this->import_xml_template($content, $title, $import_type);
        } else {
            $result = new \WP_Error(
                'missing_template', 
                __('No valid template file found in ZIP archive.', 'elementor-template-importer')
            );
        }
        
        // Clean up
        $this->recursive_delete_directory($extract_dir);
        
        return $result;
    }
    
    /**
     * Get upload error message based on error code
     * 
     * @param int $error_code PHP file upload error code
     * @return string Error message
     */
    private function get_upload_error_message($error_code) {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return __('The uploaded file exceeds the upload_max_filesize directive in php.ini', 'elementor-template-importer');
            case UPLOAD_ERR_FORM_SIZE:
                return __('The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form', 'elementor-template-importer');
            case UPLOAD_ERR_PARTIAL:
                return __('The uploaded file was only partially uploaded', 'elementor-template-importer');
            case UPLOAD_ERR_NO_FILE:
                return __('No file was uploaded', 'elementor-template-importer');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Missing a temporary folder', 'elementor-template-importer');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Failed to write file to disk', 'elementor-template-importer');
            case UPLOAD_ERR_EXTENSION:
                return __('A PHP extension stopped the file upload', 'elementor-template-importer');
            default:
                return __('Unknown upload error', 'elementor-template-importer');
        }
    }

    /**
     * Recursively delete a directory
     * 
     * @param string $dir Directory path
     * @return bool True on success, false on failure
     */
    private function recursive_delete_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->recursive_delete_directory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }

    /**
     * Import XML template
     * 
     * @param string $content XML content
     * @param string $title Template title
     * @param string $import_type Import type (page or template)
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function import_xml_template($content, $title, $import_type) {
        // Disable WordPress URL filters
        $this->disable_url_filters();
        
        try {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            
            if ($xml === false) {
                $errors = '';
                foreach(libxml_get_errors() as $error) {
                    $errors .= $error->message . "\n";
                }
                libxml_clear_errors();
                return new WP_Error('invalid_xml', 'XML parsing failed: ' . $errors);
            }
            
            // Find Elementor data in WordPress export format
            $elementor_data = null;
            $page_settings = array();
            $page_template = 'elementor_header_footer';
            
            if (isset($xml->channel) && isset($xml->channel->item)) {
                foreach ($xml->channel->item as $item) {
                    // Check for post type
                    $post_type = (string)$item->children('wp', true)->post_type;
                    if ($post_type === 'page' || $post_type === 'elementor_library') {
                        // Look for Elementor data in postmeta
                        if (isset($item->children('wp', true)->postmeta)) {
                            foreach ($item->children('wp', true)->postmeta as $meta) {
                                $meta_key = (string)$meta->children('wp', true)->meta_key;
                                $meta_value = (string)$meta->children('wp', true)->meta_value;
                                
                                if ($meta_key === '_elementor_data') {
                                    $elementor_data = $meta_value;
                                } elseif ($meta_key === '_wp_page_template') {
                                    $page_template = $meta_value;
                                }
                            }
                        }
                        
                        // If we found Elementor data, we can stop looking
                        if ($elementor_data !== null) {
                            break;
                        }
                    }
                }
            }
            
            if ($elementor_data === null) {
                return new WP_Error('no_elementor_data', 'No Elementor data found in the XML file');
            }
            
            // Create post
            $post_data = array(
                'post_title' => !empty($title) ? $title : 'Imported Template',
                'post_status' => 'publish',
                'post_type' => $import_type === 'page' ? 'page' : 'elementor_library',
                'post_content' => '',
            );
            
            $post_id = wp_insert_post($post_data);
            if (is_wp_error($post_id)) {
                return $post_id;
            }
            
            // Set Elementor edit mode
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            
            // Set template type for Elementor library
            if ($import_type === 'template') {
                update_post_meta($post_id, '_elementor_template_type', 'page');
            }
            
            // Set page template
            update_post_meta($post_id, '_wp_page_template', $page_template);
            
            // Save Elementor data - CRITICAL: Use wp_slash to preserve backslashes
            update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
            
            // Enable URL filters
            $this->enable_url_filters();
            
            return $post_id;
        } catch (Exception $e) {
            $this->enable_url_filters();
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

    /**
     * Disable WordPress URL filters that might modify URLs during import
     */
    private function disable_url_filters() {
        // Remove content filters
        remove_all_filters('content_save_pre');
        
        // Allow unfiltered HTML and uploads
        add_filter('elementor/files/allow_unfiltered_upload', '__return_true');
    }

    /**
     * Enable WordPress URL filters
     */
    private function enable_url_filters() {
        // Re-enable filters if needed
        remove_filter('elementor/files/allow_unfiltered_upload', '__return_true');
    }

    /**
     * Import XML with simple parser - enhanced version
     * 
     * @param string $content Template content
     * @param string $title   Template title
     * @param string $import_type Whether to import as 'page' or 'template'
     * @return int|WP_Error   Template ID on success, WP_Error on failure
     */
    private function import_xml_with_simple_parser($content, $title, $import_type) {
        error_log('Starting simple XML parser import');
        
        // Try to parse XML
        libxml_use_internal_errors(true);
        $xml = @simplexml_load_string($content);
        
        if ($xml === false) {
            $errors = '';
            foreach(libxml_get_errors() as $error) {
                $errors .= $error->message . "\n";
            }
            libxml_clear_errors();
            error_log('XML parsing failed: ' . $errors);
            return new \WP_Error('invalid_xml', __('Invalid XML content: ', 'elementor-template-importer') . $errors);
        }
        
        error_log('XML parsed successfully');
        
        // Try direct extraction with multiple methods
        $elementor_data = false;
        $page_settings = array();
        
        // Method 1: Look for Elementor data in WordPress export format
        if (isset($xml->channel) && isset($xml->channel->item)) {
            error_log('WordPress export format detected');
            
            foreach ($xml->channel->item as $item) {
                // Check if this is an Elementor template or page
                $post_type = (string)$item->children('wp', true)->post_type;
                if ($post_type === 'page' || $post_type === 'elementor_library') {
                    error_log('Found relevant item: ' . $post_type);
                    
                    // Look for Elementor data
                    if (isset($item->children('wp', true)->postmeta)) {
                        foreach ($item->children('wp', true)->postmeta as $meta) {
                            $meta_key = (string)$meta->children('wp', true)->meta_key;
                            
                            if ($meta_key === '_elementor_data') {
                                $meta_value = (string)$meta->children('wp', true)->meta_value;
                                error_log('Found _elementor_data, length: ' . strlen($meta_value));
                                
                                // Check if it's valid JSON
                                json_decode($meta_value);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $elementor_data = $meta_value;
                                    error_log('Valid Elementor data found');
                                } else {
                                    error_log('Found Elementor data but it\'s not valid JSON: ' . json_last_error_msg());
                                    // Try to fix by double-decoding if it seems like double-encoded JSON
                                    if (strpos($meta_value, '\\"') !== false) {
                                        $fixed_data = stripslashes($meta_value);
                                        json_decode($fixed_data);
                                        if (json_last_error() === JSON_ERROR_NONE) {
                                            $elementor_data = $fixed_data;
                                            error_log('Fixed Elementor data by removing slashes');
                                        }
                                    }
                                }
                            } elseif ($meta_key === '_elementor_page_settings') {
                                $page_settings_value = (string)$meta->children('wp', true)->meta_value;
                                error_log('Found _elementor_page_settings');
                                
                                // Try to parse as serialized or JSON
                                $decoded = @unserialize($page_settings_value);
                                if ($decoded !== false) {
                                    $page_settings = $decoded;
                                    error_log('Page settings are serialized PHP data');
                                } else {
                                    $decoded = json_decode($page_settings_value, true);
                                    if (is_array($decoded)) {
                                        $page_settings = $decoded;
                                        error_log('Page settings are JSON data');
                                    }
                                }
                            } elseif ($meta_key === '_wp_page_template') {
                                $page_template = (string)$meta->children('wp', true)->meta_value;
                                $page_settings['template'] = $page_template;
                                error_log('Found page template: ' . $page_template);
                            }
                        }
                    }
                    
                    // If we found Elementor data, we can stop looking
                    if ($elementor_data !== false) {
                        break;
                    }
                }
            }
        }
        
        // Method 2: Try to extract from CDATA sections if Method 1 failed
        if ($elementor_data === false) {
            error_log('Trying alternative extraction method with CDATA sections');
            
            preg_match_all('/<!\[CDATA\[(.*?)\]\]>/s', $content, $cdata_matches);
            if (!empty($cdata_matches[1])) {
                foreach ($cdata_matches[1] as $cdata) {
                    // Check if this looks like Elementor JSON data
                    if (strpos($cdata, '"elType"') !== false || strpos($cdata, '"widgetType"') !== false) {
                        error_log('Found potential Elementor data in CDATA');
                        
                        // Validate as JSON
                        json_decode($cdata);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $elementor_data = $cdata;
                            error_log('Valid Elementor data found in CDATA');
                            break;
                        }
                    }
                }
            }
        }
        
        // Method 3: Try to extract from the content field
        if ($elementor_data === false && isset($xml->channel) && isset($xml->channel->item)) {
            error_log('Trying to extract from content field');
            
            foreach ($xml->channel->item as $item) {
                // Look for content:encoded field
                if (isset($item->children('content', true)->encoded)) {
                    $content_field = (string)$item->children('content', true)->encoded;
                    error_log('Found content:encoded field, length: ' . strlen($content_field));
                    
                    // Check if it has Elementor marker
                    if (strpos($content_field, 'data-elementor-type') !== false) {
                        error_log('Content field has Elementor markers');
                        
                        // Create a post with this content and let Elementor handle it
                        $post_data = array(
                            'post_title'   => !empty($title) ? sanitize_text_field($title) : __('Imported Content', 'elementor-template-importer'),
                            'post_status'  => 'publish',
                            'post_type'    => $import_type === 'page' ? 'page' : 'elementor_library',
                            'post_content' => $content_field,
                        );
                        
                        error_log('Creating post with Elementor content');
                        $post_id = wp_insert_post($post_data);
                        
                        if (is_wp_error($post_id)) {
                            error_log('Error creating post: ' . $post_id->get_error_message());
                            return $post_id;
                        }
                        
                        if ($import_type === 'template') {
                            update_post_meta($post_id, '_elementor_template_type', 'page');
                        }
                        
                        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                        
                        if (!empty($page_settings['template'])) {
                            update_post_meta($post_id, '_wp_page_template', $page_settings['template']);
                        } else {
                            update_post_meta($post_id, '_wp_page_template', 'elementor_header_footer');
                        }
                        
                        if (!empty($page_settings)) {
                            update_post_meta($post_id, '_elementor_page_settings', $page_settings);
                        }
                        
                        error_log('Post created successfully from content field');
                        return $post_id;
                    }
                }
            }
        }
        
        // If we found Elementor data, create a post with it
        if ($elementor_data !== false) {
            error_log('Creating post with extracted Elementor data');
            
            // Create the post
            $post_data = array(
                'post_title'   => !empty($title) ? sanitize_text_field($title) : __('Imported Template', 'elementor-template-importer'),
                'post_status'  => 'publish',
                'post_type'    => $import_type === 'page' ? 'page' : 'elementor_library',
                'post_content' => '',
            );
            
            $post_id = wp_insert_post($post_data);
            
            if (is_wp_error($post_id)) {
                error_log('Error creating post: ' . $post_id->get_error_message());
                return $post_id;
            }
            
            // Set template type if it's a library item
            if ($import_type === 'template') {
                update_post_meta($post_id, '_elementor_template_type', 'page');
            }
            
            // Set Elementor edit mode
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            
            // Set page template
            if (!empty($page_settings['template'])) {
                update_post_meta($post_id, '_wp_page_template', $page_settings['template']);
            } else {
                update_post_meta($post_id, '_wp_page_template', 'elementor_header_footer');
            }
            
            // Store page settings if we found any
            if (!empty($page_settings)) {
                update_post_meta($post_id, '_elementor_page_settings', $page_settings);
            }
            
            // Store the Elementor data with wp_slash to prevent losing backslashes
            update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
            
            error_log('Post created successfully with ID: ' . $post_id);
            return $post_id;
        }
        
        // If all methods failed, return error
        error_log('No Elementor data could be extracted from the XML');
        return new \WP_Error(
            'no_elementor_data',
            __('Could not extract Elementor data from the XML file. Try using WordPress Importer plugin.', 'elementor-template-importer')
        );
    }

    /**
     * Custom direct import method - preserves all URLs
     * 
     * @param string $template_content JSON or XML content
     * @param string $file_extension File extension (json or xml)
     * @param string $template_title Title for the template
     * @param string $import_type Import as page or template
     * @return int|WP_Error Post ID on success, WP_Error on failure
     */
    private function direct_import_with_url_preservation($template_content, $file_extension, $template_title, $import_type) {
        error_log('Starting direct import with URL preservation');
        
        // Disable URL filters that might change image URLs
        remove_all_filters('content_save_pre');
        add_filter('wp_update_attachment_metadata', function($data) {
            return $data; // Pass through without modifications
        }, 99999);
        
        // Disable URL replacement
        add_filter('elementor/files/allow_unfiltered_upload', '__return_true');
        
        try {
            // For JSON files
            if ($file_extension === 'json') {
                // Parse the JSON
                $data = json_decode($template_content, true);
                if (empty($data) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Invalid JSON: ' . json_last_error_msg());
                }
                
                // Determine the format
                if (isset($data[0]['elType'])) {
                    $elementor_data = wp_json_encode($data);
                    $post_title = !empty($template_title) ? $template_title : 'Imported Template';
                    $page_settings = array();
                } else if (isset($data['content'])) {
                    if (is_string($data['content'])) {
                        $elementor_data = $data['content'];
                    } else {
                        $elementor_data = wp_json_encode($data['content']);
                    }
                    
                    $post_title = !empty($template_title) ? $template_title : 
                        (isset($data['title']) ? $data['title'] : 'Imported Template');
                        
                    $page_settings = isset($data['page_settings']) ? $data['page_settings'] : array();
                } else {
                    throw new Exception('Unrecognized JSON template format');
                }
                
                // Create the post
                $post_id = wp_insert_post([
                    'post_title' => sanitize_text_field($post_title),
                    'post_status' => 'publish',
                    'post_type' => $import_type === 'page' ? 'page' : 'elementor_library',
                    'post_content' => '',
                ]);
                
                if (is_wp_error($post_id)) {
                    throw new Exception('Failed to create post: ' . $post_id->get_error_message());
                }
                
                // Set template metadata
                update_post_meta($post_id, '_elementor_edit_mode', 'builder');
                
                if ($import_type === 'template') {
                    $template_type = isset($data['type']) ? $data['type'] : 'page';
                    update_post_meta($post_id, '_elementor_template_type', $template_type);
                }
                
                // Set page template
                $page_template = isset($page_settings['template']) ? $page_settings['template'] : 'elementor_header_footer';
                update_post_meta($post_id, '_wp_page_template', $page_template);
                
                // Store page settings if available
                if (!empty($page_settings)) {
                    update_post_meta($post_id, '_elementor_page_settings', $page_settings);
                }
                
                // Store Elementor data without any URL processing
                update_post_meta($post_id, '_elementor_data', $elementor_data);
                
                return $post_id;
            }
            // For XML files
            else if ($file_extension === 'xml') {
                // We'll use our enhanced XML parser
                $post_id = $this->import_xml_with_simple_parser($template_content, $template_title, $import_type);
                
                // If successful, forcibly restore any URLs that might have been replaced
                if (!is_wp_error($post_id)) {
                    $elementor_data = get_post_meta($post_id, '_elementor_data', true);
                    
                    if (!empty($elementor_data)) {
                        // Re-save the Elementor data directly without processing
                        update_post_meta($post_id, '_elementor_data', $elementor_data);
                    }
                }
                
                return $post_id;
            }
            else {
                throw new Exception('Unsupported file type: ' . $file_extension);
            }
        }
        catch (Exception $e) {
            error_log('Direct import error: ' . $e->getMessage());
            return new \WP_Error('import_failed', $e->getMessage());
        }
        finally {
            // Restore filters
            remove_filter('elementor/files/allow_unfiltered_upload', '__return_true');
            // You can restore other filters here if needed
        }
    }

    /**
     * Extract Elementor data directly from XML using regex
     * 
     * @param string $xml_content Raw XML content
     * @return string|bool Elementor data JSON or false if not found
     */
    private function extract_elementor_data_from_xml($xml_content) {
        // Look for specific patterns that enclose Elementor data
        $patterns = [
            // Pattern for wp:meta_value containing Elementor data
            '/<wp:meta_value><!\[CDATA\[(.*?)\]\]><\/wp:meta_value>/s',
            // Pattern for meta_value without CDATA
            '/<meta_value>(.*?)<\/meta_value>/s',
            // Pattern for direct content field
            '/<content><!\[CDATA\[(.*?)\]\]><\/content>/s',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $xml_content, $matches)) {
                foreach ($matches[1] as $match) {
                    // Check if the content looks like JSON (Elementor data)
                    if ($this->is_valid_json($match) && $this->looks_like_elementor_data($match)) {
                        return $match;
                    }
                }
            }
        }
        
        // More aggressive search for any JSON structure in the XML
        if (preg_match_all('/(\[\s*\{\s*"id":[^\]]*\]\s*)/s', $xml_content, $matches)) {
            foreach ($matches[1] as $match) {
                if ($this->is_valid_json($match) && $this->looks_like_elementor_data($match)) {
                    return $match;
                }
            }
        }
        
        return false;
    }

    /**
     * Extract regular content from XML
     * 
     * @param string $xml_content Raw XML content
     * @return string HTML content or empty string if not found
     */
    private function extract_content_from_xml($xml_content) {
        // Look for content in encoded_content or content fields
        $patterns = [
            '/<encoded>(.*?)<\/encoded>/s',
            '/<content:encoded><!\[CDATA\[(.*?)\]\]><\/content:encoded>/s',
            '/<content><!\[CDATA\[(.*?)\]\]><\/content>/s',
            '/<content>(.*?)<\/content>/s',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $xml_content, $matches)) {
                return $matches[1];
            }
        }
        
        return '';
    }

    /**
     * Check if a string is valid JSON
     * 
     * @param string $string String to check
     * @return bool True if valid JSON
     */
    private function is_valid_json($string) {
        json_decode($string);
        return (json_last_error() === JSON_ERROR_NONE);
    }

    /**
     * Check if JSON string looks like Elementor data
     * 
     * @param string $json_string JSON string
     * @return bool True if it looks like Elementor data
     */
    private function looks_like_elementor_data($json_string) {
        // Elementor data typically starts with an array of elements with ids
        $data = json_decode($json_string, true);
        
        if (!is_array($data)) {
            return false;
        }
        
        // Check for common Elementor data structure
        foreach ($data as $element) {
            if (is_array($element) && 
                (isset($element['id']) || isset($element['elType'])) && 
                (isset($element['elements']) || isset($element['settings']))) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Import XML with WordPress Importer while preserving URLs
     * 
     * @param string $content Template content
     * @param string $title   Template title
     * @param string $import_type Whether to import as 'page' or 'template'
     * @return int|WP_Error   Template ID on success, WP_Error on failure
     */
    private function import_xml_with_wp_importer($content, $title, $import_type) {
        // Before importing, add filters to preserve URLs
        add_filter('wp_import_post_data_raw', array($this, 'preserve_elementor_data_urls'), 10, 1);
        
        // Prevent WordPress from replacing URLs
        add_filter('import_post_meta_value', array($this, 'preserve_meta_value_urls'), 10, 2);
        
        // Create a temporary file to save XML content
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/elementor-importer-' . uniqid();
        
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $temp_file = $temp_dir . '/template.xml';
        file_put_contents($temp_file, $content);
        
        // Store import type and title in options
        update_option('_csmf_import_type', $import_type);
        update_option('_csmf_import_title', $title);
        
        // Track imported templates
        add_action('wp_import_post_meta', function($post_id, $key, $value) {
            if ($key === '_elementor_data' || $key === '_elementor_template_type') {
                update_option('_csmf_last_imported_template', $post_id);
            }
        }, 10, 3);
        
        // Setup and run the importer
        $importer = new \WP_Import();
        $importer->fetch_attachments = true;
        
        ob_start();
        $importer->import($temp_file);
        ob_end_clean();
        
        // Clean up
        unlink($temp_file);
        rmdir($temp_dir);
        
        // Remove filters
        remove_filter('wp_import_post_data_raw', array($this, 'preserve_elementor_data_urls'));
        remove_filter('import_post_meta_value', array($this, 'preserve_meta_value_urls'));
        
        // Get the imported template ID
        $imported_template_id = get_option('_csmf_last_imported_template');
        delete_option('_csmf_last_imported_template');
        delete_option('_csmf_import_type');
        delete_option('_csmf_import_title');
        
        if (!$imported_template_id) {
            return new \WP_Error(
                'import_failed', 
                __('Failed to import template. No template was created.', 'elementor-template-importer')
            );
        }
        
        // Set the page template if it was reset
        $original_page_template = get_post_meta($imported_template_id, '_wp_page_template', true);
        if ($original_page_template === 'default' || empty($original_page_template)) {
            update_post_meta($imported_template_id, '_wp_page_template', 'elementor_header_footer');
        }
        
        // Fix Elementor data formats
        $this->fix_elementor_data($imported_template_id);
        
        // Convert to page if needed
        if ($import_type === 'page' && get_post_type($imported_template_id) !== 'page') {
            $post_data = [
                'ID' => $imported_template_id,
                'post_type' => 'page'
            ];
            wp_update_post($post_data);
        }
        
        // If we had a custom title, make sure it's applied
        if (!empty($title)) {
            wp_update_post([
                'ID' => $imported_template_id,
                'post_title' => sanitize_text_field($title)
            ]);
        }
        
        return $imported_template_id;
    }

    /**
     * Preserve URLs in Elementor data during import
     *
     * @param array $post_data Post data being imported
     * @return array Modified post data
     */
    public function preserve_elementor_data_urls($post_data) {
        // If this has a custom title, apply it
        $stored_title = get_option('_csmf_import_title', '');
        if (!empty($stored_title) && ($post_data['post_type'] === 'elementor_library' || $post_data['post_type'] === 'page')) {
            $post_data['post_title'] = $stored_title;
        }
        
        // Don't modify the content - preserve URLs
        return $post_data;
    }

    /**
     * Preserve meta value URLs and ensure proper data types
     *
     * @param mixed $meta_value Meta value
     * @param string $meta_key Meta key
     * @return mixed Processed meta value
     */
    public function preserve_meta_value_urls($meta_value, $meta_key) {
        // Handle special meta keys
        if ($meta_key === '_elementor_data') {
            // Ensure it's a valid JSON string
            if (is_string($meta_value)) {
                // Check if it's already a valid JSON string
                json_decode($meta_value);
                if (json_last_error() === JSON_ERROR_NONE) {
                    // It's already valid JSON, return as is
                    return $meta_value;
                }
                
                // Try to decode and re-encode to ensure valid JSON format
                $decoded = json_decode($meta_value, true);
                if (is_array($decoded)) {
                    return wp_json_encode($decoded);
                }
            }
        } else if ($meta_key === '_elementor_page_settings') {
            // Ensure page settings are stored as an array, not a JSON string
            if (is_string($meta_value)) {
                $decoded = json_decode($meta_value, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
                
                // If we couldn't decode it or it's not an array, return an empty array
                return array();
            }
        } else if ($meta_key === '_wp_page_template' && ($meta_value === 'default' || empty($meta_value))) {
            // Set default Elementor template
            return 'elementor_header_footer';
        }
        
        return $meta_value;
    }

    /**
     * Fix Elementor data format after import
     * 
     * @param int $post_id Imported post ID
     */
    private function fix_elementor_data($post_id) {
        // Fix _elementor_page_settings if it's a string
        $page_settings = get_post_meta($post_id, '_elementor_page_settings', true);
        if (is_string($page_settings)) {
            // Try to decode
            $decoded = json_decode($page_settings, true);
            if (is_array($decoded)) {
                // Update with the decoded array
                update_post_meta($post_id, '_elementor_page_settings', $decoded);
            } else {
                // Delete the meta if we can't decode it
                delete_post_meta($post_id, '_elementor_page_settings');
            }
        }
        
        // Fix _elementor_data if it's not a valid JSON string
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (!is_string($elementor_data) || empty($elementor_data)) {
            return;
        }
        
        // Check if already valid JSON
        json_decode($elementor_data);
        if (json_last_error() === JSON_ERROR_NONE) {
            return; // Already valid
        }
        
        // Try to decode and re-encode
        $decoded = json_decode($elementor_data, true);
        if (is_array($decoded)) {
            update_post_meta($post_id, '_elementor_data', wp_json_encode($decoded));
        }
    }

    /**
     * Debug helper to analyze XML file structure
     * 
     * @param string $template_url URL to the template XML
     * @return string Debug information
     */
    public function debug_xml_structure($template_url) {
        if (!current_user_can('manage_options')) {
            return 'Insufficient permissions';
        }
        
        $response = wp_remote_get($template_url, array(
            'timeout' => 60,
            'sslverify' => false,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            return 'Error: ' . $response->get_error_message();
        }
        
        $content = wp_remote_retrieve_body($response);
        
        // Try to parse XML
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            $errors = '';
            foreach(libxml_get_errors() as $error) {
                $errors .= $error->message . "\n";
            }
            libxml_clear_errors();
            return 'Invalid XML: ' . $errors;
        }
        
        $output = "XML Structure Analysis:\n\n";
        
        // Root element
        $output .= "Root element: " . $xml->getName() . "\n\n";
        
        // Channel information (WordPress export)
        if (isset($xml->channel)) {
            $output .= "WordPress Export Structure:\n";
            $output .= "- Title: " . (isset($xml->channel->title) ? (string)$xml->channel->title : 'Not found') . "\n";
            $output .= "- Items count: " . (isset($xml->channel->item) ? count($xml->channel->item) : 0) . "\n\n";
            
            if (isset($xml->channel->item) && count($xml->channel->item) > 0) {
                $output .= "First item details:\n";
                $item = $xml->channel->item[0];
                
                $output .= "- Title: " . (isset($item->title) ? (string)$item->title : 'Not found') . "\n";
                
                if (isset($item->children('wp', true)->post_type)) {
                    $output .= "- Post type: " . (string)$item->children('wp', true)->post_type . "\n";
                } else if (isset($item->post_type)) {
                    $output .= "- Post type: " . (string)$item->post_type . "\n";
                } else {
                    $output .= "- Post type: Not found\n";
                }
                
                // Check for Elementor data
                $output .= "\nChecking for Elementor data:\n";
                
                if (isset($item->children('wp', true)->postmeta)) {
                    $output .= "- Has wp:postmeta: Yes\n";
                    
                    foreach ($item->children('wp', true)->postmeta as $meta) {
                        $meta_key = (string)$meta->children('wp', true)->meta_key;
                        $output .= "  - Meta key: " . $meta_key . "\n";
                        
                        if ($meta_key === '_elementor_data') {
                            $output .= "    => Elementor data found in wp:postmeta!\n";
                            $data_sample = substr((string)$meta->children('wp', true)->meta_value, 0, 100);
                            $output .= "    Sample: " . $data_sample . "...\n";
                        }
                    }
                } else {
                    $output .= "- Has wp:postmeta: No\n";
                }
                
                if (isset($item->postmeta)) {
                    $output .= "- Has direct postmeta: Yes\n";
                    
                    foreach ($item->postmeta as $meta) {
                        $meta_key = (string)$meta->meta_key;
                        $output .= "  - Meta key: " . $meta_key . "\n";
                        
                        if ($meta_key === '_elementor_data') {
                            $output .= "    => Elementor data found in direct postmeta!\n";
                            $data_sample = substr((string)$meta->meta_value, 0, 100);
                            $output .= "    Sample: " . $data_sample . "...\n";
                        }
                    }
                } else {
                    $output .= "- Has direct postmeta: No\n";
                }
            }
        } else {
            $output .= "Not a standard WordPress export.\n\n";
            
            // Direct Elementor export
            if (isset($xml->content)) {
                $output .= "Possible direct Elementor export found:\n";
                $output .= "- Has content element: Yes\n";
                $content_sample = substr((string)$xml->content, 0, 100);
                $output .= "- Content sample: " . $content_sample . "...\n";
            } else {
                $output .= "- No content element found\n";
            }
            
            // List all top-level elements
            $output .= "\nTop-level elements:\n";
            foreach ($xml->children() as $child) {
                $output .= "- " . $child->getName() . "\n";
            }
        }
        
        return $output;
    }
}