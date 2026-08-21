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
           'name="dairect_chat_settings[model_name]" value="' . esc_attr($val) . '" placeholder="gemini-3.1-flash-lite"/>';
      echo '<p class="description">' .
           esc_html__('Examples: gemini-3.1-flash-lite (current), gemini-2.5-flash, gemini-2.5-pro, gemini-2.5-flash-lite — enter without the "models/" prefix.', 'dairect-chat') .
           '</p>';
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

  // ── Widget Display section ──────────────────────────────────────────────────
  add_settings_section(
    'dairect_widget_section',
    __( 'Widget Display', 'dairect-chat' ),
    function () {
      echo '<p class="description">' .
           esc_html__( 'Control how and where the chat widget appears. Floating mode adds a persistent button on every page; the shortcode [dairect_chat_widget] can be used simultaneously for a dedicated chat page.', 'dairect-chat' ) .
           '</p>';
    },
    $page
  );

  // Drawer title
  add_settings_field(
    'drawer_title',
    __( 'Chat Drawer Title', 'dairect-chat' ),
    function () use ( $get ) {
      $val = (string) $get( 'drawer_title', 'Chat' );
      echo '<input type="text" style="width:20rem;" ' .
           'name="dairect_chat_settings[drawer_title]" value="' . esc_attr( $val ) . '" ' .
           'placeholder="Chat" />';
      echo '<p class="description">' . esc_html__( 'Label shown in the floating chat drawer header. Default: Chat.', 'dairect-chat' ) . '</p>';
    },
    $page,
    'dairect_widget_section'
  );

  // Floating mode toggle
  add_settings_field(
    'floating_mode_enabled',
    __( 'Floating Mode', 'dairect-chat' ),
    function () use ( $get ) {
      $val = (string) $get( 'floating_mode_enabled', '0' );
      ?>
      <label>
        <input type="checkbox"
               name="dairect_chat_settings[floating_mode_enabled]"
               value="1" <?php checked( $val, '1' ); ?> />
        <?php echo esc_html__( 'Show a floating chat button on every page (site-wide).', 'dairect-chat' ); ?>
      </label>
      <?php
    },
    $page,
    'dairect_widget_section'
  );

  // Button image (Media Library picker)
  add_settings_field(
    'button_image_url',
    __( 'Button Image', 'dairect-chat' ),
    function () use ( $get ) {
      $url = esc_url( (string) $get( 'button_image_url', '' ) );
      ?>
      <input type="text"
             id="dairect-button-image-url"
             name="dairect_chat_settings[button_image_url]"
             value="<?php echo esc_attr( $url ); ?>"
             style="width:24rem;"
             placeholder="<?php echo esc_attr__( 'Leave blank to use default chat icon', 'dairect-chat' ); ?>" />
      <button type="button" id="dairect-pick-image" class="button button-secondary" style="margin-left:6px;">
        <?php echo esc_html__( 'Choose Image', 'dairect-chat' ); ?>
      </button>
      <button type="button" id="dairect-remove-image" class="button button-link" style="margin-left:4px;color:#d63638;">
        <?php echo esc_html__( 'Remove', 'dairect-chat' ); ?>
      </button>
      <br/>
      <?php if ( $url ) : ?>
      <img id="dairect-button-image-preview"
           src="<?php echo esc_url( $url ); ?>"
           style="margin-top:8px;width:64px;height:64px;object-fit:cover;border-radius:50%;border:1px solid #ccc;" />
      <?php else : ?>
      <img id="dairect-button-image-preview"
           src=""
           style="display:none;margin-top:8px;width:64px;height:64px;object-fit:cover;border-radius:50%;border:1px solid #ccc;" />
      <?php endif; ?>
      <p class="description">
        <?php echo esc_html__( 'Optional. Uses a default chat bubble icon if left blank.', 'dairect-chat' ); ?>
      </p>
      <?php
    },
    $page,
    'dairect_widget_section'
  );

  // Drawer width
  add_settings_field(
    'drawer_width',
    __( 'Drawer Width (px)', 'dairect-chat' ),
    function () use ( $get ) {
      $val = (string) $get( 'drawer_width', '380' );
      echo '<input type="number" min="280" max="800" style="width:6rem;" ' .
           'name="dairect_chat_settings[drawer_width]" value="' . esc_attr( $val ) . '" />';
      echo '<p class="description">' . esc_html__( '280–800px. Default: 380.', 'dairect-chat' ) . '</p>';
    },
    $page,
    'dairect_widget_section'
  );

  // Show Clear button
  add_settings_field(
    'show_clear_button',
    __( 'Show Clear Button', 'dairect-chat' ),
    function () use ( $get ) {
      $val = (string) $get( 'show_clear_button', '1' );
      ?>
      <input type="hidden" name="dairect_chat_settings[show_clear_button]" value="0" />
      <label>
        <input type="checkbox"
               name="dairect_chat_settings[show_clear_button]"
               value="1" <?php checked( $val, '1' ); ?> />
        <?php echo esc_html__( 'Show a "Clear" button so users can reset the conversation.', 'dairect-chat' ); ?>
      </label>
      <?php
    },
    $page,
    'dairect_widget_section'
  );

  // Greeting enabled
  add_settings_field(
    'greeting_enabled',
    __( 'Initial Greeting', 'dairect-chat' ),
    function () use ( $get ) {
      $enabled = (string) $get( 'greeting_enabled', '0' );
      ?>
      <label>
        <input type="checkbox"
               name="dairect_chat_settings[greeting_enabled]"
               value="1" <?php checked( $enabled, '1' ); ?> />
        <?php echo esc_html__( 'Show a greeting message at the start of each chat session.', 'dairect-chat' ); ?>
      </label>
      <?php
    },
    $page,
    'dairect_widget_section'
  );

  // Greeting text
  add_settings_field(
    'greeting_text',
    __( 'Greeting Text', 'dairect-chat' ),
    function () use ( $get ) {
      $val = (string) $get( 'greeting_text', '' );
      echo '<textarea name="dairect_chat_settings[greeting_text]" rows="3" style="width:60%;">' .
           esc_textarea( $val ) . '</textarea>';
      echo '<p class="description">' .
           esc_html__( 'Opening message shown as an AI bubble at the start of every session. You may include a link using plain HTML, e.g.:', 'dairect-chat' ) .
           ' <code>&lt;a href="https://example.com" target="_blank"&gt;Click here&lt;/a&gt;</code>' .
           '</p>';
    },
    $page,
    'dairect_widget_section'
  );

  // Accent color
  add_settings_field(
    'accent_color',
    __( 'Accent Color', 'dairect-chat' ),
    function () use ( $get ) {
      $raw = (string) $get( 'accent_color', '#6366f1' );
      $val = preg_match( '/^#[0-9a-f]{6}$/i', $raw ) ? strtolower( $raw ) : '#6366f1';
      ?>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <input type="color"
               id="dairect-accent-picker"
               value="<?php echo esc_attr( $val ); ?>"
               style="height:36px;width:48px;padding:2px;cursor:pointer;border:1px solid #ccc;border-radius:4px;" />
        <input type="text"
               id="dairect-accent-text"
               name="dairect_chat_settings[accent_color]"
               value="<?php echo esc_attr( $val ); ?>"
               placeholder="#6366f1"
               maxlength="9"
               style="width:7rem;font-family:monospace;" />
        <div style="display:flex;gap:8px;align-items:center;margin-left:4px;">
          <div id="dairect-accent-preview-btn"
               style="width:36px;height:36px;border-radius:50%;background:<?php echo esc_attr( $val ); ?>;border:1px solid #ccc;flex-shrink:0;"></div>
          <div id="dairect-accent-preview-bar"
               style="width:120px;height:28px;border-radius:4px;background:<?php echo esc_attr( $val ); ?>;border:1px solid #ccc;"></div>
          <span style="font-size:12px;color:#666;"><?php echo esc_html__( 'Button / title bar', 'dairect-chat' ); ?></span>
        </div>
      </div>
      <p class="description" style="margin-top:6px;">
        <?php echo esc_html__( 'Click the color swatch to pick, or type a hex value (#rrggbb). Click Save Changes below to apply.', 'dairect-chat' ); ?>
      </p>
      <?php
    },
    $page,
    'dairect_widget_section'
  );
});

add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
  if ( 'tools_page_dairect-chat' !== $hook_suffix ) return;
  $js = <<<'COLORJS'
document.addEventListener('DOMContentLoaded', function() {
    var picker  = document.getElementById('dairect-accent-picker');
    var text    = document.getElementById('dairect-accent-text');
    var prevBtn = document.getElementById('dairect-accent-preview-btn');
    var prevBar = document.getElementById('dairect-accent-preview-bar');
    if (!picker || !text) return;

    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(function(n) {
            return ('0' + parseInt(n, 10).toString(16)).slice(-2);
        }).join('');
    }

    function parseColor(val) {
        val = val.trim();
        if (/^#[0-9a-f]{6}$/i.test(val)) return val.toLowerCase();
        if (/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i.test(val)) {
            var c = val.slice(1);
            return '#' + c[0]+c[0]+c[1]+c[1]+c[2]+c[2];
        }
        var m = val.match(/^rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i);
        if (m) return rgbToHex(m[1], m[2], m[3]);
        return null;
    }

    function applyColor(hex) {
        picker.value = hex;
        text.value   = hex;
        if (prevBtn) prevBtn.style.background = hex;
        if (prevBar) prevBar.style.background = hex;
    }

    // 'change' fires once when the native picker closes; 'input' fires on every drag
    // and can freeze the browser tab, so we use 'change' here.
    picker.addEventListener('change', function() {
        applyColor(picker.value);
    });

    text.addEventListener('input', function() {
        var hex = parseColor(text.value);
        if (hex) {
            picker.value = hex;
            if (prevBtn) prevBtn.style.background = hex;
            if (prevBar) prevBar.style.background = hex;
        }
    });

    text.addEventListener('blur', function() {
        var hex = parseColor(text.value);
        applyColor(hex || picker.value);
    });
});
COLORJS;
  wp_add_inline_script( 'jquery', $js );
} );

/**
 * Single canonical sanitizer for all dairect_chat_settings fields.
 * Handles core fields + tracing flag so only one register_setting() is needed.
 */
function dairect_chat_sanitize_core_settings( $incoming ) {
    $prev = (array) get_option( 'dairect_chat_settings', array() );
    $in   = is_array( $incoming ) ? $incoming : array();
    $out  = array_merge( $prev, $in );

    // Numeric floats
    foreach ( array( 'temperature', 'top_p' ) as $k ) {
        if ( isset( $out[ $k ] ) ) {
            $out[ $k ] = (string) floatval( $out[ $k ] );
        }
    }

    // Numeric ints
    foreach ( array( 'max_tokens', 'timeout_ms' ) as $k ) {
        if ( isset( $out[ $k ] ) ) {
            $out[ $k ] = (string) intval( $out[ $k ] );
        }
    }

    // Sanitized strings
    foreach ( array( 'model_name', 'api_key' ) as $k ) {
        if ( isset( $out[ $k ] ) ) {
            $out[ $k ] = sanitize_text_field( wp_unslash( $out[ $k ] ) );
        }
    }
    if ( isset( $out['system_instructions'] ) ) {
        $out['system_instructions'] = sanitize_textarea_field( wp_unslash( $out['system_instructions'] ) );
    }

    // Tracing flag — coerce to '0'/'1'
    $out['dairect_tracing_enabled'] = ! empty( $out['dairect_tracing_enabled'] ) ? '1' : '0';
    if ( ! isset( $out['dairect_tracing_initialized'] ) ) {
        $out['dairect_tracing_initialized'] = '0';
    }

    // Trace retention period — must be one of the allowed values
    if ( isset( $out['trace_retention_days'] ) ) {
        $days = (int) $out['trace_retention_days'];
        $out['trace_retention_days'] = in_array( $days, array( 2, 7, 15, 30 ), true ) ? (string) $days : '7';
    }

    // Phoenix observability fields
    $out['phoenix_enabled'] = ! empty( $out['phoenix_enabled'] ) ? '1' : '0';
    if ( isset( $out['phoenix_project_name'] ) ) {
        $out['phoenix_project_name'] = sanitize_text_field( wp_unslash( $out['phoenix_project_name'] ) );
    }
    if ( isset( $out['phoenix_collector_endpoint'] ) ) {
        $out['phoenix_collector_endpoint'] = esc_url_raw( wp_unslash( $out['phoenix_collector_endpoint'] ) );
    }

    // Widget display fields
    $out['floating_mode_enabled'] = ! empty( $out['floating_mode_enabled'] ) ? '1' : '0';
    if ( isset( $out['drawer_title'] ) ) {
        $out['drawer_title'] = sanitize_text_field( wp_unslash( $out['drawer_title'] ) );
    }
    if ( isset( $out['accent_color'] ) ) {
        $c = sanitize_text_field( wp_unslash( $out['accent_color'] ) );
        if ( preg_match( '/^#[0-9a-f]{6}$/i', $c ) ) {
            $out['accent_color'] = strtolower( $c );
        } elseif ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/i', $c, $m ) ) {
            $out['accent_color'] = '#' . strtolower( $m[1].$m[1].$m[2].$m[2].$m[3].$m[3] );
        } else {
            $out['accent_color'] = '#6366f1';
        }
    }
    if ( isset( $out['button_image_url'] ) ) {
        $out['button_image_url'] = esc_url_raw( wp_unslash( $out['button_image_url'] ) );
    }
    if ( isset( $out['drawer_width'] ) ) {
        $out['drawer_width'] = (string) max( 280, min( 800, intval( $out['drawer_width'] ) ) );
    }

    // Clear button visibility
    $out['show_clear_button'] = ! empty( $out['show_clear_button'] ) ? '1' : '0';

    // Greeting fields
    $out['greeting_enabled'] = ! empty( $out['greeting_enabled'] ) ? '1' : '0';
    if ( isset( $out['greeting_text'] ) ) {
        $out['greeting_text'] = wp_kses(
            wp_unslash( $out['greeting_text'] ),
            array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
        );
    }

    return $out;
}
