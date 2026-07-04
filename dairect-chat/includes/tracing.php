<?php
/**
 * dAIrect Chat - Tracing core (create table on first enable + safe logging)
 * - Table created ONLY when tracing flips from OFF -> ON
 * - All DB operations are best-effort and NEVER block chat flow
 * - Rolling purge deletes records older than DAIRECT_TRACE_RETENTION_DAYS via WP cron
 */
if (!defined('ABSPATH')) exit;

define( 'DAIRECT_TRACE_RETENTION_DAYS', 7 );

if (!function_exists('dairect_tracing_is_enabled')) {
  function dairect_tracing_is_enabled(): bool {
    // Check in dairect_chat_settings array (where admin stores it)
    $settings = get_option('dairect_chat_settings', []);
    if (is_array($settings) && isset($settings['dairect_tracing_enabled'])) {
      return $settings['dairect_tracing_enabled'] === '1';
    }
    // Fallback to separate option for backward compatibility
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
      dairect_debug_log( 'Failed to ensure trace table: ' . $e->getMessage() );
      return false;
    }
  }
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
      
      // Only include non-PII fields (exclude user_id, user_agent, ip_address)
      $allowed_fields = [
        'session_id','turn_id','prompt','response','error',
        'model_name','token_usage','truncated','language_code',
        'timestamp','is_complete'
      ];
      $data = array_intersect_key($row, array_flip($allowed_fields));
      
      // Ensure required fields have defaults
      if (!isset($data['turn_id'])) $data['turn_id'] = 1;
      if (!isset($data['is_complete'])) $data['is_complete'] = 0;
      
      // Explicitly exclude PII fields (set to NULL even if provided)
      unset($data['user_id']);
      unset($data['user_agent']);
      unset($data['ip_address']);
      
      $wpdb->insert($table, $data);
    } catch (\Throwable $e) {
      dairect_debug_log( 'Trace insert failed: ' . $e->getMessage() );
    } finally {
      if (isset($wpdb)) $wpdb->suppress_errors(false);
    }
  }
}

// ── Rolling purge (7-day retention window) ─────────────────────────────────

function dairect_schedule_trace_purge(): void {
    if ( ! wp_next_scheduled( 'dairect_trace_purge' ) ) {
        wp_schedule_event( time(), 'daily', 'dairect_trace_purge' );
    }
}

function dairect_unschedule_trace_purge(): void {
    $ts = wp_next_scheduled( 'dairect_trace_purge' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'dairect_trace_purge' );
    }
}

add_action( 'dairect_trace_purge', 'dairect_run_trace_purge' );

function dairect_run_trace_purge(): void {
    global $wpdb;
    $table = $wpdb->prefix . 'dairect_chat_trace';

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) return;

    $days = absint( DAIRECT_TRACE_RETENTION_DAYS );
    $deleted = $wpdb->query(
        "DELETE FROM {$table} WHERE timestamp < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );

    dairect_debug_log( 'Trace purge: removed ' . (int) $deleted . ' rows older than ' . $days . ' days.' );
}

// Safety net for existing installs that never triggered the activation hook
add_action( 'init', 'dairect_schedule_trace_purge' );
