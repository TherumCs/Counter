<?php
/**
 * Counter by Therum — cart contents (inner template).
 *
 * The ONLY template that knows how to render line items + totals + actions.
 * Every shell wraps this; every REST response returns the output of this
 * for client-side morph updates.
 *
 * Variables in scope:
 *   $cart : Counter\Models\Cart
 *
 * Override:
 *   Copy to <theme>/shop/cart/contents.php
 *
 * Markup notes:
 *   - Every interactive surface has a `data-counter-*` attribute that cart.js
 *     listens for. Bricks (or any styling layer) can change classes, layout,
 *     and structure freely as long as the data attributes survive.
 *   - No inline event handlers. cart.js binds via delegation on the root.
 */

/** @var \Counter\Models\Cart $cart */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div
	class="counter-cart"
	data-counter-cart
	data-counter-cart-token="<?php echo esc_attr( $cart->token ); ?>"
	data-counter-cart-currency="<?php echo esc_attr( $cart->currency ); ?>"
	data-counter-cart-count="<?php echo esc_attr( (string) $cart->itemCount() ); ?>"
>

	<?php if ( $cart->isEmpty() ) : ?>

		<div class="counter-cart__empty" data-counter-cart-empty>
			<p class="counter-cart__empty-title"><?php esc_html_e( 'Your cart is empty.', 'counter' ); ?></p>
			<p class="counter-cart__empty-sub"><?php esc_html_e( 'Add something to get started.', 'counter' ); ?></p>
		</div>

	<?php else : ?>

		<ul class="counter-cart__items" data-counter-cart-items>
			<?php foreach ( $cart->items as $item ) : ?>
				<li
					class="counter-cart__item"
					data-counter-cart-item
					data-counter-cart-item-id="<?php echo esc_attr( (string) $item->id ); ?>"
				>
					<div class="counter-cart__item-info">
						<div class="counter-cart__item-title">
							<?php /* Title lookup comes when ProductRepository is exposed to templates;
							        for v1 the line carries no title yet — admin UI milestone supplies it. */ ?>
							<?php echo esc_html( sprintf(
								/* translators: %d = product ID */
								__( 'Product #%d', 'counter' ),
								$item->productId
							) ); ?>
							<?php if ( $item->variantId !== null ) : ?>
								<span class="counter-cart__item-variant">
									<?php echo esc_html( sprintf( __( 'Variant #%d', 'counter' ), $item->variantId ) ); ?>
								</span>
							<?php endif; ?>
						</div>
						<div class="counter-cart__item-price">
							<?php echo esc_html( $item->unitPrice->format() ); ?>
						</div>
					</div>

					<div class="counter-cart__item-controls">
						<div class="counter-cart__qty" role="group" aria-label="<?php esc_attr_e( 'Quantity', 'counter' ); ?>">
							<button
								type="button"
								class="counter-cart__qty-btn counter-cart__qty-dec"
								data-counter-cart-decrement
								aria-label="<?php esc_attr_e( 'Decrease quantity', 'counter' ); ?>"
							>−</button>
							<input
								type="number"
								class="counter-cart__qty-input"
								data-counter-cart-qty
								value="<?php echo esc_attr( (string) $item->quantity ); ?>"
								min="0"
								step="1"
								inputmode="numeric"
								aria-label="<?php esc_attr_e( 'Quantity', 'counter' ); ?>"
							/>
							<button
								type="button"
								class="counter-cart__qty-btn counter-cart__qty-inc"
								data-counter-cart-increment
								aria-label="<?php esc_attr_e( 'Increase quantity', 'counter' ); ?>"
							>+</button>
						</div>

						<button
							type="button"
							class="counter-cart__remove"
							data-counter-cart-remove
							aria-label="<?php esc_attr_e( 'Remove item', 'counter' ); ?>"
						>
							<?php esc_html_e( 'Remove', 'counter' ); ?>
						</button>

						<div class="counter-cart__line-total">
							<?php echo esc_html( $item->lineTotal->format() ); ?>
						</div>
					</div>
				</li>
			<?php endforeach; ?>
		</ul>

		<div class="counter-cart__summary" data-counter-cart-summary>
			<div class="counter-cart__row">
				<span><?php esc_html_e( 'Subtotal', 'counter' ); ?></span>
				<span data-counter-cart-subtotal><?php echo esc_html( $cart->subtotal->format() ); ?></span>
			</div>

			<?php if ( ! $cart->discountTotal->isZero() ) : ?>
				<div class="counter-cart__row counter-cart__row--discount">
					<span><?php esc_html_e( 'Discount', 'counter' ); ?></span>
					<span>−<?php echo esc_html( $cart->discountTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $cart->shippingTotal->isZero() ) : ?>
				<div class="counter-cart__row">
					<span><?php esc_html_e( 'Shipping', 'counter' ); ?></span>
					<span><?php echo esc_html( $cart->shippingTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<?php if ( ! $cart->taxTotal->isZero() ) : ?>
				<div class="counter-cart__row">
					<span><?php esc_html_e( 'Tax', 'counter' ); ?></span>
					<span><?php echo esc_html( $cart->taxTotal->format() ); ?></span>
				</div>
			<?php endif; ?>

			<div class="counter-cart__row counter-cart__row--total">
				<span><?php esc_html_e( 'Total', 'counter' ); ?></span>
				<span data-counter-cart-grand><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
			</div>
		</div>

		<div class="counter-cart__actions">
			<a
				class="counter-cart__checkout"
				href="<?php echo esc_url( home_url( '/checkout/' ) ); ?>"
				data-counter-cart-checkout
			>
				<?php esc_html_e( 'Checkout', 'counter' ); ?>
				<span class="counter-cart__checkout-total"><?php echo esc_html( $cart->grandTotal->format() ); ?></span>
			</a>
		</div>

	<?php endif; ?>

</div>
