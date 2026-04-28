<?php
/**
 * Extranet functionality for FloAuth plugin.
 *
 * @package FloAuth
 */

/**
 * Whether post extranet restrictions by category are enabled.
 *
 * Enable in functions.php, e.g. add_filter( 'floauth_extranet_restrict_posts_by_category', '__return_true' );
 *
 * @return bool
 */
function floauth_extranet_restrict_posts_by_category_enabled() {
	return (bool) apply_filters( 'floauth_extranet_restrict_posts_by_category', false );
}

/**
 * Root category term IDs to restrict (descendants are included automatically).
 *
 * Filter in functions.php, e.g. return array( 12, 34 );
 *
 * @return int[]
 */
function floauth_get_extranet_restricted_category_root_ids() {
	$ids = apply_filters( 'floauth_extranet_restricted_category_ids', array() );
	if ( ! is_array( $ids ) ) {
		return array();
	}
	$out = array();
	foreach ( $ids as $id ) {
		$id = absint( $id );
		if ( $id > 0 ) {
			$out[] = $id;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Category term IDs that restrict posts (roots plus all descendants).
 *
 * @return int[]
 */
function floauth_get_extranet_restricted_category_term_ids() {
	if ( ! floauth_extranet_restrict_posts_by_category_enabled() ) {
		delete_transient( 'floauth_extranet_restricted_category_term_ids' );
		return array();
	}
	$roots = floauth_get_extranet_restricted_category_root_ids();
	if ( empty( $roots ) ) {
		delete_transient( 'floauth_extranet_restricted_category_term_ids' );
		return array();
	}

	$roots_hash = md5( wp_json_encode( $roots ) );
	$cached     = get_transient( 'floauth_extranet_restricted_category_term_ids' );
	if (
		is_array( $cached )
		&& isset( $cached['roots_hash'], $cached['term_ids'] )
		&& $roots_hash === $cached['roots_hash']
		&& is_array( $cached['term_ids'] )
	) {
		return array_values( array_unique( array_filter( array_map( 'intval', $cached['term_ids'] ) ) ) );
	}

	$all = array();
	foreach ( $roots as $root_id ) {
		$term = get_term( $root_id, 'category' );
		if ( $term instanceof WP_Term && ! is_wp_error( $term ) ) {
			$all[] = (int) $term->term_id;
		}
		$children = get_term_children( $root_id, 'category' );
		if ( ! is_wp_error( $children ) && is_array( $children ) ) {
			foreach ( $children as $child_id ) {
				$all[] = (int) $child_id;
			}
		}
	}
	$term_ids = array_values( array_unique( array_filter( $all ) ) );
	set_transient(
		'floauth_extranet_restricted_category_term_ids',
		array(
			'roots_hash' => $roots_hash,
			'term_ids'   => $term_ids,
		),
		HOUR_IN_SECONDS
	);
	return $term_ids;
}

/**
 * Redirect when extranet content is blocked for the current user.
 *
 * @return void
 */
function floauth_extranet_block_redirect() {
	wp_safe_redirect( apply_filters( 'floauth_restrict_extranet_block_redirect', home_url( '/' ) ) );
	exit();
}

/**
 * Merge a tax_query clause with an existing query tax_query using AND.
 *
 * @param array<string,mixed> $existing Existing tax_query array.
 * @param array<string,mixed> $clause   New clause.
 * @return array<string,mixed>
 */
function floauth_extranet_merge_tax_query( $existing, $clause ) {
	if ( empty( $existing ) || ! is_array( $existing ) ) {
		return array( $clause );
	}
	$clauses = array();
	foreach ( $existing as $key => $value ) {
		if ( 'relation' === $key ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$clauses[] = $value;
		}
	}
	return array_merge(
		array( 'relation' => 'AND' ),
		$clauses,
		array( $clause )
	);
}

/**
 * Whether the current user may see extranet content (pages and optional category posts).
 *
 * @return bool
 */
function floauth_extranet_user_can_read_extranet() {
	$capability = apply_filters( 'floauth_restrict_extranet_pages_capability', 'read' );
	return is_user_logged_in() && current_user_can( $capability );
}

/**
 * Whether this is a main front-end listing/feed/search query where restricted posts should be omitted.
 *
 * Skips singular queries so template_redirect can still run for blocked single posts.
 *
 * @param WP_Query $query Query instance.
 * @return bool
 */
function floauth_extranet_query_lists_posts_for_hiding( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return false;
	}
	if ( $query->is_singular() ) {
		return false;
	}
	if ( $query->is_search() || $query->is_feed() ) {
		return true;
	}
	if ( $query->is_home() || $query->is_post_type_archive( 'post' ) ) {
		return true;
	}
	if ( $query->is_category() || $query->is_tag() || $query->is_author() || $query->is_date() ) {
		return true;
	}
	return false;
}

/**
 * Whether the query includes the post post type (default blog queries do).
 *
 * @param WP_Query $query Query instance.
 * @return bool
 */
function floauth_extranet_query_includes_post_type_post( $query ) {
	$post_type = $query->get( 'post_type' );
	if ( empty( $post_type ) ) {
		return true;
	}
	if ( 'post' === $post_type ) {
		return true;
	}
	if ( is_array( $post_type ) && in_array( 'post', $post_type, true ) ) {
		return true;
	}
	return false;
}

/**
 * Remove extranet path and its children from search results if user has no rights.
 * Optionally exclude posts in restricted categories from search, listings, and feeds (see filters).
 *
 * Capability can be changed with filter "floauth_restrict_extranet_pages_capability"
 * Capability defaults to "read", also logged-in users with no role have no access
 *
 * @param  WP_Query $query Query instance.
 * @return WP_Query
 */
function floauth_filter_pre_get_posts( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return $query;
	}
	if ( floauth_extranet_user_can_read_extranet() ) {
		return $query;
	}

	if ( $query->is_search() ) {
		$restricted_page_ids = array();
		$restricted_post_id = (int) floauth_get_extranet_post_id();
		if ( 0 !== $restricted_post_id ) {
			$children = get_pages(
				array(
					'child_of' => $restricted_post_id,
				)
			);
			$restricted_page_ids[] = $restricted_post_id;
			foreach ( $children as $child ) {
				$restricted_page_ids[] = $child->ID;
			}
		}
		if ( ! empty( $restricted_page_ids ) ) {
			$not_in = $query->get( 'post__not_in' );
			if ( ! is_array( $not_in ) ) {
				$not_in = array();
			}
			$query->set(
				'post__not_in',
				array_values(
					array_unique(
						array_merge(
							array_map( 'intval', $not_in ),
							$restricted_page_ids
						)
					)
				)
			);
		}
	}

	$category_term_ids = floauth_get_extranet_restricted_category_term_ids();
	if (
		! empty( $category_term_ids )
		&& floauth_extranet_query_lists_posts_for_hiding( $query )
		&& floauth_extranet_query_includes_post_type_post( $query )
	) {
		$extranet_tax = array(
			'taxonomy' => 'category',
			'field'    => 'term_id',
			'terms'    => $category_term_ids,
			'operator' => 'NOT IN',
		);
		$query->set(
			'tax_query',
			floauth_extranet_merge_tax_query( $query->get( 'tax_query' ), $extranet_tax )
		);
	}

	return $query;
}
add_filter( 'pre_get_posts', 'floauth_filter_pre_get_posts' );

/**
 * Disable access to extranet path and its children if user has no rights.
 * Optionally restrict singular posts and category archives by category tree (see filters).
 *
 * Capability can be changed with filter "floauth_restrict_extranet_pages_capability"
 * Capability defaults to "read", also logged-in users with no role have no access
 *
 * @return void
 */
function floauth_block_extranet_pages() {
	if ( floauth_extranet_user_can_read_extranet() ) {
		return;
	}
	if ( is_search() ) {
		return;
	}

	$restricted_post_id = (int) floauth_get_extranet_post_id();
	if ( 0 !== $restricted_post_id ) {
		$current_post_id = get_the_ID();
		if ( $current_post_id ) {
			$ancestors = get_post_ancestors( $current_post_id );
			if ( $restricted_post_id === $current_post_id || in_array( $restricted_post_id, $ancestors, true ) ) {
				floauth_extranet_block_redirect();
			}
		}
	}

	$term_ids = floauth_get_extranet_restricted_category_term_ids();
	if ( empty( $term_ids ) ) {
		return;
	}

	if ( is_singular( 'post' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && has_category( $term_ids, $post ) ) {
			floauth_extranet_block_redirect();
		}
	} elseif ( is_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term && 'category' === $term->taxonomy && in_array( (int) $term->term_id, $term_ids, true ) ) {
			floauth_extranet_block_redirect();
		}
	}
}
add_action( 'template_redirect', 'floauth_block_extranet_pages' );

/**
 * Get extranet post ID from extranet path
 *
 * Saved to transient
 *
 * @return int|null
 */
function floauth_get_extranet_post_id() {
	$extranet_post_id = get_transient( 'floauth_extranet_post_id' );
	if ( false === $extranet_post_id ) {
		$extranet_path = get_option( 'floauth_extranet_path' );
		if ( $extranet_path ) {
			$post_id = url_to_postid( $extranet_path );
			if ( 0 !== $post_id ) {
				$extranet_post_id = $post_id;
				set_transient( 'floauth_extranet_post_id', $extranet_post_id, WEEK_IN_SECONDS );
			}
		}
	}
	return $extranet_post_id;
}

/**
 * Remove extranet post ID transient if option is updated
 *
 * @param mixed $old_value Old value.
 * @param mixed $new_value New value.
 * @return void
 */
function floauth_clear_extranet_transient( $old_value, $new_value ) {
	delete_transient( 'floauth_extranet_post_id' );
}
add_action( 'update_option_floauth_extranet_path', 'floauth_clear_extranet_transient', 10, 2 );

/**
 * Clear restricted category term IDs transient.
 *
 * @return void
 */
function floauth_clear_extranet_restricted_category_term_ids_transient() {
	delete_transient( 'floauth_extranet_restricted_category_term_ids' );
}
add_action( 'created_category', 'floauth_clear_extranet_restricted_category_term_ids_transient' );
add_action( 'edited_category', 'floauth_clear_extranet_restricted_category_term_ids_transient' );
add_action( 'delete_category', 'floauth_clear_extranet_restricted_category_term_ids_transient' );
