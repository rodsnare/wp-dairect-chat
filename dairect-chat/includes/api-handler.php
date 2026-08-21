<?php
/**
 * dAIrect Chat – API handler (Gemini)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Write to error log only when WP_DEBUG is enabled. */
function dairect_debug_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log( '[dAIrect Chat] ' . $message );
    }
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
    // Check both 'model_name' (from admin settings) and 'model' (fallback)
    $model = trim( (string) dairect_get_setting( 'model_name', '' ) );
    if ( $model === '' ) {
        $model = trim( (string) dairect_get_setting( 'model', '' ) );
    }
    if ( $model === '' ) {
        $model = 'gemini-2.5-flash'; // Default to a commonly available model
    }

    // Remove 'models/' prefix if present (we'll add it back)
    $model = preg_replace( '#^models/#', '', $model );
    $model = trim( $model );
    
    if ( $model === '' ) {
        $model = 'gemini-2.5-flash';
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
    protected $temperature;
    protected $timeout_ms;

    public function __construct() {
        $this->api_key     = dairect_get_api_key();
        $this->model       = dairect_get_gemini_model_name();
        $this->max_tokens  = intval( dairect_get_setting( 'max_tokens', 1024 ) );
        $this->top_p       = floatval( dairect_get_setting( 'top_p', 1.0 ) );
        $this->temperature = floatval( dairect_get_setting( 'temperature', 0.7 ) );
        $this->timeout_ms  = intval( dairect_get_setting( 'timeout_ms', 30000 ) );
    }

    /**
     * Retrieve conversation history for a session.
     * Returns array of conversation turns in Gemini API format.
     */
    private function get_conversation_history( $session_id ) {
        if ( empty( $session_id ) ) {
            return array();
        }

        // Primary: Use transients for conversation history (faster, more reliable)
        $history = get_transient( 'dairect_chat_history_' . $session_id );
        if ( is_array( $history ) && ! empty( $history ) ) {
            return $history;
        }

        // Fallback: Try trace table if transient is empty
        global $wpdb;
        $table = $wpdb->prefix . 'dairect_chat_trace';
        
        // Check if table exists
        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $exists === $table ) {
            // Get conversation history from trace table
            $results = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT turn_id, prompt, response, is_complete 
                    FROM {$table} 
                    WHERE session_id = %s AND is_complete = 1
                    ORDER BY turn_id ASC",
                    $session_id
                ),
                ARRAY_A
            );

            // Build conversation history in Gemini API format
            $history = array();
            foreach ( $results as $row ) {
                $history[] = array(
                    'role'  => 'user',
                    'parts' => array( array( 'text' => $row['prompt'] ) ),
                );
                if ( ! empty( $row['response'] ) ) {
                    $history[] = array(
                        'role'  => 'model',
                        'parts' => array( array( 'text' => $row['response'] ) ),
                    );
                }
            }

            if ( ! empty( $history ) ) {
                set_transient( 'dairect_chat_history_' . $session_id, $history, DAY_IN_SECONDS );
            }
        }

        return is_array( $history ) ? $history : array();
    }

    /**
     * Store conversation turn in transient (primary storage for conversation history).
     */
    private function store_conversation_turn( $session_id, $prompt, $response ) {
        if ( empty( $session_id ) ) {
            return;
        }

        // Get existing history from transient
        $history = get_transient( 'dairect_chat_history_' . $session_id );
        if ( ! is_array( $history ) ) {
            $history = array();
        }

        $history[] = array(
            'role'  => 'user',
            'parts' => array( array( 'text' => $prompt ) ),
        );
        if ( ! empty( $response ) ) {
            $history[] = array(
                'role'  => 'model',
                'parts' => array( array( 'text' => $response ) ),
            );
        }

        // Keep last 20 turns to avoid hitting size limits (20 user + 20 model = 40 items)
        if ( count( $history ) > 40 ) {
            $history = array_slice( $history, -40 );
        }

        set_transient( 'dairect_chat_history_' . $session_id, $history, DAY_IN_SECONDS );
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

        // Get system instructions if configured
        $system_instructions = trim( (string) dairect_get_setting( 'system_instructions', '' ) );
        
        // Get conversation history for this session
        $conversation_history = $this->get_conversation_history( $session_id );

        // Derive turn_id from actual history (each turn = 2 entries: user + model)
        $turn_id = intval( count( $conversation_history ) / 2 ) + 1;

        // Build contents array: history turns then current user message
        $contents   = $conversation_history;
        $contents[] = array(
            'role'  => 'user',
            'parts' => array( array( 'text' => (string) $prompt ) ),
        );

        // Prepare request body
        $body = array(
            'contents'         => $contents,
            'generationConfig' => array(
                'maxOutputTokens' => $this->max_tokens,
                'topP'            => $this->top_p,
                'temperature'     => $this->temperature,
            ),
        );

        $args = array(
            'timeout' => max( 1, $this->timeout_ms / 1000 ),
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
        );

        $start_time = microtime( true );

        // Try v1 first, fall back to v1beta on 404
        $api_versions = array( 'v1', 'v1beta' );
        $response     = null;
        $status_code  = 0;
        $endpoint     = '';

        foreach ( $api_versions as $version ) {
            $endpoint = sprintf(
                'https://generativelanguage.googleapis.com/%s/%s:generateContent?key=%s',
                $version,
                $this->model,
                rawurlencode( $this->api_key )
            );

            $request_body = $body;
            if ( $system_instructions !== '' && $version === 'v1beta' ) {
                $request_body['systemInstruction'] = array(
                    'parts' => array( array( 'text' => $system_instructions ) ),
                );
            } elseif ( $system_instructions !== '' && $version === 'v1' ) {
                $last_idx = count( $request_body['contents'] ) - 1;
                if ( $last_idx >= 0 && isset( $request_body['contents'][ $last_idx ]['parts'][0]['text'] ) ) {
                    $current_prompt = $request_body['contents'][ $last_idx ]['parts'][0]['text'];
                    $request_body['contents'][ $last_idx ]['parts'][0]['text'] = $system_instructions . "\n\n" . $current_prompt;
                }
            }

            $version_args         = $args;
            $version_args['body'] = wp_json_encode( $request_body );

            $response = wp_remote_post( $endpoint, $version_args );

            if ( is_wp_error( $response ) ) {
                dairect_debug_log( 'Gemini network error: ' . $response->get_error_message() );
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
                if ( $status_code !== 404 || $version === 'v1beta' ) {
                    break;
                }
                // 404 on v1 — retry with v1beta
                dairect_debug_log( 'v1 returned 404, retrying with v1beta.' );
                continue;
            } else {
                break;
            }
        }

        $raw_body = wp_remote_retrieve_body( $response );

        if ( $status_code < 200 || $status_code >= 300 ) {
            dairect_debug_log( 'Gemini API error ' . $status_code . ' for model ' . $this->model . ': ' . $raw_body );
            
            dairect_tracing_log( array(
                'session_id' => $session_id,
                'turn_id'    => $turn_id,
                'prompt'     => $prompt,
                'error'      => 'HTTP ' . $status_code . ': ' . $raw_body,
                'model_name' => $this->model,
                'is_complete' => 0,
            ) );

            return new WP_Error(
                'dairect_gemini_http_error',
                'Gemini API error',
                array(
                    'status'   => $status_code,
                    'body'     => $raw_body,
                    'endpoint' => $endpoint,
                )
            );
        }

        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) {
            dairect_tracing_log( array(
                'session_id'  => $session_id,
                'turn_id'     => $turn_id,
                'prompt'      => $prompt,
                'error'       => 'Non-JSON response: ' . substr( $raw_body, 0, 200 ),
                'model_name'  => $this->model,
                'is_complete' => 0,
            ) );

            return new WP_Error(
                'dairect_gemini_invalid_json',
                'Gemini API returned non-JSON',
                array(
                    'body' => $raw_body,
                )
            );
        }

        // Extract reply text from response
        $reply_text = '';
        if (
            isset( $decoded['candidates'][0]['content']['parts'][0]['text'] )
            && is_string( $decoded['candidates'][0]['content']['parts'][0]['text'] )
        ) {
            $reply_text = $decoded['candidates'][0]['content']['parts'][0]['text'];
        } elseif ( isset( $decoded['candidates'][0]['output'] ) ) {
            $reply_text = (string) $decoded['candidates'][0]['output'];
        } else {
            $reply_text = '[No text candidate returned by Gemini]';
        }

        // Extract token usage if available
        $token_usage = isset( $decoded['usageMetadata']['totalTokenCount'] )
            ? intval( $decoded['usageMetadata']['totalTokenCount'] )
            : null;

        // Store conversation turn in transient for history
        if ( ! empty( $session_id ) ) {
            $this->store_conversation_turn( $session_id, $prompt, $reply_text );
        }

        $end_time = microtime( true );

        // Write to trace table (best-effort, never blocks chat)
        dairect_tracing_log( array(
            'session_id'  => $session_id,
            'turn_id'     => $turn_id,
            'prompt'      => $prompt,
            'response'    => $reply_text,
            'model_name'  => $this->model,
            'token_usage' => $token_usage,
            'is_complete' => 1,
        ) );

        // Send to Phoenix Cloud (fire-and-forget, never blocks chat)
        if ( function_exists( 'dairect_phoenix_send' ) ) {
            dairect_phoenix_send( array(
                'session_id'  => $session_id,
                'prompt'      => $prompt,
                'response'    => $reply_text,
                'model_name'  => $this->model,
                'token_usage' => $token_usage,
                'start_time'  => $start_time,
                'end_time'    => $end_time,
            ) );
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
    ob_start(); // capture any stray PHP notices / server output that would corrupt JSON

    // Logged-in users: enforce nonce (CSRF protection for admin tester).
    // Logged-out users: skip nonce — the nopriv action is already public, and caching
    // plugins can bake a stale logged-in nonce into the page, breaking guest chat.
    if ( is_user_logged_in() ) {
        check_ajax_referer( 'dairect_chat_nonce', 'nonce' );
    }

    $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '(none)';

    if ( $action === 'dairect_chat_test' ) {
        $prompt     = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
        $session_id = 'admin-test';
        $turn_id    = 1;
    } else {
        $prompt     = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $turn_id    = 0;
    }

    if ( $prompt === '' ) {
        $response = array(
            'success' => false,
            'message' => 'Prompt is required.',
            'data'    => array(),
        );
    } else {
        $service = new Dairect_Gemini_Service();
        $result  = $service->send_prompt( $prompt, $session_id, $turn_id );

        if ( is_wp_error( $result ) ) {
            $response = array(
                'success' => false,
                'message' => $result->get_error_message(),
                'data'    => $result->get_error_data(),
                'code'    => $result->get_error_code(),
            );
        } else {
            $response = array(
                'success' => true,
                'message' => $result['reply'],
                'data'    => array(
                    'reply' => $result['reply'],
                    'raw'   => $result['raw'],
                ),
            );
        }
    }

    ob_end_clean(); // discard captured stray output before sending clean JSON
    wp_send_json( $response );
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
add_action( 'wp_ajax_nopriv_dairect_chat', 'dairect_ajax_bridge_to_rest' );

/**
 * Clear conversation history for a session (transient + fires on "Clear Chat").
 */
function dairect_ajax_clear_chat() {
    global $wpdb;
    ob_start();
    if ( is_user_logged_in() ) {
        check_ajax_referer( 'dairect_chat_nonce', 'nonce' );
    }
    $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
    if ( $session_id !== '' ) {
        delete_transient( 'dairect_chat_history_' . $session_id );
    }
    if ( function_exists( 'dairect_tracing_log' ) ) {
        // Place [SESSION CLEARED] after the last real turn so it sorts as the conversation's final event.
        $next_turn_id = 0;
        if ( $session_id !== '' ) {
            $table = $wpdb->prefix . 'dairect_chat_trace';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $max = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT MAX(CAST(turn_id AS UNSIGNED)) FROM `{$table}` WHERE session_id = %s",
                    $session_id
                )
            );
            if ( $max !== null && is_numeric( $max ) ) {
                $next_turn_id = (int) $max + 1;
            }
        }
        dairect_tracing_log( array(
            'session_id'  => $session_id,
            'turn_id'     => $next_turn_id,
            'prompt'      => '[SESSION CLEARED]',
            'response'    => '',
            'is_complete' => 1,
        ) );
    }
    ob_end_clean();
    wp_send_json( array( 'success' => true ) );
}
add_action( 'wp_ajax_dairect_chat_clear', 'dairect_ajax_clear_chat' );
add_action( 'wp_ajax_nopriv_dairect_chat_clear', 'dairect_ajax_clear_chat' );
