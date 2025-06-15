<?php
if (!defined('ABSPATH')) exit;
require_once DAIRECT_CHAT_DIR . 'includes/dairect_chat_get_settings.php';

add_action('init', function() {
  if (!session_id()) session_start();
});

function dairect_chat_handle_ajax() {
  check_ajax_referer('dairect_chat_nonce', 'nonce');

  $message = sanitize_text_field($_POST['message'] ?? '');
  if (!$message) wp_send_json_error('No message provided');

  $settings = dairect_chat_get_settings();
  $api_key = getenv('GEMINI_API_KEY');

  if (!isset($_SESSION['dairect_chat_history']) || !is_array($_SESSION['dairect_chat_history'])) {
    $_SESSION['dairect_chat_history'] = [];
  }

  // Ensure system instruction is always the first message if it's not already present
  $firstMsg = $_SESSION['dairect_chat_history'][0]['parts'][0]['text'] ?? '';
  if (!empty($settings['system_instruction']) && strpos($firstMsg, $settings['system_instruction']) === false) {
    array_unshift($_SESSION['dairect_chat_history'], [
      'role' => 'user',
      'parts' => [['text' => $settings['system_instruction']]]
    ]);
  }

  $_SESSION['dairect_chat_history'][] = [
    'role' => 'user',
    'parts' => [['text' => $message]]
  ];

  $body = json_encode([
    'contents' => $_SESSION['dairect_chat_history'],
    'generationConfig' => [
      'temperature' => $settings['temperature'],
      'topP' => $settings['topP'],
      'maxOutputTokens' => $settings['maxTokens']
    ]
  ]);

  $response = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/{$settings['model']}:generateContent?key={$api_key}", [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $body,
    'timeout' => $settings['timeout']
  ]);

  if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

  $result = json_decode(wp_remote_retrieve_body($response), true);
  $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

  if (!$text) wp_send_json_error('No response from Gemini');
  $_SESSION['dairect_chat_history'][] = ['role' => 'model', 'parts' => [['text' => $text]]];

  wp_send_json_success($text);
}

add_action('wp_ajax_dairect_chat', 'dairect_chat_handle_ajax');
add_action('wp_ajax_nopriv_dairect_chat', 'dairect_chat_handle_ajax');

function dairect_chat_clear_history() {
  check_ajax_referer('dairect_chat_nonce', 'nonce');
  unset($_SESSION['dairect_chat_history']);
  wp_send_json_success('Chat history cleared.');
}

add_action('wp_ajax_dairect_chat_clear', 'dairect_chat_clear_history');
add_action('wp_ajax_nopriv_dairect_chat_clear', 'dairect_chat_clear_history');
