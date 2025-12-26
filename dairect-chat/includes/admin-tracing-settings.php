<?php
/**
 * dAIrect Chat – Prompt Tracing (Settings API + first-enable table create)
 *
 * - Adds ONE checkbox field to the existing settings page:
 *     Group: dairect_chat_settings
 *     Page:  dairect_chat_settings_page
 * - On first enable (0 -> 1), creates wp_{prefix}dairect_chat_trace table.
 * - Never blocks the UI or chat if DB ops fail (logs errors only).
 * - Preserves all existing settings & UX.
 */

if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
  $group = 'dairect_chat_settings';
  $page  = 'dairect_chat_settings_page';

  // 1) Ensure the base option is registered (safe if already registered elsewhere)
  register_setting($group, 'dairect_chat_settings', [
    'type'              => 'array',
    'sanitize_callback' => 'dairect_chat_sanitize_tracing_patch',
  ]);

  // 2) Small section for Prompt Tracing (title + description)
  add_settings_section(
    'dairect_tracing_section',
    __('Prompt Tracing', 'dairect-chat'),
    function () {
      echo '<p class="description">' .
           esc_html__('Enable prompt/response tracing. The trace table will be created on first enable; any DB errors will be logged but will not affect chat.', 'dairect-chat') .
           '</p>';
    },
    $page
  );

  // 3) Single checkbox field (injected into the same page)
  add_settings_field(
    'dairect_tracing_enabled',
    __('Enable Prompt Tracing', 'dairect-chat'),
    function () {
      $opts = function_exists('dairect_chat_get_settings')
        ? dairect_chat_get_settings()
        : (array) get_option('dairect_chat_settings', []);

      $enabled = isset($opts['dairect_tracing_enabled']) ? (string) $opts['dairect_tracing_enabled'] : '0';
      $initialized = isset($opts['dairect_tracing_initialized']) ? (string) $opts['dairect_tracing_initialized'] : '0';
      ?>
      <label>
        <input type="checkbox"
               name="dairect_chat_settings[dairect_tracing_enabled]"
               value="1" <?php checked($enabled, '1'); ?> />
        <?php echo esc_html__('Enable prompt/response tracing (creates the trace table on first enable).', 'dairect-chat'); ?>
      </label>
      <p class="description" style="margin-top:6px;">
        <?php
          echo ($initialized === '1')
            ? esc_html__('Tracing has been initialized.', 'dairect-chat')
            : esc_html__('Tracing has not been initialized yet.', 'dairect-chat');
        ?>
      </p>
      <?php
    },
    $page,
    'dairect_tracing_section'
  );
});

/**
 * Sanitize callback that:
 * - Coerces the tracing flag to '0' or '1'
 * - Preserves any existing settings (merges with previous array)
 */
function dairect_chat_sanitize_tracing_patch($incoming) {
  $prev = (array) get_option('dairect_chat_settings', []);
  $incoming = is_array($incoming) ? $incoming : [];

  // Preserve everything from previous unless specifically overwritten.
  $merged = array_merge($prev, $incoming);

  // Normalize the tracing flag to '0'/'1'
  $merged['dairect_tracing_enabled'] = !empty($merged['dairect_tracing_enabled']) ? '1' : '0';
  // Ensure the init flag key exists (string '0' or '1')
  if (!isset($merged['dairect_tracing_initialized'])) {
    $merged['dairect_tracing_initialized'] = '0';
  }

  return $merged;
}

/**
 * When the option is updated, detect the transition 0->1 and create the table once.
 * This is tolerant: DB errors never block; we log and continue.
 */
add_action('update_option_dairect_chat_settings', function ($old, $new, $option) {
  try {
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];

    $was_enabled = !empty($old['dairect_tracing_enabled']) ? '1' : '0';
    $is_enabled  = !empty($new['dairect_tracing_enabled']) ? '1' : '0';
    $initialized = !empty($new['dairect_tracing_initialized']) ? '1' : '0';

    // Only act on first enable (not every save)
    if ($was_enabled === '0' && $is_enabled === '1' && $initialized === '0') {
      $ok = dairect_chat_create_trace_table();
      if ($ok) {
        // Mark initialized only if the table actually exists now
        $new['dairect_tracing_initialized'] = '1';
        update_option('dairect_chat_settings', $new, false);
      }
    }
  } catch (Throwable $e) {
    // Never break admin; just log.
    error_log('[dAIrect Chat] tracing init error: ' . $e->getMessage());
  }
}, 10, 3);

/**
 * Create the trace table if missing.
 * Returns true if the table exists after this call; false otherwise.
 */
function dairect_chat_create_trace_table() {
  global $wpdb;

  $table = $wpdb->prefix . 'dairect_chat_trace';
  $charset_collate = $wpdb->get_charset_collate();

  // If it already exists, we're done.
  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists === $table) return true;

  // SQL mirrors the project definition (includes PII columns, but capture of PII
  // can be disabled at write time elsewhere; presence here keeps schema forward-compatible).
  $sql = "CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id VARCHAR(64) NOT NULL,
    turn_id INT NOT NULL,
    prompt LONGTEXT NOT NULL,
    response LONGTEXT NULL,
    error TEXT NULL,
    model_name VARCHAR(64) NULL,
    token_usage INT NULL,
    truncated TINYINT(1) DEFAULT 0,
    language_code VARCHAR(16) NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    user_id BIGINT DEFAULT NULL,
    is_complete TINYINT(1) DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_session_turn (session_id, turn_id),
    KEY idx_timestamp (timestamp)
  ) $charset_collate;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);

  // Verify existence again
  $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
  if ($exists !== $table) {
    error_log('[dAIrect Chat] dbDelta did not create table: ' . $table);
    return false;
  }
  return true;
}
