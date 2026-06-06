<?php
/**
 * Shop — Order Received element.
 *
 * Reads ?order=SH-XXX from the URL and renders the order-received
 * template. Delegates to the existing templates/order-received.php so
 * styling stays unified.
 */

namespace Counter\Elements\Commerce;

use Counter\Elements\ControlBuilder;
use Counter\Elements\Element;
use Counter\Elements\ElementContext;
use Counter\Repositories\OrderRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class OrderReceived implements Element {

	public function __construct( private readonly OrderRepository $orders ) {}

	public function id(): string       { return 'order-received'; }
	public function name(): string     { return 'Order received'; }
	public function category(): string { return 'commerce'; }
	public function icon(): string     { return 'check-circle'; }
	public function needsJs(): bool    { return false; }

	public function controls(): array {
		return ControlBuilder::make()->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$number = isset( $_GET['order'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['order'] ) ) : '';
		$order  = $number !== '' ? $this->orders->findByNumber( $number ) : null;

		$paths = [
			get_stylesheet_directory() . '/shop/order-received.php',
			get_template_directory()   . '/shop/order-received.php',
			COUNTER_DIR . 'templates/order-received.php',
		];
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				ob_start();
				include $path;
				return '<div class="counter-el counter-el-order-received">' . (string) ob_get_clean() . '</div>';
			}
		}
		return '';
	}
}
