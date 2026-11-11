<?php
/**
 * Plugin Name: WP Auto Tagger
 * Plugin URI: https://github.com/Azanumer/wp-auto-tagger
 * Description: Automatically assigns tags to posts when their title or content matches your keyword rules — plus a bulk retro-tagger for older posts.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-auto-tagger
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAT_VERSION', '1.0.0' );
define( 'WAT_OPTION_RULES', 'wat_rules' );
define( 'WAT_OPTION_WHOLE_WORD', 'wat_whole_word' );

/**
 * Get the keyword => tag rules.
 *
 * Rules are stored as a list of [ 'keyword' => '...', 'tag' => '...' ] rows.
 * Filterable via 'wat_rules'.
 *
 * @return array
 */
function wat_get_rules() {
    $rules = get_option( WAT_OPTION_RULES, array() );
    if ( ! is_array( $rules ) ) {
        $rules = array();
    }
    $rules = array_values( array_filter( $rules, function ( $rule ) {
        return is_array( $rule )
            && isset( $rule['keyword'], $rule['tag'] )
            && '' !== trim( (string) $rule['keyword'] )
            && '' !== trim( (string) $rule['tag'] );
    } ) );
    return apply_filters( 'wat_rules', $rules );
}

/**
 * Whether keyword matching requires whole words (default true).
 *
 * @return bool
 */
function wat_match_whole_word() {
    return (bool) apply_filters( 'wat_match_whole_word', (bool) get_option( WAT_OPTION_WHOLE_WORD, true ) );
}

/**
 * Find which tags match a block of text.
 *
 * @param string $text  Title + content to scan.
 * @param array  $rules Rules from wat_get_rules().
 * @return string[] Unique tag names that matched.
 */
function wat_find_matching_tags( $text, $rules ) {
    $tags       = array();
    $whole_word = wat_match_whole_word();

    foreach ( $rules as $rule ) {
        $keyword = trim( (string) $rule['keyword'] );
        if ( '' === $keyword ) {
            continue;
        }

        if ( $whole_word ) {
            // Unicode-aware word boundaries so "cat" does not match "catalog".
            $pattern = '/(?<!\p{L})' . preg_quote( $keyword, '/' ) . '(?!\p{L})/iu';
            $matched = preg_match( $pattern, $text );
        } else {
            $matched = false !== stripos( $text, $keyword );
        }

        if ( $matched ) {
            $tags[] = trim( (string) $rule['tag'] );
        }
    }

    return array_values( array_unique( $tags ) );
}

/**
 * Tag one post according to the rules. Returns the number of tags assigned.
 *
 * @param int $post_id Post ID.
 * @return int
 */
function wat_tag_post( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return 0;
    }

    $rules = wat_get_rules();
    if ( empty( $rules ) ) {
        return 0;
    }

    $tags = wat_find_matching_tags( $post->post_title . "\n" . $post->post_content, $rules );
    if ( empty( $tags ) ) {
        return 0;
    }

    // Tag names are created on the fly when they do not exist yet.
    $result = wp_set_post_terms( $post_id, $tags, 'post_tag', true );
    if ( is_wp_error( $result ) ) {
        return 0;
    }

    return count( $tags );
}

/**
 * Auto-tag on save. Skips autosaves, revisions and trashed/auto-draft posts.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 */
function wat_auto_tag_on_save( $post_id, $post ) {
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
        return;
    }

    $post_types = apply_filters( 'wat_post_types', array( 'post' ) );
    if ( ! in_array( $post->post_type, (array) $post_types, true ) ) {
        return;
    }

    wat_tag_post( $post_id );
}
add_action( 'save_post', 'wat_auto_tag_on_save', 10, 2 );

/**
 * Meta box on the post editor: shows which rules matched the saved content.
 *
 * @param WP_Post $post Post object.
 */
function wat_meta_box_callback( $post ) {
    $rules = wat_get_rules();
    if ( empty( $rules ) ) {
        echo '<p>' . esc_html__( 'No keyword rules yet. Add some under Settings → Auto Tagger.', 'wp-auto-tagger' ) . '</p>';
        return;
    }

    $tags     = wat_find_matching_tags( $post->post_title . "\n" . $post->post_content, $rules );
    $assigned = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );

    if ( empty( $tags ) ) {
        echo '<p>' . esc_html__( 'No rules match this post yet.', 'wp-auto-tagger' ) . '</p>';
    } else {
        echo '<p>' . esc_html__( 'Matching tags:', 'wp-auto-tagger' ) . ' <strong>' . esc_html( implode( ', ', $tags ) ) . '</strong></p>';
        if ( ! empty( $assigned ) ) {
            echo '<p>' . esc_html__( 'Already assigned:', 'wp-auto-tagger' ) . ' ' . esc_html( implode( ', ', $assigned ) ) . '</p>';
        }
    }

    $url = wp_nonce_url(
        admin_url( 'admin-post.php?action=wat_retag_post&post_id=' . $post->ID ),
        'wat_retag_' . $post->ID
    );
    echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Re-run auto-tagging', 'wp-auto-tagger' ) . '</a></p>';
}

/**
 * Register the meta box on supported post types.
 */
function wat_add_meta_box() {
    $post_types = apply_filters( 'wat_post_types', array( 'post' ) );
    foreach ( (array) $post_types as $post_type ) {
        add_meta_box(
            'wat-meta-box',
            __( 'Auto Tagger', 'wp-auto-tagger' ),
            'wat_meta_box_callback',
            $post_type,
            'side',
            'default'
        );
    }
}
add_action( 'add_meta_boxes', 'wat_add_meta_box' );

/**
 * Handle the "Re-run auto-tagging" button from the meta box.
 */
function wat_handle_retag_post() {
    $post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_die( esc_html__( 'You do not have permission to do that.', 'wp-auto-tagger' ) );
    }
    check_admin_referer( 'wat_retag_' . $post_id );

    $count = wat_tag_post( $post_id );

    wp_safe_redirect( add_query_arg(
        array( 'wat_tagged' => $count ),
        get_edit_post_link( $post_id, 'raw' )
    ) );
    exit;
}
add_action( 'admin_post_wat_retag_post', 'wat_handle_retag_post' );

/**
 * Show a one-line notice after re-tagging from the meta box.
 */
function wat_retag_admin_notice() {
    if ( ! isset( $_GET['wat_tagged'] ) ) {
        return;
    }
    $count = absint( $_GET['wat_tagged'] );
    printf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html( sprintf(
            /* translators: %d: number of tags assigned. */
            _n( 'Auto Tagger assigned %d tag.', 'Auto Tagger assigned %d tags.', $count, 'wp-auto-tagger' ),
            $count
        ) )
    );
}
add_action( 'admin_notices', 'wat_retag_admin_notice' );

/**
 * Parse the rules textarea (one rule per line: "keyword => tag").
 *
 * @param string $raw Raw textarea content.
 * @return array
 */
function wat_parse_rules_text( $raw ) {
    $rules = array();
    foreach ( preg_split( "/\r\n|\r|\n/", (string) $raw ) as $line ) {
        $line = trim( $line );
        if ( '' === $line || false === strpos( $line, '=>' ) ) {
            continue;
        }
        list( $keyword, $tag ) = array_map( 'trim', explode( '=>', $line, 2 ) );
        if ( '' === $keyword || '' === $tag ) {
            continue;
        }
        $rules[] = array(
            'keyword' => $keyword,
            'tag'     => $tag,
        );
    }
    return $rules;
}

/**
 * Render the rules textarea value from the stored rules.
 *
 * @return string
 */
function wat_rules_to_text() {
    $lines = array();
    foreach ( wat_get_rules() as $rule ) {
        $lines[] = $rule['keyword'] . ' => ' . $rule['tag'];
    }
    return implode( "\n", $lines );
}

/**
 * Register settings + the Settings page.
 */
function wat_admin_init() {
    register_setting( 'wat_settings', WAT_OPTION_RULES, array(
        'type'              => 'array',
        'sanitize_callback' => 'wat_parse_rules_text',
        'default'           => array(),
    ) );
    register_setting( 'wat_settings', WAT_OPTION_WHOLE_WORD, array(
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 1,
    ) );

    add_settings_section( 'wat_main', __( 'Keyword rules', 'wp-auto-tagger' ), '__return_false', 'wat-settings' );
    add_settings_field(
        'wat_rules_field',
        __( 'Rules', 'wp-auto-tagger' ),
        'wat_rules_field_callback',
        'wat-settings',
        'wat_main'
    );
    add_settings_field(
        'wat_whole_word_field',
        __( 'Matching', 'wp-auto-tagger' ),
        'wat_whole_word_field_callback',
        'wat-settings',
        'wat_main'
    );
}
add_action( 'admin_init', 'wat_admin_init' );

function wat_rules_field_callback() {
    echo '<textarea name="' . esc_attr( WAT_OPTION_RULES ) . '" rows="10" cols="60" class="large-text code">' . esc_textarea( wat_rules_to_text() ) . '</textarea>';
    echo '<p class="description">' . esc_html__( 'One rule per line: keyword => tag. Example: "wordpress => WordPress". Tags are created automatically when they do not exist.', 'wp-auto-tagger' ) . '</p>';
}

function wat_whole_word_field_callback() {
    $checked = get_option( WAT_OPTION_WHOLE_WORD, true ) ? 'checked' : '';
    echo '<label><input type="checkbox" name="' . esc_attr( WAT_OPTION_WHOLE_WORD ) . '" value="1" ' . $checked . '> ' . esc_html__( 'Match whole words only (so "cat" does not match "catalog")', 'wp-auto-tagger' ) . '</label>';
}

function wat_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Auto Tagger', 'wp-auto-tagger' ); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'wat_settings' );
            do_settings_sections( 'wat-settings' );
            submit_button();
            ?>
        </form>
        <h2><?php esc_html_e( 'Test your rules', 'wp-auto-tagger' ); ?></h2>
        <form method="post">
            <?php wp_nonce_field( 'wat_test_rules' ); ?>
            <textarea name="wat_test_text" rows="5" cols="60" class="large-text" placeholder="<?php esc_attr_e( 'Paste a post title or paragraph here…', 'wp-auto-tagger' ); ?>"><?php echo isset( $_POST['wat_test_text'] ) ? esc_textarea( wp_unslash( $_POST['wat_test_text'] ) ) : ''; ?></textarea>
            <?php submit_button( __( 'Test rules', 'wp-auto-tagger' ), 'secondary', 'wat_test_submit' ); ?>
        </form>
        <?php
        if ( isset( $_POST['wat_test_submit'] ) && check_admin_referer( 'wat_test_rules' ) ) {
            $text = isset( $_POST['wat_test_text'] ) ? wp_unslash( $_POST['wat_test_text'] ) : '';
            $tags = wat_find_matching_tags( $text, wat_get_rules() );
            if ( empty( $tags ) ) {
                echo '<p>' . esc_html__( 'No rules matched that text.', 'wp-auto-tagger' ) . '</p>';
            } else {
                echo '<p>' . esc_html__( 'Would assign:', 'wp-auto-tagger' ) . ' <strong>' . esc_html( implode( ', ', $tags ) ) . '</strong></p>';
            }
        }
        ?>
    </div>
    <?php
}

function wat_add_settings_page() {
    add_options_page(
        __( 'Auto Tagger', 'wp-auto-tagger' ),
        __( 'Auto Tagger', 'wp-auto-tagger' ),
        'manage_options',
        'wat-settings',
        'wat_settings_page'
    );
}
add_action( 'admin_menu', 'wat_add_settings_page' );

/**
 * Tools page: bulk retro-tag existing posts, 100 at a time with auto-continue.
 */
function wat_retro_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to do that.', 'wp-auto-tagger' ) );
    }

    $running = isset( $_GET['wat_retro'] ) && '1' === $_GET['wat_retro'];
    $offset  = isset( $_GET['offset'] ) ? absint( $_GET['offset'] ) : 0;
    $tagged  = isset( $_GET['tagged'] ) ? absint( $_GET['tagged'] ) : 0;

    if ( $running ) {
        check_admin_referer( 'wat_retro' );

        $ids = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ) );

        $batch_tagged = 0;
        foreach ( $ids as $id ) {
            $batch_tagged += wat_tag_post( $id );
        }
        $tagged += $batch_tagged;

        if ( count( $ids ) === 100 ) {
            // More posts remain — continue automatically.
            $next = add_query_arg(
                array(
                    'wat_retro' => '1',
                    'offset'    => $offset + 100,
                    'tagged'    => $tagged,
                    '_wpnonce'  => wp_create_nonce( 'wat_retro' ),
                ),
                menu_page_url( 'wat-retro', false )
            );
            echo '<meta http-equiv="refresh" content="0;url=' . esc_url( $next ) . '">';
            echo '<p>' . esc_html__( 'Tagging… please wait.', 'wp-auto-tagger' ) . '</p>';
            return;
        }

        printf(
            '<div class="notice notice-success"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: %d: number of tag assignments. */
                __( 'Done. %d tag assignments made across your published posts.', 'wp-auto-tagger' ),
                $tagged
            ) )
        );
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Retro Tag Posts', 'wp-auto-tagger' ); ?></h1>
        <p><?php esc_html_e( 'Runs your keyword rules over every published post and assigns any matching tags. Existing tags are kept; nothing is removed.', 'wp-auto-tagger' ); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'wat_retro' => '1', 'offset' => 0, 'tagged' => 0 ), menu_page_url( 'wat-retro', false ) ), 'wat_retro' ) ); ?>"><?php esc_html_e( 'Tag all published posts', 'wp-auto-tagger' ); ?></a></p>
    </div>
    <?php
}

function wat_add_tools_page() {
    add_management_page(
        __( 'Retro Tag Posts', 'wp-auto-tagger' ),
        __( 'Retro Tag Posts', 'wp-auto-tagger' ),
        'manage_options',
        'wat-retro',
        'wat_retro_page'
    );
}
add_action( 'admin_menu', 'wat_add_tools_page' );
