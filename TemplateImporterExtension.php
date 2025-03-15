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
        
        // AJAX handlers
        add_action('wp_ajax_csmf_fetch_templates', array($this, 'ajax_fetch_templates'));
        add_action('wp_ajax_csmf_import_template', array($this, 'ajax_import_template'));
        add_action('wp_ajax_csmf_upload_template', array($this, 'ajax_upload_template'));

        // Admin init
        add_action('admin_init', array($this, 'init_template_processing'));

        // Add template converter functionality
        if (is_admin()) {
            // Register converter assets
            add_action('admin_enqueue_scripts', array($this, 'register_converter_assets'));
            
            // Add button to row actions
            add_filter('post_row_actions', array($this, 'add_template_row_actions'), 10, 2);
            
            // Register AJAX handler for template conversion
            add_action('wp_ajax_csmf_convert_template_to_page', array($this, 'ajax_convert_template_to_page'));
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
            array(),
            $this->version
        );

        // Register JS
        wp_register_script(
            'csmf-template-importer',
            CSMF_URL . 'assets/js/template-importer.js',
            array('jquery'),
            $this->version,
            true
        );

        // Localize script with data
        wp_localize_script('csmf-template-importer', 'csmfTemplateImporter', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('csmf_template_importer_nonce'),
            'i18n' => array(
                'importing' => __('Importing...', 'elementor-template-importer'),
                'success' => __('Success', 'elementor-template-importer'),
                'error' => __('Error', 'elementor-template-importer'),
                'noTemplatesFound' => __('No templates found.', 'elementor-template-importer')
            )
        ));
        
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
            array(),
            $this->version
        );
        
        // Register JS
        wp_register_script(
            'csmf-template-converter',
            CSMF_URL . 'assets/js/template-converter.js',
            array('jquery'),
            $this->version,
            true
        );
        
        // Localize script
        wp_localize_script('csmf-template-converter', 'csmfTemplateConverter', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('csmf_template_importer_nonce'),
            'i18n' => array(
                'converting' => __('Converting...', 'elementor-template-importer'),
                'successTitle' => __('Success!', 'elementor-template-importer'),
                'preview' => __('Preview', 'elementor-template-importer'),
                'edit' => __('Edit with Elementor', 'elementor-template-importer'),
                'close' => __('Close', 'elementor-template-importer'),
                'errorConverting' => __('Error converting template to page', 'elementor-template-importer'),
                'errorServer' => __('Error connecting to the server', 'elementor-template-importer'),
                'noTemplateSelected' => __('No template selected', 'elementor-template-importer')
            )
        ));
        
        // Enqueue assets
        wp_enqueue_style('dashicons');
        wp_enqueue_style('csmf-template-converter');
        wp_enqueue_script('csmf-template-converter');
    }

    /**
     * Extract title from JSON data
     * 
     * @param array $data Template data
     * @return string Original title or empty string
     */
    private function extract_title_from_json($data) {
        if (isset($data['title']) && !empty($data['title'])) {
            return sanitize_text_field($data['title']);
        } else if (isset($data['page_settings']['title']) && !empty($data['page_settings']['title'])) {
            return sanitize_text_field($data['page_settings']['title']);
        } else if (isset($data['settings']['title']) && !empty($data['settings']['title'])) {
            return sanitize_text_field($data['settings']['title']);
        }
        
        return '';
    }

    /**
     * Extract title from XML content
     * 
     * @param string $content XML content
     * @return string Original title or empty string
     */
    private function extract_title_from_xml($content) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        
        if ($xml === false) {
            libxml_clear_errors();
            return '';
        }
        
        // Try to extract from WordPress export format
        if (isset($xml->channel) && isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                if (isset($item->title) && !empty($item->title)) {
                    return sanitize_text_field((string)$item->title);
                }
            }
        }
        
        // Try direct title
        if (isset($xml->title)) {
            return sanitize_text_field((string)$xml->title);
        }
        
        libxml_clear_errors();
        return '';
    }

        /**
     * Check if page with given title exists
     * 
     * @param string $title Page title
     * @return bool Whether page exists
     */
    private function page_exists_with_title($title) {
        if (empty($title)) {
            return false;
        }
        
        $page = get_page_by_title($title, OBJECT, 'page');
        return !is_null($page);
    }
    
    /**
     * Check if template with given title exists
     * 
     * @param string $title Template title
     * @return bool Whether template exists
     */
    private function template_exists_with_title($title) {
        if (empty($title)) {
            return false;
        }
        
        $template = get_page_by_title($title, OBJECT, 'elementor_library');
        return !is_null($template);
    }
    
    /**
     * Create a unique page title
     * 
     * @param string $title Original title
     * @return string Unique title with suffix if needed
     */
    private function create_unique_page_title($title) {
        if (empty($title)) {
            return 'Imported Page';
        }
        
        $original_title = $title;
        $counter = 1;
        $suffixes = [' - Copy', ' - New', ' - Duplicate'];
        
        while ($this->page_exists_with_title($title)) {
            if ($counter <= count($suffixes)) {
                $title = $original_title . $suffixes[$counter - 1];
            } else {
                $title = $original_title . ' - Copy ' . ($counter - count($suffixes) + 1);
            }
            $counter++;
            
            // Safety check to prevent infinite loops
            if ($counter > 100) {
                $title = $original_title . ' - ' . uniqid();
                break;
            }
        }
        
        return $title;
    }
    
    /**
     * Create a unique template title
     * 
     * @param string $title Original title
     * @return string Unique title with suffix if needed
     */
    private function create_unique_template_title($title) {
        if (empty($title)) {
            return 'Imported Template';
        }
        
        $original_title = $title;
        $counter = 1;
        $suffixes = [' - Copy', ' - New', ' - Duplicate'];
        
        while ($this->template_exists_with_title($title)) {
            if ($counter <= count($suffixes)) {
                $title = $original_title . $suffixes[$counter - 1];
            } else {
                $title = $original_title . ' - Copy ' . ($counter - count($suffixes) + 1);
            }
            $counter++;
            
            // Safety check to prevent infinite loops
            if ($counter > 100) {
                $title = $original_title . ' - ' . uniqid();
                break;
            }
        }
        
        return $title;
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
            return;
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
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        if ($status_code !== 200) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('Error fetching templates. Server returned status code: %d', 'elementor-template-importer'),
                    $status_code
                )
            ));
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(array(
                'message' => __('Invalid JSON response: ', 'elementor-template-importer') . json_last_error_msg()
            ));
            return;
        }
        
        if (!isset($data['templates']) || !is_array($data['templates'])) {
            wp_send_json_error(array(
                'message' => __('No templates found in response', 'elementor-template-importer')
            ));
            return;
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
            wp_send_json_error(array('message' => __('Security check failed', 'elementor-template-importer')));
            return;
        }
        
        // Get parameters
        $template_id = isset($_POST['template_id']) ? sanitize_text_field($_POST['template_id']) : '';
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'template';
        $template_title = isset($_POST['template_title']) ? sanitize_text_field($_POST['template_title']) : '';
        
        if (empty($template_id)) {
            wp_send_json_error(array('message' => __('Template ID is required', 'elementor-template-importer')));
            return;
        }
        
        // Build template URL - try JSON first
        $template_url = $this->templates_base_url . 'csmf-page-' . $template_id . '.json';
        
        // Download the template
        $response = wp_remote_get($template_url, array(
            'timeout' => 60,
            'sslverify' => false
        ));
        
        // Check for errors
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => __('Error downloading template: ', 'elementor-template-importer') . $response->get_error_message()));
            return;
        }
        
        // Check response code
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            // Try alternative URL format with XML
            $template_url = $this->templates_base_url . 'csmf-page-' . $template_id . '.xml';
            
            $response = wp_remote_get($template_url, array(
                'timeout' => 60,
                'sslverify' => false
            ));
            
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                wp_send_json_error(array('message' => __('Template not found. Please check the template ID.', 'elementor-template-importer')));
                return;
            }
        }
        
        // Get content and determine file type
        $content = wp_remote_retrieve_body($response);
        $file_extension = pathinfo($template_url, PATHINFO_EXTENSION);
        
        // Try to extract original template name
        $original_title = '';
        if ($file_extension === 'json') {
            $data = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $original_title = $this->extract_title_from_json($data);
            }
        } elseif ($file_extension === 'xml') {
            $original_title = $this->extract_title_from_xml($content);
        }
        
        // Use original title if found, otherwise use provided title
        $final_title = !empty($original_title) ? $original_title : $template_title;
        
        // Import the template
        if ($file_extension === 'json') {
            $result = $this->import_json_template($content, $final_title, $import_type);
        } else {
            $result = $this->import_xml_template($content, $final_title, $import_type);
        }
        
        // Check for errors
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
            return;
        }
        
        // Return success
        wp_send_json_success(array(
            'message' => __('Template imported successfully', 'elementor-template-importer'),
            'template_id' => $result,
            'edit_url' => admin_url('post.php?post=' . $result . '&action=elementor')
        ));
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
        
        // Get original template name if stored in metadata, otherwise use template title
        $original_name = get_post_meta($template_id, '_csmf_original_template_name', true);
        $template_name = !empty($original_name) ? $original_name : $template->post_title;
        
        // Create a unique page title to avoid duplicates
        $page_title = $this->create_unique_page_title($template_name);
        
        // Create new page
        $page_data = [
            'post_title' => $page_title,
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
        
        // Store reference to original template
        update_post_meta($page_id, '_csmf_original_template_id', $template_id);
        if (!empty($original_name)) {
            update_post_meta($page_id, '_csmf_original_template_name', $original_name);
        }
        
        // Get URLs for the new page
        $preview_url = get_permalink($page_id);
        $edit_url = admin_url('post.php?post=' . $page_id . '&action=elementor');
        
        // Send success response with complete data
        wp_send_json_success([
            'message' => sprintf(
                __('Template "%s" successfully converted to page', 'elementor-template-importer'),
                esc_html($template_name)
            ),
            'title' => __('Success!', 'elementor-template-importer'),
            'page_id' => $page_id,
            'preview_url' => $preview_url,
            'edit_url' => $edit_url,
        ]);
    }

        /**
     * AJAX handler for uploading templates
     */
    public function ajax_upload_template() {
        // Verify nonce
        if (!isset($_POST['csmf_nonce']) || !wp_verify_nonce($_POST['csmf_nonce'], 'csmf_template_upload_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed', 'elementor-template-importer')));
            return;
        }
        
        // Check file upload
        if (!isset($_FILES['template_file']) || $_FILES['template_file']['error'] !== UPLOAD_ERR_OK) {
            $error_message = isset($_FILES['template_file']) ? 
                            $this->get_upload_error_message($_FILES['template_file']['error']) : 
                            __('No file uploaded', 'elementor-template-importer');
            
            wp_send_json_error(array('message' => $error_message));
            return;
        }
        
        // Get import type
        $import_type = isset($_POST['import_type']) ? sanitize_text_field($_POST['import_type']) : 'template';
        
        $file_tmp = $_FILES['template_file']['tmp_name'];
        $file_name = $_FILES['template_file']['name'];
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Get original filename without extension as template title
        $template_title = pathinfo($file_name, PATHINFO_FILENAME);
        
        // Process the template based on file type
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
            return;
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message()
            ));
            return;
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
            
            // Try to extract original template name from JSON
            $original_title = $this->extract_title_from_json($data);
            
            // Use original title if found, otherwise use provided title
            $final_title = !empty($original_title) ? $original_title : $title;
            
            // Make sure title is unique
            if ($import_type === 'page') {
                $final_title = $this->create_unique_page_title($final_title);
            } else {
                $final_title = $this->create_unique_template_title($final_title);
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
                'post_title' => $final_title,
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

            $elementor_data = $this->csmf_process_elementor_data($elementor_data);

            // Then save as usual
            update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
            
            // Store the original template name for future reference
            if (!empty($original_title)) {
                update_post_meta($post_id, '_csmf_original_template_name', $original_title);
            }
            
            // Enable URL filters
            $this->enable_url_filters();
            
            return $post_id;
        } catch (Exception $e) {
            $this->enable_url_filters();
            return new WP_Error('import_failed', $e->getMessage());
        }
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
            
            // Try to extract original template name from XML
            $original_title = $this->extract_title_from_xml($content);
            
            // Use original title if found, otherwise use provided title
            $final_title = !empty($original_title) ? $original_title : $title;
            
            // Make sure title is unique
            if ($import_type === 'page') {
                $final_title = $this->create_unique_page_title($final_title);
            } else {
                $final_title = $this->create_unique_template_title($final_title);
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
                                } elseif ($meta_key === '_elementor_page_settings') {
                                    // Try to decode page settings
                                    $decoded = json_decode($meta_value, true);
                                    if (is_array($decoded)) {
                                        $page_settings = $decoded;
                                    }
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
                'post_title' => $final_title,
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
            
            // Add page settings if available
            if (!empty($page_settings)) {
                update_post_meta($post_id, '_elementor_page_settings', $page_settings);
            }

            $elementor_data = $this->csmf_process_elementor_data($elementor_data);

            // Save Elementor data - CRITICAL: Use wp_slash to preserve backslashes
            update_post_meta($post_id, '_elementor_data', wp_slash($elementor_data));
            
            // Store original template name for future reference
            if (!empty($original_title)) {
                update_post_meta($post_id, '_csmf_original_template_name', $original_title);
            }
            
            // Enable URL filters
            $this->enable_url_filters();
            
            return $post_id;
        } catch (Exception $e) {
            $this->enable_url_filters();
            return new WP_Error('import_failed', $e->getMessage());
        }
    }

        /**
     * Import ZIP template from uploaded file
     * 
     * @param string $file_path Path to the ZIP file
     * @param string $title Template title
     * @param string $import_type Whether to import as 'page' or 'template'
     * @return int|WP_Error Template ID on success, WP_Error on failure
     */
    private function import_zip_template_from_file($file_path, $title, $import_type = 'template') {
        if (!class_exists('ZipArchive')) {
            return new WP_Error(
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
        $zip = new ZipArchive();
        $result = $zip->open($file_path);
        
        if ($result !== true) {
            rmdir($extract_dir);
            
            return new WP_Error(
                'invalid_zip', 
                __('Invalid or corrupted ZIP file.', 'elementor-template-importer')
            );
        }
        
        $zip->extractTo($extract_dir);
        $zip->close();
        
        // Find and process template files
        $files = array_diff(scandir($extract_dir), array('.', '..'));
        $json_file = null;
        $xml_file = null;
        $extracted_title = '';
        
        foreach ($files as $file) {
            $file_path = $extract_dir . '/' . $file;
            $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            
            // Try to extract original title from filename
            if (empty($extracted_title)) {
                $extracted_title = pathinfo($file, PATHINFO_FILENAME);
            }
            
            if ($file_ext === 'json') {
                $json_file = $file_path;
            } elseif ($file_ext === 'xml') {
                $xml_file = $file_path;
            }
        }
        
        // Import template
        $result = null;
        $final_title = !empty($title) ? $title : $extracted_title;
        
        if ($json_file) {
            $content = file_get_contents($json_file);
            $result = $this->import_json_template($content, $final_title, $import_type);
        } elseif ($xml_file) {
            $content = file_get_contents($xml_file);
            $result = $this->import_xml_template($content, $final_title, $import_type);
        } else {
            $result = new WP_Error(
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
     * Sideload image from URL and attach it to the post
     * 
     * @param string $image_url Remote image URL
     * @return int|WP_Error Attachment ID if successful, WP_Error otherwise
     */
    public function csmf_sideload_image($image_url) {
        if (empty($image_url)) {
            return new WP_Error('empty_url', __('Image URL is empty', 'elementor-template-importer'));
        }
        
        // Check if this is a valid URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('Image URL is not valid', 'elementor-template-importer'));
        }
        
        // Create file from URL
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Check if URL is from an external source
        $url_host = parse_url($image_url, PHP_URL_HOST);
        $site_host = parse_url(site_url(), PHP_URL_HOST);
        
        if ($url_host === $site_host) {
            // This is a local URL, just return the attachment ID if we can find it
            $attachment_id = attachment_url_to_postid($image_url);
            if ($attachment_id) {
                return $attachment_id;
            }
        }
        
        // See if we've already downloaded this image
        $cached_image = get_transient('csmf_sideloaded_' . md5($image_url));
        if ($cached_image) {
            return $cached_image;
        }
        
        // Download image to temp location
        $temp_file = download_url($image_url);
        
        if (is_wp_error($temp_file)) {
            return $temp_file;
        }
        
        // Get file name
        $filename = basename(parse_url($image_url, PHP_URL_PATH));
        
        // An array similar to $_FILES
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $temp_file,
            'error'    => 0,
            'size'     => filesize($temp_file),
        );
        
        // Move the temporary file into the uploads directory
        $attachment_id = media_handle_sideload($file_array, 0);
        
        // Delete the temporary file if attachment creation failed
        if (is_wp_error($attachment_id)) {
            @unlink($temp_file);
            return $attachment_id;
        }
        
        // Store in transient cache to avoid duplicates
        set_transient('csmf_sideloaded_' . md5($image_url), $attachment_id, WEEK_IN_SECONDS);
        
        return $attachment_id;
    }

    /**
     * Process Elementor data to replace image URLs
     * 
     * @param string|array $elementor_data Elementor template data
     * @return string|array Processed Elementor data with local image URLs
     */
    public function csmf_process_elementor_data($elementor_data) {
        // If data is a string, convert to array
        $is_string = is_string($elementor_data);
        $data = $is_string ? json_decode($elementor_data, true) : $elementor_data;
        
        if (empty($data) || !is_array($data)) {
            return $elementor_data;
        }
        
        // Walk through data recursively
        $data = $this->csmf_recursively_process_element_data($data);
        
        // Return proper format based on input
        return $is_string ? wp_json_encode($data) : $data;
    }

    /**
     * Recursively process Elementor element data to replace image URLs
     * 
     * @param array $elements Elements to process
     * @return array Processed elements
     */
    public function csmf_recursively_process_element_data($elements) {
        // Track imported images to avoid duplicates
        static $imported_images = array();
        
        if (!is_array($elements)) {
            return $elements;
        }
        
        foreach ($elements as $key => &$element) {
            // Skip non-array items
            if (!is_array($element)) {
                continue;
            }
            
            // Process settings that may contain image URLs
            if (isset($element['settings'])) {
                foreach ($element['settings'] as $setting_key => $setting_value) {
                    // Process image settings
                    if (is_string($setting_value) && preg_match('/\.(jpg|jpeg|png|gif|webp|svg)/i', $setting_value) && filter_var($setting_value, FILTER_VALIDATE_URL)) {
                        // Check if this is an external URL
                        $url_host = parse_url($setting_value, PHP_URL_HOST);
                        $site_host = parse_url(site_url(), PHP_URL_HOST);
                        
                        if ($url_host !== $site_host) {
                            // Import image if we haven't already
                            if (!isset($imported_images[$setting_value])) {
                                $attachment_id = $this->csmf_sideload_image($setting_value);
                                
                                if (!is_wp_error($attachment_id)) {
                                    $imported_images[$setting_value] = $attachment_id;
                                    $attachment_url = wp_get_attachment_url($attachment_id);
                                    $element['settings'][$setting_key] = $attachment_url;
                                }
                            } else {
                                // Use previously imported image
                                $attachment_url = wp_get_attachment_url($imported_images[$setting_value]);
                                $element['settings'][$setting_key] = $attachment_url;
                            }
                        }
                    }
                    
                    // Handle background image
                    if ($setting_key === 'background_image' && is_array($setting_value) && isset($setting_value['url']) && filter_var($setting_value['url'], FILTER_VALIDATE_URL)) {
                        $url_host = parse_url($setting_value['url'], PHP_URL_HOST);
                        $site_host = parse_url(site_url(), PHP_URL_HOST);
                        
                        if ($url_host !== $site_host) {
                            // Import image if we haven't already
                            if (!isset($imported_images[$setting_value['url']])) {
                                $attachment_id = $this->csmf_sideload_image($setting_value['url']);
                                
                                if (!is_wp_error($attachment_id)) {
                                    $imported_images[$setting_value['url']] = $attachment_id;
                                    $attachment_url = wp_get_attachment_url($attachment_id);
                                    $element['settings'][$setting_key]['url'] = $attachment_url;
                                    $element['settings'][$setting_key]['id'] = $attachment_id;
                                }
                            } else {
                                // Use previously imported image
                                $attachment_id = $imported_images[$setting_value['url']];
                                $attachment_url = wp_get_attachment_url($attachment_id);
                                $element['settings'][$setting_key]['url'] = $attachment_url;
                                $element['settings'][$setting_key]['id'] = $attachment_id;
                            }
                        }
                    }
                }
            }
            
            // Process child elements recursively
            if (isset($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = $this->csmf_recursively_process_element_data($element['elements']);
            }
        }
        
        return $elements;
    }

    /**
     * Get fallback image URL or ID
     * 
     * @param string $type 'url' or 'id'
     * @return int|string Fallback image URL or ID
     */
    public function csmf_get_fallback_image($type = 'url') {
        // Check if we've already created a fallback image
        $fallback_id = get_option('csmf_fallback_image_id');
        
        if (!$fallback_id) {
            // Create a fallback image
            $fallback_id = $this->csmf_create_fallback_image();
            update_option('csmf_fallback_image_id', $fallback_id);
        }
        
        if ($type === 'id') {
            return $fallback_id;
        }
        
        return wp_get_attachment_url($fallback_id);
    }

    /**
     * Create a fallback image in the media library
     * 
     * @return int|WP_Error Attachment ID if successful
     */
    public function csmf_create_fallback_image() {
        // Create a simple placeholder image
        $width = 800;
        $height = 600;
        
        // Create image
        $image = imagecreatetruecolor($width, $height);
        
        // Set background
        $bg_color = imagecolorallocate($image, 240, 240, 240);
        imagefill($image, 0, 0, $bg_color);
        
        // Add text
        $text_color = imagecolorallocate($image, 150, 150, 150);
        $text = "Image Placeholder";
        $font_size = 5;
        $font = imageloadfont(5);
        
        // Calculate position to center text
        $text_width = imagefontwidth($font_size) * strlen($text);
        $text_height = imagefontheight($font_size);
        $x = ($width - $text_width) / 2;
        $y = ($height - $text_height) / 2;
        
        imagestring($image, $font_size, $x, $y, $text, $text_color);
        
        // Save to temp file
        $temp_file = wp_tempnam('placeholder.jpg');
        imagejpeg($image, $temp_file);
        imagedestroy($image);
        
        // Import to media library
        $file_array = array(
            'name' => 'elementor-template-placeholder.jpg',
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        );
        
        $attachment_id = media_handle_sideload($file_array, 0, 'Elementor Template Placeholder');
        
        // Delete temp file
        @unlink($temp_file);
        
        return $attachment_id;
    }
}