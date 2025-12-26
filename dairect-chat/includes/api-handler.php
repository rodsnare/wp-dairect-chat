<?php
/**
 * dAIrect Chat – API handler (Gemini)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get plugin setting safely.
 */
function dairect_get_setting( $key, $default = '' ) {
    $settings = get_option( 'dairect_chat_settings', array() );
    if ( ! is_array( $settings ) ) {
        $settings = array();
    }

    if ( isset( $settings[ $key ] ) && $settings[ $key ] !== '' ) {
        return $settings[ $key ];
    }

    return $default;
}

/**
 * Small helper for starts_with compatible with older PHP.
 */
if ( ! function_exists( 'dairect_str_starts_with' ) ) {
    function dairect_str_starts_with( $haystack, $needle ) {
        if ( $needle === '' ) {
            return true;
        }
        return strpos( $haystack, $needle ) === 0;
    }
}

/**
 * Central place to fetch the Gemini API key.
 *
 * Priority:
 *  1) Plugin setting: dairect_chat_settings['api_key']
 *  2) Constants defined in wp-config.php:
 *       DAIRECT_CHAT_GEMINI_API_KEY
 *       GEMINI_API_KEY
 *       GOOGLE_GEMINI_API_KEY
 *  3) Environment variables with the same names.
 */
function dairect_get_api_key() {
    // 1) Plugin setting.
    $from_setting = trim( (string) dairect_get_setting( 'api_key', '' ) );
    if ( $from_setting !== '' ) {
        return $from_setting;
    }

    // 2) Constants from wp-config.php.
    $const_names = array(
        'DAIRECT_CHAT_GEMINI_API_KEY',
        'GEMINI_API_KEY',
        'GOOGLE_GEMINI_API_KEY',
    );

    foreach ( $const_names as $name ) {
        if ( defined( $name ) ) {
            $val = trim( (string) constant( $name ) );
            if ( $val !== '' ) {
                return $val;
            }
        }
    }

    // 3) Environment variables.
    foreach ( $const_names as $name ) {
        $val = getenv( $name );
        if ( $val !== false && trim( (string) $val ) !== '' ) {
            return trim( (string) $val );
        }
    }

    // No key found.
    return '';
}

/**
 * Build the full Gemini model name.
 *
 * Admin UI typically stores something like "gemini-2.5-flash".
 * API expects "models/gemini-2.5-flash".
 */
function dairect_get_gemini_model_name() {
    $model = trim( (string) dairect_get_setting( 'model', 'gemini-2.5-flash' ) );
    if ( $model === '' ) {
        $model = 'gemini-2.5-flash';
    }

    if ( dairect_str_starts_with( $model, 'models/' ) ) {
        return $model;
    }

    return 'models/' . $model;
}

/**
 * Gemini service wrapper.
 */
class Dairect_Gemini_Service {

    protected $api_key;
    protected $model;
    protected $max_tokens;
    protected $top_p;
    protected $timeout_ms;

    public function __construct() {
        $this->api_key    = dairect_get_api_key();
        $this->model      = dairect_get_gemini_model_name();
        $this->max_tokens = intval( dairect_get_setting( 'max_tokens', 1024 ) );
        $this->top_p      = floatval( dairect_get_setting( 'top_p', 1.0 ) );
        $this->timeout_ms = intval( dairect_get_setting( 'timeout_ms', 30000 ) );
    }

    /**
     * Send a prompt to Gemini and return:
     *   array( 'reply' => string, 'raw' => array )
     * or a WP_Error.
     */
    public function send_prompt( $prompt, $session_id = '', $turn_id = 0 ) {
        if ( $this->api_key === '' ) {
            return new WP_Error(
                'dairect_missing_api_key',
                'Gemini API key is not configured.',
                array(
                    'status' => 500,
                )
            );
        }

        $endpoint = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/%s:generateContent?key=%s',
            rawurlencode( $this->model ),
            rawurlencode( $this->api_key )
        );

        $body = array(
            'contents'         => array(
                array(
                    'parts' => array(
                        array(
                            'text' => (string) $prompt,
                        ),
                    ),
                ),
            ),
            'generationConfig' => array(
                'maxOutputTokens' => $this->max_tokens,
                'topP'            => $this->top_p,
            ),
        );

        $args = array(
            'timeout' => max( 1, $this->timeout_ms / 1000 ),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'dairect_gemini_http_error',
                'Gemini HTTP error',
                array(
                    'status' => 500,
                    'error'  => $response->get_error_message(),
                )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $raw_body    = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            return new WP_Error(
                'dairect_gemini_http_error',
                'Gemini API error',
                array(
                    'status' => $status_code,
                    'body'   => $raw_body,
                )
            );
        }

        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error(
                'dairect_gemini_invalid_json',
                'Gemini API returned non-JSON',
                array(
                    'body' => $raw_body,
                )
            );
        }

        // Try the standard v1beta shape.
        $reply_text = '';
        if (
            isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
            && is_string( $decoded['candidates'][0]['content']['parts'][0]['text'] )
        ) {
            $reply_text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        } elseif ( isset( $decoded['candidates'][0]['output'] ) ) {
            $reply_text = (string) $decoded['candidates'][0]['output'];
        } else {
            // Fallback – this keeps the chat from being blank.
            $reply_text = '[No text candidate returned by Gemini]';
        }

        return array(
            'reply' => $reply_text,
            'raw'   => $decoded,
        );
    }
}

/**
 * REST callbacks.
 */
function dairect_rest_health( WP_REST_Request $request ) {
    return new WP_REST_Response(
        array(
            'ok'      => true,
            'time'    => current_time( 'mysql' ),
            'version' => DAIRECT_CHAT_VERSION ?? 'dev',
        ),
        200
    );
}

function dairect_handle_chat_rest( WP_REST_Request $request ) {
    $prompt     = isset( $request['prompt'] ) ? sanitize_textarea_field( $request['prompt'] ) : '';
    $session_id = isset( $request['session_id'] ) ? sanitize_text_field( $request['session_id'] ) : '';
    $turn_id    = isset( $request['turn_id'] ) ? intval( $request['turn_id'] ) : 0;

    if ( $prompt === '' ) {
        return new WP_Error(
            'dairect_missing_prompt',
            'Prompt is required.',
            array( 'status' => 400 )
        );
    }

    $service = new Dairect_Gemini_Service();
    $result  = $service->send_prompt( $prompt, $session_id, $turn_id );

    if ( is_wp_error( $result ) ) {
        return $result;
    }

    return new WP_REST_Response(
        array(
            'ok'    => true,
            'reply' => $result['reply'],
            'raw'   => $result['raw'],
        ),
        200
    );
}

/**
 * Register REST routes.
 */
function dairect_register_rest_routes() {
    register_rest_route(
        'dairect/v1',
        '/health',
        array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => 'dairect_rest_health',
            'permission_callback' => '__return_true',
        )
    );

    register_rest_route(
        'dairect/v1',
        '/chat',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'dairect_handle_chat_rest',
            'permission_callback' => '__return_true',
            'args'                => array(
                'prompt'     => array(
                    'required' => true,
                ),
                'session_id' => array(
                    'required' => false,
                ),
                'turn_id'    => array(
                    'required' => false,
                ),
            ),
        )
    );
}
add_action( 'rest_api_init', 'dairect_register_rest_routes' );

/**
 * AJAX bridge: admin tester + front-end widget → REST handler.
 */
function dairect_ajax_bridge_to_rest() {
    $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '(none)';

    // Light logging to help debug.
    error_log( '[DAIRECT AJAX] action=' . $action . ' params=' . wp_json_encode( $_POST ) );

    if ( $action === 'dairect_chat_test' ) {
        // Admin test panel.
        $prompt     = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $session_id = 'admin-test';
        $turn_id    = 1;
    } else {
        // Front-end widget / shortcode chat.
        $prompt     = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $turn_id    = 0;
    }

    if ( $prompt === '' ) {
        wp_send_json(
            array(
                'success' => false,
                'message' => 'Prompt is required.',
                'data'    => array(),
            )
        );
    }

    $service = new Dairect_Gemini_Service();
    $result  = $service->send_prompt( $prompt, $session_id, $turn_id );

    if ( is_wp_error( $result ) ) {
        wp_send_json(
            array(
                'success' => false,
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
                'code'    => $result->get_error_code(),
            )
        );
    }

    // Normalized success shape for both admin + front-end.
    wp_send_json(
        array(
            'success' => true,
            'message' => $result['reply'],
            'data'    => array(
                'reply' => $result['reply'],
                'raw'   => $result['raw'],
            ),
        )
    );
}

/**
 * Hook AJAX actions.
 * These are the ones we saw in debug.log:
 *   - dairect_chat_test  (admin page tester)
 *   - dairect_chat       (front-end widget)
 */
add_action( 'wp_ajax_dairect_chat_send', 'dairect_ajax_bridge_to_rest' );
add_action( 'wp_ajax_nopriv_dairect_chat_send', 'dairect_ajax_bridge_to_rest' );
add_action( 'wp_ajax_dairect_chat_test', 'dairect_ajax_bridge_to_rest' );
add_action( 'wp_ajax_dairect_chat', 'dairect_ajax_bridge_to_rest' );
