<?php
/*
Plugin Name: dAIrect Chat
Plugin URI: https://github.com/rodsnare/wp-dairect-chat
Description: A lightweight Gemini-powered chat widget for WordPress with multi-turn conversation support.
Version: 2.0.11
Author: Rod Snare
Author URI: https://github.com/rodsnare
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: dairect-chat
Requires at least: 6.0
*/


if (!defined('ABSPATH')) exit;

register_activation_hook( __FILE__, function () {
    if ( function_exists( 'dairect_schedule_trace_purge' ) ) {
        dairect_schedule_trace_purge();
    }
} );

register_deactivation_hook( __FILE__, function () {
    if ( function_exists( 'dairect_unschedule_trace_purge' ) ) {
        dairect_unschedule_trace_purge();
    }
} );

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
        define('DAIRECT_CHAT_VERSION', !empty($hdr['Version']) ? $hdr['Version'] : '2.0.1');
    } else {
        define('DAIRECT_CHAT_VERSION', '2.0.11');
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
if ( is_admin() ) {
    require_once $includes_dir . 'admin-core-settings.php';
    require_once $includes_dir . 'admin-tracing-settings.php';
    require_once $includes_dir . 'admin-phoenix-settings.php';
    require_once $admin_dir    . 'settings-page.php';
}


// ================================================
// 3) Tracing core functions (needed by API handler)
// ================================================
$tracing = $includes_dir . 'tracing.php';
if (file_exists($tracing)) {
    require_once $tracing;
}

// Phoenix observability (optional, best-effort)
$phoenix = $includes_dir . 'phoenix-observer.php';
if (file_exists($phoenix)) {
    require_once $phoenix;
}

// ================================================
// 5) Public/AJAX handlers & other runtime includes
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
  $sc              = dairect_chat_get_settings();
  $sc_greeting     = ( isset( $sc['greeting_enabled'] ) && $sc['greeting_enabled'] === '1' && ! empty( $sc['greeting_text'] ) )
                     ? $sc['greeting_text'] : '';
  ob_start(); ?>
  <div id="dairect-chat-widget">
    <div id="chat-messages"<?php echo $sc_greeting ? ' data-greeting="' . esc_attr( $sc_greeting ) . '"' : ''; ?>></div>
    <div class="dairect-input-wrap">
      <input type="text" id="chat-input" placeholder="<?php echo esc_attr__( 'Type a message…', 'dairect-chat' ); ?>" autocomplete="off" />
      <button id="chat-mic" class="dairect-mic-btn" title="<?php echo esc_attr__( 'Click to speak', 'dairect-chat' ); ?>" aria-label="<?php echo esc_attr__( 'Voice input', 'dairect-chat' ); ?>">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2a3 3 0 0 1 3 3v7a3 3 0 0 1-6 0V5a3 3 0 0 1 3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
      </button>
    </div>
    <div style="margin-top: 8px;">
      <button id="chat-send"><?php echo esc_html__( 'Send', 'dairect-chat' ); ?></button>
      <button id="chat-clear"><?php echo esc_html__( 'Clear Chat', 'dairect-chat' ); ?></button>
    </div>
  </div>
  <?php return ob_get_clean();
});

add_action( 'wp_footer', function () {
    $settings = dairect_chat_get_settings();
    if ( empty( $settings['floating_mode_enabled'] ) || $settings['floating_mode_enabled'] !== '1' ) {
        return;
    }
    $image_url    = ! empty( $settings['button_image_url'] ) ? esc_url( $settings['button_image_url'] ) : '';
    $drawer_width = isset( $settings['drawer_width'] ) ? max( 280, min( 800, intval( $settings['drawer_width'] ) ) ) : 380;
    $drawer_title = ! empty( $settings['drawer_title'] ) ? $settings['drawer_title'] : __( 'Chat', 'dairect-chat' );
    $float_greeting  = ( isset( $settings['greeting_enabled'] ) && $settings['greeting_enabled'] === '1' && ! empty( $settings['greeting_text'] ) )
                       ? $settings['greeting_text'] : '';
    $show_clear_btn  = ! isset( $settings['show_clear_button'] ) || $settings['show_clear_button'] === '1';
    ?>
    <button id="dairect-float-btn"
            aria-label="<?php echo esc_attr__( 'Open chat', 'dairect-chat' ); ?>"
            aria-expanded="false">
        <?php if ( $image_url ) : ?>
            <img src="<?php echo esc_url( $image_url ); ?>"
                 alt="<?php echo esc_attr__( 'Chat', 'dairect-chat' ); ?>" />
        <?php else : ?>
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white" width="32" height="32" aria-hidden="true" focusable="false">
                <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/>
            </svg>
        <?php endif; ?>
    </button>
    <div id="dairect-float-drawer"
         style="width:<?php echo esc_attr( (string) $drawer_width ); ?>px;"
         role="dialog"
         aria-label="<?php echo esc_attr( $drawer_title ); ?>"
         aria-modal="false">
        <div id="dairect-float-header">
            <span><?php echo esc_html( $drawer_title ); ?></span>
            <button id="dairect-float-close"
                    aria-label="<?php echo esc_attr__( 'Close chat', 'dairect-chat' ); ?>">&#10005;</button>
        </div>
        <div id="dairect-float-messages"<?php echo $float_greeting ? ' data-greeting="' . esc_attr( $float_greeting ) . '"' : ''; ?>></div>
        <div id="dairect-float-input-area">
            <div class="dairect-input-wrap">
                <input type="text"
                       id="dairect-float-input"
                       placeholder="<?php echo esc_attr__( 'Type a message…', 'dairect-chat' ); ?>"
                       autocomplete="off" />
                <button id="dairect-float-mic"
                        class="dairect-mic-btn"
                        title="<?php echo esc_attr__( 'Click to speak', 'dairect-chat' ); ?>"
                        aria-label="<?php echo esc_attr__( 'Voice input', 'dairect-chat' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 2a3 3 0 0 1 3 3v7a3 3 0 0 1-6 0V5a3 3 0 0 1 3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>
                </button>
            </div>
            <div class="dairect-actions">
                <button id="dairect-float-send"><?php echo esc_html__( 'Send', 'dairect-chat' ); ?></button>
                <?php if ( $show_clear_btn ) : ?>
                <button id="dairect-float-clear"><?php echo esc_html__( 'Clear', 'dairect-chat' ); ?></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
} );

add_action('wp_enqueue_scripts', function() use ($plugin_version) {
  wp_enqueue_script(
    'dairect-chat-widget-js',
    plugin_dir_url(__FILE__) . 'public/chat-widget.js',
    ['jquery'],
    $plugin_version,
    true
  );

  $s = dairect_chat_get_settings();

  wp_localize_script('dairect-chat-widget-js', 'DairectChatAjax', [
    'ajax_url'          => admin_url('admin-ajax.php'),
    'nonce'             => wp_create_nonce('dairect_chat_nonce'),
    'greeting'          => ( isset( $s['greeting_enabled'] ) && $s['greeting_enabled'] === '1' && ! empty( $s['greeting_text'] ) )
                           ? $s['greeting_text'] : '',
    'show_clear_button' => ( isset( $s['show_clear_button'] ) && $s['show_clear_button'] === '0' ) ? '0' : '1',
  ]);

  wp_enqueue_style(
    'dairect-chat-style-css',
    plugin_dir_url(__FILE__) . 'public/chat-widget.css',
    [],
    $plugin_version
  );

  // Dynamic accent color — overrides the CSS variable default
  $accent = ( ! empty( $s['accent_color'] ) && preg_match( '/^#[0-9a-f]{6}$/i', $s['accent_color'] ) )
              ? $s['accent_color'] : '#6366f1';
  wp_add_inline_style( 'dairect-chat-style-css', ':root{--dairect-accent:' . esc_attr( $accent ) . ';}' );
});
