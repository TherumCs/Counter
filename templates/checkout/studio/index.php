<?php
/**
 * Counter by Therum — Studio checkout pattern.
 *
 * The unified payment-strip checkout from checkout-experience.html.
 * Pulls available payment methods from Studio Pay at boot, renders
 * grouped pills (Card / Wallets / BNPL / Bank / Crypto / P2P), and
 * shows the matching panel underneath.
 *
 * Server-rendered shell + tiny ~3KB inline JS — no Preact, no React.
 * The method strip is the only interactive piece on the page.
 *
 * Variables available in scope (from CheckoutRenderer):
 *   $cart        — Cart DTO
 *   $totals      — pre-computed totals (subtotal, shipping, tax, grand)
 *   $rest_url    — REST root (for JS to call /studio-pay/methods)
 *   $nonce       — REST nonce
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="counter-checkout counter-checkout--studio" data-rest="<?php echo esc_url( $rest_url ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
	<div class="counter-checkout__layout">
		<form class="counter-checkout__form" id="counter-checkout-form">
			<section class="counter-checkout__section" data-section="info">
				<header class="counter-checkout__section-head">
					<h2><span class="counter-checkout__section-num">1</span> Your info</h2>
				</header>
				<div class="counter-checkout__row counter-checkout__row--1">
					<label>Email
						<input class="counter-checkout__input" type="email" name="email" required autocomplete="email">
					</label>
				</div>
				<div class="counter-checkout__row counter-checkout__row--1">
					<label>Ship to
						<input class="counter-checkout__input" name="ship_line1" placeholder="Street address" autocomplete="shipping address-line1">
					</label>
				</div>
				<div class="counter-checkout__row">
					<input class="counter-checkout__input" name="ship_line2" placeholder="Apt, suite (optional)" autocomplete="shipping address-line2">
					<input class="counter-checkout__input" name="ship_city" placeholder="City" autocomplete="shipping address-level2">
				</div>
				<div class="counter-checkout__row counter-checkout__row--3">
					<input class="counter-checkout__input" name="ship_state" placeholder="State" autocomplete="shipping address-level1">
					<input class="counter-checkout__input" name="ship_postal_code" placeholder="ZIP" autocomplete="shipping postal-code" maxlength="10">
					<input class="counter-checkout__input" name="ship_country" placeholder="Country" value="US" autocomplete="shipping country">
				</div>
			</section>

			<section class="counter-checkout__section" data-section="shipping">
				<header class="counter-checkout__section-head">
					<h2><span class="counter-checkout__section-num">2</span> Shipping</h2>
					<span class="counter-checkout__section-status">Auto-calculated</span>
				</header>
				<div class="counter-checkout__ship-list" data-counter-shipping-list>
					<!-- Populated by JS from /admin/checkout/shipping-options or server-rendered. -->
					<label class="counter-checkout__ship-row is-active">
						<input type="radio" name="shipping" value="standard" checked hidden>
						<div>
							<div class="counter-checkout__ship-name">Standard</div>
							<div class="counter-checkout__ship-sub">5–7 business days</div>
						</div>
						<div class="counter-checkout__ship-price">Free</div>
					</label>
				</div>
			</section>

			<section class="counter-checkout__section" data-section="payment">
				<header class="counter-checkout__section-head">
					<h2><span class="counter-checkout__section-num">3</span> Payment</h2>
					<span class="counter-checkout__section-status">Pick a method</span>
				</header>

				<!-- Method strip — populated by JS from /studio-pay/methods -->
				<div class="counter-checkout__methods" data-counter-methods role="tablist">
					<div class="counter-checkout__methods-loading">Loading payment options…</div>
				</div>

				<!-- Method panels — JS swaps the visible one based on the active pill -->
				<div class="counter-checkout__panels" data-counter-panels></div>
			</section>
		</form>

		<aside class="counter-checkout__summary">
			<h3>Order summary</h3>
			<div class="counter-checkout__line-items">
				<?php foreach ( $cart->items as $item ) :
					// Title + variant label live in $item->meta — CartItem
					// keeps presentation snapshots there so the cart can
					// render without re-querying the product.
					$title = (string) ( $item->meta['title']         ?? 'Item' );
					$vlbl  = (string) ( $item->meta['variant_label'] ?? '' );
				?>
					<div class="counter-checkout__line-item">
						<div class="counter-checkout__li-img">
							<?php echo esc_html( mb_strtoupper( mb_substr( $title, 0, 1 ) ) ); ?>
							<span class="counter-checkout__li-qty"><?php echo esc_html( (string) $item->quantity ); ?></span>
						</div>
						<div style="flex:1">
							<div class="counter-checkout__li-name"><?php echo esc_html( $title ); ?></div>
							<?php if ( $vlbl !== '' ) : ?>
								<div class="counter-checkout__li-meta"><?php echo esc_html( $vlbl ); ?></div>
							<?php endif; ?>
						</div>
						<div class="counter-checkout__li-price">
							<?php echo esc_html( '$' . number_format( $item->lineTotal->amount / 100, 2 ) ); ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="counter-checkout__totals">
				<div class="counter-checkout__totals-row"><span>Subtotal</span><span data-counter-subtotal>
					<?php echo esc_html( '$' . number_format( $totals['subtotal'] / 100, 2 ) ); ?>
				</span></div>
				<div class="counter-checkout__totals-row"><span>Shipping</span><span data-counter-shipping>
					<?php echo $totals['shipping'] ? esc_html( '$' . number_format( $totals['shipping'] / 100, 2 ) ) : 'Free'; ?>
				</span></div>
				<div class="counter-checkout__totals-row"><span>Tax (est.)</span><span data-counter-tax>
					<?php echo esc_html( '$' . number_format( $totals['tax'] / 100, 2 ) ); ?>
				</span></div>
				<div class="counter-checkout__totals-row counter-checkout__totals-row--total"><span>Total</span><span data-counter-total>
					<?php echo esc_html( '$' . number_format( $totals['grand'] / 100, 2 ) ); ?>
				</span></div>
			</div>

			<button class="counter-checkout__pay" type="submit" form="counter-checkout-form" data-counter-pay>
				Pay <?php echo esc_html( '$' . number_format( $totals['grand'] / 100, 2 ) ); ?>
			</button>

			<div class="counter-checkout__security">
				<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
				Encrypted end-to-end · No card data stored
			</div>
		</aside>
	</div>
</div>
