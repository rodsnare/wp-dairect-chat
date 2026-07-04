<?php
/**
 * dAIrect Chat – Phoenix Cloud observability (optional, best-effort).
 * Sends OTLP/HTTP JSON traces to Phoenix Cloud when enabled.
 * API key is read from the DAIRECT_PHOENIX_API_KEY constant (set in wp-config.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function dairect_phoenix_get_api_key(): string {
    if ( defined( 'DAIRECT_PHOENIX_API_KEY' ) && DAIRECT_PHOENIX_API_KEY !== '' ) {
        return DAIRECT_PHOENIX_API_KEY;
    }
    $env = getenv( 'DAIRECT_PHOENIX_API_KEY' );
    return is_string( $env ) ? $env : '';
}

function dairect_phoenix_get_collector_endpoint(): string {
    // 1. wp-config.php constant (highest priority)
    if ( defined( 'DAIRECT_PHOENIX_COLLECTOR_ENDPOINT' ) && DAIRECT_PHOENIX_COLLECTOR_ENDPOINT !== '' ) {
        return rtrim( DAIRECT_PHOENIX_COLLECTOR_ENDPOINT, '/' );
    }
    // 2. Environment variable
    $env = getenv( 'DAIRECT_PHOENIX_COLLECTOR_ENDPOINT' );
    if ( is_string( $env ) && $env !== '' ) {
        return rtrim( $env, '/' );
    }
    // 3. Settings page value
    $settings = dairect_chat_get_settings();
    if ( ! empty( $settings['phoenix_collector_endpoint'] ) ) {
        return rtrim( $settings['phoenix_collector_endpoint'], '/' );
    }
    return '';
}

function dairect_phoenix_is_enabled(): bool {
    $settings = dairect_chat_get_settings();
    return ( isset( $settings['phoenix_enabled'] ) ? $settings['phoenix_enabled'] : '0' ) === '1'
        && dairect_phoenix_get_api_key() !== '';
}

function dairect_phoenix_send( array $data ): void {
    if ( ! dairect_phoenix_is_enabled() ) return;

    $api_key  = dairect_phoenix_get_api_key();
    $settings = dairect_chat_get_settings();
    $project  = sanitize_text_field( isset( $settings['phoenix_project_name'] ) ? $settings['phoenix_project_name'] : 'dairect-chat' );
    if ( $project === '' ) {
        $project = 'dairect-chat';
    }

    try {
        $trace_id = bin2hex( random_bytes( 16 ) );
        $span_id  = bin2hex( random_bytes( 8 ) );
    } catch ( \Exception $e ) {
        dairect_debug_log( 'Phoenix: failed to generate IDs: ' . $e->getMessage() );
        return;
    }

    // Nanosecond timestamps as strings (avoids float precision issues in JSON)
    $start_ns = isset( $data['start_time'] ) ? number_format( $data['start_time'] * 1e9, 0, '.', '' ) : '0';
    $end_ns   = isset( $data['end_time'] )   ? number_format( $data['end_time']   * 1e9, 0, '.', '' ) : $start_ns;

    $attributes = array(
        array( 'key' => 'openinference.span.kind', 'value' => array( 'stringValue' => 'LLM' ) ),
        array( 'key' => 'input.value',             'value' => array( 'stringValue' => (string) ( isset( $data['prompt'] )    ? $data['prompt']    : '' ) ) ),
        array( 'key' => 'output.value',            'value' => array( 'stringValue' => (string) ( isset( $data['response'] )  ? $data['response']  : '' ) ) ),
        array( 'key' => 'llm.model_name',          'value' => array( 'stringValue' => (string) ( isset( $data['model_name'] ) ? $data['model_name'] : '' ) ) ),
    );

    if ( ! empty( $data['session_id'] ) ) {
        $attributes[] = array( 'key' => 'session.id', 'value' => array( 'stringValue' => (string) $data['session_id'] ) );
    }
    if ( ! empty( $data['token_usage'] ) ) {
        $attributes[] = array( 'key' => 'llm.token_count.total', 'value' => array( 'intValue' => intval( $data['token_usage'] ) ) );
    }

    $payload = array(
        'resourceSpans' => array(
            array(
                'resource' => array(
                    'attributes' => array(
                        array( 'key' => 'service.name',               'value' => array( 'stringValue' => 'dairect-chat' ) ),
                        array( 'key' => 'openinference.project.name', 'value' => array( 'stringValue' => $project ) ),
                    ),
                ),
                'scopeSpans' => array(
                    array(
                        'scope' => array(
                            'name'    => 'dairect-chat',
                            'version' => defined( 'DAIRECT_CHAT_VERSION' ) ? DAIRECT_CHAT_VERSION : '',
                        ),
                        'spans' => array(
                            array(
                                'traceId'           => $trace_id,
                                'spanId'            => $span_id,
                                'name'              => 'chat',
                                'kind'              => 3,
                                'startTimeUnixNano' => $start_ns,
                                'endTimeUnixNano'   => $end_ns,
                                'attributes'        => $attributes,
                                'status'            => array( 'code' => 1 ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    );

    $endpoint = dairect_phoenix_get_collector_endpoint() . '/v1/traces';

    wp_remote_post(
        $endpoint,
        array(
            'blocking' => false,
            'timeout'  => 5,
            'headers'  => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ),
            'body' => wp_json_encode( $payload ),
        )
    );
}
