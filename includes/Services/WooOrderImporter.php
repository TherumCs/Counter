<?php
/**
 * Counter by Therum — WooCommerce order importer.
 *
 * One-time import of existing WooCommerce orders into Counter's SQLite
 * database so they appear in the Counter admin orders grid.
 *
 * Converts WC_Order to Counter order format: products, totals, addresses,
 * payment status, etc.
 */

namespace Counter\Services;

use Counter\DB;
use Counter\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class WooOrderImporter {

	public function importFromWooCommerce(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$pdo = DB::pdo();
		$imported = 0;

		// Fetch all WooCommerce orders (no pagination)
		$woo_orders = wc_get_orders( [ 'limit' => -1 ] );

		foreach ( $woo_orders as $wc_order ) {
			try {
				$this->importOrder( $pdo, $wc_order );
				$imported++;
			} catch ( \Throwable $e ) {
				// Log but continue on individual order errors
				error_log( 'Counter WooOrder import error: ' . $e->getMessage() );
			}
		}

		return $imported;
	}

	/**
	 * Import a single WooCommerce order into Counter.
	 *
	 * @param \PDO $pdo
	 * @param \WC_Order $wc_order
	 */
	private function importOrder( \PDO $pdo, \WC_Order $wc_order ): void {
		// Skip if already imported (by order number)
		$stmt = $pdo->prepare( 'SELECT id FROM orders WHERE number = :num' );
		$stmt->execute( [ ':num' => (string) $wc_order->get_id() ] );
		if ( $stmt->fetch() ) {
			return; // Already imported
		}

		// Map WooCommerce status to Counter status
		$status_map = [
			'pending'    => 'pending',
			'processing' => 'processing',
			'completed'  => 'completed',
			'cancelled'  => 'cancelled',
			'refunded'   => 'refunded',
			'failed'     => 'failed',
		];
		$wc_status = $wc_order->get_status();
		$status = $status_map[ $wc_status ] ?? 'processing';

		// Gather WooCommerce data
		$order_number = (string) $wc_order->get_id();
		$user_id = $wc_order->get_customer_id() ?: null;
		$email = $wc_order->get_billing_email() ?: 'unknown@invalid';
		$currency = $wc_order->get_currency() ?: 'USD';

		// All amounts in cents
		$subtotal = (int) ( $wc_order->get_subtotal() * 100 );
		$shipping = (int) ( $wc_order->get_shipping_total() * 100 );
		$tax = (int) ( $wc_order->get_total_tax() * 100 );
		$discount = (int) ( $wc_order->get_discount_total() * 100 );
		$total = (int) ( $wc_order->get_total() * 100 );
		$refunded = (int) ( $wc_order->get_total_refunded() * 100 );

		// Addresses as JSON
		$billing_address = $this->buildAddress(
			$wc_order->get_billing_first_name(),
			$wc_order->get_billing_last_name(),
			$wc_order->get_billing_address_1(),
			$wc_order->get_billing_address_2(),
			$wc_order->get_billing_city(),
			$wc_order->get_billing_state(),
			$wc_order->get_billing_postcode(),
			$wc_order->get_billing_country()
		);
		$shipping_address = $this->buildAddress(
			$wc_order->get_shipping_first_name(),
			$wc_order->get_shipping_last_name(),
			$wc_order->get_shipping_address_1(),
			$wc_order->get_shipping_address_2(),
			$wc_order->get_shipping_city(),
			$wc_order->get_shipping_state(),
			$wc_order->get_shipping_postcode(),
			$wc_order->get_shipping_country()
		);

		// Payment info
		$payment_method = $wc_order->get_payment_method() ?: 'woocommerce';
		$paid_at = null;
		if ( $wc_order->is_paid() ) {
			$paid_date = $wc_order->get_date_paid();
			$paid_at = $paid_date ? $paid_date->getTimestamp() : time();
		}

		// Insert into Counter orders table
		$stmt = $pdo->prepare( <<<SQL
			INSERT INTO orders (
				number, user_id, email, currency, status,
				subtotal, shipping_total, tax_total, discount_total, grand_total, refunded_total,
				bill_address, ship_address,
				payment_provider, payment_method, paid_at,
				created_at, updated_at
			) VALUES (
				:number, :user_id, :email, :currency, :status,
				:subtotal, :shipping, :tax, :discount, :total, :refunded,
				:bill_addr, :ship_addr,
				:provider, :method, :paid_at,
				:created, :updated
			)
		SQL );

		$stmt->execute( [
			':number'   => $order_number,
			':user_id'  => $user_id,
			':email'    => $email,
			':currency' => $currency,
			':status'   => $status,
			':subtotal' => $subtotal,
			':shipping' => $shipping,
			':tax'      => $tax,
			':discount' => $discount,
			':total'    => $total,
			':refunded' => $refunded,
			':bill_addr' => $billing_address,
			':ship_addr' => $shipping_address,
			':provider' => 'woocommerce',
			':method'   => $payment_method,
			':paid_at'  => $paid_at,
			':created'  => time(),
			':updated'  => time(),
		] );

		// Import order items (products)
		$order_id = $pdo->lastInsertId();
		foreach ( $wc_order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) continue;

			$product = $item->get_product();
			if ( ! $product ) continue;

			$item_stmt = $pdo->prepare( <<<SQL
				INSERT INTO order_items (
					order_id, product_id, product_title, sku,
					quantity, unit_price, line_total,
					created_at, updated_at
				) VALUES (
					:order_id, :product_id, :title, :sku,
					:qty, :unit_price, :total,
					:created, :updated
				)
			SQL );

			$item_stmt->execute( [
				':order_id'   => $order_id,
				':product_id' => $product->get_id(),
				':title'      => $item->get_name(),
				':sku'        => $product->get_sku() ?: '',
				':qty'        => (int) $item->get_quantity(),
				':unit_price' => (int) ( $item->get_total() / $item->get_quantity() * 100 ),
				':total'      => (int) ( $item->get_total() * 100 ),
				':created'    => time(),
				':updated'    => time(),
			] );
		}
	}

	/**
	 * Build address JSON object from WooCommerce fields.
	 */
	private function buildAddress(
		string $first,
		string $last,
		string $line1,
		string $line2,
		string $city,
		string $state,
		string $postal,
		string $country
	): string {
		$addr = [
			'name'    => trim( "$first $last" ) ?: 'Unknown',
			'line1'   => $line1 ?: null,
			'line2'   => $line2 ?: null,
			'city'    => $city ?: null,
			'state'   => $state ?: null,
			'postal'  => $postal ?: null,
			'country' => $country ?: null,
		];

		// Remove nulls, keep structure compact
		$addr = array_filter( $addr, fn( $v ) => $v !== null );

		return wp_json_encode( $addr );
	}
}
