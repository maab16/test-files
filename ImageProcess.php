<?php

namespace CodexShaper\Framework\Foundation;

use WP_Background_Process;
use WP_Error;

/**
 * Template Importer Image Processor
 * Handles downloading and processing images in the background
 */
class ImageProcessor extends WP_Background_Process {
    
     /**
     * Action name for background process
     * @var string
     */
    protected $action = 'csmf_image_import';
    
    /**
     * Task processing
     * 
     * @param array $item Queue item with image processing details
     * @return mixed False if done, $item to requeue
     */
    protected function task($item) {
        if (!isset($item['url']) || !isset($item['post_id'])) {
            return false;
        }
        
        $url = $item['url'];
        $post_id = $item['post_id'];
        $element_id = isset($item['element_id']) ? $item['element_id'] : null;
        $setting_key = isset($item['setting_key']) ? $item['setting_key'] : null;
        
        // Download and import image
        $attachment_id = $this->sideload_image($url);
        
        if (!is_wp_error($attachment_id)) {
            // Get the local URL
            $local_url = wp_get_attachment_url($attachment_id);
            
            // Update the stored reference
            update_post_meta($post_id, '_csmf_image_map_' . md5($url), [
                'external_url' => $url,
                'local_url' => $local_url,
                'attachment_id' => $attachment_id
            ]);
            
            // Update elementor data
            $this->update_elementor_data($post_id, $url, $local_url, $attachment_id);
            
            // Update progress
            $this->update_import_progress($post_id);
        } else {
            // Log error and use fallback
            error_log('Error importing image: ' . $attachment_id->get_error_message());
            $this->handle_failed_import($post_id, $url);
        }
        
        return false; // Task complete
    }
    
    /**
     * Complete process
     */
    protected function complete() {
        parent::complete();
        
        // Get post_id from current batch
        $batch = $this->get_batch();
        if (!empty($batch->data)) {
            foreach ($batch->data as $item) {
                if (isset($item['post_id'])) {
                    $post_id = $item['post_id'];
                    update_post_meta($post_id, '_csmf_image_import_status', 'complete');
                    
                    // Trigger completion action
                    do_action('csmf_image_import_completed', $post_id);
                    break;
                }
            }
        }
    }
    
    /**
     * Sideload image from URL
     * 
     * @param string $url Image URL
     * @return int|WP_Error Attachment ID on success, WP_Error on failure
     */
    protected function sideload_image($url) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', __('Invalid image URL', 'elementor-template-importer'));
        }
        
        // Check if this is a local URL
        $site_url = site_url();
        $site_host = parse_url($site_url, PHP_URL_HOST);
        $url_host = parse_url($url, PHP_URL_HOST);
        
        if ($url_host === $site_host) {
            // Already a local image
            $attachment_id = attachment_url_to_postid($url);
            if ($attachment_id) {
                return $attachment_id;
            }
        }
        
        // Check transient cache
        $cached_id = get_transient('csmf_imported_image_' . md5($url));
        if ($cached_id) {
            return $cached_id;
        }
        
        // Required files for media handling
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Download the file
        $tmp = download_url($url);
        
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        
        // Secure file handling - validate the file is an image
        $file_type = wp_check_filetype(basename($url), null);
        if (empty($file_type['ext']) || !in_array($file_type['ext'], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) {
            @unlink($tmp);
            return new WP_Error('invalid_image', __('File is not a valid image', 'elementor-template-importer'));
        }
        
        // Prepare file data
        $file_array = [
            'name' => sanitize_file_name(basename($url)),
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp)
        ];
        
        // Stop WP from adding scaled images for a cleaner import
        add_filter('big_image_size_threshold', '__return_false', 999);
        
        // Insert into media library
        $attachment_id = media_handle_sideload($file_array, 0);
        
        // Remove filter
        remove_filter('big_image_size_threshold', '__return_false', 999);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        
        // Save in transient cache to avoid duplicates
        set_transient('csmf_imported_image_' . md5($url), $attachment_id, WEEK_IN_SECONDS);
        
        return $attachment_id;
    }
    
    /**
     * Update Elementor data with new image URL
     * 
     * @param int $post_id Post ID
     * @param string $old_url Original image URL
     * @param string $new_url New local image URL
     * @param int $attachment_id Attachment ID
     */
    protected function update_elementor_data($post_id, $old_url, $new_url, $attachment_id) {
        // Get current Elementor data
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($elementor_data)) {
            return;
        }
        
        // Convert to array if it's a JSON string
        $is_json = is_string($elementor_data);
        if ($is_json) {
            $data = json_decode($elementor_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return;
            }
        } else {
            $data = $elementor_data;
        }
        
        // Update image URLs in the data
        $data = $this->replace_image_urls_in_data($data, $old_url, $new_url, $attachment_id);
        
        // Save updated data
        if ($is_json) {
            update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($data)));
        } else {
            update_post_meta($post_id, '_elementor_data', $data);
        }
    }
    
    /**
     * Recursively replace image URLs in Elementor data
     * 
     * @param array $elements Elements array
     * @param string $old_url Original URL
     * @param string $new_url New URL
     * @param int $attachment_id Attachment ID
     * @return array Updated elements
     */
    protected function replace_image_urls_in_data($elements, $old_url, $new_url, $attachment_id) {
        if (!is_array($elements)) {
            return $elements;
        }
        
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            
            // Process settings that may contain images
            if (isset($element['settings'])) {
                // Handle simple image URL
                if (isset($element['settings']['image']['url']) && $element['settings']['image']['url'] === $old_url) {
                    $element['settings']['image']['url'] = $new_url;
                    $element['settings']['image']['id'] = $attachment_id;
                }
                
                // Handle background image
                if (isset($element['settings']['background_image']['url']) && $element['settings']['background_image']['url'] === $old_url) {
                    $element['settings']['background_image']['url'] = $new_url;
                    $element['settings']['background_image']['id'] = $attachment_id;
                }
                
                // Handle other direct URL settings (gallery, carousel, etc)
                foreach ($element['settings'] as $key => &$setting) {
                    // Handle string URL properties
                    if (is_string($setting) && $setting === $old_url) {
                        $element['settings'][$key] = $new_url;
                    }
                    
                    // Handle gallery items
                    if ($key === 'gallery' && is_array($setting)) {
                        foreach ($setting as &$gallery_item) {
                            if (isset($gallery_item['url']) && $gallery_item['url'] === $old_url) {
                                $gallery_item['url'] = $new_url;
                                $gallery_item['id'] = $attachment_id;
                            }
                        }
                    }
                }
            }
            
            // Process child elements recursively
            if (isset($element['elements']) && is_array($element['elements'])) {
                $element['elements'] = $this->replace_image_urls_in_data($element['elements'], $old_url, $new_url, $attachment_id);
            }
        }
        
        return $elements;
    }
    
    /**
     * Update import progress
     * 
     * @param int $post_id Post ID
     */
    protected function update_import_progress($post_id) {
        $processed = (int) get_post_meta($post_id, '_csmf_images_processed', true);
        $total = (int) get_post_meta($post_id, '_csmf_images_total', true);
        
        update_post_meta($post_id, '_csmf_images_processed', $processed + 1);
        
        // Calculate percentage
        $percentage = 0;
        if ($total > 0) {
            $percentage = min(99, round((($processed + 1) / $total) * 100));
        }
        
        update_post_meta($post_id, '_csmf_image_import_percentage', $percentage);
    }
    
    /**
     * Handle failed image import
     * 
     * @param int $post_id Post ID
     * @param string $url Failed image URL
     */
    protected function handle_failed_import($post_id, $url) {
        // Get or create placeholder image
        $placeholder_id = $this->get_placeholder_image();
        
        if (!is_wp_error($placeholder_id)) {
            $placeholder_url = wp_get_attachment_url($placeholder_id);
            $this->update_elementor_data($post_id, $url, $placeholder_url, $placeholder_id);
        }
        
        // Track failed imports
        $failed_imports = get_post_meta($post_id, '_csmf_failed_images', true);
        if (!is_array($failed_imports)) {
            $failed_imports = [];
        }
        $failed_imports[] = $url;
        update_post_meta($post_id, '_csmf_failed_images', $failed_imports);
        
        // Update progress
        $this->update_import_progress($post_id);
    }
    
    /**
     * Get or create placeholder image
     * 
     * @return int|WP_Error Attachment ID or error
     */
    protected function get_placeholder_image() {
        // Check if we have a placeholder already
        $placeholder_id = get_option('csmf_placeholder_image_id');
        if ($placeholder_id) {
            return $placeholder_id;
        }
        
        // Create placeholder image
        $placeholder_url = plugin_dir_url(CSMF_PATH) . 'assets/images/placeholder.jpg';
        
        // Required files for media handling
        if (!function_exists('download_url')) {
            require_once(ABSPATH . 'wp-admin/includes/file.php');
        }
        if (!function_exists('media_handle_sideload')) {
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
        }
        
        // Download file
        $tmp = download_url($placeholder_url);
        if (is_wp_error($tmp)) {
            return $tmp;
        }
        
        // Add to media library
        $file_array = [
            'name' => 'elementor-template-placeholder.jpg',
            'tmp_name' => $tmp,
            'error' => 0,
            'size' => filesize($tmp)
        ];
        
        $placeholder_id = media_handle_sideload($file_array, 0, 'Template Image Placeholder');
        if (!is_wp_error($placeholder_id)) {
            update_option('csmf_placeholder_image_id', $placeholder_id);
        } else {
            @unlink($tmp);
        }
        
        return $placeholder_id;
    }
}