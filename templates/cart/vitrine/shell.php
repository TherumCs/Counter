<?php
/**
 * Vitrine cart — modal shell (stub). Full port from preview/vitrine.html
 * lands next chunk.
 *
 * @var \Counter\Models\Cart $cart
 * @var string $contents
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="counter-vitrine" data-counter-cart-shell="modal" data-counter-cart-state="closed" role="dialog" aria-modal="true" aria-hidden="true">
	<div class="counter-vitrine__backdrop" data-counter-cart-close></div>
	<div class="counter-vitrine__dialog" data-counter-cart-mount>
		<?php echo $contents; // phpcs:ignore ?>
	</div>
</div>
