<?php
if (!defined('ABSPATH')) exit;

/**
 * dAIrect Chat – enqueue (force single modern script)
 * - Dequeues/deregisters any earlier chat scripts.
 * - Enqueues one fresh script with a unique handle.
 * - Localizes AJAX info with a matching nonce action.
 */
add_action('wp_enqueue_scripts', function () {
  // 0) Try to remove any earlier/legacy enqueues (add to this list if needed)
  $old_handles = [
    'dairect-chat',      // legacy handle
    'dairect_chat',      // alt legacy
    'gpt_dairect_chat',  // alt legacy
  ];
  foreach ($old_handles as $h) {
    wp_dequeue_script($h);
    wp_deregister_script($h);
  }

  // 1) Cache-bust in dev; stable in prod
  $ver = (defined('WP_DEBUG') && WP_DEBUG) ? time() : '1.1.4';

  // 2) Styles (unchanged)
  wp_enqueue_style(
    'dairect-chat-style',
    plugins_url('public/chat-widget.css', dirname(__FILE__)),
    [],
    $ver
  );

  // 3) Enqueue ONE script with a distinct/new handle to avoid collisions
  //    If you created 'chat-widget.v2.js', point to that; otherwise use your current JS file.
  wp_enqueue_script(
    'dairect-chat-v2',
    plugins_url('public/chat-widget.js', dirname(__FILE__)), // or 'public/chat-widget.v2.js'
    [],
    $ver,
    true
  );

  // 4) Localize onto the SAME handle used above
  $seed_session = isset($_COOKIE['dairect_session_id'])
    ? sanitize_text_field($_COOKIE['dairect_session_id'])
    : '';

  wp_localize_script('dairect-chat-v2', 'DairectChatAjax', [
    'ajax_url'   => admin_url('admin-ajax.php'),
    'nonce'      => wp_create_nonce('dairect_chat'), // MUST match wp_verify_nonce(...,'dairect_chat')
    'session_id' => $seed_session,
  ]);
}, 1000); // high priority so this wins over earlier enqueues
