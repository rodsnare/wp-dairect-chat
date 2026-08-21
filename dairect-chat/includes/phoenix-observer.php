<?php
/**
 * dAIrect Chat – Phoenix Cloud observability (optional, best-effort).
 * Sends OTLP/HTTP protobuf traces to Phoenix Cloud when enabled.
 * API key is read from the DAIRECT_PHOENIX_API_KEY constant (set in wp-config.php).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function dairect_phoenix_get_api_key(): string {
    if ( defined( 'DAIRECT_PHOENIX_API_KEY' ) && DAIRECT_PHOENIX_API_KEY !== '' ) {
        $key = DAIRECT_PHOENIX_API_KEY;
    } else {
        $env = getenv( 'DAIRECT_PHOENIX_API_KEY' );
        $key = is_string( $env ) ? $env : '';
    }
    // Strip any whitespace or line endings that could corrupt the Authorization header.
    return preg_replace( '/[\r\n\s]+/', '', $key );
}

function dairect_phoenix_get_collector_endpoint(): string {
    if ( defined( 'DAIRECT_PHOENIX_COLLECTOR_ENDPOINT' ) && DAIRECT_PHOENIX_COLLECTOR_ENDPOINT !== '' ) {
        return rtrim( DAIRECT_PHOENIX_COLLECTOR_ENDPOINT, '/' );
    }
    $env = getenv( 'DAIRECT_PHOENIX_COLLECTOR_ENDPOINT' );
    if ( is_string( $env ) && $env !== '' ) {
        return rtrim( $env, '/' );
    }
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

// ── Minimal OTLP/Protobuf encoder ──────────────────────────────────────────
// Implements just enough of the protobuf wire format to encode
// an ExportTraceServiceRequest with a single LLM span.

function dairect_pb_varint( int $n ): string {
    if ( $n === 0 ) return "\x00";
    $out = '';
    while ( $n > 0 ) {
        $b   = $n & 0x7F;
        $n >>= 7;
        $out .= chr( $n > 0 ? ( $b | 0x80 ) : $b );
    }
    return $out;
}

// Wire type 2: length-delimited (strings, bytes, embedded messages)
function dairect_pb_ld( int $field, string $data ): string {
    return dairect_pb_varint( ( $field << 3 ) | 2 )
         . dairect_pb_varint( strlen( $data ) )
         . $data;
}

// String field — omit if empty
function dairect_pb_str( int $field, string $s ): string {
    return $s !== '' ? dairect_pb_ld( $field, $s ) : '';
}

// Varint field (wire type 0) — omit proto3 default (0)
function dairect_pb_vf( int $field, int $val ): string {
    return $val !== 0
        ? dairect_pb_varint( ( $field << 3 ) | 0 ) . dairect_pb_varint( $val )
        : '';
}

// Fixed 64-bit field (wire type 1, little-endian) — nanosecond timestamps
function dairect_pb_f64( int $field, int $val ): string {
    $lo = $val & 0xFFFFFFFF;
    $hi = ( $val >> 32 ) & 0xFFFFFFFF;
    return dairect_pb_varint( ( $field << 3 ) | 1 ) . pack( 'VV', $lo, $hi );
}

// KeyValue with a string AnyValue
function dairect_pb_kv_str( string $key, string $str_val ): string {
    $any_val = dairect_pb_ld( 1, $str_val ); // AnyValue.string_value = field 1
    return dairect_pb_ld( 1, $key )          // KeyValue.key = field 1
         . dairect_pb_ld( 2, $any_val );     // KeyValue.value = field 2
}

/**
 * Encode an OTLP ExportTraceServiceRequest as binary protobuf.
 *
 * @param string   $trace_id_hex  32-char lowercase hex (16 raw bytes)
 * @param string   $span_id_hex   16-char lowercase hex (8 raw bytes)
 * @param int      $start_ns      Start time, nanoseconds since epoch
 * @param int      $end_ns        End time, nanoseconds since epoch
 * @param string   $span_name     Span name (e.g. 'chat')
 * @param array    $kv_attrs      [['key'=>string,'value'=>string], ...]
 * @param string   $project       Phoenix project name
 * @return string                 Binary protobuf payload
 */
function dairect_otlp_encode(
    string $trace_id_hex,
    string $span_id_hex,
    int    $start_ns,
    int    $end_ns,
    string $span_name,
    array  $kv_attrs,
    string $project
): string {
    // Span attributes (field 9, repeated)
    $span_attrs = '';
    foreach ( $kv_attrs as $kv ) {
        $span_attrs .= dairect_pb_ld( 9, dairect_pb_kv_str( $kv['key'], $kv['value'] ) );
    }

    // Status { code = STATUS_CODE_OK (1), field 3 }
    $status = dairect_pb_vf( 3, 1 );

    // Span
    $span = dairect_pb_ld( 1, hex2bin( $trace_id_hex ) )  // trace_id (bytes, field 1)
          . dairect_pb_ld( 2, hex2bin( $span_id_hex ) )   // span_id  (bytes, field 2)
          . dairect_pb_str( 5, $span_name )                // name     (field 5)
          . dairect_pb_vf( 6, 1 )                          // kind = SPAN_KIND_INTERNAL (field 6)
          . dairect_pb_f64( 7, $start_ns )                 // start_time_unix_nano (field 7)
          . dairect_pb_f64( 8, $end_ns )                   // end_time_unix_nano   (field 8)
          . $span_attrs
          . dairect_pb_ld( 15, $status );                  // status (field 15)

    // InstrumentationScope { name=1, version=2 }
    $scope = dairect_pb_str( 1, 'dairect-chat' )
           . dairect_pb_str( 2, defined( 'DAIRECT_CHAT_VERSION' ) ? DAIRECT_CHAT_VERSION : '' );

    // ScopeSpans: field 1 = InstrumentationScope, field 2 = spans (repeated).
    // In both old and new OTLP protos, ResourceSpans.scope_spans is field 2.
    // Field 3 of ResourceSpans is schema_url (a string) in both versions — never a spans block.
    $scope_spans = dairect_pb_ld( 1, $scope ) . dairect_pb_ld( 2, $span );

    // Resource { attributes=1 (repeated) }
    $resource = dairect_pb_ld( 1, dairect_pb_kv_str( 'service.name', 'dairect-chat' ) )
              . dairect_pb_ld( 1, dairect_pb_kv_str( 'openinference.project.name', $project !== '' ? $project : 'dairect-chat' ) );

    // ResourceSpans { resource=1, scope_spans=2 }
    $resource_spans = dairect_pb_ld( 1, $resource )
                    . dairect_pb_ld( 2, $scope_spans );

    // ExportTraceServiceRequest { resource_spans=1 }
    return dairect_pb_ld( 1, $resource_spans );
}

// ── Send a trace to Phoenix ─────────────────────────────────────────────────

function dairect_phoenix_send( array $data ): void {
    if ( ! dairect_phoenix_is_enabled() ) return;
    if ( ! function_exists( 'curl_init' ) ) return;

    $api_key  = dairect_phoenix_get_api_key();
    $settings = dairect_chat_get_settings();
    $project  = sanitize_text_field( $settings['phoenix_project_name'] ?? 'dairect-chat' );
    if ( $project === '' ) $project = 'dairect-chat';

    try {
        $trace_id_hex = bin2hex( random_bytes( 16 ) );
        $span_id_hex  = bin2hex( random_bytes( 8 ) );
    } catch ( \Exception $e ) {
        dairect_debug_log( 'Phoenix: failed to generate IDs: ' . $e->getMessage() );
        return;
    }

    $start_ns = isset( $data['start_time'] ) ? (int)( $data['start_time'] * 1e9 ) : (int)( microtime( true ) * 1e9 );
    $end_ns   = isset( $data['end_time'] )   ? (int)( $data['end_time']   * 1e9 ) : $start_ns;

    $kv_attrs = array(
        array( 'key' => 'openinference.span.kind', 'value' => 'LLM' ),
        array( 'key' => 'input.value',             'value' => (string)( $data['prompt']     ?? '' ) ),
        array( 'key' => 'output.value',            'value' => (string)( $data['response']   ?? '' ) ),
        array( 'key' => 'llm.model_name',          'value' => (string)( $data['model_name'] ?? '' ) ),
    );
    if ( ! empty( $data['session_id'] ) ) {
        $kv_attrs[] = array( 'key' => 'session.id', 'value' => (string)$data['session_id'] );
    }

    $pb_body  = dairect_otlp_encode( $trace_id_hex, $span_id_hex, $start_ns, $end_ns, 'chat', $kv_attrs, $project );
    $endpoint = dairect_phoenix_get_collector_endpoint() . '/v1/traces';

    dairect_debug_log( 'Phoenix: queuing live send → ' . $endpoint );

    // Defer to PHP shutdown so the AJAX response reaches the browser first.
    // Direct cURL with HTTP/2 — WordPress HTTP API forces HTTP/1.1 which GFE rejects.
    // Use file_put_contents in the shutdown callback — dairect_debug_log() can't write
    // to debug.log after WordPress's error handler is cleaned up at shutdown.
    $debug    = defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
    $log_path = $debug && defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : '';
    register_shutdown_function( static function () use ( $pb_body, $endpoint, $api_key, $log_path ) {
        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, array(
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $pb_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/x-protobuf',
                'Authorization: Bearer ' . trim( $api_key ),
                'Accept-Encoding: gzip, deflate',
                'Accept: */*',
                'Connection: keep-alive',
                'Expect:',
                // Phoenix Cloud's GFE routes OTLP requests by User-Agent — only python-requests/* reaches the API handler
                'User-Agent: python-requests/2.31.0',
            ),
        ) );
        $body = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );
        if ( $log_path !== '' ) {
            $body_str = ( $body !== false && $body !== '' ) ? ' body: ' . substr( $body, 0, 200 ) : ' (empty body)';
            $msg = $err !== '' ? 'Phoenix live send cURL error: ' . $err : 'Phoenix live send → HTTP ' . $code . $body_str;
            @file_put_contents( $log_path, '[' . gmdate( 'd-M-Y H:i:s' ) . ' UTC] [dAIrect Chat] ' . $msg . "\n", FILE_APPEND | LOCK_EX );
        }
    } );
}
