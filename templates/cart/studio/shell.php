<?php
/**
 * Studio cart — drawer shell.
 *
 * Slide-in drawer. Houses a single panel that can transition between cart
 * view and in-drawer checkout stages (added in v0.4 via `data-counter-stage`).
 *
 * Variables in scope:
 *   $cart     : Counter\Models\Cart
 *   $contents : string  (pre-rendered contents.php HTML)
 *
 * Override: copy to <theme>/shop/cart/studio/shell.php
 */

/** @var \Counter\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="counter-studio-drawer"
	data-counter-cart-shell="drawer"
	data-counter-cart-state="closed"
	data-counter-stage="cart"
	role="dialog"
	aria-modal="true"
	aria-labelledby="counter-studio-drawer-title"
	aria-hidden="true"
>
	<div class="counter-studio-drawer__backdrop" data-counter-cart-close></div>

	<aside class="counter-studio-drawer__panel" role="document">
		<header class="counter-studio-drawer__header">
			<h2 id="counter-studio-drawer-title" class="counter-studio-drawer__title">
				<?php esc_html_e( 'Your Cart', 'counter' ); ?>
				<span class="counter-studio-drawer__count" data-counter-cart-count-label>
					<?php echo esc_html( (string) $cart->itemCount() ); ?>
				</span>
			</h2>
			<button
				type="button"
				class="counter-studio-drawer__close"
				data-counter-cart-close
				aria-label="<?php esc_attr_e( 'Close cart', 'counter' ); ?>"
			>&times;</button>
		</header>

		<div class="counter-studio-drawer__body" data-counter-cart-mount>
			<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
		</div>
	</aside>
</div>
