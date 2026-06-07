<?php
/**
 * Counter by Therum — Comprehensive WooCommerce importer.
 *
 * One-click import of everything from WooCommerce:
 * - Products (with variants, attributes, pricing, images)
 * - Customers
 * - Orders (with items)
 *
 * After import, you can safely delete WooCommerce.
 */

namespace Counter\Services;

use Counter\DB;

if ( ! defined( 'ABSPATH' ) ) exit;

final class ComprehensiveWooImporter {

	public function importEverything(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [
				'success' => false,
				'message' => 'WooCommerce not detected',
			];
		}

		try {
			$pdo = DB::pdo();
			$pdo->beginTransaction();

			$stats = [
				'products'   => 0,
				'variants'   => 0,
				'attributes' => 0,
				'customers'  => 0,
				'orders'     => 0,
				'order_items' => 0,
			];

			// Import in dependency order
			$stats['customers'] = $this->importCustomers( $pdo );
			$stats['attributes'] = $this->importAttributes( $pdo );
			$stats['products'] = $this->importProducts( $pdo );
			$stats['variants'] = $this->importVariants( $pdo );
			$stats['orders'] = $this->importOrders( $pdo );
			$stats['order_items'] = $this->importOrderItems( $pdo );

			$pdo->commit();

			return [
				'success' => true,
				'message' => "Imported {$stats['products']} products, {$stats['customers']} customers, {$stats['orders']} orders",
				'stats'   => $stats,
			];
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}

	private function importCustomers( \PDO $pdo ): int {
		$users = get_users( [ 'number' => -1 ] );
		$count = 0;

		foreach ( $users as $user ) {
			$stmt = $pdo->prepare( 'SELECT id FROM customers WHERE wp_user_id = :uid' );
			$stmt->execute( [ ':uid' => $user->ID ] );
			if ( $stmt->fetch() ) continue;

			$wc_customer = function_exists( 'wc_get_customer' ) ? wc_get_customer( $user->ID ) : null;

			$phone = '';
			$addr1 = '';
			$addr2 = '';
			$city = '';
			$state = '';
			$postal = '';
			$country = '';

			if ( $wc_customer ) {
				$phone = $wc_customer->get_billing_phone() ?: '';
				$addr1 = $wc_customer->get_billing_address_1() ?: '';
				$addr2 = $wc_customer->get_billing_address_2() ?: '';
				$city = $wc_customer->get_billing_city() ?: '';
				$state = $wc_customer->get_billing_state() ?: '';
				$postal = $wc_customer->get_billing_postcode() ?: '';
				$country = $wc_customer->get_billing_country() ?: '';
			}

			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::generateUUID();

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO customers (
					uuid, wp_user_id, email, first_name, last_name,
					phone, accepts_marketing,
					address_line1, address_line2, city, state, postal_code, country,
					tags
				) VALUES (
					:uuid, :uid, :email, :first, :last,
					:phone, :marketing,
					:addr1, :addr2, :city, :state, :postal, :country,
					:tags
				)
			SQL );

			$stmt->execute( [
				':uuid'      => $uuid,
				':uid'       => $user->ID,
				':email'     => $user->user_email,
				':first'     => $user->first_name ?: '',
				':last'      => $user->last_name ?: '',
				':phone'     => $phone,
				':marketing' => ( $wc_customer && $wc_customer->is_paying_customer() ? 1 : 0 ),
				':addr1'     => $addr1,
				':addr2'     => $addr2,
				':city'      => $city,
				':state'     => $state,
				':postal'    => $postal,
				':country'   => $country,
				':tags'      => '[]',
			] );

			$count++;
		}

		return $count;
	}

	private function importAttributes( \PDO $pdo ): int {
		$wc_attrs = wc_get_attribute_taxonomies();
		$count = 0;

		foreach ( $wc_attrs as $attr ) {
			$stmt = $pdo->prepare( 'SELECT id FROM attributes WHERE slug = :slug' );
			$stmt->execute( [ ':slug' => $attr->attribute_name ] );
			if ( $stmt->fetch() ) continue;

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO attributes (slug, name, type)
				VALUES (:slug, :name, :type)
			SQL );

			$stmt->execute( [
				':slug' => $attr->attribute_name,
				':name' => $attr->attribute_label,
				':type' => $attr->attribute_type ?: 'select',
			] );

			$attr_id = $pdo->lastInsertId();

			// Import attribute terms/values
			if ( function_exists( 'wc_attribute_taxonomy_name' ) ) {
				$taxonomy = wc_attribute_taxonomy_name( $attr->attribute_name );
				$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );

				if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
					foreach ( $terms as $term ) {
						$check = $pdo->prepare( 'SELECT id FROM attribute_values WHERE attribute_id = :attr_id AND slug = :slug' );
						$check->execute( [ ':attr_id' => $attr_id, ':slug' => $term->slug ] );
						if ( $check->fetch() ) continue;

						$val_stmt = $pdo->prepare( <<<SQL
							INSERT INTO attribute_values (attribute_id, slug, value, position)
							VALUES (:attr_id, :slug, :value, 0)
						SQL );

						$val_stmt->execute( [
							':attr_id' => $attr_id,
							':slug'    => $term->slug,
							':value'   => $term->name,
						] );
					}
				}
			}

			$count++;
		}

		return $count;
	}

	private function importProducts( \PDO $pdo ): int {
		$wc_products = wc_get_products( [ 'limit' => -1, 'type' => [ 'simple', 'variable' ] ] );
		$count = 0;

		foreach ( $wc_products as $wc_prod ) {
			$stmt = $pdo->prepare( 'SELECT id FROM products WHERE slug = :slug' );
			$stmt->execute( [ ':slug' => $wc_prod->get_slug() ] );
			if ( $stmt->fetch() ) continue;

			$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::generateUUID();
			$is_variable = $wc_prod->is_type( 'variable' ) ? 1 : 0;

			// Variable products don't have get_cost(); only simple products do
			$cost = 0;
			if ( method_exists( $wc_prod, 'get_cost' ) ) {
				$cost_val = $wc_prod->get_cost();
				$cost = $cost_val ? (int) ( $cost_val * 100 ) : 0;
			}

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO products (
					uuid, slug, title, description, status,
					has_variants, is_shippable,
					price, sku, stock_qty, cost
				) VALUES (
					:uuid, :slug, :title, :desc, :status,
					:has_variants, 1,
					:price, :sku, :stock, :cost
				)
			SQL );

			$stmt->execute( [
				':uuid'        => $uuid,
				':slug'        => $wc_prod->get_slug(),
				':title'       => $wc_prod->get_name(),
				':desc'        => $wc_prod->get_description() ?: '',
				':status'      => $wc_prod->get_status() === 'publish' ? 'active' : 'draft',
				':has_variants' => $is_variable,
				':price'       => (int) ( $wc_prod->get_price() * 100 ),
				':sku'         => $wc_prod->get_sku() ?: '',
				':stock'       => max( 0, (int) $wc_prod->get_stock_quantity() ),
				':cost'        => $cost,
			] );

			$count++;
		}

		return $count;
	}

	private function importVariants( \PDO $pdo ): int {
		$wc_products = wc_get_products( [ 'limit' => -1, 'type' => 'variable' ] );
		$count = 0;

		foreach ( $wc_products as $wc_var_prod ) {
			$stmt = $pdo->prepare( 'SELECT id FROM products WHERE slug = :slug' );
			$stmt->execute( [ ':slug' => $wc_var_prod->get_slug() ] );
			$prod_row = $stmt->fetch();
			if ( ! $prod_row ) continue;

			$prod_id = $prod_row['id'];

			foreach ( $wc_var_prod->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) continue;

				$stmt = $pdo->prepare( 'SELECT id FROM product_variants WHERE sku = :sku' );
				$stmt->execute( [ ':sku' => $child->get_sku() ] );
				if ( $stmt->fetch() ) continue;

				$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::generateUUID();

				// Child products should have get_cost() method
				$cost = 0;
				if ( method_exists( $child, 'get_cost' ) ) {
					$cost_val = $child->get_cost();
					$cost = $cost_val ? (int) ( $cost_val * 100 ) : 0;
				}

				$insert = $pdo->prepare( <<<SQL
					INSERT INTO product_variants (
						uuid, product_id, sku, price, cost, stock_qty, enabled
					) VALUES (
						:uuid, :prod_id, :sku, :price, :cost, :stock, 1
					)
				SQL );

				$insert->execute( [
					':uuid'     => $uuid,
					':prod_id'  => $prod_id,
					':sku'      => $child->get_sku() ?: '',
					':price'    => (int) ( $child->get_price() * 100 ),
					':cost'     => $cost,
					':stock'    => max( 0, (int) $child->get_stock_quantity() ),
				] );

				$count++;
			}
		}

		return $count;
	}

	private function importOrders( \PDO $pdo ): int {
		$wc_orders = wc_get_orders( [ 'limit' => -1 ] );
		$count = 0;

		$status_map = [
			'pending'    => 'pending',
			'processing' => 'processing',
			'completed'  => 'completed',
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
			'failed'     => 'failed',
		];

		foreach ( $wc_orders as $wc_order ) {
			$stmt = $pdo->prepare( 'SELECT id FROM orders WHERE number = :number' );
			$stmt->execute( [ ':number' => (string) $wc_order->get_id() ] );
			if ( $stmt->fetch() ) continue;

			$wc_status = $wc_order->get_status();
			$status = $status_map[ $wc_status ] ?? 'processing';

			$paid_at = $wc_order->is_paid() ? ( $wc_order->get_date_paid() ? $wc_order->get_date_paid()->getTimestamp() : time() ) : null;

			$bill_addr = [
				'name'    => trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() ),
				'line1'   => $wc_order->get_billing_address_1(),
				'city'    => $wc_order->get_billing_city(),
				'state'   => $wc_order->get_billing_state(),
				'postal'  => $wc_order->get_billing_postcode(),
				'country' => $wc_order->get_billing_country(),
			];

			$ship_addr = [
				'name'    => trim( $wc_order->get_shipping_first_name() . ' ' . $wc_order->get_shipping_last_name() ),
				'line1'   => $wc_order->get_shipping_address_1(),
				'city'    => $wc_order->get_shipping_city(),
				'state'   => $wc_order->get_shipping_state(),
				'postal'  => $wc_order->get_shipping_postcode(),
				'country' => $wc_order->get_shipping_country(),
			];

			$stmt = $pdo->prepare( <<<SQL
				INSERT INTO orders (
					number, user_id, email, currency, status,
					subtotal, shipping_total, tax_total, discount_total, grand_total, refunded_total,
					bill_address, ship_address,
					payment_provider, payment_method, paid_at
				) VALUES (
					:number, :user_id, :email, :currency, :status,
					:subtotal, :shipping, :tax, :discount, :total, :refunded,
					:bill_addr, :ship_addr,
					:provider, :method, :paid_at
				)
			SQL );

			$stmt->execute( [
				':number'    => (string) $wc_order->get_id(),
				':user_id'   => $wc_order->get_customer_id() ?: null,
				':email'     => $wc_order->get_billing_email(),
				':currency'  => $wc_order->get_currency(),
				':status'    => $status,
				':subtotal'  => (int) ( $wc_order->get_subtotal() * 100 ),
				':shipping'  => (int) ( $wc_order->get_shipping_total() * 100 ),
				':tax'       => (int) ( $wc_order->get_total_tax() * 100 ),
				':discount'  => (int) ( $wc_order->get_discount_total() * 100 ),
				':total'     => (int) ( $wc_order->get_total() * 100 ),
				':refunded'  => (int) ( $wc_order->get_total_refunded() * 100 ),
				':bill_addr' => wp_json_encode( array_filter( $bill_addr ) ),
				':ship_addr' => wp_json_encode( array_filter( $ship_addr ) ),
				':provider'  => 'woocommerce',
				':method'    => $wc_order->get_payment_method() ?: 'woocommerce',
				':paid_at'   => $paid_at,
			] );

			$count++;
		}

		return $count;
	}

	private function importOrderItems( \PDO $pdo ): int {
		$wc_orders = wc_get_orders( [ 'limit' => -1 ] );
		$count = 0;

		foreach ( $wc_orders as $wc_order ) {
			$stmt = $pdo->prepare( 'SELECT id FROM orders WHERE number = :number' );
			$stmt->execute( [ ':number' => (string) $wc_order->get_id() ] );
			$order_row = $stmt->fetch();
			if ( ! $order_row ) continue;

			$order_id = $order_row['id'];

			foreach ( $wc_order->get_items() as $item ) {
				if ( ! ( $item instanceof \WC_Order_Item_Product ) ) continue;

				$product = $item->get_product();
				if ( ! $product ) continue;

				$qty = (int) $item->get_quantity();
				if ( $qty < 1 ) continue;

				$stmt = $pdo->prepare( 'SELECT id FROM products WHERE slug = :slug' );
				$stmt->execute( [ ':slug' => $product->get_slug() ] );
				$prod_row = $stmt->fetch();
				$prod_id = $prod_row ? $prod_row['id'] : null;

				$unit_price = (int) ( ( $item->get_total() / $qty ) * 100 );
				$line_total = (int) ( $item->get_total() * 100 );

				$i_stmt = $pdo->prepare( <<<SQL
					INSERT INTO order_items (
						order_id, product_id, title, sku,
						quantity, unit_price, line_total
					) VALUES (
						:order_id, :product_id, :title, :sku,
						:qty, :unit_price, :total
					)
				SQL );

				$i_stmt->execute( [
					':order_id'   => $order_id,
					':product_id' => $prod_id,
					':title'      => $item->get_name(),
					':sku'        => $product->get_sku() ?: '',
					':qty'        => $qty,
					':unit_price' => $unit_price,
					':total'      => $line_total,
				] );

				$count++;
			}
		}

		return $count;
	}

	private static function generateUUID(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}
