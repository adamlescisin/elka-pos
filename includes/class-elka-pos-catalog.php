<?php
/**
 * Catalogue and customer exports for the till (compact JSON, paged, delta-capable),
 * so the app can keep a full local copy for fast search and offline use.
 *
 * @package ElkaPos
 */

defined( 'ABSPATH' ) || exit;

class Elka_Pos_Catalog {

	/**
	 * Product categories as a flat list (the app builds the tree).
	 */
	public static function categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'orderby'    => 'menu_order',
			)
		);
		$out = array();
		if ( is_wp_error( $terms ) ) {
			return $out;
		}
		$uncat = (int) get_option( 'default_product_cat' );
		foreach ( $terms as $t ) {
			if ( (int) $t->term_id === $uncat ) {
				continue;
			}
			$out[] = array(
				'id'     => (int) $t->term_id,
				'name'   => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ),
				'parent' => (int) $t->parent,
				'count'  => (int) $t->count,
				'order'  => (int) get_term_meta( $t->term_id, 'order', true ),
			);
		}
		return $out;
	}

	/**
	 * One page of products.
	 *
	 * @param int    $page           1-based page.
	 * @param int    $per_page       Page size.
	 * @param string $modified_after GMT 'Y-m-d H:i:s' for delta sync, or ''.
	 * @return array { products, total, pages, deleted }
	 */
	public static function products( $page = 1, $per_page = 100, $modified_after = '' ) {
		$args = array(
			'status'   => array( 'publish', 'private' ),
			'type'     => array( 'simple', 'variable' ),
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'paginate' => true,
			'return'   => 'objects',
		);
		if ( $modified_after ) {
			$args['date_modified'] = '>' . strtotime( $modified_after . ' UTC' );
		}
		$result = wc_get_products( $args );
		$items  = array();
		foreach ( $result->products as $product ) {
			$items[] = self::product( $product );
		}

		$deleted = array();
		if ( $modified_after && 1 === (int) $page ) {
			// Products that left the catalogue since the last sync.
			$deleted = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => array( 'trash', 'draft', 'pending' ),
					'fields'         => 'ids',
					'posts_per_page' => 500,
					'date_query'     => array(
						array(
							'column' => 'post_modified_gmt',
							'after'  => $modified_after,
						),
					),
				)
			);
		}

		return array(
			'products' => $items,
			'total'    => (int) $result->total,
			'pages'    => (int) $result->max_num_pages,
			'deleted'  => array_map( 'intval', $deleted ),
		);
	}

	/**
	 * @param WC_Product $product Product.
	 */
	public static function product( $product ) {
		$image = wp_get_attachment_image_src( $product->get_image_id(), 'woocommerce_thumbnail' );
		$data  = array(
			'id'       => $product->get_id(),
			'type'     => $product->get_type(),
			'name'     => html_entity_decode( $product->get_name(), ENT_QUOTES, 'UTF-8' ),
			'sku'      => (string) $product->get_sku(),
			'price'    => self::gross_price( $product ),
			'regular'  => self::gross_price( $product, 'regular' ),
			'cats'     => array_map( 'intval', $product->get_category_ids() ),
			'brand'    => self::brand( $product ),
			'image'    => $image ? $image[0] : '',
			'stock'    => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'inStock'  => $product->is_in_stock(),
			'taxClass' => $product->get_tax_class(),
			'taxable'  => $product->is_taxable(),
			'modified' => $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0,
		);

		if ( $product->is_type( 'variable' ) ) {
			$data['attributes'] = array();
			foreach ( $product->get_variation_attributes() as $attr => $values ) {
				$data['attributes'][] = array(
					'key'     => 'attribute_' . sanitize_title( $attr ),
					'name'    => wc_attribute_label( $attr, $product ),
					'options' => self::attribute_options( $product, $attr, $values ),
				);
			}
			$data['variations'] = array();
			$prices = array();
			foreach ( $product->get_children() as $child_id ) {
				$v = wc_get_product( $child_id );
				if ( ! $v || ! $v->exists() || 'publish' !== $v->get_status() ) {
					continue;
				}
				$price    = self::gross_price( $v );
				$prices[] = $price;
				$attrs    = array();
				foreach ( $v->get_variation_attributes() as $k => $val ) {
					$attrs[ $k ] = (string) $val; // '' = any.
				}
				$vimg = $v->get_image_id() && $v->get_image_id() !== $product->get_image_id() ? wp_get_attachment_image_src( $v->get_image_id(), 'woocommerce_thumbnail' ) : null;
				$data['variations'][] = array(
					'id'      => $v->get_id(),
					'sku'     => (string) $v->get_sku(),
					'attrs'   => $attrs,
					'price'   => $price,
					'regular' => self::gross_price( $v, 'regular' ),
					'stock'   => $v->managing_stock() ? (int) $v->get_stock_quantity() : null,
					'inStock' => $v->is_in_stock(),
					'image'   => $vimg ? $vimg[0] : '',
					'taxClass'=> $v->get_tax_class(),
				);
			}
			if ( $prices ) {
				$data['price']    = min( $prices );
				$data['priceMax'] = max( $prices );
			}
			$total = 0;
			$any   = false;
			foreach ( $data['variations'] as $v ) {
				if ( null !== $v['stock'] ) {
					$total += max( 0, $v['stock'] );
					$any    = true;
				}
			}
			$data['stock'] = $any ? $total : $data['stock'];
		}
		return $data;
	}

	/**
	 * Option list for an attribute in the term order set in WooCommerce (sizes stay in size order).
	 */
	private static function attribute_options( $product, $attr, $values ) {
		$options = array();
		if ( taxonomy_exists( $attr ) ) {
			$terms = wc_get_product_terms( $product->get_id(), $attr, array( 'fields' => 'all' ) );
			foreach ( $terms as $term ) {
				if ( in_array( $term->slug, $values, true ) ) {
					$options[] = array(
						'value' => $term->slug,
						'label' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ),
					);
				}
			}
		} else {
			foreach ( $values as $val ) {
				$options[] = array(
					'value' => $val,
					'label' => $val,
				);
			}
		}
		return $options;
	}

	private static function brand( $product ) {
		foreach ( array( 'product_brand', 'pwb-brand', 'pa_znacka', 'pa_brand' ) as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$names = wc_get_product_terms( $product->get_id(), $tax, array( 'fields' => 'names' ) );
				if ( $names ) {
					return html_entity_decode( $names[0], ENT_QUOTES, 'UTF-8' );
				}
			}
		}
		return '';
	}

	/**
	 * Shelf price incl. VAT (what the customer sees on the label).
	 *
	 * @param WC_Product $product Product.
	 * @param string     $which   active | regular.
	 */
	public static function gross_price( $product, $which = 'active' ) {
		$price = 'regular' === $which ? $product->get_regular_price() : $product->get_price();
		if ( '' === $price || null === $price ) {
			return null;
		}
		return (float) wc_get_price_including_tax( $product, array( 'price' => $price, 'qty' => 1 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Customers                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Compact customer row.
	 *
	 * @param WC_Customer|WP_User|int $customer Customer.
	 * @param bool                    $vouchers Include club vouchers.
	 */
	public static function customer( $customer, $vouchers = true ) {
		if ( ! $customer instanceof WC_Customer ) {
			$customer = new WC_Customer( is_object( $customer ) ? $customer->ID : (int) $customer );
		}
		$email = $customer->get_billing_email() ? $customer->get_billing_email() : $customer->get_email();
		$first = $customer->get_billing_first_name() ? $customer->get_billing_first_name() : $customer->get_first_name();
		$last  = $customer->get_billing_last_name() ? $customer->get_billing_last_name() : $customer->get_last_name();
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$user = get_userdata( $customer->get_id() );
			$name = $user ? $user->display_name : $email;
		}
		$member = Elka_Pos_Klub::find( $customer->get_id(), $email );
		return array(
			'id'        => $customer->get_id(),
			'firstName' => $first,
			'lastName'  => $last,
			'name'      => $name,
			'email'     => (string) $email,
			'phone'     => (string) $customer->get_billing_phone(),
			'city'      => (string) $customer->get_billing_city(),
			'company'   => (string) $customer->get_billing_company(),
			'klub'      => Elka_Pos_Klub::summary( $member, $vouchers ),
		);
	}

	/**
	 * Club members without a web account (in-store only), as customer rows with id 0.
	 *
	 * @param object $member Member row.
	 * @param bool   $vouchers Include vouchers.
	 */
	public static function member_as_customer( $member, $vouchers = true ) {
		return array(
			'id'        => 0,
			'firstName' => (string) $member->first_name,
			'lastName'  => (string) $member->last_name,
			'name'      => trim( $member->first_name . ' ' . $member->last_name ) ? trim( $member->first_name . ' ' . $member->last_name ) : $member->email,
			'email'     => (string) $member->email,
			'phone'     => '',
			'city'      => '',
			'company'   => '',
			'klub'      => Elka_Pos_Klub::summary( $member, $vouchers ),
		);
	}

	/**
	 * Search customers by name, email, phone or club card number.
	 *
	 * @param string $term Search term.
	 * @param int    $limit Max results.
	 */
	public static function search_customers( $term, $limit = 20 ) {
		$term = trim( (string) $term );
		$out  = array();
		$seen = array();
		if ( '' === $term ) {
			return $out;
		}

		// Exact club card match first.
		$member = Elka_Pos_Klub::find_by_card( $term );
		if ( $member ) {
			if ( $member->user_id ) {
				$row = self::customer( (int) $member->user_id );
			} else {
				$row = self::member_as_customer( $member );
			}
			$out[]                       = $row;
			$seen[ strtolower( $row['email'] ) ] = true;
		}

		$query = new WP_User_Query(
			array(
				'search'         => '*' . esc_attr( $term ) . '*',
				'search_columns' => array( 'user_login', 'user_email', 'user_nicename', 'display_name' ),
				'number'         => $limit,
				'fields'         => 'ID',
			)
		);
		$ids = $query->get_results();

		// Name / phone in billing meta.
		$meta = new WP_User_Query(
			array(
				'number'     => $limit,
				'fields'     => 'ID',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array( 'key' => 'billing_first_name', 'value' => $term, 'compare' => 'LIKE' ),
					array( 'key' => 'billing_last_name', 'value' => $term, 'compare' => 'LIKE' ),
					array( 'key' => 'billing_phone', 'value' => preg_replace( '/\s+/', '', $term ), 'compare' => 'LIKE' ),
					array( 'key' => 'first_name', 'value' => $term, 'compare' => 'LIKE' ),
					array( 'key' => 'last_name', 'value' => $term, 'compare' => 'LIKE' ),
				),
			)
		);
		$ids = array_unique( array_merge( $ids, $meta->get_results() ) );

		// "Jana Nová" – both words.
		$parts = preg_split( '/\s+/', $term );
		if ( count( $parts ) >= 2 ) {
			$both = new WP_User_Query(
				array(
					'number'     => $limit,
					'fields'     => 'ID',
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array( 'key' => 'billing_first_name', 'value' => $parts[0], 'compare' => 'LIKE' ),
						array( 'key' => 'billing_last_name', 'value' => $parts[1], 'compare' => 'LIKE' ),
					),
				)
			);
			$ids = array_unique( array_merge( $both->get_results(), $ids ) );
		}

		foreach ( array_slice( $ids, 0, $limit ) as $id ) {
			$row = self::customer( (int) $id );
			$key = strtolower( $row['email'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $row;
		}

		// In-store club members without an account.
		if ( Elka_Pos_Klub::active() && count( $out ) < $limit ) {
			$members = Elka_Club_Members::query(
				array(
					'search'   => $term,
					'per_page' => $limit,
					'status'   => 'active',
				)
			);
			$rows = isset( $members['items'] ) ? $members['items'] : ( is_array( $members ) ? $members : array() );
			foreach ( $rows as $m ) {
				if ( ! is_object( $m ) || ! empty( $m->user_id ) || isset( $seen[ strtolower( $m->email ) ] ) ) {
					continue;
				}
				$seen[ strtolower( $m->email ) ] = true;
				$out[] = self::member_as_customer( $m );
			}
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Page of all customers for the offline cache (no vouchers – fetched live on selection).
	 *
	 * @param int $page     Page.
	 * @param int $per_page Per page.
	 */
	public static function customers_page( $page = 1, $per_page = 200 ) {
		$query = new WP_User_Query(
			array(
				'role__in' => array( 'customer', 'elka_klub', 'subscriber' ),
				'number'   => $per_page,
				'paged'    => $page,
				'orderby'  => 'ID',
				'fields'   => 'ID',
				'count_total' => true,
			)
		);
		$rows = array();
		foreach ( $query->get_results() as $id ) {
			$rows[] = self::customer( (int) $id, false );
		}
		$total = (int) $query->get_total();
		$data  = array(
			'customers' => $rows,
			'total'     => $total,
			'pages'     => (int) ceil( $total / $per_page ),
		);

		// Account-less club members ride along on the first page.
		if ( 1 === (int) $page && Elka_Pos_Klub::active() ) {
			global $wpdb;
			$table   = Elka_Club_Install::members_table();
			$members = $wpdb->get_results( "SELECT * FROM {$table} WHERE (user_id IS NULL OR user_id = 0) AND status = 'active' LIMIT 5000" ); // phpcs:ignore
			foreach ( (array) $members as $m ) {
				$data['customers'][] = self::member_as_customer( $m, false );
			}
		}
		return $data;
	}
}
