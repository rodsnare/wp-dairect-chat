<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared settings getter — returns the dairect_chat_settings option array.
 * Loaded first in dairect-chat.php so every include can call this safely.
 */
if ( ! function_exists( 'dairect_chat_get_settings' ) ) {
    function dairect_chat_get_settings() {
        $opts = get_option( 'dairect_chat_settings', array() );
        return is_array( $opts ) ? $opts : array();
    }
}
