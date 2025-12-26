<?php
if (!defined('ABSPATH')) exit;

/**
 * dAIrect Chat – Core settings registrar (restores original fields)
 * Registers fields into:
 *   group: dairect_chat_settings
 *   page:  dairect_chat_settings_page
 * These match your settings-page.php render calls.
 */
add_action('admin_init', function () {
  $group = 'dairect_chat_settings';
  $page  = 'dairect_chat_settings_page';

  // Register the main option array if not already
  register_setting($group, 'dairect_chat_settings', [
    'type'              => 'array',
    'sanitize_callback' => 'dairect_chat_sanitize_core_settings',
  ]);

  // Core section
  add_settings_section(
    'dairect_core_section',
    __('Model & Behavior', 'dairect-chat'),
    function () {
      echo '<p class="description">' .
           esc_html__('Configure system instructions and generation parameters.', 'dairect-chat') .
           '</p>';
    },
    $page
  );

  // Helpers
  $get = function ($key, $default = '') {
    $opts = (array) get_option('dairect_chat_settings', []);
    return isset($opts[$key]) ? $opts[$key] : $default;
  };

  // System Instructions (textarea)
  add_settings_field(
    'system_instructions',
    __('System Instructions', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('system_instructions', '');
      echo '<textarea name="dairect_chat_settings[system_instructions]" rows="6" style="width:60%;">' .
           esc_textarea($val) . '</textarea>';
    },
    $page,
    'dairect_core_section'
  );

  // Temperature (0–2)
  add_settings_field(
    'temperature',
    __('Temperature', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('temperature', '0.7');
      echo '<input type="number" step="0.01" min="0" max="2" ' .
           'name="dairect_chat_settings[temperature]" value="' . esc_attr($val) . '"/>';
    },
    $page,
    'dairect_core_section'
  );

  // Top-p (0–1)
  add_settings_field(
    'top_p',
    __('Top-p', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('top_p', '1');
      echo '<input type="number" step="0.01" min="0" max="1" ' .
           'name="dairect_chat_settings[top_p]" value="' . esc_attr($val) . '"/>';
    },
    $page,
    'dairect_core_section'
  );

  // Max Tokens
  add_settings_field(
    'max_tokens',
    __('Max tokens', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('max_tokens', '');
      echo '<input type="number" min="0" ' .
           'name="dairect_chat_settings[max_tokens]" value="' . esc_attr($val) . '"/>';
    },
    $page,
    'dairect_core_section'
  );

  // Timeout (ms)
  add_settings_field(
    'timeout_ms',
    __('Timeout (ms)', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('timeout_ms', '30000');
      echo '<input type="number" min="0" ' .
           'name="dairect_chat_settings[timeout_ms]" value="' . esc_attr($val) . '"/>';
    },
    $page,
    'dairect_core_section'
  );

  // Model name (text) — keep your exact key if different
  add_settings_field(
    'model_name',
    __('Model', 'dairect-chat'),
    function () use ($get) {
      $val = (string) $get('model_name', '');
      echo '<input type="text" style="width:24rem" ' .
           'name="dairect_chat_settings[model_name]" value="' . esc_attr($val) . '"/>';
    },
    $page,
    'dairect_core_section'
  );

  // API Key (password) — keeps value; hides on screen
  //add_settings_field(
  //  'api_key',
  //  __('API Key', 'dairect-chat'),
  //  function () use ($get) {
  //    $val = (string) $get('api_key', '');
  //    echo '<input type="password" autocomplete="new-password" style="width:24rem" ' .
  //         'name="dairect_chat_settings[api_key]" value="' . esc_attr($val) . '"/>';
  //  },
  //  $page,
  //  'dairect_core_section'
  //);
});

/** Basic sanitizer that preserves existing keys and normalizes numeric fields */
function dairect_chat_sanitize_core_settings($incoming) {
  $prev = (array) get_option('dairect_chat_settings', []);
  $in   = is_array($incoming) ? $incoming : [];

  $out = array_merge($prev, $in);

  // Normalize numerics
  foreach (['temperature','top_p'] as $k) {
    if (isset($out[$k])) $out[$k] = (string) floatval($out[$k]);
  }
  foreach (['max_tokens','timeout_ms'] as $k) {
    if (isset($out[$k])) $out[$k] = (string) intval($out[$k]);
  }

  // Strings
  foreach (['system_instructions','model_name','api_key'] as $k) {
    if (isset($out[$k])) $out[$k] = (string) $out[$k];
  }

  return $out;
}
