<?php
/**
 * Uninstall WP Auto Tagger.
 *
 * Removes the plugin's options. Tags assigned to posts are kept — they are
 * normal post tags and stay useful after the plugin is gone.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'wat_rules' );
delete_option( 'wat_whole_word' );
