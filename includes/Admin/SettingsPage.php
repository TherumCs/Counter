<?php
/**
 * Counter by Therum — admin Settings page.
 *
 * Native WP admin (no React/no app). Renders the option fields that
 * `register_setting()` already declared in shop.php. The form posts to
 * options.php — WP's built-in handler — so we get sanitization +
 * nonce verification for free.
 *
 * Sections:
 *   Cart experience    — presentation + button position
 *   Checkout           — presentation
 *   Catalog source     — native vs Woo
 */

namespace Counter\Admin;

use Counter\Services\CartRenderer;
use Counter\Services\CheckoutRenderer;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SettingsPage {

	public function render(): void {
		$cart_present     = (string) get_option( 'counter_cart_presentation',     CartRenderer::MODE_STUDIO );
		$checkout_present = (string) get_option( 'counter_checkout_presentation', CheckoutRenderer::MODE_CLASSIC );
		$button_pos       = (string) get_option( 'counter_cart_button_position',  'bottom-right' );
		$product_source   = (string) get_option( 'counter_product_source',        'native' );
		$woo_detected     = function_exists( 'wc_get_product' );

		?>
		<div class="wrap counter-admin">

			<h1 class="counter-admin__title">
				<span class="counter-admin__mark">T</span>
				<?php esc_html_e( 'Counter by Therum', 'counter' ); ?>
				<span class="counter-admin__version">v<?php echo esc_html( COUNTER_VERSION ); ?></span>
			</h1>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="counter-admin__form">
				<?php settings_fields( 'counter_appearance' ); ?>

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Cart experience', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'How the cart appears to customers across your store.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_cart_presentation"><?php esc_html_e( 'Presentation', 'counter' ); ?></label>
						<div>
							<select name="counter_cart_presentation" id="counter_cart_presentation">
								<?php foreach ( $this->cartPresentations() as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $cart_present, $key ); ?>>
										<?php echo esc_html( $info['label'] ); ?> — <?php echo esc_html( $info['desc'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Studio = drawer with thumbnails and in-drawer checkout. Counter = full /cart/ page. Vitrine = centered modal. Each carries through to the matching checkout style.', 'counter' ); ?>
							</p>
						</div>
					</div>

					<div class="counter-admin__row">
						<label for="counter_cart_button_position"><?php esc_html_e( 'Floating button position', 'counter' ); ?></label>
						<div>
							<select name="counter_cart_button_position" id="counter_cart_button_position">
								<?php foreach ( [
									'bottom-right' => __( 'Bottom right', 'counter' ),
									'bottom-left'  => __( 'Bottom left', 'counter' ),
									'top-right'    => __( 'Top right', 'counter' ),
									'top-left'     => __( 'Top left', 'counter' ),
								] as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $button_pos, $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Where the persistent cart button anchors on the page. Only used when presentation includes a floating button (Studio, Vitrine).', 'shop' ); ?>
							</p>
						</div>
					</div>
				</div>

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Checkout', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Page-level checkout pattern for cart presentations that hand off to a separate /checkout/ page.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_checkout_presentation"><?php esc_html_e( 'Presentation', 'counter' ); ?></label>
						<div>
							<select name="counter_checkout_presentation" id="counter_checkout_presentation">
								<?php foreach ( $this->checkoutPresentations() as $key => $info ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $checkout_present, $key ); ?>>
										<?php echo esc_html( $info['label'] ); ?> — <?php echo esc_html( $info['desc'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Studio cart owns its in-drawer checkout — this setting only affects Counter, Vitrine, Mini.', 'shop' ); ?>
							</p>
						</div>
					</div>
				</div>

				<?php submit_button(); ?>
			</form>

			<form method="post" action="options.php" class="counter-admin__form counter-admin__form--secondary">
				<?php settings_fields( 'counter_catalog' ); ?>

				<div class="counter-admin__section">
					<header>
						<h2><?php esc_html_e( 'Catalog source', 'counter' ); ?></h2>
						<p><?php esc_html_e( 'Where Shop reads product data from.', 'counter' ); ?></p>
					</header>

					<div class="counter-admin__row">
						<label for="counter_product_source"><?php esc_html_e( 'Source', 'counter' ); ?></label>
						<div>
							<select name="counter_product_source" id="counter_product_source">
								<option value="native" <?php selected( $product_source, 'native' ); ?>>
									<?php esc_html_e( 'Native — products live in Counter\'s SQLite', 'counter' ); ?>
								</option>
								<option value="woo" <?php selected( $product_source, 'woo' ); ?> <?php disabled( ! $woo_detected ); ?>>
									<?php esc_html_e( 'WooCommerce — read existing Woo products in place', 'counter' ); ?>
									<?php if ( ! $woo_detected ) echo ' ' . esc_html__( '(install Woo first)', 'counter' ); ?>
								</option>
							</select>
							<p class="description">
								<?php if ( $woo_detected ) : ?>
									<?php esc_html_e( 'In Woo mode, Shop reads from wp_posts via wc_get_product() and mirrors paid orders back to WC_Orders so POD plugins (Printful, Printify, PodPartner, TapStitch, PodPluser) fulfill normally. No migration, no data copy.', 'shop' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'WooCommerce not detected. Native is the only option until Woo is installed.', 'counter' ); ?>
								<?php endif; ?>
							</p>
						</div>
					</div>
				</div>

				<?php submit_button( __( 'Save catalog source', 'counter' ) ); ?>
			</form>

		</div>
		<?php
	}

	/**
	 * @return array<string, array{label:string, desc:string}>
	 */
	private function cartPresentations(): array {
		return [
			CartRenderer::MODE_STUDIO  => [ 'label' => 'Studio',  'desc' => 'Drawer + in-drawer checkout' ],
			CartRenderer::MODE_COUNTER => [ 'label' => 'Counter', 'desc' => 'Full page, dark footer' ],
			CartRenderer::MODE_VITRINE => [ 'label' => 'Vitrine', 'desc' => 'Centered modal' ],
			CartRenderer::MODE_MINI    => [ 'label' => 'Mini',    'desc' => 'Header dropdown' ],
			CartRenderer::MODE_NONE    => [ 'label' => 'None',    'desc' => 'Skip cart, go straight to checkout' ],
		];
	}

	/**
	 * @return array<string, array{label:string, desc:string}>
	 */
	private function checkoutPresentations(): array {
		return [
			CheckoutRenderer::MODE_CLASSIC  => [ 'label' => 'Classic',  'desc' => 'Sections stacked + sticky summary' ],
			CheckoutRenderer::MODE_THERUM   => [ 'label' => 'Therum',   'desc' => 'Editable summary + payment form' ],
			CheckoutRenderer::MODE_SEQUENCE => [ 'label' => 'Sequence', 'desc' => 'Stepped Info → Payment → Done' ],
		];
	}
}
