<?php
/**
 * Sum of (quantity × unit_price) across all cart items.
 *
 * The cart items already carry `line_total` computed at add-time, so this
 * is a simple sum. We re-derive from quantity × unit_price defensively
 * in case the line_total was stale — single source of truth.
 */

namespace Counter\Pipelines\Steps;

use Counter\Money;
use Counter\Pipelines\CartStep;
use Counter\Pipelines\CartTotalsContext;

if ( ! defined( 'ABSPATH' ) ) exit;

final class SubtotalStep implements CartStep {

	public function run( CartTotalsContext $ctx ): void {
		$total = Money::zero( $ctx->cart->currency );
		foreach ( $ctx->items() as $item ) {
			$total = $total->plus( $item->unitPrice->times( $item->quantity ) );
		}
		$ctx->subtotal = $total;
	}
}
