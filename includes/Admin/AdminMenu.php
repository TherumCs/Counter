<?php
/**
 * Counter by Therum — admin menu registration.
 *
 * Top-level "Counter" menu in the WP admin sidebar with sub-pages:
 *
 *   Settings   — cart/checkout presentation, product source, button position
 *   Products   — spreadsheet-style manager (inline edit, bulk actions)
 *   Import     — universal catalog importer wizard
 *   Orders     — list/detail (next chunk — uses same grid component as Products)
 *
 * Each sub-page is its own class so the menu file stays a thin shell.
 */

namespace Counter\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

final class AdminMenu {

	public function __construct(
		private readonly SettingsPage               $settings,
		private readonly ImporterPage               $importer,
		private readonly ProductsPage               $products,
		private readonly OrdersPage                 $orders,
		private readonly BuilderPage                $builder,
		private readonly StudioPayPage              $studioPay,
		private readonly CustomersPage              $customers,
		private readonly OrderIoPage                $orderIo,
		private readonly UpdatesPage                $updates,
		private readonly ProductCategoryOrderPage   $categoryOrder,
		private readonly ProductVariantOrderPage    $variantOrder,
		private readonly CustomTaxonomyOrderPage    $taxonomyOrder,
		private readonly DashboardPage              $dashboard,
		private readonly CategoriesPage             $categories,
		private readonly ImportExportPage           $importExport,
	) {}

	public function register(): void {
		add_action( 'admin_menu',   [ $this, 'menus' ], 20 );
		add_action( 'admin_menu',   [ $this, 'organizeMenu' ], 25 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );

		// When Therum OS is the host, Counter IS the store — its pages
		// belong under Therum's "Store" sidebar section, not as a separate
		// top-level "Counter" entry. Hook the filter that owns Therum's
		// sidebar nav to inject the Counter routes.
		if ( self::isTherumHost() ) {
			add_filter( 'therum_admin_nav_items', [ $this, 'injectIntoStoreSection' ], 20 );
		}

		// Dashboard registers its own meta-box hooks on its screen load.
		$this->dashboard->register();
		// Categories registers admin-post handlers for create/delete.
		$this->categories->register();
	}

	/**
	 * True when Therum OS is loaded as the admin host. Counter then hides
	 * its top-level WP sidebar entry and surfaces its pages under Therum's
	 * Store section instead. Standalone (no Therum) keeps the classic
	 * top-level menu.
	 */
	public static function isTherumHost(): bool {
		return defined( 'THERUM_OS_VERSION' );
	}

	public function menus(): void {
		// Under Therum the top-level "Counter" entry is suppressed — every
		// Counter page is registered as a hidden submenu (parent_slug: null)
		// so URLs still resolve but nothing appears in WP's sidebar. The
		// Therum sidebar surfaces these via injectIntoStoreSection().
		$therum_host = self::isTherumHost();

		if ( ! $therum_host ) {
			// Standalone WP: classic top-level "Counter" menu.
			add_menu_page(
				page_title: __( 'Counter by Therum', 'counter' ),
				menu_title: __( 'Counter', 'counter' ),
				capability: 'manage_options',
				menu_slug:   'counter',
				callback:   [ $this->dashboard, 'render' ],
				icon_url:   'dashicons-cart',
				position:   58,
			);

			add_submenu_page(
				parent_slug: 'counter',
				page_title:  __( 'Dashboard', 'counter' ),
				menu_title:  __( 'Dashboard', 'counter' ),
				capability:  'manage_options',
				menu_slug:   'counter',
				callback:    [ $this->dashboard, 'render' ],
			);
		} else {
			// Therum host: register the Dashboard page hidden so
			// ?page=counter still resolves to the dashboard render.
			add_submenu_page(
				parent_slug: null,
				page_title:  __( 'Dashboard', 'counter' ),
				menu_title:  __( 'Dashboard', 'counter' ),
				capability:  'manage_options',
				menu_slug:   'counter',
				callback:    [ $this->dashboard, 'render' ],
			);
		}

		// Resolve the parent slug for every "visible" Counter page based on
		// host context. Therum host → null (hidden from WP sidebar);
		// standalone → 'counter' (under the top-level entry).
		$parent = $therum_host ? null : 'counter';

		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Settings', 'counter' ),
			menu_title:  __( 'Settings', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-settings',
			callback:    [ $this->settings, 'render' ],
		);

		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Products', 'counter' ),
			menu_title:  __( 'Products', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-products',
			callback:    [ $this->products, 'render' ],
		);

		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Orders', 'counter' ),
			menu_title:  __( 'Orders', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-orders',
			callback:    [ $this->orders, 'render' ],
		);

		// Unified Import / Export hub — products, orders, customers all
		// in one page with sub-tabs. Replaces the old separate "Import"
		// and "Order I/O" entries.
		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Import / Export', 'counter' ),
			menu_title:  __( 'Import / Export', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-import',
			callback:    [ $this->importExport, 'render' ],
		);

		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Customers', 'counter' ),
			menu_title:  __( 'Customers', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-customers',
			callback:    [ $this->customers, 'render' ],
		);
		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Categories', 'counter' ),
			menu_title:  __( 'Categories', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-categories',
			callback:    [ $this->categories, 'render' ],
		);
		// counter-orders-io: hidden — kept registered for deep-link
		// back-compat so old bookmarks still resolve. The page itself
		// redirects to the unified Import / Export view.
		add_submenu_page(
			parent_slug: null,
			page_title:  __( 'Order Import / Export', 'counter' ),
			menu_title:  __( 'Order I/O', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-orders-io',
			callback:    function (): void {
				wp_safe_redirect( admin_url( 'admin.php?page=counter-import&tab=orders' ) );
				exit;
			},
		);
		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Payments', 'counter' ),
			menu_title:  __( 'Payments', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-studio-pay',
			callback:    [ $this->studioPay, 'render' ],
		);
		// Updates is gated on `manage_options` (full WP admin) since
		// it can rewrite the plugin filesystem.
		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Updates', 'counter' ),
			menu_title:  __( 'Updates', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-updates',
			callback:    [ $this->updates, 'render' ],
		);

		// Unified Order hub — one sidebar entry that owns Categories,
		// Variants, Vendors, and Collections via sub-tabs. The two
		// sibling slugs stay registered (parent_slug:null) so existing
		// bookmarks and the sub-tab links keep resolving.
		add_submenu_page(
			parent_slug: $parent,
			page_title:  __( 'Order', 'counter' ),
			menu_title:  __( 'Order', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-categories-order',
			callback:    [ $this->categoryOrder, 'render' ],
		);

		add_submenu_page(
			parent_slug: null,
			page_title:  __( 'Variant Order', 'counter' ),
			menu_title:  __( 'Variant Order', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-variants-order',
			callback:    [ $this->variantOrder, 'render' ],
		);

		add_submenu_page(
			parent_slug: null,
			page_title:  __( 'Taxonomy Order', 'counter' ),
			menu_title:  __( 'Taxonomy Order', 'counter' ),
			capability:  'manage_options',
			menu_slug:   'counter-taxonomies',
			callback:    [ $this->taxonomyOrder, 'render' ],
		);

		// Pure page builder — only when the active mode wants it.
		if ( \Counter\Mode::loadsPureBuilder() ) {
			add_submenu_page(
				parent_slug: $parent,
				page_title:  __( 'Pages', 'counter' ),
				menu_title:  __( 'Pages', 'counter' ),
				capability:  'manage_options',
				menu_slug:   'counter-pages',
				callback:    [ $this->builder, 'renderPageList' ],
			);

			// The actual builder canvas — hidden from the menu but
			// reachable via ?page=counter-builder&page_id=X
			add_submenu_page(
				parent_slug: null,
				page_title:  __( 'Builder', 'counter' ),
				menu_title:  __( 'Builder', 'counter' ),
				capability:  'manage_options',
				menu_slug:   'counter-builder',
				callback:    [ $this->builder, 'render' ],
			);
		}
	}

	/**
	 * Inject Counter's pages into Therum's Store sidebar section. Called via
	 * the `therum_admin_nav_items` filter — Therum renders the resulting
	 * structure as the left sidebar.
	 *
	 * If a 'store' section already exists (e.g. Woo bridge added one), we
	 * append Counter's pages. Otherwise we create the section ourselves so
	 * Counter standalone-on-Therum still gets a Store nav.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	public function injectIntoStoreSection( array $items ): array {
		$counter_pages = [
			[ 'label' => 'Dashboard',     'icon' => 'home',     'url' => 'admin.php?page=counter',                 'match' => 'page=counter' ],
			[ 'label' => 'Products',      'icon' => 'box',      'url' => 'admin.php?page=counter-products',        'match' => 'page=counter-products' ],
			[ 'label' => 'Orders',        'icon' => 'receipt',  'url' => 'admin.php?page=counter-orders',          'match' => 'page=counter-orders' ],
			[ 'label' => 'Customers',     'icon' => 'users',    'url' => 'admin.php?page=counter-customers',       'match' => 'page=counter-customers' ],
			[ 'label' => 'Categories',    'icon' => 'tag',      'url' => 'admin.php?page=counter-categories',      'match' => 'page=counter-categories' ],
			[ 'label' => 'Import/Export', 'icon' => 'upload',   'url' => 'admin.php?page=counter-import',          'match' => 'page=counter-import' ],
			[ 'label' => 'Payments',      'icon' => 'card',     'url' => 'admin.php?page=counter-studio-pay',      'match' => 'page=counter-studio-pay' ],
			[ 'label' => 'Settings',      'icon' => 'settings', 'url' => 'admin.php?page=counter-settings',        'match' => 'page=counter-settings' ],
		];
		if ( \Counter\Mode::loadsPureBuilder() ) {
			// Insert Pages between Categories and Import/Export so the builder
			// surfaces next to the catalog tools it edits.
			array_splice( $counter_pages, 5, 0, [
				[ 'label' => 'Pages', 'icon' => 'page', 'url' => 'admin.php?page=counter-pages', 'match' => 'page=counter-pages' ],
			] );
		}

		// Look for an existing Store section. Theme/host may have created
		// one already (Woo bridge, custom mu-plugin); we append rather than
		// replace so we don't clobber sibling integrations.
		foreach ( $items as &$section ) {
			if ( ( $section['id'] ?? '' ) === 'store' ) {
				$section['items'] = array_merge( $section['items'] ?? [], $counter_pages );
				return $items;
			}
		}
		unset( $section );

		// No Store section yet — create one. Therum's renderer expects
		// id/label/icon/items at minimum.
		$items[] = [
			'id'    => 'store',
			'label' => 'Store',
			'icon'  => 'store',
			'items' => $counter_pages,
		];
		return $items;
	}

	/**
	 * Organize menu items into sections with headers.
	 * Called after add_submenu_page() to restructure the menu.
	 *
	 * Skips entirely under Therum host — there's no top-level "counter"
	 * submenu group to organize there (pages are hidden, surfaced via
	 * `therum_admin_nav_items` instead).
	 */
	public function organizeMenu(): void {
		global $submenu;

		if ( self::isTherumHost() ) return;

		if ( ! isset( $submenu['counter'] ) ) {
			return;
		}

		// Index submenu items by slug so we can pluck them by name and not
		// be brittle against menu registration order changes.
		$by_slug = [];
		foreach ( $submenu['counter'] as $item ) {
			$by_slug[ $item[2] ?? '' ] = $item;
		}

		$section = fn( string $label ) => [ $label, 'counter', 'counter-menu-section-title' ];
		$pick = function ( string $slug ) use ( $by_slug ): ?array {
			return $by_slug[ $slug ] ?? null;
		};

		$organized = [];
		$add = function ( ?array $item ) use ( &$organized ): void {
			if ( $item !== null ) $organized[] = $item;
		};

		// Flat ordered list — section headers were inert pseudo-rows that
		// confused merchants. The global top-tab strip groups visually.
		$add( $pick( 'counter' ) );
		$add( $pick( 'counter-products' ) );
		$add( $pick( 'counter-orders' ) );
		$add( $pick( 'counter-customers' ) );
		$add( $pick( 'counter-categories' ) );
		$add( $pick( 'counter-import' ) );
		$add( $pick( 'counter-updates' ) );
		$add( $pick( 'counter-categories-order' ) );
		$add( $pick( 'counter-studio-pay' ) );
		$add( $pick( 'counter-settings' ) );

		$submenu['counter'] = $organized;

		// Render section headers as inert labels — the rows still come
		// through WP's submenu pipeline (so they sit in the right spot in
		// the sidebar), but we strip click behavior. Targeting by href is
		// reliable because WP builds it from the menu_slug we set, and
		// our section slugs are unique to these rows.
		add_action( 'admin_head', function () {
			?>
			<style>
				#adminmenu li a[href$="page=counter-menu-section-title"] {
					padding: 12px 16px;
					font-size: 11px;
					font-weight: 700;
					text-transform: uppercase;
					letter-spacing: 0.5px;
					color: #9ca3af !important;
					background: #f9fafb !important;
					margin: 16px 0 8px 0;
					border-top: 1px solid #e5e7eb;
					cursor: default;
					pointer-events: none;
				}
				#adminmenu li a[href$="page=counter-menu-section-title"]:hover {
					background: #f9fafb !important;
					color: #9ca3af !important;
				}
			</style>
			<script>
				( function () {
					document.querySelectorAll(
						'#adminmenu a[href$="page=counter-menu-section-title"]'
					).forEach( function ( a ) {
						a.removeAttribute( 'href' );
						a.setAttribute( 'tabindex', '-1' );
						a.setAttribute( 'aria-disabled', 'true' );
					} );
				} )();
			</script>
			<?php
		} );
	}

	public function assets( string $hook ): void {
		// Only enqueue on Counter's own admin pages.
		if ( strpos( $hook, 'counter' ) === false ) return;

		// Counter admin styles only. admin-redesign.css is intentionally NOT
		// enqueued — it contains global #adminmenu / body.wp-admin selectors
		// that leak into WP's own chrome and break the dashboard.
		wp_register_style( 'counter-admin', COUNTER_URL . 'assets/admin/admin.css', [], COUNTER_VERSION );
		wp_enqueue_style( 'counter-admin' );

		$rest_config = wp_json_encode( [
			'rest'  => esc_url_raw( rest_url() ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		] );

		if ( str_contains( $hook, 'counter-import' ) ) {
			wp_register_script( 'counter-importer', COUNTER_URL . 'assets/admin/importer.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-importer',
				'window.CounterImporterConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-importer' );
		}

		if ( str_contains( $hook, 'counter-products' ) ) {
			wp_register_script( 'counter-products-grid', COUNTER_URL . 'assets/admin/products-grid.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-products-grid',
				'window.CounterAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-products-grid' );

			// Product editor drawer — ES module (Preact + htm via esm.sh).
			// Registered with `type=module` filter so WP emits the right
			// script tag attribute.
			wp_register_style(  'counter-product-editor', COUNTER_URL . 'assets/admin/product-editor.css', [], COUNTER_VERSION );
			wp_enqueue_style(   'counter-product-editor' );
			add_filter( 'script_loader_tag', function ( $tag, $handle ) {
				if ( $handle === 'counter-product-editor' ) {
					return str_replace( '<script ', '<script type="module" ', $tag );
				}
				return $tag;
			}, 10, 2 );
			wp_register_script( 'counter-product-editor', COUNTER_URL . 'assets/admin/product-editor.js', [], COUNTER_VERSION, [ 'in_footer' => true ] );
			wp_enqueue_script(  'counter-product-editor' );
		}

		if ( str_contains( $hook, 'counter-orders' ) ) {
			wp_register_script( 'counter-orders-grid', COUNTER_URL . 'assets/admin/orders-grid.js', [], COUNTER_VERSION, [
				'in_footer' => true,
				'strategy'  => 'defer',
			] );
			wp_add_inline_script( 'counter-orders-grid',
				'window.CounterAdminGridConfig = ' . $rest_config . ';',
				'before'
			);
			wp_enqueue_script( 'counter-orders-grid' );
		}

		if ( str_contains( $hook, 'counter-builder' ) ) {
			// Builder is full-bleed — hide standard admin chrome by
			// adding a body class.
			add_filter( 'admin_body_class', fn( $c ) => $c . ' counter-builder-active' );

			// WP media library is used by the image picker
			wp_enqueue_media();

			wp_register_style(  'counter-builder-css', COUNTER_URL . 'assets/builder/builder.css', [], COUNTER_VERSION );
			wp_enqueue_style(   'counter-builder-css' );

			// ES module — Preact + htm from esm.sh, no build step
			wp_register_script_module( 'counter-builder',
				COUNTER_URL . 'assets/builder/builder.js',
				[],
				COUNTER_VERSION,
			);
			wp_enqueue_script_module( 'counter-builder' );
		}

		// Taxonomy ordering pages — drag-drop term reordering
		if ( str_contains( $hook, 'counter-categories-order' ) || str_contains( $hook, 'counter-variants-order' ) || str_contains( $hook, 'counter-taxonomies' ) ) {
			// Load Sortable.js from CDN
			wp_register_script( 'sortable', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js', [], '1.15.0', [ 'strategy' => 'defer' ] );

			wp_register_style(  'counter-taxonomy-order', COUNTER_URL . 'assets/admin/taxonomy-order.css', [], COUNTER_VERSION );
			wp_register_script( 'counter-taxonomy-order', COUNTER_URL . 'assets/admin/taxonomy-order.js', [ 'sortable' ], COUNTER_VERSION, [ 'strategy' => 'defer' ] );

			wp_enqueue_style(  'counter-taxonomy-order' );
			wp_enqueue_script( 'counter-taxonomy-order' );
		}
	}
}
