<?php
/**
 * Shop — Cart button (header essential).
 *
 * Renders a button that opens the active cart drawer / popover. Counts
 * are wired via the existing shop:cartChange event so the count badge
 * stays live without a page reload.
 */

namespace Counter\Elements\Chrome;

use Counter\Elements\ControlBuilder;
use Counter\Elements\Element;
use Counter\Elements\ElementContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartButton implements Element {

	public function id(): string       { return 'cart-button'; }
	public function name(): string     { return 'Cart button'; }
	public function category(): string { return 'chrome'; }
	public function icon(): string     { return 'bag'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()
			->select( 'style', 'Style', [ 'pill' => 'Pill', 'icon' => 'Icon only', 'text' => 'Text + count' ], default: 'pill' )
			->text(   'label', 'Label', default: 'Cart' )
			->toggle( 'show_count', 'Show count badge', default: true )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$style = (string) ( $settings['style'] ?? 'pill' );
		$label = (string) ( $settings['label'] ?? 'Cart' );
		$badge = ! empty( $settings['show_count'] );

		$inner = '';
		if ( $style !== 'text' ) {
			$inner .= '<span class="counter-el-cartbtn__icon" aria-hidden="true">🛍</span>';
		}
		if ( $style !== 'icon' ) {
			$inner .= '<span class="counter-el-cartbtn__label">' . esc_html( $label ) . '</span>';
		}
		if ( $badge ) {
			$inner .= '<span class="counter-el-cartbtn__count" data-counter-cart-count>0</span>';
		}

		return '<button type="button" class="counter-el counter-el-cartbtn counter-el-cartbtn--' . esc_attr( $style ) . '" data-counter-cart-open>'
			. $inner
			. '</button>';
	}
}
