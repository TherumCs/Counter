<?php
/**
 * Shop — Cart Contents element.
 *
 * Renders the customer's current cart inline. Used inside the cart
 * template. Delegates to CartRenderer::contents() so styling stays
 * unified with the drawer.
 */

namespace Counter\Elements\Commerce;

use Counter\Elements\ControlBuilder;
use Counter\Elements\Element;
use Counter\Elements\ElementContext;
use Counter\Services\CartRenderer;
use Counter\Services\CartService;
use Counter\Services\CartTokenManager;

if ( ! defined( 'ABSPATH' ) ) exit;

final class CartContents implements Element {

	public function __construct(
		private readonly CartService $cart,
		private readonly CartRenderer $renderer,
		private readonly CartTokenManager $token,
	) {}

	public function id(): string       { return 'cart-contents'; }
	public function name(): string     { return 'Cart contents'; }
	public function category(): string { return 'commerce'; }
	public function icon(): string     { return 'shopping-bag'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		$cart = $this->cart->getOrCreate( $this->token->current() );
		return '<div class="counter-el counter-el-cart-contents">' . $this->renderer->contents( $cart ) . '</div>';
	}
}
