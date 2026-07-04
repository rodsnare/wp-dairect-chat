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

  // register_setting() is handled once in admin-core-settings.php

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

  // 4) Download section for trace data
  add_settings_section(
    'dairect_tracing_download_section',
    __('Download Trace Data', 'dairect-chat'),
    function () {
      global $wpdb;
      $table = $wpdb->prefix . 'dairect_chat_trace';
      $has_data = false;
      
      // Check if table exists and has data
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if ($exists === $table) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $has_data = ($count > 0);
      }
      
      if (!$has_data) {
        echo '<p class="description" style="color:#d63638;">' .
             esc_html__('Download feature is disabled. Tracing must be active or have been active in the past with data to enable downloads.', 'dairect-chat') .
             '</p>';
      } else {
        echo '<p class="description">' .
             esc_html__('Download trace data as JSON by date range. Select start and end dates to export captured prompts and responses.', 'dairect-chat') .
             '</p>';
      }
    },
    $page
  );

  // 5) Date range and download button field
  add_settings_field(
    'dairect_tracing_download',
    __('Export Trace Data', 'dairect-chat'),
    function () {
      global $wpdb;
      $table = $wpdb->prefix . 'dairect_chat_trace';
      $has_data = false;
      
      // Check if table exists and has data
      $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
      if ($exists === $table) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $has_data = ($count > 0);
      }
      
      // Get date range from existing data
      $date_range = array('min' => '', 'max' => '');
      if ($has_data) {
        $date_range['min'] = $wpdb->get_var("SELECT MIN(timestamp) FROM {$table}");
        $date_range['max'] = $wpdb->get_var("SELECT MAX(timestamp) FROM {$table}");
      }
      
      $today = gmdate('Y-m-d');
      $start_date = !empty($date_range['min']) ? gmdate('Y-m-d', strtotime($date_range['min'])) : $today;
      $end_date = !empty($date_range['max']) ? gmdate('Y-m-d', strtotime($date_range['max'])) : $today;

      // Set min to earliest trace date (or a safe past date), no max restriction
      // This allows selecting any date from the earliest data up to today and beyond
      // Server-side validation will ensure we only query valid date ranges
      $min_selectable_date = !empty($date_range['min']) ? gmdate('Y-m-d', strtotime($date_range['min'])) : gmdate('Y-m-d', strtotime('-1 year'));
      ?>
      <div id="dairect-tracing-download-container">
        <p>
          <label for="dairect-trace-start-date"><?php echo esc_html__('Start Date:', 'dairect-chat'); ?></label><br/>
          <input type="date" 
                 id="dairect-trace-start-date" 
                 value="<?php echo esc_attr($start_date); ?>" 
                 min="<?php echo esc_attr($min_selectable_date); ?>"
                 <?php echo $has_data ? '' : 'disabled'; ?> />
        </p>
        <p>
          <label for="dairect-trace-end-date"><?php echo esc_html__('End Date:', 'dairect-chat'); ?></label><br/>
          <input type="date" 
                 id="dairect-trace-end-date" 
                 value="<?php echo esc_attr($end_date); ?>" 
                 min="<?php echo esc_attr($min_selectable_date); ?>"
                 <?php echo $has_data ? '' : 'disabled'; ?> />
        </p>
        <p>
          <button type="button" 
                  id="dairect-download-trace" 
                  class="button button-secondary"
                  <?php echo $has_data ? '' : 'disabled'; ?>>
            <?php echo esc_html__('Download JSON', 'dairect-chat'); ?>
          </button>
          <span id="dairect-download-status" style="margin-left:10px;"></span>
        </p>
      </div>
      <?php
    },
    $page,
    'dairect_tracing_download_section'
  );
});

add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
    if ( 'tools_page_dairect-chat' !== $hook_suffix ) {
        return;
    }

    // Media Library picker for button image upload
    wp_enqueue_media();
    wp_add_inline_script( 'jquery', <<<'MEDIAJS'
document.addEventListener('DOMContentLoaded', function() {
    var pickBtn   = document.getElementById('dairect-pick-image');
    var removeBtn = document.getElementById('dairect-remove-image');
    var urlInput  = document.getElementById('dairect-button-image-url');
    var preview   = document.getElementById('dairect-button-image-preview');
    if (!pickBtn || !urlInput) return;
    pickBtn.addEventListener('click', function(e) {
        e.preventDefault();
        var frame = wp.media({
            title: 'Select Button Image',
            button: { text: 'Use this image' },
            multiple: false,
            library: { type: 'image' }
        });
        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            urlInput.value = attachment.url;
            if (preview) { preview.src = attachment.url; preview.style.display = 'block'; }
        });
        frame.open();
    });
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            urlInput.value = '';
            if (preview) { preview.src = ''; preview.style.display = 'none'; }
        });
    }
});
MEDIAJS
    );

    wp_localize_script(
        'jquery',
        'DairectTraceDownload',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dairect_download_trace' ),
        )
    );
    $js = <<<'ENDJS'
document.addEventListener('DOMContentLoaded', function() {
    var downloadBtn = document.getElementById('dairect-download-trace');
    var startDate   = document.getElementById('dairect-trace-start-date');
    var endDate     = document.getElementById('dairect-trace-end-date');
    var statusSpan  = document.getElementById('dairect-download-status');
    if (!downloadBtn) return;
    downloadBtn.addEventListener('click', function() {
        var start = startDate.value;
        var end   = endDate.value;
        if (!start || !end) {
            statusSpan.textContent = 'Please select both dates.';
            statusSpan.style.color = '#d63638';
            return;
        }
        if (start > end) {
            statusSpan.textContent = 'Start date must be before end date.';
            statusSpan.style.color = '#d63638';
            return;
        }
        statusSpan.textContent = 'Preparing download…';
        statusSpan.style.color = '#2271b1';
        downloadBtn.disabled = true;
        var url = new URL(DairectTraceDownload.ajaxUrl);
        url.searchParams.append('action', 'dairect_download_trace');
        url.searchParams.append('start_date', start);
        url.searchParams.append('end_date', end);
        url.searchParams.append('nonce', DairectTraceDownload.nonce);
        fetch(url.toString())
            .then(function(response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.blob();
            })
            .then(function(blob) {
                var dlUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = dlUrl;
                a.download = 'dairect-trace-' + start + '-to-' + end + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(dlUrl);
                statusSpan.textContent = 'Download complete!';
                statusSpan.style.color = '#00a32a';
                setTimeout(function() { statusSpan.textContent = ''; }, 3000);
            })
            .catch(function(error) {
                statusSpan.textContent = 'Error: ' + error.message;
                statusSpan.style.color = '#d63638';
            })
            .finally(function() {
                downloadBtn.disabled = false;
            });
    });
});
ENDJS;
    wp_add_inline_script( 'jquery', $js );

    // System instructions focus warning modal
    $confirm_js = <<<'CONFIRMJS'
document.addEventListener('DOMContentLoaded', function() {
    var sysField = document.querySelector('textarea[name="dairect_chat_settings[system_instructions]"]');
    if (!sysField) return;

    var warned = false;

    // Inject styles
    var style = document.createElement('style');
    style.textContent = [
        '#dairect-sys-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100100;align-items:center;justify-content:center;}',
        '#dairect-sys-overlay.is-open{display:flex;}',
        '#dairect-sys-modal{background:#fff;border-radius:4px;padding:28px 32px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.25);}',
        '#dairect-sys-modal h2{margin:0 0 12px;font-size:1.1rem;display:flex;align-items:center;gap:8px;}',
        '#dairect-sys-modal p{margin:0 0 20px;color:#3c434a;line-height:1.6;}',
        '#dairect-sys-modal .dairect-modal-actions{display:flex;gap:10px;justify-content:flex-end;}',
        '#dairect-sys-cancel{background:#f0f0f1;border:1px solid #c3c4c7;color:#3c434a;padding:6px 16px;border-radius:3px;cursor:pointer;font-size:.875rem;}',
        '#dairect-sys-ok{background:#2271b1;border:1px solid #2271b1;color:#fff;padding:6px 16px;border-radius:3px;cursor:pointer;font-size:.875rem;}',
        '#dairect-sys-cancel:hover{background:#dcdcde;}',
        '#dairect-sys-ok:hover{background:#135e96;border-color:#135e96;}'
    ].join('');
    document.head.appendChild(style);

    var overlay = document.createElement('div');
    overlay.id  = 'dairect-sys-overlay';
    overlay.innerHTML = [
        '<div id="dairect-sys-modal">',
        '  <h2><span style="font-size:1.3rem;">⚠️</span> System Instructions</h2>',
        '  <p>Changes here will alter SpotHelp\'s behavior for <strong>all users</strong>.',
        '  This should only be done as part of a planned, coordinated system update.</p>',
        '  <div class="dairect-modal-actions">',
        '    <button type="button" id="dairect-sys-cancel">Cancel — take me back</button>',
        '    <button type="button" id="dairect-sys-ok">I understand — let me edit</button>',
        '  </div>',
        '</div>'
    ].join('');
    document.body.appendChild(overlay);

    // Cancel: close modal and move focus away from the textarea
    document.getElementById('dairect-sys-cancel').addEventListener('click', function() {
        overlay.classList.remove('is-open');
        sysField.blur();
    });

    // OK: close modal and keep focus in the textarea
    document.getElementById('dairect-sys-ok').addEventListener('click', function() {
        overlay.classList.remove('is-open');
        sysField.focus();
    });

    // Show once per page load on first focus
    sysField.addEventListener('focus', function() {
        if (warned) return;
        warned = true;
        overlay.classList.add('is-open');
    });
});
CONFIRMJS;
    wp_add_inline_script( 'jquery', $confirm_js );
} );

/**
 * Download trace data as JSON for a given date range (admin only).
 */
function dairect_ajax_download_trace() {
    check_ajax_referer( 'dairect_download_trace', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Unauthorized', '', array( 'response' => 403 ) );
    }

    $start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
    $end   = isset( $_GET['end_date'] )   ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) )   : '';

    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end ) ) {
        wp_die( 'Invalid date format', '', array( 'response' => 400 ) );
    }

    if ( $start > $end ) {
        wp_die( 'Start date must be before end date', '', array( 'response' => 400 ) );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dairect_chat_trace';

    $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    if ( $exists !== $table ) {
        wp_die( 'Trace table does not exist', '', array( 'response' => 404 ) );
    }

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT session_id, turn_id, prompt, response, error, model_name,
                    token_usage, truncated, language_code, timestamp, is_complete
             FROM {$table}
             WHERE DATE(timestamp) BETWEEN %s AND %s
             ORDER BY timestamp ASC, session_id ASC, turn_id ASC",
            $start,
            $end
        ),
        ARRAY_A
    );

    $filename = 'dairect-trace-' . $start . '-to-' . $end . '.json';
    header( 'Content-Type: application/json' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo wp_json_encode( $rows, JSON_PRETTY_PRINT );
    exit;
}
add_action( 'wp_ajax_dairect_download_trace', 'dairect_ajax_download_trace' );


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
    dairect_debug_log( 'Tracing init error: ' . $e->getMessage() );
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
    dairect_debug_log( 'dbDelta did not create table: ' . $table );
    return false;
  }
  return true;
}
