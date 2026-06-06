# Counter Plugin: Drag-and-Drop Taxonomy Ordering System

**Version:** 0.29.0  
**Date:** 2026-06-06  
**Status:** Implementation Plan (Ready for Build)

---

## Executive Summary

This document outlines a complete system for managing hierarchical ordering of product categories, variants, and custom taxonomies in Counter via drag-and-drop UI. The design leverages existing Counter patterns (schema conventions, REST architecture, admin asset loading) while adding minimal new tables and endpoints.

**Key Design Decisions:**
1. **Schema:** Extend existing `position` columns; add single `taxonomy_orders` table for hierarchical parent/child relationships
2. **UI:** Three separate admin pages (Categories, Variants, Custom Taxonomies) with shared drag-drop component
3. **Drag Library:** SortableJS (battle-tested, no build required, works with existing Counter assets)
4. **Hierarchy:** Use `parent_id + position` pattern already present in attributes/variants schema
5. **Persistence:** Batch REST endpoint for reordering with transaction safety

---

## Part 1: Schema Changes

### Current State Analysis

The Counter schema **already has `position` columns** on:
- `attributes` (line 155)
- `attribute_values` (line 169)
- `product_variants` (line 122)
- `product_images` (line 202)
- `digital_files` (line 217)

**Existing position usage:**
- Attributes sorted by `position ASC` in product attribute queries (AttributeRepository line 49)
- Attribute values sorted by `position ASC, av.id ASC` (line 61)
- Variants explicitly use `position` field (schema line 122)

### Schema Additions

#### 1. New Table: `taxonomy_orders` (Schema v5)

```sql
CREATE TABLE IF NOT EXISTS taxonomy_orders (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_type   TEXT NOT NULL,        -- 'category' | 'variant_option' | 'vendor' | 'collection' | custom
    taxonomy_key    TEXT NOT NULL,        -- slugified name for custom types; 'product_cat' for WP categories
    parent_id       INTEGER,              -- NULL for root; self-referential for hierarchies
    position        INTEGER NOT NULL DEFAULT 0,
    enabled         INTEGER NOT NULL DEFAULT 1,
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    UNIQUE(taxonomy_type, taxonomy_key, parent_id),
    FOREIGN KEY (parent_id) REFERENCES taxonomy_orders(id) ON DELETE CASCADE
);

-- Indexes for traversal and list operations
CREATE INDEX idx_taxonomy_orders_type_parent ON taxonomy_orders(taxonomy_type, parent_id, position);
CREATE INDEX idx_taxonomy_orders_type_key   ON taxonomy_orders(taxonomy_type, taxonomy_key);
```

#### 2. Extend Existing Tables (Additive Only)

**Why this table instead of extending existing ones:**
- `attributes` and `variants` tables already have position; we need a unified way to manage category hierarchies
- A generic table supports arbitrary custom taxonomies without schema churn
- Parent/child relationships require recursive FK which is cleaner in a single table
- Enables future vendor/collection management without altering product schema

#### 3. Data Migration (v5)

No data migration needed for existing attributes/variants — they already have position columns with sensible defaults (0). The new table starts empty; taxonomies opt-in to ordering via admin.

---

## Part 2: File & Class Organization

### Directory Structure

```
includes/
├── Admin/
│   ├── TaxonomyOrderingPage.php          [NEW] Abstract base for ordering UIs
│   ├── CategoryOrderingPage.php           [NEW] Product categories (hierarchical)
│   ├── VariantOptionOrderingPage.php      [NEW] Product variants/options
│   ├── CustomTaxonomyOrderingPage.php     [NEW] Vendors, collections, etc.
│   └── AdminMenu.php                      [EDIT] Register new submenu pages
│
├── Rest/
│   └── TaxonomyOrderingController.php    [NEW] Reordering endpoints
│
├── Repositories/
│   └── TaxonomyOrderRepository.php        [NEW] DB access for taxonomy orders
│
├── Services/
│   └── TaxonomyOrderingService.php        [NEW] Business logic: validation, hierarchies
│
└── Models/
    └── TaxonomyOrder.php                  [NEW] Value object for a single order entry

assets/
└── admin/
    ├── taxonomy-ordering.js               [NEW] SortableJS controller + REST integration
    ├── taxonomy-ordering.css              [NEW] Styling for drag-drop list
    └── admin.css                          [EDIT] Import taxonomy-ordering.css
```

### Class Responsibilities

#### `TaxonomyOrderRepository` (Database Layer)
Queries and mutations for `taxonomy_orders` table:

```php
class TaxonomyOrderRepository {
    // Read
    public function getByType(string $type, ?int $parentId = null): array // Lists all of type, optionally filtered by parent
    public function getWithHierarchy(string $type): array // Tree structure (recursive)
    public function getPosition(string $type, string $key, ?int $parentId): ?int
    
    // Write
    public function upsert(string $type, string $key, ?int $parentId, int $position): int // Returns ID
    public function reorderBatch(array $moves): void // Multiple position updates in transaction
    public function delete(int $id): void
    public function moveSubtree(int $id, ?int $newParentId): void // Reparent + reorder children
}
```

#### `TaxonomyOrderingService` (Business Logic)
Higher-level operations on ordering:

```php
class TaxonomyOrderingService {
    public function __construct(
        private readonly TaxonomyOrderRepository $repo,
        private readonly AttributeRepository $attributes,  // For variant options
        // ... other dependencies
    ) {}
    
    // Hierarchical operations
    public function applyHierarchy(string $type, array $tree): void // Persist nested array
    public function getDisplayTree(string $type): array // For UI rendering
    
    // Validation
    public function validateParentExists(string $type, ?int $parentId): bool
    public function validateNoCycles(int $itemId, ?int $newParentId): bool
    
    // Sync with frontend after drag
    public function persistReorderFromPayload(array $payload): void
}
```

#### Admin Page Classes

**`TaxonomyOrderingPage` (Abstract Base)**
```php
abstract class TaxonomyOrderingPage {
    protected abstract function getTaxonomyType(): string;
    protected abstract function getTaxonomyLabel(): string;
    
    public function render(): void {
        // Shared structure: title, search, hierarchy editor div
    }
}
```

**`CategoryOrderingPage extends TaxonomyOrderingPage`**
- Loads product categories (from attributes or custom table)
- Renders as hierarchical tree
- Supports drag between parents

**`VariantOptionOrderingPage extends TaxonomyOrderingPage`**
- Loads attribute values (Color, Size, etc.)
- Shows per-attribute sections
- Flat or nested depending on attribute type

**`CustomTaxonomyOrderingPage extends TaxonomyOrderingPage`**
- Generic UI for vendors, collections, custom taxonomies
- Allows site admin to register new taxonomy types
- Lazy-loads available types from filter hook

#### REST Controller

**`TaxonomyOrderingController`**

Namespace: `counter/v1`

Endpoints:
```
GET    /admin/taxonomies/{type}           → List with hierarchy
POST   /admin/taxonomies/{type}/reorder   → Batch reorder
PUT    /admin/taxonomies/{type}/{id}      → Single item (parent_id, position)
DELETE /admin/taxonomies/{type}/{id}      → Remove ordering entry
```

---

## Part 3: REST API Specification

### Authentication
All endpoints require `manage_woocommerce` capability (matches existing AdminController pattern).

### Endpoint 1: List Taxonomy Orders

**Request:**
```
GET /counter/v1/admin/taxonomies/category?include_tree=true

Headers:
  X-WP-Nonce: {wp_rest nonce}
```

**Response (200 OK):**
```json
{
  "type": "category",
  "items": [
    {
      "id": 1,
      "key": "electronics",
      "parent_id": null,
      "position": 0,
      "enabled": 1,
      "children": [
        {
          "id": 5,
          "key": "phones",
          "parent_id": 1,
          "position": 0,
          "enabled": 1,
          "children": []
        },
        {
          "id": 6,
          "key": "laptops",
          "parent_id": 1,
          "position": 1,
          "enabled": 1,
          "children": []
        }
      ]
    },
    {
      "id": 2,
      "key": "clothing",
      "parent_id": null,
      "position": 1,
      "enabled": 1,
      "children": []
    }
  ]
}
```

**Query Parameters:**
- `include_tree=true|false` (default: true) — Flatten or nest response
- `parent_id=123` (optional) — Only items under parent (requires include_tree=false)

### Endpoint 2: Batch Reorder

**Request:**
```
POST /counter/v1/admin/taxonomies/category/reorder

Content-Type: application/json
X-WP-Nonce: {wp_rest nonce}

{
  "moves": [
    {
      "id": 5,
      "parent_id": 1,          // Can change parent
      "position": 0            // New position among siblings under parent_id
    },
    {
      "id": 6,
      "parent_id": 1,
      "position": 1
    },
    {
      "id": 2,
      "parent_id": null,       // Moving to root
      "position": 0
    },
    {
      "id": 1,
      "parent_id": null,
      "position": 1
    }
  ]
}
```

**Response (200 OK):**
```json
{
  "success": true,
  "updated_count": 4,
  "tree": [
    {
      "id": 2,
      "key": "clothing",
      "position": 0,
      "children": []
    },
    {
      "id": 1,
      "key": "electronics",
      "position": 1,
      "children": [
        {
          "id": 5,
          "key": "phones",
          "position": 0
        },
        {
          "id": 6,
          "key": "laptops",
          "position": 1
        }
      ]
    }
  ]
}
```

**Validation:**
- If `parent_id` would create a cycle (item reparented under its own child), return 400 with message
- If `parent_id` doesn't exist, return 404
- If `id` doesn't exist, return 404
- All updates execute atomically (SQLite transaction)

### Endpoint 3: Single Item Update

**Request:**
```
PUT /counter/v1/admin/taxonomies/category/5

Content-Type: application/json
X-WP-Nonce: {wp_rest nonce}

{
  "parent_id": 1,
  "position": 2,
  "enabled": 0
}
```

**Response (200 OK):**
```json
{
  "id": 5,
  "key": "phones",
  "parent_id": 1,
  "position": 2,
  "enabled": 0
}
```

### Endpoint 4: Delete Ordering Entry

**Request:**
```
DELETE /counter/v1/admin/taxonomies/category/5

Headers:
  X-WP-Nonce: {wp_rest nonce}
```

**Response (200 OK):**
```json
{
  "deleted": true,
  "id": 5
}
```

**Behavior:**
- Deletes the taxonomy_orders entry (not the taxonomy itself, just the ordering metadata)
- Children under deleted parent become root items (parent_id set to NULL)
- Position numbers reflow automatically

---

## Part 4: Admin UI Implementation

### Layout: Shared Components

All three pages (`CategoryOrderingPage`, `VariantOptionOrderingPage`, `CustomTaxonomyOrderingPage`) follow this structure:

```html
<div class="wrap counter-admin">
  <h1 class="counter-admin__title">
    <span class="counter-admin__mark">T</span>
    [Page Title]
  </h1>

  <!-- Search/filter bar -->
  <div class="counter-taxonomy-ordering__controls">
    <input type="search" 
           data-taxonomy-search 
           placeholder="Search categories..." />
    <label>
      <input type="checkbox" data-taxonomy-show-disabled /> 
      Show disabled
    </label>
  </div>

  <!-- Hierarchy editor (SortableJS targets this) -->
  <div class="counter-taxonomy-ordering__list"
       data-taxonomy-type="category"
       data-taxonomy-list>
    <!-- Rendered by JS from REST response -->
  </div>

  <!-- Status feedback -->
  <div class="counter-taxonomy-ordering__status" 
       data-taxonomy-status
       hidden></div>

  <!-- Unsaved changes warning -->
  <div class="notice notice-warning" 
       id="counter-taxonomy-ordering-unsaved"
       hidden>
    <p>You have unsaved changes. 
       <a href="#" data-save-ordering>Save now</a> or 
       <a href="#" data-discard-ordering>discard</a>.</p>
  </div>
</div>
```

### SortableJS Integration

**Key Features:**
1. **Drag within hierarchy:** Drag categories under parent categories
2. **Drag between levels:** Drag item to root or under different parent
3. **Auto-scroll:** When dragging near viewport edges
4. **Ghost preview:** Dragging shows placeholder + ghost clone
5. **Drop validation:** Prevent dropping into own subtree
6. **Pending indicator:** Mark reordered items until persisted

**Configuration:**

```javascript
// assets/admin/taxonomy-ordering.js

const sortable = Sortable.create(listElement, {
  group: {
    name: 'taxonomies',
    pull: true,
    put: true,
  },
  nested: true,                    // Enable hierarchical drag
  animation: 150,
  ghostClass: 'sortable-ghost',    // Item being dragged
  dragClass: 'sortable-drag',      // Placeholder
  handle: '.taxonomy-item__drag',  // Only drag from icon
  forceFallback: false,            // Use native HTML5 if available
  
  onEnd: (evt) => {
    // Serialize tree, detect changes, mark unsaved
    const moves = serializeTree(listElement);
    if (hasChanges(moves)) {
      showUnsavedWarning();
      enableSaveButton();
    }
  },
});
```

### CSS Classes

**Structure:**
```css
.counter-taxonomy-ordering__list {}
.taxonomy-item {
  --taxonomy-depth: 0;              /* Set via CSS var for indentation */
}
.taxonomy-item__content {
  padding-left: calc(var(--taxonomy-depth, 0) * 24px);
}
.taxonomy-item__drag {
  cursor: grab;
  opacity: 0.5;
  transition: opacity 0.2s;
}
.taxonomy-item__drag:hover {
  opacity: 1;
}
.taxonomy-item__toggle {
  cursor: pointer;
  user-select: none;
}
.taxonomy-item--collapsed .taxonomy-item__children {
  display: none;
}

.sortable-ghost {
  opacity: 0.4;
  background: var(--counter-sf-2);
}
.sortable-drag {
  background: var(--counter-ac-s);
  border-left: 3px solid var(--counter-ac);
}

.counter-taxonomy-ordering__status {
  padding: 12px 16px;
  border-radius: 6px;
  margin-top: 12px;
  background: var(--counter-ok);
  color: white;
  font-size: 14px;
}
.counter-taxonomy-ordering__status[data-status="saving"] {
  background: var(--counter-bd-2);
  color: #1d2327;
}
.counter-taxonomy-ordering__status[data-status="error"] {
  background: #c5192d;
}
```

### JavaScript Logic

**File:** `assets/admin/taxonomy-ordering.js`

**Key Functions:**

```javascript
// Initialize
document.addEventListener('DOMContentLoaded', () => {
  const listEl = document.querySelector('[data-taxonomy-list]');
  const type = listEl.dataset.taxonomyType;
  
  // 1. Fetch current tree from REST
  fetchTaxonomyTree(type);
  
  // 2. Render tree structure
  renderTaxonomyTree(data.items, listEl);
  
  // 3. Attach SortableJS
  initSortable(listEl);
  
  // 4. Attach save/discard handlers
  attachFormHandlers();
});

// Main serialize: walk tree, collect moves
function serializeTree(listEl) {
  const moves = [];
  const items = listEl.querySelectorAll('[data-taxonomy-item-id]');
  
  items.forEach((item, index) => {
    const id = parseInt(item.dataset.taxonomyItemId);
    const parent = item.parentElement.closest('[data-taxonomy-item-id]');
    const parentId = parent ? parseInt(parent.dataset.taxonomyItemId) : null;
    
    // Only track if position or parent changed
    const original = originalTree.find(i => i.id === id);
    if (original.position !== index || original.parent_id !== parentId) {
      moves.push({ id, parent_id: parentId, position: index });
    }
  });
  
  return moves;
}

// POST to /counter/v1/admin/taxonomies/{type}/reorder
async function saveOrdering(type, moves) {
  const response = await fetch(
    `/wp-json/counter/v1/admin/taxonomies/${type}/reorder`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wp?.nonce || '',
      },
      body: JSON.stringify({ moves }),
    }
  );
  
  if (!response.ok) {
    const error = await response.json();
    showError(error.message || 'Save failed');
    throw new Error(error.message);
  }
  
  const result = await response.json();
  originalTree = result.tree;  // Update reference
  showSuccess('Ordering saved!');
  hideUnsavedWarning();
  
  return result;
}

// Prevent cycle: check if targetParentId is under draggedItemId
function validateNoCycle(draggedId, targetParentId) {
  // Walk up targetParent's ancestors; if we hit draggedId, it's a cycle
  let current = findItemById(targetParentId);
  while (current) {
    if (current.id === draggedId) return false;
    current = findItemById(current.parent_id);
  }
  return true;
}
```

---

## Part 5: Frontend Display Integration

### Where Ordering is Used

#### 1. Product Detail Page (Variant Options)

Current code queries variants with:
```php
SELECT ... FROM product_variants WHERE product_id = ? ORDER BY position ASC
```

**No change needed** — position column already exists and queries already sort by it.

#### 2. Attribute Value Picker (Color, Size, etc.)

```php
// In AttributeRepository::variantAttributesFor()
// Already orders by: av.position ASC, av.id ASC
// This respects any position changes made in the ordering UI
```

**No change needed** — queries already respect position.

#### 3. Product Grid / Archive Page

When rendering products with variants, queries already use position. If a theme wants to show categories in the sidebar:

```php
// New method in TaxonomyOrderRepository
$categories = $repo->getByType('category', parent_id: null);
// Returns array sorted by position
```

#### 4. Category Navigation Menu

If Counter integrates with WordPress menus or renders breadcrumbs:

```php
// In a template or Walker class
$ordered_terms = TaxonomyOrderRepository::getWithHierarchy('category');
// Returns tree structure ready for menu rendering
```

---

## Part 6: Admin Menu Integration

### Modified AdminMenu.php

Add three new submenu pages:

```php
// In AdminMenu::menus()

// Category ordering
add_submenu_page(
    parent_slug: 'counter',
    page_title:  __( 'Category Ordering', 'counter' ),
    menu_title:  __( 'Categories', 'counter' ),
    capability:  'manage_woocommerce',
    menu_slug:   'counter-category-ordering',
    callback:    [ new CategoryOrderingPage(), 'render' ],
);

// Variant/Option ordering
add_submenu_page(
    parent_slug: 'counter',
    page_title:  __( 'Variant Options', 'counter' ),
    menu_title:  __( 'Options', 'counter' ),
    capability:  'manage_woocommerce',
    menu_slug:   'counter-variant-ordering',
    callback:    [ new VariantOptionOrderingPage(), 'render' ],
);

// Custom taxonomies
add_submenu_page(
    parent_slug: 'counter',
    page_title:  __( 'Custom Taxonomies', 'counter' ),
    menu_title:  __( 'Taxonomies', 'counter' ),
    capability:  'manage_woocommerce',
    menu_slug:   'counter-custom-taxonomy-ordering',
    callback:    [ new CustomTaxonomyOrderingPage(), 'render' ],
);
```

### Asset Enqueue in AdminMenu::assets()

```php
// Add to existing condition check in AdminMenu::assets()
if ( str_contains( $hook, 'counter-category-ordering' ) || 
     str_contains( $hook, 'counter-variant-ordering' ) ||
     str_contains( $hook, 'counter-custom-taxonomy-ordering' ) ) {
    
    // SortableJS from CDN (no build step, pure ES module)
    wp_enqueue_script( 'sortablejs', 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js', [], '1.15.0', [ 'in_footer' => true ] );
    
    wp_register_style( 'counter-taxonomy-ordering', COUNTER_URL . 'assets/admin/taxonomy-ordering.css', [], COUNTER_VERSION );
    wp_enqueue_style( 'counter-taxonomy-ordering' );
    
    wp_register_script( 'counter-taxonomy-ordering', COUNTER_URL . 'assets/admin/taxonomy-ordering.js', 
        [ 'sortablejs' ], COUNTER_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
    
    wp_add_inline_script( 'counter-taxonomy-ordering',
        'window.CounterTaxonomyOrderingConfig = ' . wp_json_encode( [
            'rest'  => esc_url_raw( rest_url() ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
        ] ) . ';',
        'before'
    );
    
    wp_enqueue_script( 'counter-taxonomy-ordering' );
}
```

---

## Part 7: Container/Dependency Injection

### Service Registration

Add to `counter_register_container_bindings()` in counter.php:

```php
// ─── Taxonomy Ordering (v0.30.0) ────────────────────────────

$c->singleton( \Counter\Repositories\TaxonomyOrderRepository::class,
    fn() => new \Counter\Repositories\TaxonomyOrderRepository() );

$c->singleton( \Counter\Services\TaxonomyOrderingService::class,
    fn( $c ) => new \Counter\Services\TaxonomyOrderingService(
        $c->get( \Counter\Repositories\TaxonomyOrderRepository::class ),
        $c->get( \Counter\Repositories\AttributeRepository::class ),
        // other deps
    )
);

$c->set( \Counter\Admin\CategoryOrderingPage::class,
    fn( $c ) => new \Counter\Admin\CategoryOrderingPage(
        $c->get( \Counter\Services\TaxonomyOrderingService::class )
    )
);

$c->set( \Counter\Admin\VariantOptionOrderingPage::class,
    fn( $c ) => new \Counter\Admin\VariantOptionOrderingPage(
        $c->get( \Counter\Services\TaxonomyOrderingService::class ),
        $c->get( \Counter\Repositories\AttributeRepository::class )
    )
);

$c->set( \Counter\Admin\CustomTaxonomyOrderingPage::class,
    fn( $c ) => new \Counter\Admin\CustomTaxonomyOrderingPage(
        $c->get( \Counter\Services\TaxonomyOrderingService::class )
    )
);

// REST Controller
$c->set( \Counter\Rest\TaxonomyOrderingController::class,
    fn( $c ) => new \Counter\Rest\TaxonomyOrderingController(
        $c->get( \Counter\Services\TaxonomyOrderingService::class )
    )
);
```

---

## Part 8: Migration Path & Backward Compatibility

### Schema Version Bump

Increment `Schema::VERSION` from 4 → 5 in Schema.php

Add v5 DDL statement to `Schema::statements()` array:

```php
// In Schema::statements()
"CREATE TABLE IF NOT EXISTS taxonomy_orders (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    taxonomy_type   TEXT NOT NULL,
    taxonomy_key    TEXT NOT NULL,
    parent_id       INTEGER,
    position        INTEGER NOT NULL DEFAULT 0,
    enabled         INTEGER NOT NULL DEFAULT 1,
    created_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    updated_at      INTEGER NOT NULL DEFAULT (unixepoch()),
    UNIQUE(taxonomy_type, taxonomy_key, parent_id),
    FOREIGN KEY (parent_id) REFERENCES taxonomy_orders(id) ON DELETE CASCADE
)",
"CREATE INDEX IF NOT EXISTS idx_taxonomy_orders_type_parent ON taxonomy_orders(taxonomy_type, parent_id, position)",
"CREATE INDEX IF NOT EXISTS idx_taxonomy_orders_type_key   ON taxonomy_orders(taxonomy_type, taxonomy_key)",
```

Add optional migration callback in Migrations::applyVersion() if future data migrations are needed:

```php
match ( $version ) {
    // ... existing
    5 => null, // v5 is pure DDL, no data moves needed
    default => null,
};
```

### Existing Attributes/Variants Still Work

- `attributes` table position column continues to work as-is
- `attribute_values` position column continues to work
- `product_variants` position column continues to work
- All existing queries (AttributeRepository, etc.) continue unchanged
- `taxonomy_orders` is opt-in — empty by default

### Safe Deprecation Path (Future)

If Counter later wants to unify all position management into `taxonomy_orders`:

1. Add data migration to copy existing positions from attributes/variants to taxonomy_orders
2. Update queries to read from taxonomy_orders instead of native position columns
3. Deprecate native position columns (keep for backward compat)
4. Remove in v2.0

For now, no data moves necessary — coexist peacefully.

---

## Part 9: Testing Strategy

### Unit Tests

**TaxonomyOrderRepository**
- Insert, read, reorder single item
- Hierarchical reads (with_hierarchy)
- Batch reorder in transaction
- Cycle detection (parent_id update)
- Delete with cascade (children become root)

**TaxonomyOrderingService**
- Validate parent exists
- Validate no cycles
- Transform flat array → tree
- Transform tree → flat moves array
- Sanitize taxonomy_type input

### Integration Tests

**TaxonomyOrderingController**
- GET /admin/taxonomies/{type} returns full tree
- POST /admin/taxonomies/{type}/reorder persists moves
- Concurrent reorder requests don't race
- Invalid parent_id returns 404
- Cycle in payload returns 400
- Unauthorized request returns 403

### Manual UI Tests

- [ ] Drag category under parent category — saves position + parent_id
- [ ] Drag to root — parent_id becomes NULL
- [ ] Drag child away from parent — parent changes, position resets
- [ ] Collapse/expand hierarchy
- [ ] Search filters items
- [ ] "Show disabled" checkbox
- [ ] Undo/reload discards unsaved changes
- [ ] Rapid drag (3+ items in 1s) doesn't cause dupes

### Browser Compatibility

Target:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

SortableJS handles fallbacks via Pointer Events API.

---

## Part 10: Implementation Checklist

### Phase 1: Schema & Database Layer (2-3 hours)
- [ ] Update Schema.php: add VERSION 5, new DDL
- [ ] Create TaxonomyOrderRepository with read/write methods
- [ ] Create TaxonomyOrder model class (value object)
- [ ] Write unit tests for repository (CRUD, hierarchy, cycles)

### Phase 2: Business Logic (2-3 hours)
- [ ] Create TaxonomyOrderingService
- [ ] Implement validation (parent exists, no cycles)
- [ ] Implement tree ↔ flat transformation
- [ ] Write unit tests for service

### Phase 3: REST API (2-3 hours)
- [ ] Create TaxonomyOrderingController
- [ ] Register routes in counter.php
- [ ] Implement GET /admin/taxonomies/{type}
- [ ] Implement POST /admin/taxonomies/{type}/reorder
- [ ] Implement PUT/DELETE endpoints
- [ ] Write integration tests for all endpoints

### Phase 4: Admin UI Pages (3-4 hours)
- [ ] Create abstract TaxonomyOrderingPage base class
- [ ] Create CategoryOrderingPage
- [ ] Create VariantOptionOrderingPage
- [ ] Create CustomTaxonomyOrderingPage
- [ ] Register pages in AdminMenu
- [ ] Wire up asset enqueue

### Phase 5: Frontend JavaScript + CSS (3-4 hours)
- [ ] Create assets/admin/taxonomy-ordering.js
  - [ ] Initialize SortableJS
  - [ ] Fetch & render tree
  - [ ] Serialize tree on drag
  - [ ] Persist via REST POST
  - [ ] Handle errors & show status
  - [ ] Detect unsaved changes
  - [ ] Undo/reload functionality
- [ ] Create assets/admin/taxonomy-ordering.css
  - [ ] Hierarchy indentation
  - [ ] Drag states (ghost, drag)
  - [ ] Status messages
  - [ ] Expand/collapse toggle
- [ ] Test SortableJS integration

### Phase 6: Container & Integration (1-2 hours)
- [ ] Register services in counter_register_container_bindings()
- [ ] Wire up pages in AdminMenu
- [ ] Full end-to-end smoke test

### Phase 7: Testing & Polish (2-3 hours)
- [ ] Unit test coverage (target 90%+)
- [ ] Integration test coverage
- [ ] Manual UI QA
- [ ] Accessibility audit (keyboard nav, screen readers)
- [ ] Performance: test with 1000+ items (tree rendering, drag)

---

## Part 11: Configuration & Extensibility

### Registering Custom Taxonomies

Third-party code can register custom taxonomy types for ordering:

```php
// Plugin or mu-plugin can call after counter_bootstrap_container_for_activation

add_filter( 'counter_custom_taxonomy_types', function( $types ) {
    return array_merge( $types, [
        'vendor' => [
            'label' => 'Vendors',
            'description' => 'POD vendor ordering',
            'hierarchical' => false,
        ],
        'collection' => [
            'label' => 'Collections',
            'description' => 'Curated product collections',
            'hierarchical' => true,
        ],
    ] );
} );
```

CustomTaxonomyOrderingPage reads this filter and displays all registered types.

### Hooks for Extensibility

```php
// Fire when ordering is saved (useful for cache invalidation)
do_action( 'counter_taxonomy_ordering_saved', $type, $tree );

// Allow validation of moves before save
apply_filters( 'counter_validate_taxonomy_moves', $moves, $type );

// Customize REST response
apply_filters( 'counter_taxonomy_ordering_response', $response, $type );
```

---

## Part 12: Performance Considerations

### Database Queries

**Indexes:**
- `taxonomy_orders(taxonomy_type, parent_id, position)` → Fast list queries
- `taxonomy_orders(taxonomy_type, taxonomy_key)` → Fast lookups by key

**Tree Queries:**
- Hierarchical SELECT with recursive CTE (SQLite 3.8.3+):
  ```sql
  WITH RECURSIVE tree AS (
    SELECT * FROM taxonomy_orders WHERE taxonomy_type = ? AND parent_id IS NULL
    UNION ALL
    SELECT t.* FROM taxonomy_orders t
    JOIN tree ON t.parent_id = tree.id
  )
  SELECT * FROM tree ORDER BY parent_id, position
  ```

### Frontend Performance

**SortableJS:**
- Minimal: ~5KB gzipped
- No jQuery dependency
- Native Pointer Events → fast, no polyfill churn
- Shallow DOM: don't clone entire list, just move `<li>` elements

**Rendering Large Trees:**
- Limit tree render to first 500 items
- Lazy-load children on expand
- Virtualization not needed for typical 50-100 categories

### Batch Reorder

- Single transaction: all moves applied atomically
- Position numbering: gap-fill only on affected siblings (not full table rewrite)

---

## Part 13: Accessibility & UX

### Keyboard Navigation

```javascript
// In taxonomy-ordering.js

document.addEventListener( 'keydown', (e) => {
    const item = e.target.closest('[data-taxonomy-item-id]');
    if (!item) return;
    
    if (e.key === 'ArrowUp') {
        // Move item up (decrease position)
    } else if (e.key === 'ArrowDown') {
        // Move item down (increase position)
    } else if (e.key === 'ArrowLeft' && hasChildren(item)) {
        // Collapse subtree
    } else if (e.key === 'ArrowRight' && hasChildren(item)) {
        // Expand subtree
    } else if (e.key === 'Tab') {
        // Native tab; don't prevent
    }
});
```

### ARIA Labels

```html
<li data-taxonomy-item-id="5"
    role="treeitem"
    aria-expanded="true"
    aria-level="2">
  <button class="taxonomy-item__toggle"
          aria-label="Toggle children of Phones">
    <span aria-hidden="true">▼</span>
  </button>
  <span class="taxonomy-item__drag"
        aria-label="Drag to reorder"
        role="button"
        tabindex="0">⋮</span>
  <span>Phones</span>
</li>
```

### Screen Reader Announcements

```javascript
// Announce moves as they happen
const announce = (msg) => {
    const region = document.querySelector('[role="status"]');
    region.textContent = msg;
    region.setAttribute('aria-live', 'polite');
};

// After drag + drop:
announce( 'Phones moved to position 2 under Electronics' );
```

---

## Part 14: Known Limitations & Future Work

### Current Limitations

1. **No drag-and-drop preview for large trees** (1000+ items)
   - **Workaround:** Paginated list view with search
   - **Future:** Virtual scrolling

2. **No bulk operations** (e.g., move 10 items to parent)
   - **Future:** Checkbox select + bulk move dialog

3. **No export/import of ordering**
   - **Future:** CSV download/upload for ordering configs

4. **No version history** (can't undo to previous ordering snapshot)
   - **Future:** Audit log + restore to date

5. **Hierarchies only 5 levels deep** (UX degradation past that)
   - **Workaround:** Use flat taxonomies for vendors/collections
   - **Acceptable:** Most sites use 2-3 levels

### Future Enhancements (Post v0.30)

1. **Variant option matrix view**
   - Show all variants in table, drag columns to reorder options
   - Useful for size/color grids

2. **Auto-numbering**
   - Show position numbers: 1, 2, 3 (not just visual order)
   - Useful for vendors who reference "option #2"

3. **Template presets**
   - "Alphabetical," "by popularity," "newest first"
   - Apply and lock ordering

4. **Sync with WooCommerce**
   - Read/write term order from WP's post_meta.term_order
   - For Woo-source mode products

---

## Part 15: Deployment & Rollout

### Pre-Release Checklist

- [ ] All tests pass (unit, integration, manual)
- [ ] No SQL injection or XSS vectors
- [ ] Backward compatible (all v0.29 installs upgrade cleanly)
- [ ] Performance tested with 1000+ items
- [ ] Accessibility audit passed (WCAG 2.1 AA)
- [ ] Documentation complete
- [ ] Changelog entry written
- [ ] Version bumped to 0.30.0

### Rollout Plan

1. **Beta testing:** Release as 0.30.0-beta.1 to Therum partners
   - Collect feedback on UX, bugs, missing features
   - 1-2 week window

2. **RC release:** 0.30.0-rc.1
   - Final polish, performance tuning
   - Staging server QA

3. **General release:** 0.30.0
   - Push to production
   - Auto-update for existing installs
   - Monitor error logs for schema migration issues

---

## Part 16: Code Examples

### Example: ProductsPage Integration (Future)

Once ordering is live, ProductsPage could show category alongside each product:

```php
// In products-grid.js, after product fetch:
const product = { id: 123, title: 'Widget', category_id: 5, ... };
const category = categories.find(c => c.id === product.category_id);
const categoryPosition = category?.position || '-';

// Render in grid:
<td>${categoryPosition}</td>
```

### Example: Custom Taxonomy Registration

A vendor management feature might do:

```php
// In a service provider or mu-plugin:
add_filter( 'counter_custom_taxonomy_types', function( $types ) {
    $types['vendor'] = [
        'label' => 'Vendors',
        'hierarchical' => false,
        'keys' => [ 'printful', 'teespring', 'aopplus' ], // Auto-register known vendors
    ];
    return $types;
});

// Admin can then reorder vendors in Counter > Taxonomies > Vendors
```

Then queries would use:

```php
$vendors = TaxonomyOrderRepository::getByType( 'vendor' );
// Returns: [ 'printful', 'teespring', 'aopplus' ] in order
```

---

## Summary Table

| Component | File | Status | Complexity |
|-----------|------|--------|------------|
| Schema DDL | Schema.php | NEW | Low |
| Repository | TaxonomyOrderRepository.php | NEW | Medium |
| Service | TaxonomyOrderingService.php | NEW | Medium |
| REST Controller | TaxonomyOrderingController.php | NEW | Medium |
| Category UI | CategoryOrderingPage.php | NEW | Low |
| Variant UI | VariantOptionOrderingPage.php | NEW | Low |
| Custom UI | CustomTaxonomyOrderingPage.php | NEW | Low |
| JavaScript | taxonomy-ordering.js | NEW | High |
| CSS | taxonomy-ordering.css | NEW | Low |
| Admin Menu | AdminMenu.php | EDIT | Low |
| Bootstrap | counter.php | EDIT | Low |
| **Total New Lines** | ~1800 | | |
| **Total Modified Lines** | ~150 | | |

---

## Approval & Sign-Off

**Design Lead:** Claude Code  
**Date:** 2026-06-06  
**Status:** Ready for Implementation  

**Next Step:** Begin Phase 1 (Schema & Database Layer)

---

**End of Implementation Plan**
