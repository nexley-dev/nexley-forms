<?php
declare(strict_types=1);

/**
 * Plugin Name: Nexley Forms
 * Plugin URI: https://nexley.com
 * Description: Ultra-lightweight, zero-bloat WordPress form builder with raw HTML editing, zero frontend CSS stylesheets, and modern REST API submissions.
 * Version: 1.0.0
 * Author: Nexley
 * Author URI: https://nexley.com
 * Text Domain: nexley-forms
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants.
define('NEXLEY_FORMS_VERSION', '1.0.0');
define('NEXLEY_FORMS_FILE', __FILE__);
define('NEXLEY_FORMS_PATH', plugin_dir_path(__FILE__));
define('NEXLEY_FORMS_URL', plugin_dir_url(__FILE__));
define('NEXLEY_FORMS_BASENAME', plugin_basename(__FILE__));

// Require class files.
require_once NEXLEY_FORMS_PATH . 'includes/class-post-types.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-form-parser.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-mailer.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-meta-boxes.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-shortcode.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-rest-controller.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-admin-docs.php';
require_once NEXLEY_FORMS_PATH . 'includes/class-plugin.php';

/**
 * Plugin activation handler.
 */
register_activation_hook(__FILE__, static function (): void {
    // Register post types temporarily to flush rewrite rules safely.
    Post_Types::register_post_types();
    flush_rewrite_rules();
});

/**
 * Plugin deactivation handler.
 */
register_deactivation_hook(__FILE__, static function (): void {
    flush_rewrite_rules();
});

/**
 * Initialize the plugin.
 */
add_action('plugins_loaded', static function (): void {
    Plugin::instance()->init();
});
