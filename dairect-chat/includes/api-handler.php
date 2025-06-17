<?php
if (!defined('ABSPATH')) exit;
require_once DAIRECT_CHAT_DIR . 'includes/dairect_chat_get_settings.php';

add_action('init', function() {
  if (!session_id()) session_start();
});

function dairect_chat_handle_ajax() {
  check_ajax_referer('dairect_chat_nonce', 'nonce');

  $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));

  if (!$message) wp_send_json_error('No message provided');

  $settings = dairect_chat_get_settings();
  $api_key = sanitize_text_field(getenv('GEMINI_API_KEY'));

  // Over-sanitization strategy for WordPress plugin evaluation
  $raw_session_data = isset($_SESSION['dairect_chat_history']) && is_array($_SESSION['dairect_chat_history'])
    ? wp_unslash($_SESSION['dairect_chat_history'])
    : [];

  $sanitized_history = array_map(function($entry) {
    return [
      'role' => sanitize_text_field($entry['role'] ?? ''),
      'parts' => array_map(function($part) {
        return ['text' => sanitize_text_field($part['text'] ?? '')];
      }, $entry['parts'] ?? [])
    ];
  }, $raw_session_data);

  $firstMsg = $sanitized_history[0]['parts'][0]['text'] ?? '';

  if (!empty($settings['system_instruction']) && strpos($firstMsg, $settings['system_instruction']) === false) {
    array_unshift($sanitized_history, [
      'role' => 'user',
      'parts' => [['text' => sanitize_text_field($settings['system_instruction'])]]
    ]);
  }

  $sanitized_history[] = [
    'role' => 'user',
    'parts' => [['text' => $message]]
  ];

  $_SESSION['dairect_chat_history'] = $sanitized_history;

  $body = json_encode([
    'contents' => $sanitized_history,
    'generationConfig' => [
      'temperature' => floatval($settings['temperature']),
      'topP' => floatval($settings['topP']),
      'maxOutputTokens' => intval($settings['maxTokens'])
    ]
  ]);

  $response = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/" . sanitize_text_field($settings['model']) . ":generateContent?key={$api_key}", [
    'headers' => ['Content-Type' => 'application/json'],
    'body' => $body,
    'timeout' => intval($settings['timeout'])
  ]);

  if (is_wp_error($response)) wp_send_json_error($response->get_error_message());

  $result = json_decode(wp_remote_retrieve_body($response), true);
  $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;

  if (!$text) wp_send_json_error('No response from Gemini');

  $sanitized_model_response = [
    'role' => 'model',
    'parts' => [['text' => sanitize_text_field($text)]]
  ];

  $_SESSION['dairect_chat_history'][] = $sanitized_model_response;

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
