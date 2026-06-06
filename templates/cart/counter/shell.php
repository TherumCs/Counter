<?php
/**
 * Counter cart — page shell (stub). Full port from preview/counter.html
 * lands next chunk.
 *
 * @var \Counter\Models\Cart $cart
 * @var string $contents
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="counter-counter" data-counter-cart-shell="page" data-counter-cart-mount>
	<header class="counter-counter__header">
		<h1 class="counter-counter__title"><?php esc_html_e( 'Shopping Cart', 'counter' ); ?></h1>
	</header>
	<?php echo $contents; // phpcs:ignore ?>
</section>
