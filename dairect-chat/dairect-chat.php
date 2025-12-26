<?php
/*
Plugin Name: dAIrect Chat
Plugin URI: https://github.com/rodsnare/wp-dairect-chat
Description: A lightweight Gemini-powered chat widget for WordPress with multi-turn conversation support.
Version: 1.1.4
Author: Rod Snare
Author URI: https://github.com/rodsnare
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: dairect-chat
Requires at least: 6.0
*/


if (!defined('ABSPATH')) exit;

/**
 * dAIrect Chat – ordered bootstrap
 * - Defines a stable version constant and legacy $plugin_version.
 * - Loads shared includes first, then admin UI, then optional tracing admin, then public/AJAX.
 * - Do NOT set WP_DEBUG* constants here; keep those in wp-config.php.
 */

// ---- Version: define constant + legacy variable (fixes "$plugin_version" warnings) ----
if (!defined('DAIRECT_CHAT_VERSION')) {
    if (function_exists('get_file_data')) {
        $hdr = get_file_data(__FILE__, ['Version' => 'Version']);
        define('DAIRECT_CHAT_VERSION', !empty($hdr['Version']) ? $hdr['Version'] : '1.1.4');
    } else {
        define('DAIRECT_CHAT_VERSION', '1.1.4');
    }
}

// For any legacy code that expects $plugin_version:
if (!isset($plugin_version)) {
    $plugin_version = DAIRECT_CHAT_VERSION;
}

// ---- Paths ----
$base_dir     = plugin_dir_path(__FILE__);
$includes_dir = $base_dir . 'includes/';
$admin_dir    = $base_dir . 'admin/';

// ======================================================================
// 1) Load the shared getter FIRST so admin screens and tracing can use it
// ======================================================================
$getter = $includes_dir . 'dairect_chat_get_settings.php';
if (file_exists($getter)) {
    require_once $getter;
} else {
    // Belt & suspenders: minimal fallback to prevent fatals if file missing
    if (!function_exists('dairect_chat_get_settings')) {
        function dairect_chat_get_settings() {
            $opts = get_option('dairect_chat_settings', []);
            return is_array($opts) ? $opts : [];
        }
    }
}

// ==================================
// 2) Admin settings page (uses getter)
// ==================================
//if (is_admin()) {
//    $admin_settings = $admin_dir . 'settings-page.php';
//    if (file_exists($admin_settings)) {
//        require_once $admin_settings;
//    }
//}

if (is_admin()) {
  // Resolve directories from plugin root (no side effects elsewhere)
  $plugin_root = plugin_dir_path(__FILE__);
  $admin_dir   = $plugin_root . 'admin/';     // you already use this
  $inc_dir     = $plugin_root . 'includes/';  // new: for registrar files

  // 1) Register the original/core settings fields (group/page the page renders)
  $core_settings = $inc_dir . 'admin-core-settings.php';
  if (file_exists($core_settings)) {
      require_once $core_settings;
  }

  // 2) Register the Prompt Tracing checkbox + first-enable table init
  $trace_settings = $inc_dir . 'admin-tracing-settings.php';
  if (file_exists($trace_settings)) {
      require_once $trace_settings;
  }

  // 3) Render the admin page (kept minimal; just Settings API render + Prompt Test)
  $admin_settings_page = $admin_dir . 'settings-page.php';
  if (file_exists($admin_settings_page)) {
      require_once $admin_settings_page;
  }
}


// ==========================================================
// 3) Tracing admin (OPTIONAL) – after getter + settings page
// ==========================================================
$trace_admin = $includes_dir . 'admin-tracing-settings.php';
if (file_exists($trace_admin)) {
    require_once $trace_admin;
}

// ================================================
// 4) Public/AJAX handlers & other runtime includes
// ================================================
$api_handler = $includes_dir . 'api-handler.php';
if (file_exists($api_handler)) {
    require_once $api_handler;
}
  // (Load any other non-admin includes here…)
  // e.g. front-end shortcode/widget bootstrap, assets, etc.


add_action('admin_menu', function() {
  add_submenu_page(
    'tools.php',
    'dAIrect Chat Settings',
    'dAIrect Chat',
    'manage_options',
    'dairect-chat',
    'dairect_chat_render_settings_page'
  );
});

add_shortcode('dairect_chat_widget', function() {
  ob_start(); ?>
  <div id="dairect-chat-widget">
    <div id="chat-messages"></div>
    <input type="text" id="chat-input" placeholder="Type a message..." />
    <div style="margin-top: 10px;">
      <button id="chat-send">Send</button>
      <button id="chat-clear">Clear Chat</button>
      <button id="chat-mic" title="Click to speak">🎤</button>
    </div>
  </div>
  <?php return ob_get_clean();
});

add_action('wp_enqueue_scripts', function() use ($plugin_version) {
  wp_enqueue_script(
    'dairect-chat-widget-js',
    plugin_dir_url(__FILE__) . 'public/chat-widget.js',
    ['jquery'],
    $plugin_version,
    true
  );

  wp_localize_script('dairect-chat-widget-js', 'DairectChatAjax', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('dairect_chat_nonce')
  ]);

// --- Minimal bootstrap to register REST routes (don’t change other code) ---
add_action('plugins_loaded', function () {
  // Adjust the path if your file lives elsewhere.
  $api_file = __DIR__ . '/includes/api-handler.php';
  if (file_exists($api_file)) {
      require_once $api_file;
  } else {
      error_log('[DAIRECT] includes/api-handler.php not found');
  }
});


  wp_enqueue_style(
    'dairect-chat-style-css',
    plugin_dir_url(__FILE__) . 'public/chat-widget.css',
    [],
    $plugin_version
  );
});
