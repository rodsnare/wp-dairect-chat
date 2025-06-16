<?php
if (!defined('ABSPATH')) exit;

function dairect_chat_render_settings_page() {
  $settings = dairect_chat_get_settings();
  ?>
  <div class="wrap">
    <h1>dAIrect Chat Settings</h1>
    <form method="post" action="options.php">
      <?php settings_fields('dairect_chat_settings'); ?>
      <table class="form-table">
        <?php foreach ($settings as $key => $val): ?>
        <tr>
          <th scope="row"><?php echo esc_html(ucfirst($key)); ?></th>
          <td>
            <?php if ($key === 'system_instruction'): ?>
              <textarea name="dairect_chat_settings[<?php echo $key; ?>]" rows="3" cols="60"><?php echo esc_textarea($val); ?></textarea>
            <?php else: ?>
              <input type="<?php echo is_numeric($val) ? 'number' : 'text'; ?>"
                name="dairect_chat_settings[<?php echo $key; ?>]"
                value="<?php echo esc_attr($val); ?>" step="any" />
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
      <?php submit_button(); ?>
    </form>
    <hr>
    <h2>Test Prompt</h2>
    <form method="post">
      <textarea name="dairect_test_prompt" rows="3" cols="80" placeholder="Enter a prompt..."><?php echo isset($_POST['dairect_test_prompt']) ? esc_textarea($_POST['dairect_test_prompt']) : ''; ?></textarea><br>
      <?php submit_button('Send Prompt', 'secondary'); ?>
    </form>
    <?php
    if (!empty($_POST['dairect_test_prompt'])) {
      $prompt = sanitize_text_field($_POST['dairect_test_prompt']);
      $api_key = getenv('GEMINI_API_KEY');
      $model = $settings['model'];

      $chat_context = [];

      if (!empty($settings['system_instruction'])) {
        $chat_context[] = [
          'role' => 'user',
          'parts' => [['text' => $settings['system_instruction']]]
        ];
      }

      $chat_context[] = [
        'role' => 'user',
        'parts' => [['text' => $prompt]]
      ];

      $response = wp_remote_post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}", [
        'headers' => ['Content-Type' => 'application/json'],
        'body' => json_encode([
          'contents' => $chat_context,
          'generationConfig' => [
            'temperature' => $settings['temperature'],
            'topP' => $settings['topP'],
            'maxOutputTokens' => $settings['maxTokens']
          ]
        ]),
        'timeout' => $settings['timeout']
      ]);

      echo '<div style="margin-top:1em;padding:1em;border:1px solid #ccc;background:#fff;"><strong>Response:</strong><br>';
      echo is_wp_error($response)
        ? 'API Error: ' . esc_html($response->get_error_message())
        : esc_html(json_decode(wp_remote_retrieve_body($response), true)['candidates'][0]['content']['parts'][0]['text'] ?? 'No response.');
      echo '</div>';
    }
  echo '</div>';
}
add_action('admin_init', function() {
  register_setting('dairect_chat_settings', 'dairect_chat_settings');
});
