<?php
/**
 * Counter by Therum — page shell.
 *
 * Standalone /cart/ page contents. No drawer, no backdrop — just the cart
 * inside its container, ready for a theme/Bricks layout to wrap it.
 *
 * Variables in scope:
 *   $cart     : Counter\Models\Cart
 *   $contents : string  (already-rendered contents.php HTML)
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-page.php
 */

/** @var \Counter\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="counter-cart-page" data-counter-cart-shell="page" data-counter-cart-mount>
	<header class="counter-cart-page__header">
		<h1 class="counter-cart-page__title">
			<?php esc_html_e( 'Your cart', 'counter' ); ?>
		</h1>
	</header>

	<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
</section>
