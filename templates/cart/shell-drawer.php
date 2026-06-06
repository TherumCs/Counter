<?php
/**
 * Counter by Therum — drawer shell.
 *
 * Slide-in from the right. Includes a backdrop and a close button. Hidden
 * by default; cart.js toggles `is-open`. Focus-trapped while open.
 *
 * Variables in scope:
 *   $cart     : Counter\Models\Cart
 *   $contents : string  (already-rendered contents.php HTML)
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-drawer.php
 */

/** @var \Counter\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="counter-cart-drawer"
	data-counter-cart-shell="drawer"
	data-counter-cart-state="closed"
	role="dialog"
	aria-modal="true"
	aria-labelledby="counter-cart-drawer-title"
	aria-hidden="true"
>
	<div class="counter-cart-drawer__backdrop" data-counter-cart-close></div>

	<aside class="counter-cart-drawer__panel" role="document">
		<header class="counter-cart-drawer__header">
			<h2 id="counter-cart-drawer-title" class="counter-cart-drawer__title">
				<?php esc_html_e( 'Cart', 'counter' ); ?>
				<span class="counter-cart-drawer__count" data-counter-cart-count-label>
					<?php echo esc_html( (string) $cart->itemCount() ); ?>
				</span>
			</h2>
			<button
				type="button"
				class="counter-cart-drawer__close"
				data-counter-cart-close
				aria-label="<?php esc_attr_e( 'Close cart', 'counter' ); ?>"
			>×</button>
		</header>

		<div class="counter-cart-drawer__body" data-counter-cart-mount>
			<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
		</div>
	</aside>
</div>
