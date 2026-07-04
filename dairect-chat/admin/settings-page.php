<?php
/**
 * dAIrect Chat – Admin Settings Page (with AJAX debug fix)
 *
 * - Preserves all original admin settings and layout.
 * - Adds proper JS binding for the "Send Prompt" tester.
 * - Ensures AJAX calls point to /wp-admin/admin-ajax.php
 *   instead of tools.php?page=dairect-chat (404 fix).
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('dairect_chat_render_settings_page')) {
  function dairect_chat_render_settings_page() {
    $settings = function_exists('dairect_chat_get_settings')
      ? dairect_chat_get_settings()
      : (array) get_option('dairect_chat_settings', []);

    if (!is_array($settings)) $settings = [];

    $test_prompt = isset($settings['test_prompt']) ? (string) $settings['test_prompt'] : '';
    ?>
    <div class="wrap">
      <h1><?php echo esc_html__('dAIrect Chat Settings', 'dairect-chat'); ?></h1>

      <form method="post" action="options.php">
        <?php
          settings_fields('dairect_chat_settings');
          do_settings_sections('dairect_chat_settings_page');
          submit_button();
        ?>
      </form>

      <h2 class="title" style="margin-top:24px;"><?php echo esc_html__('Test Prompt', 'dairect-chat'); ?></h2>
      <p>
        <textarea id="dairect_test_prompt" rows="4"
                  style="width:60%;max-width:900px;"><?php echo esc_textarea($test_prompt); ?></textarea>
      </p>
      <p>
        <button type="button" class="button button-secondary" id="dairect_send_prompt">
          <?php echo esc_html__('Send Prompt', 'dairect-chat'); ?>
        </button>
      </p>

      <div>
        <h3 class="title"><?php echo esc_html__('Response:', 'dairect-chat'); ?></h3>
        <div id="dairect_test_response"
             style="min-height:60px;padding:8px;background:#fff;border:1px solid #ccd0d4;">
          <?php echo esc_html__('No response.', 'dairect-chat'); ?>
        </div>
      </div>
    </div>

    <script type="text/javascript">
      (function() {
        const ajaxUrl = window.ajaxurl || "<?php echo esc_js(admin_url('admin-ajax.php')); ?>";
        console.log('[dAIrect] ajaxUrl =', ajaxUrl);

        const sendBtn = document.getElementById('dairect_send_prompt');
        if (!sendBtn) {
          console.warn('[dAIrect] Send Prompt button not found.');
          return;
        }

        sendBtn.addEventListener('click', async () => {
          const textarea = document.getElementById('dairect_test_prompt');
          const responseDiv = document.getElementById('dairect_test_response');
          const prompt = textarea ? textarea.value.trim() : '';

          responseDiv.textContent = 'Sending...';

          try {
            const formData = new FormData();
            formData.append('action', 'dairect_chat_test');
            formData.append('prompt', prompt);
            formData.append('nonce', '<?php echo esc_js( wp_create_nonce( 'dairect_chat_nonce' ) ); ?>');

            const res = await fetch(ajaxUrl, {
              method: 'POST',
              body: formData,
            });

            if (!res.ok) {
              responseDiv.textContent = 'Error: HTTP ' + res.status;
              console.error('[dAIrect] HTTP error', res.status);
              return;
            }

            const data = await res.json();
            console.log('[dAIrect] Response data:', data);

            if (data.success) {
              // Extract reply text from response - check message first, then data.reply
              const replyText = data.message || (data.data && data.data.reply) || (data.data && typeof data.data === 'string' ? data.data : '') || '';
              responseDiv.textContent = replyText || '(Empty response)';
            } else {
              const errorMsg = data.message || (data.data && typeof data.data === 'string' ? data.data : '') || 'Unknown error';
              responseDiv.textContent = 'Error: ' + errorMsg;
            }

          } catch (err) {
            console.error('[dAIrect] Fetch failed:', err);
            responseDiv.textContent = 'Error: ' + err.message;
          }
        });
      })();
    </script>
    <?php
  }
}
?>
