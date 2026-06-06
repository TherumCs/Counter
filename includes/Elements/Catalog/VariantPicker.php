<?php
/**
 * Shop — Variant Picker element.
 *
 * Reads the product's variant attributes (Color, Size, …) and renders
 * a swatch / dropdown picker per attribute. Selection updates the
 * variant_id via a small inline script that the Pure / Bricks render
 * shares.
 *
 * Style options:
 *   - swatches (default for Color when color_hex is set)
 *   - dropdowns
 *   - buttons
 *   - auto (swatches for colors, buttons for sizes, dropdowns for everything else)
 */

namespace Counter\Elements\Catalog;

use Counter\Elements\ControlBuilder;
use Counter\Elements\Element;
use Counter\Elements\ElementContext;
use Counter\Repositories\AttributeRepository;
use Counter\Repositories\ProductRepository;

if ( ! defined( 'ABSPATH' ) ) exit;

final class VariantPicker implements Element {

	public function __construct(
		private readonly ProductRepository $products,
		private readonly AttributeRepository $attributes,
	) {}

	public function id(): string       { return 'variant-picker'; }
	public function name(): string     { return 'Variant picker'; }
	public function category(): string { return 'catalog'; }
	public function icon(): string     { return 'list-checks'; }
	public function needsJs(): bool    { return true; }

	public function controls(): array {
		return ControlBuilder::make()
			->group( 'Style' )
				->select( 'style', 'Picker style', [
					'auto'      => 'Auto (smart default per attribute)',
					'swatches'  => 'Swatches',
					'buttons'   => 'Buttons',
					'dropdowns' => 'Dropdowns',
				], 'auto' )
				->toggle( 'show_labels', 'Show attribute labels', true )
			->build();
	}

	public function render( array $settings, ElementContext $context ): string {
		if ( $context->productId === null ) return '';
		$product = $this->products->findById( $context->productId );
		if ( $product === null || ! $product->hasVariants ) return '';

		$attrs = $this->attributes->variantAttributesFor( $product->id );
		if ( ! $attrs ) return '';

		$style       = $settings['style'] ?? 'auto';
		$show_labels = $settings['show_labels'] ?? true;

		$out  = '<div class="counter-el counter-el-variant-picker" data-counter-variant-picker data-counter-product-id="' . esc_attr( (string) $product->id ) . '">';
		foreach ( $attrs as $slug => $attr ) {
			$kind = $this->kindFor( $style, $attr );
			$out .= '<div class="counter-el-variant-picker__group" data-counter-attr-slug="' . esc_attr( $slug ) . '">';
			if ( $show_labels ) {
				$out .= '<label class="counter-el-variant-picker__label">' . esc_html( $attr['name'] ) . '</label>';
			}
			$out .= match ( $kind ) {
				'swatches'  => $this->renderSwatches( $slug, $attr ),
				'buttons'   => $this->renderButtons( $slug, $attr ),
				default     => $this->renderDropdown( $slug, $attr ),
			};
			$out .= '</div>';
		}
		$out .= '</div>';
		return $out;
	}

	private function kindFor( string $style, array $attr ): string {
		if ( $style !== 'auto' ) return $style;
		$has_hex = array_filter( $attr['values'], fn( array $v ): bool => $v['color_hex'] !== null );
		if ( $has_hex ) return 'swatches';
		// Heuristic: 1–6 values → buttons, else dropdown
		return count( $attr['values'] ) <= 6 ? 'buttons' : 'dropdowns';
	}

	private function renderSwatches( string $slug, array $attr ): string {
		$out = '<div class="counter-el-variant-picker__swatches">';
		foreach ( $attr['values'] as $v ) {
			$hex   = $v['color_hex'] ?? '';
			$style = $hex !== ''
				? sprintf( 'background:%s;', esc_attr( $hex ) )
				: '';
			$out .= sprintf(
				'<button type="button" class="counter-el-variant-picker__swatch" data-counter-option-value="%s" style="%s" title="%s" aria-label="%s">'
				. '<span class="counter-el-variant-picker__swatch-label">%s</span></button>',
				esc_attr( $v['slug'] ),
				$style,
				esc_attr( $v['value'] ),
				esc_attr( $v['value'] ),
				esc_html( $v['value'] ),
			);
		}
		$out .= '</div>';
		return $out;
	}

	private function renderButtons( string $slug, array $attr ): string {
		$out = '<div class="counter-el-variant-picker__buttons">';
		foreach ( $attr['values'] as $v ) {
			$out .= sprintf(
				'<button type="button" class="counter-el-variant-picker__button" data-counter-option-value="%s">%s</button>',
				esc_attr( $v['slug'] ),
				esc_html( $v['value'] ),
			);
		}
		$out .= '</div>';
		return $out;
	}

	private function renderDropdown( string $slug, array $attr ): string {
		$out = '<select class="counter-el-variant-picker__select" data-counter-option-select>';
		$out .= '<option value="">' . sprintf( esc_html__( 'Choose %s', 'counter' ), esc_html( $attr['name'] ) ) . '</option>';
		foreach ( $attr['values'] as $v ) {
			$out .= sprintf( '<option value="%s">%s</option>', esc_attr( $v['slug'] ), esc_html( $v['value'] ) );
		}
		$out .= '</select>';
		return $out;
	}
}
