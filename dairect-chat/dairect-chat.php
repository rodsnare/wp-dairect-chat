<?php
/*
Plugin Name: dAIrect Chat
Description: Gemini-powered multi-turn AI chat plugin with admin settings and frontend chat widget.
Version: 1.0.8
Author: Rod Snare
*/

defined('ABSPATH') || exit;

define('DAIRECT_CHAT_DIR', plugin_dir_path(__FILE__));

require_once DAIRECT_CHAT_DIR . 'includes/dairect_chat_get_settings.php';
require_once DAIRECT_CHAT_DIR . 'includes/api-handler.php';
require_once DAIRECT_CHAT_DIR . 'admin/settings-page.php';

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

add_action('wp_enqueue_scripts', function() {
  wp_enqueue_script('dairect-chat-widget-js', plugin_dir_url(__FILE__) . 'public/chat-widget.js', ['jquery'], null, true);
  wp_localize_script('dairect-chat-widget-js', 'DairectChatAjax', [
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('dairect_chat_nonce')
  ]);
  wp_enqueue_style('dairect-chat-style-css', plugin_dir_url(__FILE__) . 'public/chat-widget.css');
});
