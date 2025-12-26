<?php
/**
 * dAIrect Chat - Tracing core (create table on first enable + safe logging)
 * - Table created ONLY when tracing flips from OFF -> ON
 * - All DB operations are best-effort and NEVER block chat flow
 */
if (!defined('ABSPATH')) exit;

if (!function_exists('dairect_tracing_is_enabled')) {
  function dairect_tracing_is_enabled(): bool {
    return get_option('dairect_tracing_enabled', '0') === '1';
  }
}

if (!function_exists('dairect_tracing_ensure_table')) {
  function dairect_tracing_ensure_table(): bool {
    global $wpdb;
    try {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';
      $table = $wpdb->prefix . 'dairect_chat_trace';
      $charset = $wpdb->get_charset_collate();
      $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        session_id VARCHAR(64) NOT NULL,
        turn_id INT NOT NULL,
        prompt TEXT NOT NULL,
        response TEXT NULL,
        error TEXT NULL,
        model_name VARCHAR(64) NULL,
        token_usage INT NULL,
        truncated TINYINT(1) DEFAULT 0,
        language_code VARCHAR(16) NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        user_id BIGINT NULL,
        is_complete TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (id),
        KEY session_turn (session_id, turn_id),
        KEY ts_idx (timestamp)
      ) {$charset};";
      dbDelta($sql);
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      return is_string($exists) && strtolower($exists) == strtolower($table);
    } catch (\Throwable $e) {
      error_log('[dAIrect Chat] Failed to ensure trace table: ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('dairect_tracing_on_toggle')) {
  function dairect_tracing_on_toggle($old_value, $value, $option) {
    if ($option !== 'dairect_tracing_enabled') return;
    if ($old_value !== '1' && $value === '1') {
      $initialized = get_option('dairect_tracing_initialized', '0') === '1';
      if (!dairect_tracing_ensure_table()) {
        error_log('[dAIrect Chat] Tracing enabled but trace table is unavailable.');
        return;
      }
      if (!$initialized) {
        update_option('dairect_tracing_initialized', '1', true);
      }
    }
  }
  add_action('update_option_dairect_tracing_enabled', 'dairect_tracing_on_toggle', 10, 3);
}

if (!function_exists('dairect_tracing_log')) {
  function dairect_tracing_log(array $row): void {
    if (!dairect_tracing_is_enabled()) return;
    global $wpdb;
    $table = $wpdb->prefix . 'dairect_chat_trace';
    try {
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if (!is_string($exists) || strtolower($exists) !== strtolower($table)) {
        return;
      }
      $wpdb->suppress_errors(true);
      $data = array_intersect_key($row, array_flip([
        'session_id','turn_id','prompt','response','error',
        'model_name','token_usage','truncated','language_code',
        'timestamp','ip_address','user_agent','user_id','is_complete'
      ]));
      if (!isset($data['turn_id'])) $data['turn_id'] = 1;
      if (!isset($data['is_complete'])) $data['is_complete'] = 0;
      $wpdb->insert($table, $data);
    } catch (\Throwable $e) {
      error_log('[dAIrect Chat] Trace insert failed: ' . $e->getMessage());
    } finally {
      if (isset($wpdb)) $wpdb->suppress_errors(false);
    }
  }
}
