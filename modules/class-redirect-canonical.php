<?php
/**
 * Redirect Canonical Debugger.
 */

namespace SWPD;

/**
 * SWPD\Redirect_Canonical Class.
 */
class Redirect_Canonical extends Base {
	/**
	 * Name of the SWPD Debugger running.
	 *
	 * @var string
	 */
	public string $debugger_name = 'Redirect Canoniocal';

	/**
	 * Constructor; set up all of the necessary WordPress hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'swap_redirect_canoniocal' ), 10, 0 );
	}

	/**
	 * Swaps out the core redirect_canoniocal for our custom version.
	 *
	 * @return void
	 */
	public function swap_redirect_canoniocal(): void {
		remove_action( 'template_redirect', 'redirect_canonical' );
		add_action( 'template_redirect', array( $this, 'redirect_canonical' ) );
	}

	/**
	 * Custom redirect_canoniocal that adds debugging.
	 *
	 * @param  string $location     The URL being redirected from.
	 * @param  string $redirect_url The URL being redirected to.
	 *
	 * @return void
	 */
	public function redirect_canonical_log( $location, $redirect_url ) {
		$message = '$redirect_url is being set via ' . $location;
		$data    = array( 'redirect_url' => $redirect_url );

		$this->log(
			message: $response_message,
			data: $data,
			backtrace: true
		);
	}

	// The following function is from core, so let's disable PHPCS.
	// phpcs:disable

	/**
	 * Custom redirect_canoniocal that adds debugging.
	 *
	 * @param  string  $requested_url Optional. The URL that was requested, used to figure if redirect is needed.
	 * @param  boolean $do_redirect   Optional. Redirect to the new URL.
	 *
	 * @return @return string|void The string of the URL, if redirect needed.
	 */
	public function redirect_canonical( $requested_url = null, bool $do_redirect = true ) {
		global $wp_rewrite, $is_IIS, $wp_query, $wpdb, $wp;

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}

		// If we're not in wp-admin and the post has been published and preview nonce
		// is non-existent or invalid then no need for preview in query.
		if ( is_preview() && get_query_var( 'p' ) && 'publish' === get_post_status( get_query_var( 'p' ) ) ) {
			if ( ! isset( $_GET['preview_id'] )
				|| ! isset( $_GET['preview_nonce'] )
				|| ! wp_verify_nonce( $_GET['preview_nonce'], 'post_preview_' . (int) $_GET['preview_id'] )
			) {
				$wp_query->is_preview = false;
			}
		}

		if ( is_admin() || is_search() || is_preview() || is_trackback() || is_favicon()
			|| ( $is_IIS && ! iis7_supports_permalinks() )
		) {
			return;
		}

		if ( ! $requested_url && isset( $_SERVER['HTTP_HOST'] ) ) {
			// Build the URL in the address bar.
			$requested_url  = is_ssl() ? 'https://' : 'http://';
			$requested_url .= $_SERVER['HTTP_HOST'];
			$requested_url .= $_SERVER['REQUEST_URI'];
		}

		$original = parse_url( $requested_url );
		if ( false === $original ) {
			return;
		}

		$redirect     = $original;
		$redirect_url = false;
		$redirect_obj = false;

		// Notice fixing.
		if ( ! isset( $redirect['path'] ) ) {
			$redirect['path'] = '';
		}
		if ( ! isset( $redirect['query'] ) ) {
			$redirect['query'] = '';
		}

		/*
		 * If the original URL ended with non-breaking spaces, they were almost
		 * certainly inserted by accident. Let's remove them, so the reader doesn't
		 * see a 404 error with no obvious cause.
		 */
		$redirect['path'] = preg_replace( '|(%C2%A0)+$|i', '', $redirect['path'] );

		// It's not a preview, so remove it from URL.
		if ( get_query_var( 'preview' ) ) {
			$redirect['query'] = remove_query_arg( 'preview', $redirect['query'] );
		}

		$post_id = get_query_var( 'p' );

		if ( is_feed() && $post_id ) {
			$redirect_url = get_post_comments_feed_link( $post_id, get_query_var( 'feed' ) );
			$redirect_obj = get_post( $post_id );

			if ( $redirect_url ) {
				$this->redirect_canonical_log( '#1', $redirect_url );
				$redirect['query'] = _remove_qs_args_if_not_in_url(
					$redirect['query'],
					array( 'p', 'page_id', 'attachment_id', 'pagename', 'name', 'post_type', 'feed' ),
					$redirect_url
				);

				$redirect['path'] = parse_url( $redirect_url, PHP_URL_PATH );
			}
		}

		if ( is_singular() && $wp_query->post_count < 1 && $post_id ) {

			$vars = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, post_parent FROM $wpdb->posts WHERE ID = %d", $post_id ) );

			if ( ! empty( $vars[0] ) ) {
				$vars = $vars[0];

				if ( 'revision' === $vars->post_type && $vars->post_parent > 0 ) {
					$post_id = $vars->post_parent;
				}

				$redirect_url = get_permalink( $post_id );
				$redirect_obj = get_post( $post_id );

				if ( $redirect_url ) {
					$this->redirect_canonical_log( '#2', $redirect_url );
					$redirect['query'] = _remove_qs_args_if_not_in_url(
						$redirect['query'],
						array( 'p', 'page_id', 'attachment_id', 'pagename', 'name', 'post_type' ),
						$redirect_url
					);
				}
			}
		}

		// These tests give us a WP-generated permalink.
		if ( is_404() ) {

			// Redirect ?page_id, ?p=, ?attachment_id= to their respective URLs.
			$post_id = max( get_query_var( 'p' ), get_query_var( 'page_id' ), get_query_var( 'attachment_id' ) );

			$redirect_post = $post_id ? get_post( $post_id ) : false;

			if ( $redirect_post ) {
				$post_type_obj = get_post_type_object( $redirect_post->post_type );

				if ( $post_type_obj && $post_type_obj->public && 'auto-draft' !== $redirect_post->post_status ) {
					$redirect_url = get_permalink( $redirect_post );
					$this->redirect_canonical_log( '#3', $redirect_url );
					$redirect_obj = get_post( $redirect_post );

					$redirect['query'] = _remove_qs_args_if_not_in_url(
						$redirect['query'],
						array( 'p', 'page_id', 'attachment_id', 'pagename', 'name', 'post_type' ),
						$redirect_url
					);
				}
			}

			$year  = get_query_var( 'year' );
			$month = get_query_var( 'monthnum' );
			$day   = get_query_var( 'day' );

			if ( $year && $month && $day ) {
				$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );

				if ( ! wp_checkdate( $month, $day, $year, $date ) ) {
					$redirect_url = get_month_link( $year, $month );
					$this->redirect_canonical_log( '#4', $redirect_url );

					$redirect['query'] = _remove_qs_args_if_not_in_url(
						$redirect['query'],
						array( 'year', 'monthnum', 'day' ),
						$redirect_url
					);
				}
			} elseif ( $year && $month && $month > 12 ) {
				$redirect_url = get_year_link( $year );
				$this->redirect_canonical_log( '#5', $redirect_url );

				$redirect['query'] = _remove_qs_args_if_not_in_url(
					$redirect['query'],
					array( 'year', 'monthnum' ),
					$redirect_url
				);
			}

			// Strip off non-existing <!--nextpage--> links from single posts or pages.
			if ( get_query_var( 'page' ) ) {
				$post_id = 0;

				if ( $wp_query->queried_object instanceof WP_Post ) {
					$post_id = $wp_query->queried_object->ID;
				} elseif ( $wp_query->post ) {
					$post_id = $wp_query->post->ID;
				}

				if ( $post_id ) {
					$redirect_url = get_permalink( $post_id );
					$this->redirect_canonical_log( '#6', $redirect_url );
					$redirect_obj = get_post( $post_id );

					$redirect['path']  = rtrim( $redirect['path'], (int) get_query_var( 'page' ) . '/' );
					$redirect['query'] = remove_query_arg( 'page', $redirect['query'] );
				}
			}

			if ( ! $redirect_url ) {
				$redirect_url = redirect_guess_404_permalink();

				if ( $redirect_url ) {
					$this->redirect_canonical_log( '#7', $redirect_url );
					$redirect['query'] = _remove_qs_args_if_not_in_url(
						$redirect['query'],
						array( 'page', 'feed', 'p', 'page_id', 'attachment_id', 'pagename', 'name', 'post_type' ),
						$redirect_url
					);
				}
			}
		} elseif ( is_object( $wp_rewrite ) && $wp_rewrite->using_permalinks() ) {

			// Rewriting of old ?p=X, ?m=2004, ?m=200401, ?m=20040101.
			if ( is_attachment()
				&& ! array_diff( array_keys( $wp->query_vars ), array( 'attachment', 'attachment_id' ) )
				&& ! $redirect_url
			) {
				if ( ! empty( $_GET['attachment_id'] ) ) {
					$redirect_url = get_attachment_link( get_query_var( 'attachment_id' ) );
					$this->redirect_canonical_log( '#8', $redirect_url );
					$redirect_obj = get_post( get_query_var( 'attachment_id' ) );

					if ( $redirect_url ) {
						$redirect['query'] = remove_query_arg( 'attachment_id', $redirect['query'] );
					}
				} else {
					$redirect_url = get_attachment_link();
					$this->redirect_canonical_log( '#9', $redirect_url );
					$redirect_obj = get_post();
				}
			} elseif ( is_single() && ! empty( $_GET['p'] ) && ! $redirect_url ) {
				$redirect_url = get_permalink( get_query_var( 'p' ) );
				$this->redirect_canonical_log( '#10', $redirect_url );
				$redirect_obj = get_post( get_query_var( 'p' ) );

				if ( $redirect_url ) {
					$redirect['query'] = remove_query_arg( array( 'p', 'post_type' ), $redirect['query'] );
				}
			} elseif ( is_single() && ! empty( $_GET['name'] ) && ! $redirect_url ) {
				$redirect_url = get_permalink( $wp_query->get_queried_object_id() );
				$this->redirect_canonical_log( '#11', $redirect_url );
				$redirect_obj = get_post( $wp_query->get_queried_object_id() );

				if ( $redirect_url ) {
					$redirect['query'] = remove_query_arg( 'name', $redirect['query'] );
				}
			} elseif ( is_page() && ! empty( $_GET['page_id'] ) && ! $redirect_url ) {
				$redirect_url = get_permalink( get_query_var( 'page_id' ) );
				$redirect_obj = get_post( get_query_var( 'page_id' ) );

				if ( $redirect_url ) {
					$this->redirect_canonical_log( '#12', $redirect_url );
					$redirect['query'] = remove_query_arg( 'page_id', $redirect['query'] );
				}
			} elseif ( is_page() && ! is_feed() && ! $redirect_url
				&& 'page' === get_option( 'show_on_front' ) && get_queried_object_id() === (int) get_option( 'page_on_front' )
			) {
				$redirect_url = home_url( '/' );
				$this->redirect_canonical_log( '#13', $redirect_url );
			} elseif ( is_home() && ! empty( $_GET['page_id'] ) && ! $redirect_url
				&& 'page' === get_option( 'show_on_front' ) && get_query_var( 'page_id' ) === (int) get_option( 'page_for_posts' )
			) {
				$redirect_url = get_permalink( get_option( 'page_for_posts' ) );
				$redirect_obj = get_post( get_option( 'page_for_posts' ) );

				if ( $redirect_url ) {
					$this->redirect_canonical_log( '#14', $redirect_url );
					$redirect['query'] = remove_query_arg( 'page_id', $redirect['query'] );
				}
			} elseif ( ! empty( $_GET['m'] ) && ( is_year() || is_month() || is_day() ) ) {
				$m = get_query_var( 'm' );

				switch ( strlen( $m ) ) {
					case 4: // Yearly.
						$redirect_url = get_year_link( $m );
						$this->redirect_canonical_log( '#15', $redirect_url );
						break;
					case 6: // Monthly.
						$redirect_url = get_month_link( substr( $m, 0, 4 ), substr( $m, 4, 2 ) );
						$this->redirect_canonical_log( '#16', $redirect_url );
						break;
					case 8: // Daily.
						$redirect_url = get_day_link( substr( $m, 0, 4 ), substr( $m, 4, 2 ), substr( $m, 6, 2 ) );
						$this->redirect_canonical_log( '#17', $redirect_url );
						break;
				}

				if ( $redirect_url ) {
					$redirect['query'] = remove_query_arg( 'm', $redirect['query'] );
				}
				// Now moving on to non ?m=X year/month/day links.
			} elseif ( is_date() ) {
				$year  = get_query_var( 'year' );
				$month = get_query_var( 'monthnum' );
				$day   = get_query_var( 'day' );

				if ( is_day() && $year && $month && ! empty( $_GET['day'] ) ) {
					$redirect_url = get_day_link( $year, $month, $day );

					if ( $redirect_url ) {
						$this->redirect_canonical_log( '#18', $redirect_url );
						$redirect['query'] = remove_query_arg( array( 'year', 'monthnum', 'day' ), $redirect['query'] );
					}
				} elseif ( is_month() && $year && ! empty( $_GET['monthnum'] ) ) {
					$redirect_url = get_month_link( $year, $month );

					if ( $redirect_url ) {
						$this->redirect_canonical_log( '#19', $redirect_url );
						$redirect['query'] = remove_query_arg( array( 'year', 'monthnum' ), $redirect['query'] );
					}
				} elseif ( is_year() && ! empty( $_GET['year'] ) ) {
					$redirect_url = get_year_link( $year );

					if ( $redirect_url ) {
						$this->redirect_canonical_log( '#20', $redirect_url );
						$redirect['query'] = remove_query_arg( 'year', $redirect['query'] );
					}
				}
			} elseif ( is_author() && ! empty( $_GET['author'] ) && preg_match( '|^[0-9]+$|', $_GET['author'] ) ) {
				$author = get_userdata( get_query_var( 'author' ) );

				if ( false !== $author
					&& $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE $wpdb->posts.post_author = %d AND $wpdb->posts.post_status = 'publish' LIMIT 1", $author->ID ) )
				) {
					$redirect_url = get_author_posts_url( $author->ID, $author->user_nicename );
					$redirect_obj = $author;

					if ( $redirect_url ) {
						$this->redirect_canonical_log( '#21', $redirect_url );
						$redirect['query'] = remove_query_arg( 'author', $redirect['query'] );
					}
				}
			} elseif ( is_category() || is_tag() || is_tax() ) { // Terms (tags/categories).
				$term_count = 0;

				foreach ( $wp_query->tax_query->queried_terms as $tax_query ) {
					$term_count += count( $tax_query['terms'] );
				}

				$obj = $wp_query->get_queried_object();

				if ( $term_count <= 1 && ! empty( $obj->term_id ) ) {
					$tax_url = get_term_link( (int) $obj->term_id, $obj->taxonomy );

					if ( $tax_url && ! is_wp_error( $tax_url ) ) {
						if ( ! empty( $redirect['query'] ) ) {
							// Strip taxonomy query vars off the URL.
							$qv_remove = array( 'term', 'taxonomy' );

							if ( is_category() ) {
								$qv_remove[] = 'category_name';
								$qv_remove[] = 'cat';
							} elseif ( is_tag() ) {
								$qv_remove[] = 'tag';
								$qv_remove[] = 'tag_id';
							} else {
								// Custom taxonomies will have a custom query var, remove those too.
								$tax_obj = get_taxonomy( $obj->taxonomy );
								if ( false !== $tax_obj->query_var ) {
									$qv_remove[] = $tax_obj->query_var;
								}
							}

							$rewrite_vars = array_diff( array_keys( $wp_query->query ), array_keys( $_GET ) );

							// Check to see if all the query vars are coming from the rewrite, none are set via $_GET.
							if ( ! array_diff( $rewrite_vars, array_keys( $_GET ) ) ) {
								// Remove all of the per-tax query vars.
								$redirect['query'] = remove_query_arg( $qv_remove, $redirect['query'] );

								// Create the destination URL for this taxonomy.
								$tax_url = parse_url( $tax_url );

								if ( ! empty( $tax_url['query'] ) ) {
									// Taxonomy accessible via ?taxonomy=...&term=... or any custom query var.
									parse_str( $tax_url['query'], $query_vars );
									$redirect['query'] = add_query_arg( $query_vars, $redirect['query'] );
								} else {
									// Taxonomy is accessible via a "pretty URL".
									$redirect['path'] = $tax_url['path'];
								}
							} else {
								// Some query vars are set via $_GET. Unset those from $_GET that exist via the rewrite.
								foreach ( $qv_remove as $_qv ) {
									if ( isset( $rewrite_vars[ $_qv ] ) ) {
										$redirect['query'] = remove_query_arg( $_qv, $redirect['query'] );
									}
								}
							}
						}
					}
				}
			} elseif ( is_single() && strpos( $wp_rewrite->permalink_structure, '%category%' ) !== false ) {
				$category_name = get_query_var( 'category_name' );

				if ( $category_name ) {
					$category = get_category_by_path( $category_name );

					if ( ! $category || is_wp_error( $category )
						|| ! has_term( $category->term_id, 'category', $wp_query->get_queried_object_id() )
					) {
						$redirect_url = get_permalink( $wp_query->get_queried_object_id() );
						$this->redirect_canonical_log( '#22', $redirect_url );
						$redirect_obj = get_post( $wp_query->get_queried_object_id() );
					}
				}
			}

			// Post paging.
			if ( is_singular() && get_query_var( 'page' ) ) {
				$page = get_query_var( 'page' );

				if ( ! $redirect_url ) {
					$redirect_url = get_permalink( get_queried_object_id() );
					$this->redirect_canonical_log( '#23', $redirect_url );
					$redirect_obj = get_post( get_queried_object_id() );
				}

				if ( $page > 1 ) {
					$redirect_url = trailingslashit( $redirect_url );
					$this->redirect_canonical_log( '#24', $redirect_url );

					if ( is_front_page() ) {
						$redirect_url .= user_trailingslashit( "$wp_rewrite->pagination_base/$page", 'paged' );
						$this->redirect_canonical_log( '#25', $redirect_url );
					} else {
						$redirect_url .= user_trailingslashit( $page, 'single_paged' );
						$this->redirect_canonical_log( '#26', $redirect_url );
					}
				}

				$redirect['query'] = remove_query_arg( 'page', $redirect['query'] );
			}

			if ( get_query_var( 'sitemap' ) ) {
				$redirect_url      = get_sitemap_url( get_query_var( 'sitemap' ), get_query_var( 'sitemap-subtype' ), get_query_var( 'paged' ) );
				$redirect['query'] = remove_query_arg( array( 'sitemap', 'sitemap-subtype', 'paged' ), $redirect['query'] );
			} elseif ( get_query_var( 'paged' ) || is_feed() || get_query_var( 'cpage' ) ) {
				// Paging and feeds.
				$paged = get_query_var( 'paged' );
				$feed  = get_query_var( 'feed' );
				$cpage = get_query_var( 'cpage' );

				while ( preg_match( "#/$wp_rewrite->pagination_base/?[0-9]+?(/+)?$#", $redirect['path'] )
					|| preg_match( '#/(comments/?)?(feed|rss2?|rdf|atom)(/+)?$#', $redirect['path'] )
					|| preg_match( "#/{$wp_rewrite->comments_pagination_base}-[0-9]+(/+)?$#", $redirect['path'] )
				) {
					// Strip off any existing paging.
					$redirect['path'] = preg_replace( "#/$wp_rewrite->pagination_base/?[0-9]+?(/+)?$#", '/', $redirect['path'] );
					// Strip off feed endings.
					$redirect['path'] = preg_replace( '#/(comments/?)?(feed|rss2?|rdf|atom)(/+|$)#', '/', $redirect['path'] );
					// Strip off any existing comment paging.
					$redirect['path'] = preg_replace( "#/{$wp_rewrite->comments_pagination_base}-[0-9]+?(/+)?$#", '/', $redirect['path'] );
				}

				$addl_path    = '';
				$default_feed = get_default_feed();

				if ( is_feed() && in_array( $feed, $wp_rewrite->feeds, true ) ) {
					$addl_path = ! empty( $addl_path ) ? trailingslashit( $addl_path ) : '';

					if ( ! is_singular() && get_query_var( 'withcomments' ) ) {
						$addl_path .= 'comments/';
					}

					if ( ( 'rss' === $default_feed && 'feed' === $feed ) || 'rss' === $feed ) {
						$format = ( 'rss2' === $default_feed ) ? '' : 'rss2';
					} else {
						$format = ( $default_feed === $feed || 'feed' === $feed ) ? '' : $feed;
					}

					$addl_path .= user_trailingslashit( 'feed/' . $format, 'feed' );

					$redirect['query'] = remove_query_arg( 'feed', $redirect['query'] );
				} elseif ( is_feed() && 'old' === $feed ) {
					$old_feed_files = array(
						'wp-atom.php'         => 'atom',
						'wp-commentsrss2.php' => 'comments_rss2',
						'wp-feed.php'         => $default_feed,
						'wp-rdf.php'          => 'rdf',
						'wp-rss.php'          => 'rss2',
						'wp-rss2.php'         => 'rss2',
					);

					if ( isset( $old_feed_files[ basename( $redirect['path'] ) ] ) ) {
						$redirect_url = get_feed_link( $old_feed_files[ basename( $redirect['path'] ) ] );
						$this->redirect_canonical_log( '#27', $redirect_url );

						wp_redirect( $redirect_url, 301 );
						die();
					}
				}

				if ( $paged > 0 ) {
					$redirect['query'] = remove_query_arg( 'paged', $redirect['query'] );

					if ( ! is_feed() ) {
						if ( ! is_single() ) {
							$addl_path = ! empty( $addl_path ) ? trailingslashit( $addl_path ) : '';

							if ( $paged > 1 ) {
								$addl_path .= user_trailingslashit( "$wp_rewrite->pagination_base/$paged", 'paged' );
							}
						}
					} elseif ( $paged > 1 ) {
						$redirect['query'] = add_query_arg( 'paged', $paged, $redirect['query'] );
					}
				}

				$default_comments_page = get_option( 'default_comments_page' );

				if ( get_option( 'page_comments' )
					&& ( 'newest' === $default_comments_page && $cpage > 0
						|| 'newest' !== $default_comments_page && $cpage > 1 )
				) {
					$addl_path  = ( ! empty( $addl_path ) ? trailingslashit( $addl_path ) : '' );
					$addl_path .= user_trailingslashit( $wp_rewrite->comments_pagination_base . '-' . $cpage, 'commentpaged' );

					$redirect['query'] = remove_query_arg( 'cpage', $redirect['query'] );
				}

				// Strip off trailing /index.php/.
				$redirect['path'] = preg_replace( '|/' . preg_quote( $wp_rewrite->index, '|' ) . '/?$|', '/', $redirect['path'] );
				$redirect['path'] = user_trailingslashit( $redirect['path'] );

				if ( ! empty( $addl_path )
					&& $wp_rewrite->using_index_permalinks()
					&& strpos( $redirect['path'], '/' . $wp_rewrite->index . '/' ) === false
				) {
					$redirect['path'] = trailingslashit( $redirect['path'] ) . $wp_rewrite->index . '/';
				}

				if ( ! empty( $addl_path ) ) {
					$redirect['path'] = trailingslashit( $redirect['path'] ) . $addl_path;
				}

				$redirect_url = $redirect['scheme'] . '://' . $redirect['host'] . $redirect['path'];
				$this->redirect_canonical_log( '#28', $redirect_url );
			}

			if ( 'wp-register.php' === basename( $redirect['path'] ) ) {
				if ( is_multisite() ) {
					/** This filter is documented in wp-login.php */
					$redirect_url = apply_filters( 'wp_signup_location', network_site_url( 'wp-signup.php' ) );
					$this->redirect_canonical_log( '#29', $redirect_url );
				} else {
					$redirect_url = wp_registration_url();
					$this->redirect_canonical_log( '#30', $redirect_url );
				}

				wp_redirect( $redirect_url, 301 );
				die();
			}
		}

		$redirect['query'] = preg_replace( '#^\??&*?#', '', $redirect['query'] );

		// Tack on any additional query vars.
		if ( $redirect_url && ! empty( $redirect['query'] ) ) {
			parse_str( $redirect['query'], $_parsed_query );
			$redirect = parse_url( $redirect_url );

			if ( ! empty( $_parsed_query['name'] ) && ! empty( $redirect['query'] ) ) {
				parse_str( $redirect['query'], $_parsed_redirect_query );

				if ( empty( $_parsed_redirect_query['name'] ) ) {
					unset( $_parsed_query['name'] );
				}
			}

			$_parsed_query = array_combine(
				rawurlencode_deep( array_keys( $_parsed_query ) ),
				rawurlencode_deep( array_values( $_parsed_query ) )
			);

			$redirect_url = add_query_arg( $_parsed_query, $redirect_url );
			$this->redirect_canonical_log( '#31', $redirect_url );
		}

		if ( $redirect_url ) {
			$redirect = parse_url( $redirect_url );
		}

		// www.example.com vs. example.com
		$user_home = parse_url( home_url() );

		if ( ! empty( $user_home['host'] ) ) {
			$redirect['host'] = $user_home['host'];
		}

		if ( empty( $user_home['path'] ) ) {
			$user_home['path'] = '/';
		}

		// Handle ports.
		if ( ! empty( $user_home['port'] ) ) {
			$redirect['port'] = $user_home['port'];
		} else {
			unset( $redirect['port'] );
		}

		// Trailing /index.php.
		$redirect['path'] = preg_replace( '|/' . preg_quote( $wp_rewrite->index, '|' ) . '/*?$|', '/', $redirect['path'] );

		$punctuation_pattern = implode(
			'|',
			array_map(
				'preg_quote',
				array(
					' ',
					'%20',  // Space.
					'!',
					'%21',  // Exclamation mark.
					'"',
					'%22',  // Double quote.
					"'",
					'%27',  // Single quote.
					'(',
					'%28',  // Opening bracket.
					')',
					'%29',  // Closing bracket.
					',',
					'%2C',  // Comma.
					'.',
					'%2E',  // Period.
					';',
					'%3B',  // Semicolon.
					'{',
					'%7B',  // Opening curly bracket.
					'}',
					'%7D',  // Closing curly bracket.
					'%E2%80%9C', // Opening curly quote.
					'%E2%80%9D', // Closing curly quote.
				)
			)
		);

		// Remove trailing spaces and end punctuation from the path.
		$redirect['path'] = preg_replace( "#($punctuation_pattern)+$#", '', $redirect['path'] );

		if ( ! empty( $redirect['query'] ) ) {
			// Remove trailing spaces and end punctuation from certain terminating query string args.
			$redirect['query'] = preg_replace( "#((^|&)(p|page_id|cat|tag)=[^&]*?)($punctuation_pattern)+$#", '$1', $redirect['query'] );

			// Clean up empty query strings.
			$redirect['query'] = trim( preg_replace( '#(^|&)(p|page_id|cat|tag)=?(&|$)#', '&', $redirect['query'] ), '&' );

			// Redirect obsolete feeds.
			$redirect['query'] = preg_replace( '#(^|&)feed=rss(&|$)#', '$1feed=rss2$2', $redirect['query'] );

			// Remove redundant leading ampersands.
			$redirect['query'] = preg_replace( '#^\??&*?#', '', $redirect['query'] );
		}

		// Strip /index.php/ when we're not using PATHINFO permalinks.
		if ( ! $wp_rewrite->using_index_permalinks() ) {
			$redirect['path'] = str_replace( '/' . $wp_rewrite->index . '/', '/', $redirect['path'] );
		}

		// Trailing slashes.
		if ( is_object( $wp_rewrite ) && $wp_rewrite->using_permalinks()
			&& ! is_404() && ( ! is_front_page() || is_front_page() && get_query_var( 'paged' ) > 1 )
		) {
			$user_ts_type = '';

			if ( get_query_var( 'paged' ) > 0 ) {
				$user_ts_type = 'paged';
			} else {
				foreach ( array( 'single', 'category', 'page', 'day', 'month', 'year', 'home' ) as $type ) {
					$func = 'is_' . $type;
					if ( call_user_func( $func ) ) {
						$user_ts_type = $type;
						break;
					}
				}
			}

			$redirect['path'] = user_trailingslashit( $redirect['path'], $user_ts_type );
		} elseif ( is_front_page() ) {
			$redirect['path'] = trailingslashit( $redirect['path'] );
		}

		// Remove trailing slash for robots.txt or sitemap requests.
		if ( is_robots()
			|| ! empty( get_query_var( 'sitemap' ) ) || ! empty( get_query_var( 'sitemap-stylesheet' ) )
		) {
			$redirect['path'] = untrailingslashit( $redirect['path'] );
		}

		// Strip multiple slashes out of the URL.
		if ( strpos( $redirect['path'], '//' ) > -1 ) {
			$redirect['path'] = preg_replace( '|/+|', '/', $redirect['path'] );
		}

		// Always trailing slash the Front Page URL.
		if ( trailingslashit( $redirect['path'] ) === trailingslashit( $user_home['path'] ) ) {
			$redirect['path'] = trailingslashit( $redirect['path'] );
		}

		$original_host_low = strtolower( $original['host'] );
		$redirect_host_low = strtolower( $redirect['host'] );

		// Ignore differences in host capitalization, as this can lead to infinite redirects.
		// Only redirect no-www <=> yes-www.
		if ( $original_host_low === $redirect_host_low
			|| ( 'www.' . $original_host_low !== $redirect_host_low
				&& 'www.' . $redirect_host_low !== $original_host_low )
		) {
			$redirect['host'] = $original['host'];
		}

		$compare_original = array( $original['host'], $original['path'] );

		if ( ! empty( $original['port'] ) ) {
			$compare_original[] = $original['port'];
		}

		if ( ! empty( $original['query'] ) ) {
			$compare_original[] = $original['query'];
		}

		$compare_redirect = array( $redirect['host'], $redirect['path'] );

		if ( ! empty( $redirect['port'] ) ) {
			$compare_redirect[] = $redirect['port'];
		}

		if ( ! empty( $redirect['query'] ) ) {
			$compare_redirect[] = $redirect['query'];
		}

		if ( $compare_original !== $compare_redirect ) {
			$redirect_url = $redirect['scheme'] . '://' . $redirect['host'];
			$this->redirect_canonical_log( '#32', $redirect_url );

			if ( ! empty( $redirect['port'] ) ) {
				$redirect_url .= ':' . $redirect['port'];
			}

			$redirect_url .= $redirect['path'];

			if ( ! empty( $redirect['query'] ) ) {
				$redirect_url .= '?' . $redirect['query'];
			}
		}

		if ( ! $redirect_url || $redirect_url === $requested_url ) {
			return;
		}

		// Hex encoded octets are case-insensitive.
		if ( false !== strpos( $requested_url, '%' ) ) {
			if ( ! function_exists( 'lowercase_octets' ) ) {
				/**
				 * Converts the first hex-encoded octet match to lowercase.
				 *
				 * @since 3.1.0
				 * @ignore
				 *
				 * @param array $matches Hex-encoded octet matches for the requested URL.
				 * @return string Lowercased version of the first match.
				 */
				function lowercase_octets( $matches ) {
					return strtolower( $matches[0] );
				}
			}

			$requested_url = preg_replace_callback( '|%[a-fA-F0-9][a-fA-F0-9]|', 'lowercase_octets', $requested_url );
		}

		if ( $redirect_obj instanceof WP_Post ) {
			$post_status_obj = get_post_status_object( get_post_status( $redirect_obj ) );
			/*
			 * Unset the redirect object and URL if they are not readable by the user.
			 * This condition is a little confusing as the condition needs to pass if
			 * the post is not readable by the user. That's why there are ! (not) conditions
			 * throughout.
			 */
			if (
				// Private post statuses only redirect if the user can read them.
				! (
					$post_status_obj->private &&
					current_user_can( 'read_post', $redirect_obj->ID )
				) &&
				// For other posts, only redirect if publicly viewable.
				! is_post_publicly_viewable( $redirect_obj )
			) {
				$redirect_obj = false;
				$redirect_url = false;
			}
		}

		/**
		 * Filters the canonical redirect URL.
		 *
		 * Returning false to this filter will cancel the redirect.
		 *
		 * @since 2.3.0
		 *
		 * @param string $redirect_url  The redirect URL.
		 * @param string $requested_url The requested URL.
		 */
		$redirect_url = apply_filters( 'redirect_canonical', $redirect_url, $requested_url );

		// Yes, again -- in case the filter aborted the request.
		if ( ! $redirect_url || strip_fragment_from_url( $redirect_url ) === strip_fragment_from_url( $requested_url ) ) {
			return;
		}

		if ( $do_redirect ) {
			// Protect against chained redirects.
			if ( ! redirect_canonical( $redirect_url, false ) ) {
				wp_redirect( $redirect_url, 301 );
				exit;
			} else {
				// Debug.
				// die("1: $redirect_url<br />2: " . redirect_canonical( $redirect_url, false ) );
				return;
			}
		} else {
			return $redirect_url;
		}
	}

	// phpcs:enable

}

new SWPD\Redirect_Canonical();
