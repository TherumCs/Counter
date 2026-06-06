<?php
/**
 * Order received — post-payment confirmation page.
 *
 * Rendered by the [shop_order_received] shortcode. Reads the order ID from
 * a query var (`?order=SH-...`) and looks it up; if absent, renders the
 * empty / generic-thanks state.
 *
 * Variables in scope:
 *   $order : Counter\Models\Order|null
 *
 * Override: copy to <theme>/shop/order-received.php
 */

/** @var \Counter\Models\Order|null $order */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<section class="counter-received">
	<?php if ( $order === null ) : ?>

		<div class="counter-received__empty">
			<h1><?php esc_html_e( 'Order confirmed', 'counter' ); ?></h1>
			<p><?php esc_html_e( 'Thanks for your purchase. A receipt is on its way.', 'counter' ); ?></p>
		</div>

	<?php else : ?>

		<header class="counter-received__head">
			<div class="counter-received__check">
				<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
			</div>
			<h1><?php esc_html_e( 'Thanks — your order is in', 'counter' ); ?></h1>
			<p class="counter-received__sub">
				<?php /* translators: 1: order number, 2: customer email */
				printf(
					esc_html__( 'Order %1$s · receipt sent to %2$s', 'counter' ),
					esc_html( $order->number ),
					esc_html( $order->email )
				); ?>
			</p>
		</header>

		<div class="counter-received__detail">
			<h2><?php esc_html_e( 'Items', 'counter' ); ?></h2>
			<ul class="counter-received__items">
				<?php foreach ( $order->items as $item ) : ?>
					<li class="counter-received__item">
						<span class="counter-received__item-qty"><?php echo esc_html( (string) $item->quantity ); ?>&times;</span>
						<span class="counter-received__item-title"><?php echo esc_html( $item->title ); ?></span>
						<span class="counter-received__item-price"><?php echo esc_html( $item->lineTotal->format() ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<dl class="counter-received__totals">
				<div><dt><?php esc_html_e( 'Subtotal', 'counter' ); ?></dt><dd><?php echo esc_html( $order->subtotal->format() ); ?></dd></div>
				<?php if ( ! $order->shippingTotal->isZero() ) : ?>
					<div><dt><?php esc_html_e( 'Shipping', 'counter' ); ?></dt><dd><?php echo esc_html( $order->shippingTotal->format() ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! $order->taxTotal->isZero() ) : ?>
					<div><dt><?php esc_html_e( 'Tax', 'counter' ); ?></dt><dd><?php echo esc_html( $order->taxTotal->format() ); ?></dd></div>
				<?php endif; ?>
				<?php if ( ! $order->discountTotal->isZero() ) : ?>
					<div><dt><?php esc_html_e( 'Discount', 'counter' ); ?></dt><dd>&minus;<?php echo esc_html( $order->discountTotal->format() ); ?></dd></div>
				<?php endif; ?>
				<div class="counter-received__totals-total">
					<dt><?php esc_html_e( 'Total', 'counter' ); ?></dt>
					<dd><?php echo esc_html( $order->grandTotal->format() ); ?></dd>
				</div>
			</dl>
		</div>

		<footer class="counter-received__foot">
			<p>
				<?php esc_html_e( 'We’ll send shipping updates as your order moves.', 'counter' ); ?>
			</p>
		</footer>

	<?php endif; ?>
</section>
