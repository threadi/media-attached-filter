<?php
/**
 * Plugin Name:       Media Attached Filter
 * Description:       Adds a new filter for media library to filter for files attached to posts or pages.
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Version:           @@VersionNumber@@
 * Author:            Thomas Zwirner
 * Author URI:        https://www.thomaszwirner.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-attached-filter
 *
 * @package media-attached-filter
 */

// prevent direct access.
defined( 'ABSPATH' ) || exit;

// do nothing if PHP-version is not 8.0 or newer.
if ( PHP_VERSION_ID < 80000 ) { // @phpstan-ignore smaller.alwaysFalse
	return;
}

/**
 * Add filter field in media library.
 *
 * @return void
 */
function media_attached_filter_add_filter(): void {
	// bail if get_current_screen is not available.
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}

	// get the actual screen.
	$screen = get_current_screen();

	// bail if screen is null.
	if ( ! $screen instanceof WP_Screen ) {
		return;
	}

	// bail if screen is not media library.
	if ( 'upload' !== $screen->base ) {
		return;
	}

	// get actual value from request.
	$attached = filter_input( INPUT_GET, 'maf_attached', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
	if ( is_null( $attached ) ) {
		$attached = '';
	}

	// show filter with AJAX-function to search.
	?>
	<!--suppress HtmlFormInputWithoutLabel -->
	<input list="maf_attached_list" id="maf_attached" name="maf_attached" value="<?php echo esc_attr( $attached ); ?>" placeholder="<?php echo esc_attr__( 'Attached to ..', 'media-attached-filter' ); ?>" autocomplete="off" />
	<datalist id="maf_attached_list"></datalist>

	<?php
}
add_action( 'restrict_manage_posts', 'media_attached_filter_add_filter' );

/**
 * Add own CSS and JS for backend.
 *
 * @return void
 */
function media_attached_filter_add_files(): void {
	// admin-specific styles.
	wp_enqueue_style(
		'maf-admin',
		plugin_dir_url( __FILE__ ) . '/admin/styles.css',
		array(),
		(string) filemtime( plugin_dir_path( __FILE__ ) . '/admin/styles.css' ),
	);

	// backend-JS.
	wp_enqueue_script(
		'maf-admin',
		plugins_url( '/admin/js.js', __FILE__ ),
		array( 'jquery' ),
		(string) filemtime( plugin_dir_path( __FILE__ ) . '/admin/js.js' ),
		true
	);

	// add php-vars to our js-script.
	wp_localize_script(
		'maf-admin',
		'mafJsVars',
		array(
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'maf_search_nonce' => wp_create_nonce( 'maf-search' ),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'media_attached_filter_add_files' );

/**
 * Run search for entries with given keyword and return resulting limited list.
 *
 * @return void
 */
function media_attached_filter_search_ajax(): void {
	// check nonce.
	check_ajax_referer( 'maf-search', 'nonce' );

	// get requested keyword.
	$keyword = filter_input( INPUT_POST, 'keyword', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

	// bail if keyword is not given.
	if ( is_null( $keyword ) ) {
		wp_send_json( array( 'success' => false ) );
	}

    // get post types with thumbnail support.
    $post_types = get_post_types_by_support( 'thumbnail' );

    // bail if list is empty.
    if( empty( $post_types ) ) {
        wp_send_json( array( 'success' => false ) );
    }

	// define query.
	$query  = array(
		'post_type'      => $post_types,
		'post_status'    => 'any',
		's'              => $keyword,
		'fields'         => 'ids',
		'search_columns' => array( 'post_title' ),
	);
	$result = new WP_Query( $query );

	// get results.
	$list = array();
	foreach ( $result->posts as $post_id ) {
		// bail if object is WP_Post.
		if ( $post_id instanceof WP_Post ) {
			continue;
		}

		// add the entry to the resulting list.
		$list[ absint( $post_id ) ] = get_the_title( $post_id );
	}

	// return resulting list.
	wp_send_json(
		array(
			'success' => ! empty( $list ),
			'results' => $list,
		)
	);
}
add_action( 'wp_ajax_maf_search', 'media_attached_filter_search_ajax' );

/**
 * Run the filter.
 *
 * @param WP_Query $query The WP_Query object which will be run.
 *
 * @return void
 */
function media_attached_filter_run_filter( WP_Query $query ): void {
	// bail if this is not wp-admin.
	if ( ! is_admin() ) {
		return;
	}

	// bail if this is not the main query.
	if ( ! $query->is_main_query() ) {
		return;
	}

	// get the attribute from request.
	$attached = filter_input( INPUT_GET, 'maf_attached', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

	// bail if attribute is not set.
	if ( is_null( $attached ) ) {
		return;
	}

	// bail if attribute is empty.
	if ( empty( $attached ) ) {
		return;
	}

    // get post types with thumbnail support.
    $post_types = get_post_types_by_support( 'thumbnail' );

    // bail if list is empty.
    if( empty( $post_types ) ) {
        return;
    }

	// query for the attached page or post.
	$query_to_get_post_id = array(
		'post_type'   => $post_types,
		'post_status' => 'any',
		'title'       => $attached,
		'fields'      => 'ids',
	);
	$results              = new WP_Query( $query_to_get_post_id );

	// if we have only one result, add it to the main query.
	if ( 1 === $results->post_count ) {
		$query->set( 'post_parent', $results->posts[0] );
	} else {
		// otherwise let the query return nothing.
		$query->set( 'post_parent', -1 );
	}
}
add_action( 'pre_get_posts', 'media_attached_filter_run_filter' );
