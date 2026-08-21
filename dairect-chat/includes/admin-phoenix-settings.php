<?php
/**
 * dAIrect Chat – Phoenix Cloud settings section.
 * Enable toggle + project name only; API key lives in wp-config.php as
 * define('DAIRECT_PHOENIX_API_KEY', 'your-key');
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_init', function () {
    $page = 'dairect_chat_settings_page';

    add_settings_section(
        'dairect_phoenix_section',
        __( 'Phoenix Observability', 'dairect-chat' ),
        function () {
            $key_set = function_exists( 'dairect_phoenix_get_api_key' ) && dairect_phoenix_get_api_key() !== '';
            echo '<p class="description">' .
                 esc_html__( 'Send LLM traces to Phoenix Cloud for evaluation and observability.', 'dairect-chat' ) .
                 '</p>';
            if ( $key_set ) {
                echo '<p class="description" style="color:#00a32a;">&#10003; ' .
                     esc_html__( 'DAIRECT_PHOENIX_API_KEY is configured.', 'dairect-chat' ) .
                     '</p>';
            } else {
                echo '<p class="description" style="color:#d63638;">&#10007; ' .
                     esc_html__( "API key not found. Add define('DAIRECT_PHOENIX_API_KEY', 'your-key'); to wp-config.php.", 'dairect-chat' ) .
                     '</p>';
            }
        },
        $page
    );

    $get = function ( $key, $default = '' ) {
        $opts = (array) get_option( 'dairect_chat_settings', array() );
        return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
    };

    // Enable checkbox — disabled until key is present
    add_settings_field(
        'phoenix_enabled',
        __( 'Enable Phoenix Tracing', 'dairect-chat' ),
        function () use ( $get ) {
            $enabled = (string) $get( 'phoenix_enabled', '0' );
            $key_set = function_exists( 'dairect_phoenix_get_api_key' ) && dairect_phoenix_get_api_key() !== '';
            ?>
            <label>
                <input type="checkbox"
                       name="dairect_chat_settings[phoenix_enabled]"
                       value="1"
                       <?php checked( $enabled, '1' ); ?>
                       <?php disabled( ! $key_set ); ?> />
                <?php echo esc_html__( 'Send traces to Phoenix Cloud.', 'dairect-chat' ); ?>
            </label>
            <?php if ( ! $key_set ) : ?>
            <p class="description">
                <?php echo esc_html__( 'Configure DAIRECT_PHOENIX_API_KEY in wp-config.php to enable.', 'dairect-chat' ); ?>
            </p>
            <?php endif; ?>
            <?php
        },
        $page,
        'dairect_phoenix_section'
    );

    // Collector endpoint
    add_settings_field(
        'phoenix_collector_endpoint',
        __( 'Collector Endpoint', 'dairect-chat' ),
        function () use ( $get ) {
            $override = defined( 'DAIRECT_PHOENIX_COLLECTOR_ENDPOINT' ) && DAIRECT_PHOENIX_COLLECTOR_ENDPOINT !== '';
            $val      = (string) $get( 'phoenix_collector_endpoint', '' );
            echo '<input type="url" style="width:32rem;" ' .
                 'name="dairect_chat_settings[phoenix_collector_endpoint]" ' .
                 'value="' . esc_attr( $val ) . '" ' .
                 'placeholder="https://app.phoenix.arize.com/s/your-workspace"' .
                 ( $override ? ' disabled' : '' ) . ' />';
            if ( $override ) {
                echo '<p class="description" style="color:#00a32a;">&#10003; ' .
                     esc_html__( 'Overridden by DAIRECT_PHOENIX_COLLECTOR_ENDPOINT in wp-config.php.', 'dairect-chat' ) .
                     '</p>';
            } else {
                echo '<p class="description">' .
                     esc_html__( 'Base URL only — /v1/traces is appended automatically. For stronger security, set DAIRECT_PHOENIX_COLLECTOR_ENDPOINT in wp-config.php instead.', 'dairect-chat' ) .
                     '</p>';
            }
        },
        $page,
        'dairect_phoenix_section'
    );

    // Project name
    add_settings_field(
        'phoenix_project_name',
        __( 'Project Name', 'dairect-chat' ),
        function () use ( $get ) {
            $val = (string) $get( 'phoenix_project_name', 'dairect-chat' );
            echo '<input type="text" style="width:24rem" ' .
                 'name="dairect_chat_settings[phoenix_project_name]" value="' . esc_attr( $val ) . '" ' .
                 'placeholder="dairect-chat" />';
            echo '<p class="description">' .
                 esc_html__( 'Phoenix project name traces will appear under.', 'dairect-chat' ) .
                 '</p>';
        },
        $page,
        'dairect_phoenix_section'
    );

    // Connection test
    add_settings_field(
        'phoenix_test',
        __( 'Test Connection', 'dairect-chat' ),
        function () {
            $endpoint = function_exists( 'dairect_phoenix_get_collector_endpoint' )
                ? dairect_phoenix_get_collector_endpoint() . '/v1/traces'
                : '(endpoint not resolved)';
            ?>
            <button type="button" id="dairect-phoenix-test" class="button button-secondary">
                <?php echo esc_html__( 'Send Test Trace', 'dairect-chat' ); ?>
            </button>
            <span id="dairect-phoenix-test-status" style="margin-left:10px;font-family:monospace;"></span>
            <p class="description">
                <?php echo esc_html__( 'Endpoint: ', 'dairect-chat' ); ?>
                <code><?php echo esc_html( $endpoint ); ?></code>
            </p>
            <?php
        },
        $page,
        'dairect_phoenix_section'
    );
} );

/**
 * AJAX: connectivity diagnostic + test trace to Phoenix.
 */
function dairect_ajax_test_phoenix() {
    ob_start();
    try {

    check_ajax_referer( 'dairect_chat_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        ob_end_clean();
        wp_send_json_error( 'Unauthorized', 403 );
    }

    if ( ! function_exists( 'dairect_phoenix_get_api_key' ) || ! function_exists( 'dairect_phoenix_get_collector_endpoint' ) ) {
        ob_end_clean();
        wp_send_json_error( 'Phoenix functions not loaded.' );
    }

    $api_key        = dairect_phoenix_get_api_key();
    $collector_base = dairect_phoenix_get_collector_endpoint();
    $endpoint       = $collector_base . '/v1/traces';
    $steps          = array(
        'php_version' => PHP_VERSION,
        'curl_version' => function_exists( 'curl_version' ) ? ( curl_version()['version'] . ' SSL:' . curl_version()['ssl_version'] ) : 'n/a',
    );

    // Step 0: server outbound IP
    $ip_resp = wp_remote_get( 'https://api.ipify.org?format=text', array( 'timeout' => 5, 'blocking' => true ) );
    $steps['server_outbound_ip'] = is_wp_error( $ip_resp )
        ? 'FAIL – ' . $ip_resp->get_error_message()
        : trim( wp_remote_retrieve_body( $ip_resp ) );

    // Step 1: outbound HTTPS sanity check
    $ext = wp_remote_get( 'https://httpbin.org/get', array( 'timeout' => 8, 'blocking' => true ) );
    if ( is_wp_error( $ext ) ) {
        $steps['outbound_https'] = 'FAIL – ' . $ext->get_error_message();
    } else {
        $steps['outbound_https'] = 'OK – HTTP ' . wp_remote_retrieve_response_code( $ext );
    }

    // Step 2: can we reach app.phoenix.arize.com at all?
    $host_check = wp_remote_get( 'https://app.phoenix.arize.com/', array( 'timeout' => 8, 'blocking' => true ) );
    if ( is_wp_error( $host_check ) ) {
        $steps['phoenix_host'] = 'FAIL – ' . $host_check->get_error_message();
    } else {
        $steps['phoenix_host'] = 'OK – HTTP ' . wp_remote_retrieve_response_code( $host_check );
    }

    // Step 3: POST OTLP trace (only if key is set) — try configured endpoint AND bare /v1/traces
    $otlp_result     = array( 'skipped' => 'No API key configured.' );
    $otlp_alt_result = null;
    $pb_body         = null;
    if ( $api_key !== '' ) {
        $trace_id_hex = bin2hex( random_bytes( 16 ) );
        $span_id_hex  = bin2hex( random_bytes( 8 ) );
        $now_ns       = (int)( microtime( true ) * 1e9 );

        $kv_attrs = array(
            array( 'key' => 'openinference.span.kind', 'value' => 'LLM' ),
            array( 'key' => 'input.value',             'value' => 'connection test' ),
            array( 'key' => 'output.value',            'value' => 'ok' ),
        );

        $settings = dairect_chat_get_settings();
        $project  = ! empty( $settings['phoenix_project_name'] ) ? $settings['phoenix_project_name'] : 'dairect-chat';

        $pb_body = dairect_otlp_encode(
            $trace_id_hex, $span_id_hex, $now_ns, $now_ns,
            'dairect-connection-test', $kv_attrs, $project
        );
        $do_otlp_post = function ( $url, $http_version = null, $user_agent = null ) use ( $pb_body, $api_key ) {
            if ( ! function_exists( 'curl_init' ) ) {
                return array( 'error' => 'cURL not available on this host.' );
            }
            if ( $http_version === null ) {
                $http_version = CURL_HTTP_VERSION_1_1;
            }
            if ( $user_agent === null ) {
                // Phoenix Cloud GFE routes OTLP requests by UA — only python-requests/* reaches the API handler
                $user_agent = 'python-requests/2.31.0';
            }
            $ch = curl_init( $url );
            curl_setopt_array( $ch, array(
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $pb_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLINFO_HEADER_OUT    => true,
                CURLOPT_HTTP_VERSION   => $http_version,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/x-protobuf',
                    'Authorization: Bearer ' . trim( $api_key ),
                    'Accept-Encoding: gzip, deflate',
                    'Accept: */*',
                    'Connection: keep-alive',
                    'Expect:',
                    'User-Agent: ' . $user_agent,
                ),
            ) );
            $resp_body = curl_exec( $ch );
            $code      = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $sent      = (string) curl_getinfo( $ch, CURLINFO_HEADER_OUT );
            $curl_err  = curl_error( $ch );
            curl_close( $ch );

            if ( $curl_err !== '' ) {
                return array( 'error' => $curl_err );
            }
            $body_display = ( $resp_body !== false && $resp_body !== '' )
                ? substr( $resp_body, 0, 500 )
                : '(empty response body)';
            error_log( 'dAIrect Phoenix OTLP ' . $url . ' → HTTP ' . $code . ': ' . $body_display );
            return array(
                'status'       => $code,
                'body'         => $body_display,
                'success'      => ( $code >= 200 && $code < 300 ),
                'method'       => 'curl_direct',
                'sent_headers' => $sent,
            );
        };

        // Primary: configured endpoint
        $otlp_result = $do_otlp_post( $endpoint );

        // Alt: bare Phoenix OTLP endpoint (no workspace slug)
        $alt_endpoint    = 'https://app.phoenix.arize.com/v1/traces';
        $otlp_alt_result = ( $alt_endpoint !== $endpoint )
            ? $do_otlp_post( $alt_endpoint )
            : array( 'skipped' => 'Same as primary endpoint.' );

        // Alt2: same endpoint, explicit HTTP/1.1 flag
        $otlp_h1_result = $do_otlp_post( $endpoint, CURL_HTTP_VERSION_1_1 );

        // Alt4: Full verbose diagnostic — CUSTOMREQUEST + response headers + cURL trace
        $do_otlp_verbose = function ( $url ) use ( $pb_body, $api_key ) {
            if ( ! function_exists( 'curl_init' ) ) {
                return array( 'error' => 'cURL not available.' );
            }
            // Use tempnam() + fopen() — tmpfile() doesn't work in PHP-FPM context
            $verbose_file = tempnam( sys_get_temp_dir(), 'dairect_v' );
            $vf           = ( $verbose_file !== false ) ? fopen( $verbose_file, 'w+b' ) : false;
            $verbose_out  = '';
            $ch = curl_init( $url );
            $opts = array(
                CURLOPT_CUSTOMREQUEST  => 'POST',
                CURLOPT_POSTFIELDS     => $pb_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => true,   // prepend response headers to body
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLINFO_HEADER_OUT    => true,
                CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/x-protobuf',
                    'Authorization: Bearer ' . trim( $api_key ),
                    'Accept-Encoding: gzip, deflate',
                    'Accept: */*',
                    'Connection: keep-alive',
                    'User-Agent: python-requests/2.31.0',
                ),
            );
            if ( $vf !== false ) {
                $opts[ CURLOPT_VERBOSE ] = true;
                $opts[ CURLOPT_STDERR ]  = $vf;
            }
            curl_setopt_array( $ch, $opts );
            $raw        = curl_exec( $ch );
            $code       = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $sent       = (string) curl_getinfo( $ch, CURLINFO_HEADER_OUT );
            $hdr_size   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
            $primary_ip = (string) curl_getinfo( $ch, CURLINFO_PRIMARY_IP );
            $curl_err   = curl_error( $ch );
            curl_close( $ch );
            if ( $vf !== false ) {
                fseek( $vf, 0 );
                $verbose_out = (string) fread( $vf, 10000 );
                fclose( $vf );
                if ( $verbose_file !== false ) {
                    @unlink( $verbose_file );
                }
            }
            // Split response headers from body
            $resp_hdr_raw  = ( is_string( $raw ) && $hdr_size > 0 ) ? substr( $raw, 0, $hdr_size ) : '';
            $resp_body_raw = ( is_string( $raw ) && $hdr_size > 0 ) ? substr( $raw, $hdr_size ) : '';
            // Log to debug.log so we get it even if JS rendering truncates
            error_log( 'dAIrect Phoenix verbose sent: ' . $sent );
            error_log( 'dAIrect Phoenix verbose resp-hdr: ' . $resp_hdr_raw );
            error_log( 'dAIrect Phoenix verbose body: ' . substr( $resp_body_raw, 0, 500 ) );
            if ( $verbose_out !== '' ) {
                error_log( 'dAIrect Phoenix cURL verbose: ' . substr( $verbose_out, 0, 5000 ) );
            }
            if ( $curl_err !== '' ) {
                return array( 'error' => $curl_err, 'verbose' => $verbose_out );
            }
            $curl_info = function_exists( 'curl_version' ) ? curl_version() : array();
            return array(
                'status'       => $code,
                'body'         => ( $resp_body_raw !== '' ) ? substr( $resp_body_raw, 0, 300 ) : '(empty body)',
                'success'      => ( $code >= 200 && $code < 300 ),
                'method'       => 'curl_verbose',
                'sent_headers' => $sent,
                'resp_headers' => $resp_hdr_raw,
                'primary_ip'   => $primary_ip,
                'verbose'      => $verbose_out,
                'curl_ver'     => ( isset( $curl_info['version'] ) ? $curl_info['version'] : '?' )
                                . ' SSL:' . ( isset( $curl_info['ssl_version'] ) ? $curl_info['ssl_version'] : '?' ),
            );
        };
        $otlp_custom_result = $do_otlp_verbose( $endpoint );

        // Alt5: wp_remote_post — WordPress HTTP API, HTTP/1.1, same as Python requests
        $do_otlp_post_wp = function ( $url ) use ( $pb_body, $api_key ) {
            $response = wp_remote_post(
                $url,
                array(
                    'body'      => $pb_body,
                    'headers'   => array(
                        'Content-Type'  => 'application/x-protobuf',
                        'Authorization' => 'Bearer ' . trim( $api_key ),
                    ),
                    'timeout'   => 10,
                    'sslverify' => true,
                )
            );
            if ( is_wp_error( $response ) ) {
                return array( 'error' => $response->get_error_message() );
            }
            $code         = (int) wp_remote_retrieve_response_code( $response );
            $body         = wp_remote_retrieve_body( $response );
            $body_display = ( $body !== '' ) ? substr( $body, 0, 500 ) : '(empty response body)';
            error_log( 'dAIrect Phoenix OTLP/wp ' . $url . ' → HTTP ' . $code . ': ' . $body_display );
            return array(
                'status'  => $code,
                'body'    => $body_display,
                'success' => ( $code >= 200 && $code < 300 ),
                'method'  => 'wp_remote_post',
            );
        };
        $otlp_wp_result = $do_otlp_post_wp( $endpoint );

        // Alt3: JSON format — bypasses custom protobuf encoder, tests if format is the issue
        $json_attrs = array();
        foreach ( $kv_attrs as $kv ) {
            $json_attrs[] = array( 'key' => $kv['key'], 'value' => array( 'stringValue' => $kv['value'] ) );
        }
        $json_body = (string) wp_json_encode( array(
            'resourceSpans' => array( array(
                'resource' => array(
                    'attributes' => array(
                        array( 'key' => 'service.name',                   'value' => array( 'stringValue' => 'dairect-chat' ) ),
                        array( 'key' => 'openinference.project.name',      'value' => array( 'stringValue' => $project ) ),
                    ),
                ),
                'scopeSpans' => array( array(
                    'scope' => array(
                        'name'    => 'dairect-chat',
                        'version' => defined( 'DAIRECT_CHAT_VERSION' ) ? DAIRECT_CHAT_VERSION : '',
                    ),
                    'spans' => array( array(
                        'traceId'             => $trace_id_hex,
                        'spanId'              => $span_id_hex,
                        'name'                => 'dairect-connection-test',
                        'kind'                => 1,
                        'startTimeUnixNano'   => (string) $now_ns,
                        'endTimeUnixNano'     => (string) $now_ns,
                        'attributes'          => $json_attrs,
                        'status'              => array( 'code' => 1 ),
                    ) ),
                ) ),
            ) ),
        ) );
        $otlp_json_result = ( function () use ( $json_body, $api_key, $endpoint ) {
            if ( ! function_exists( 'curl_init' ) ) {
                return array( 'error' => 'cURL not available.' );
            }
            $http_ver = defined( 'CURL_HTTP_VERSION_2TLS' ) ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_2_0;
            $ch = curl_init( $endpoint );
            curl_setopt_array( $ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $json_body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLINFO_HEADER_OUT    => true,
                CURLOPT_HTTP_VERSION   => $http_ver,
                CURLOPT_HTTPHEADER     => array(
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . trim( $api_key ),
                    'Expect:',
                ),
            ) );
            $resp_body = curl_exec( $ch );
            $code      = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            $sent      = (string) curl_getinfo( $ch, CURLINFO_HEADER_OUT );
            $curl_err  = curl_error( $ch );
            curl_close( $ch );
            if ( $curl_err !== '' ) {
                return array( 'error' => $curl_err );
            }
            $body_display = ( $resp_body !== false && $resp_body !== '' )
                ? substr( $resp_body, 0, 500 )
                : '(empty response body)';
            error_log( 'dAIrect Phoenix OTLP/JSON ' . $endpoint . ' → HTTP ' . $code . ': ' . $body_display );
            return array(
                'status'       => $code,
                'body'         => $body_display,
                'success'      => ( $code >= 200 && $code < 300 ),
                'method'       => 'curl_json',
                'sent_headers' => $sent,
            );
        } )();
    }

    ob_end_clean();
    $any_success = ( isset( $otlp_result['success'] )        && $otlp_result['success'] )
                || ( isset( $otlp_custom_result['success'] ) && $otlp_custom_result['success'] )
                || ( isset( $otlp_wp_result['success'] )     && $otlp_wp_result['success'] );
    wp_send_json( array(
        'success'         => $any_success,
        'endpoint'        => $endpoint,
        'steps'           => $steps,
        'otlp'            => $otlp_result,
        'otlp_alt'        => $otlp_alt_result,
        'otlp_h1'         => isset( $otlp_h1_result )        ? $otlp_h1_result        : null,
        'otlp_custom'     => isset( $otlp_custom_result )    ? $otlp_custom_result    : null,
        'otlp_wp'         => isset( $otlp_wp_result )        ? $otlp_wp_result        : null,
        'otlp_json'       => isset( $otlp_json_result )      ? $otlp_json_result      : null,
        'alt_endpoint'    => 'https://app.phoenix.arize.com/v1/traces',
        'payload_b64'     => isset( $pb_body ) ? base64_encode( $pb_body ) : null,
        'payload_hex'     => isset( $pb_body ) ? bin2hex( substr( $pb_body, 0, 32 ) ) : null,
    ) );

    } catch ( \Throwable $e ) {
        ob_end_clean();
        wp_send_json_error( array(
            'php_fatal'  => get_class( $e ) . ': ' . $e->getMessage(),
            'file'       => basename( $e->getFile() ),
            'line'       => $e->getLine(),
            'php_ver'    => PHP_VERSION,
        ) );
    }
}
add_action( 'wp_ajax_dairect_test_phoenix', 'dairect_ajax_test_phoenix' );

add_action( 'admin_enqueue_scripts', function ( $hook_suffix ) {
    if ( 'tools_page_dairect-chat' !== $hook_suffix ) {
        return;
    }
    wp_localize_script(
        'jquery',
        'DairectPhoenixTest',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'dairect_chat_nonce' ),
        )
    );
    $js = <<<'ENDJS'
document.addEventListener('DOMContentLoaded', function() {
    var testBtn    = document.getElementById('dairect-phoenix-test');
    var statusSpan = document.getElementById('dairect-phoenix-test-status');
    if (!testBtn || !statusSpan) return;
    testBtn.addEventListener('click', function() {
        testBtn.disabled = true;
        statusSpan.style.color = '#2271b1';
        statusSpan.textContent = 'Sending test trace…';
        var params = new URLSearchParams();
        params.set('action', 'dairect_test_phoenix');
        params.set('nonce', DairectPhoenixTest.nonce);
        fetch(DairectPhoenixTest.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            var lines = [];
            // PHP fatal caught by try/catch — show immediately
            if (!data.success && data.data && data.data.php_fatal) {
                lines.push('PHP fatal: ' + data.data.php_fatal);
                lines.push('File: ' + data.data.file + ' line ' + data.data.line);
                lines.push('PHP ver: ' + data.data.php_ver);
                statusSpan.style.color = '#d63638';
                statusSpan.innerHTML = lines.join('<br>');
                return;
            }
            if (data.endpoint) lines.push('Endpoint: ' + data.endpoint);
            if (data.steps) {
                Object.keys(data.steps).forEach(function(k) {
                    lines.push(k + ': ' + data.steps[k]);
                });
            }
            var esc = function(s) {
                return s ? String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '';
            };
            var fmtOtlp = function(label, url, res) {
                if (!res) return;
                if (res.skipped) { lines.push(esc(label) + ': skipped – ' + esc(res.skipped)); return; }
                if (res.error)   { lines.push(esc(label) + ' error: ' + esc(res.error)); if (res.verbose) lines.push('&nbsp;&nbsp;cURL verbose:<br><pre>' + esc(res.verbose) + '</pre>'); return; }
                lines.push(esc(label) + ' [' + esc(url) + '] HTTP ' + res.status + (res.success ? ' ✓' : ' ✗'));
                if (res.curl_ver)     lines.push('&nbsp;&nbsp;cURL: ' + esc(res.curl_ver));
                if (res.primary_ip)   lines.push('&nbsp;&nbsp;Connected IP: ' + esc(res.primary_ip));
                if (res.body)         lines.push('&nbsp;&nbsp;Body: ' + esc(res.body));
                if (res.resp_headers) lines.push('&nbsp;&nbsp;Response headers:<br><pre>' + esc(res.resp_headers) + '</pre>');
                if (res.sent_headers) lines.push('&nbsp;&nbsp;Sent: ' + esc(res.sent_headers.replace(/\r\n/g, ' | ').trim()));
                if (res.verbose)      lines.push('&nbsp;&nbsp;cURL verbose:<br><pre>' + esc(res.verbose) + '</pre>');
            };
            fmtOtlp('OTLP (configured endpoint)', data.endpoint, data.otlp);
            fmtOtlp('OTLP (alt /v1/traces)', data.alt_endpoint, data.otlp_alt);
            fmtOtlp('OTLP (configured, HTTP/1.1 explicit)', data.endpoint, data.otlp_h1);
            fmtOtlp('OTLP (verbose: CUSTOMREQUEST+resp-headers)', data.endpoint, data.otlp_custom);
            fmtOtlp('OTLP (wp_remote_post)', data.endpoint, data.otlp_wp);
            fmtOtlp('OTLP/JSON (application/json)', data.endpoint, data.otlp_json);
            if (data.payload_hex) lines.push('Payload hex (first 32B): ' + data.payload_hex);
            if (data.payload_b64) lines.push('Payload b64: ' + data.payload_b64);
            var anySuccess = data.success
                || (data.otlp_alt    && data.otlp_alt.success)
                || (data.otlp_h1     && data.otlp_h1.success)
                || (data.otlp_custom && data.otlp_custom.success)
                || (data.otlp_wp     && data.otlp_wp.success)
                || (data.otlp_json   && data.otlp_json.success);
            statusSpan.style.color = anySuccess ? '#00a32a' : '#d63638';
            statusSpan.innerHTML = lines.join('<br>');
        })
        .catch(function(err) {
            statusSpan.style.color = '#d63638';
            statusSpan.textContent = 'Network error: ' + err.message;
        })
        .finally(function() {
            testBtn.disabled = false;
        });
    });
});
ENDJS;
    wp_add_inline_script( 'jquery', $js );
} );
