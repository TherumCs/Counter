<?php
/**
 * Counter by Therum — mini-cart shell.
 *
 * Header dropdown variant. Compact summary; click any item or footer to go
 * full drawer or /cart/ page. Hidden until cart icon hover or click.
 *
 * Variables in scope:
 *   $cart     : Counter\Models\Cart
 *   $contents : string
 *
 * Override:
 *   Copy to <theme>/shop/cart/shell-mini.php
 */

/** @var \Counter\Models\Cart $cart */
/** @var string $contents */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="counter-cart-mini"
	data-counter-cart-shell="mini"
	data-counter-cart-state="closed"
>
	<div class="counter-cart-mini__panel" data-counter-cart-mount>
		<?php echo $contents; // phpcs:ignore — pre-rendered, escaped at source ?>
	</div>
</div>
