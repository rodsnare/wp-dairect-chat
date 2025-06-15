<?php
if (!defined('ABSPATH')) exit;

function dairect_chat_get_settings() {
  return wp_parse_args(
    get_option('dairect_chat_settings', []),
    [
      'model' => 'gemini-pro',
      'temperature' => 0.7,
      'topP' => 1.0,
      'maxTokens' => 1024,
      'timeout' => 20,
      'system_instruction' => 'You are a helpful AI assistant.'
    ]
  );
}
