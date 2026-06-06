# Taxonomy Ordering System - Quick Reference Guide

## For Developers Building This Feature

### File Checklist (19 new/modified files)

**NEW FILES:**

1. `includes/Repositories/TaxonomyOrderRepository.php` (250-300 lines)
2. `includes/Services/TaxonomyOrderingService.php` (200-250 lines)
3. `includes/Models/TaxonomyOrder.php` (50-80 lines)
4. `includes/Rest/TaxonomyOrderingController.php` (200-250 lines)
5. `includes/Admin/TaxonomyOrderingPage.php` (100-150 lines, abstract base)
6. `includes/Admin/CategoryOrderingPage.php` (50-100 lines, extends base)
7. `includes/Admin/VariantOptionOrderingPage.php` (50-100 lines, extends base)
8. `includes/Admin/CustomTaxonomyOrderingPage.php` (100-150 lines, extends base)
9. `assets/admin/taxonomy-ordering.js` (400-500 lines)
10. `assets/admin/taxonomy-ordering.css` (150-200 lines)

**MODIFIED FILES:**

11. `includes/Schema.php` (add v5 DDL, +15 lines)
12. `includes/Migrations.php` (add v5 callback, +5 lines)
13. `includes/Admin/AdminMenu.php` (add 3 subpages, +50 lines)
14. `counter.php` (add container bindings, +40 lines)
15. `assets/admin/admin.css` (import taxonomy-ordering.css, +1 line)

**TOTAL:** ~1900 lines new code, ~120 lines modified

### Dependencies & Imports

Each file needs these base imports:

```php
<?php
namespace Counter\[SubNamespace];

if ( ! defined( 'ABSPATH' ) ) exit;

// Then class definition
```

**Common Dependencies:**
- `Counter\DB` — for PDO access
- `Counter\Container::instance()` — for container access
- `Counter\Repositories\AttributeRepository` — for attribute lookups

### Key Constants

```php
// In TaxonomyOrderRepository
const TAXONOMY_TYPES = [
    'category',           // Product categories
    'variant_option',     // Variant options (Color, Size, etc.)
    'vendor',             // POD vendors
    'collection',         // Custom product collections
];

// In TaxonomyOrderingController
const NAMESPACE = 'counter/v1';
const MAX_NESTING_DEPTH = 5;
const MAX_ITEMS_PER_BATCH = 100;  // Reorder sanity check
```

### Testing Template

Each new class should have a corresponding test:

```php
// tests/Unit/Repositories/TaxonomyOrderRepositoryTest.php
namespace Counter\Tests\Unit\Repositories;

use PHPUnit\Framework\TestCase;
use Counter\Repositories\TaxonomyOrderRepository;

class TaxonomyOrderRepositoryTest extends TestCase {
    private TaxonomyOrderRepository $repo;
    
    protected function setUp(): void {
        // Create test DB
        // Instantiate repo
    }
    
    public function test_getByType_returns_items_sorted_by_position(): void {
        // Arrange
        // Act
        // Assert
    }
    
    // ... more tests
}
```

---

## For Code Reviewers

### Critical Review Points

1. **SQL Injection Prevention**
   - All user input must be parameterized (:param, ?)
   - No string concatenation in queries
   - Whitelist taxonomy_type values: `if (!in_array($type, TAXONOMY_TYPES))`

2. **REST Authorization**
   - All endpoints check `manage_woocommerce` capability
   - Use `current_user_can()` in permission_callback
   - Sanitize/validate all JSON input

3. **Circular Reference Prevention**
   - `validateNoCycles()` must walk up ancestor chain
   - Depth limit of 5 enforced in service layer
   - Batch reorder must validate all moves before transaction

4. **Transaction Safety**
   - SQLite `BEGIN / COMMIT / ROLLBACK` in reorderBatch()
   - Position gaps filled correctly (no skips)
   - Children of deleted items reparented

5. **Frontend XSS Prevention**
   - All item names escaped: `esc_html()`
   - JSON responses use `wp_json_encode()`
   - SortableJS doesn't inject raw HTML

### Performance Red Flags

- [ ] Tree query is NOT recursive (use WITH RECURSIVE in SQLite)
- [ ] Reorder updates multiple rows (batch transaction, OK)
- [ ] No N+1: single query for full tree, not per-item
- [ ] Index on (taxonomy_type, parent_id, position) exists
- [ ] No full-table scans in position renumbering

### Backward Compatibility Checklist

- [ ] Existing `attributes.position` still read/written by AttributeRepository
- [ ] Existing `product_variants.position` unchanged
- [ ] Existing queries still sort by position (no breaking changes)
- [ ] Old installs without `taxonomy_orders` still work
- [ ] v0.29 products display fine in v0.30 (no position data loss)

---

## For QA / Manual Testing

### Admin Pages to Test

**Category Ordering** (counter-category-ordering)
- [ ] Page loads and fetches categories
- [ ] Categories nested under parents show indentation
- [ ] Click triangle to collapse/expand children
- [ ] Drag category within same parent → reorders
- [ ] Drag category to different parent → parent changes
- [ ] Drag to root → parent_id becomes NULL
- [ ] Save button appears after drag
- [ ] Click Save → POST request sent, status shows "Saved!"
- [ ] Reload page → order persists
- [ ] Error in save → red error message with retry link

**Variant Options** (counter-variant-ordering)
- [ ] Page groups options by attribute (Color, Size, etc.)
- [ ] Each attribute section shows its values in order
- [ ] Drag within attribute reorders values
- [ ] Save persists to attribute_values.position
- [ ] Multiple attributes can be reordered independently

**Custom Taxonomies** (counter-custom-taxonomy-ordering)
- [ ] Dropdown shows registered taxonomy types
- [ ] Select "Vendors" → loads vendor list
- [ ] Vendors reorder flat (no nesting)
- [ ] Select "Collections" → loads collection list
- [ ] Collections can nest (hierarchical)

### Browser Testing

| Browser | Version | Test |
|---------|---------|------|
| Chrome | Latest | Drag-drop works; smooth animation |
| Firefox | Latest | Drag-drop works; no console errors |
| Safari | Latest | Drag-drop works; check pointer events |
| Edge | Latest | Drag-drop works; Sortable.js fallback OK |

### Edge Cases to Test

```
Scenario 1: Drag Cycle Prevention
  - Have: Category > Subcategory > Sub-subcategory
  - Try: Drag Sub-subcategory over Subcategory (would be cycle)
  - Expect: Drop rejected, category stays in place
  
Scenario 2: Large Tree (100+ items)
  - Test: Fetch, render, drag
  - Expect: < 2s load time, smooth 60fps drag
  
Scenario 3: Concurrent Reorders
  - Have: Two admin tabs with same page open
  - Action: Reorder in Tab A, save
  - Action: Reorder different item in Tab B, save
  - Expect: Both saves succeed, no lost updates
  
Scenario 4: Parent Deleted
  - Have: Categories: A > B (B has parent_id = A's id)
  - Action: Delete A from taxonomy_orders
  - Expect: B becomes root (parent_id = NULL)
  
Scenario 5: Position Gaps
  - Have: Positions 0, 1, 2 of 4 items
  - Action: Delete item at position 1
  - Expect: Remaining items stay at 0, 2 (gap OK, or auto-fill to 0, 1)
```

### Performance Benchmark Targets

```
Page Load Times:
  - 10 categories: < 500ms
  - 100 categories: < 1s
  - 1000 categories (flattened): < 3s
  
Drag Performance:
  - No jank (60fps) up to 100 items
  - Pointer events used (not mousedown/mouseup)
  
Save Performance:
  - Reorder 10 items: < 100ms
  - Reorder 50 items: < 500ms
  - Database transaction (atomicity check)
```

---

## For Designers / UX Review

### Color Tokens Used

```css
--counter-ac: #e83b3b           /* Accent red (drag handle) */
--counter-ac-h: #c92e2e         /* Accent hover */
--counter-ac-s: rgba(..., 0.08) /* Accent soft background */
--counter-ok: #10b981           /* Green (success message) */
--counter-bd: rgba(0,0,0, 0.08) /* Border light */
--counter-bd-2: rgba(0,0,0, 0.16) /* Border dark */
--counter-sf-2: #f7f7f6         /* Surface light gray */
--counter-tx-3: #999            /* Text tertiary */
```

### Component States

| State | Visual | CSS Class | Trigger |
|-------|--------|-----------|---------|
| Default | Item with grab handle | `.taxonomy-item` | Page load |
| Hover drag | Handle opaque, cursor changes | `.taxonomy-item__drag:hover` | Mouse over |
| Dragging | 40% opacity, ghost shows placeholder | `.sortable-ghost` | During drag |
| Drop zone | Blue-ish highlight on parent | `.sortable-target` | Hover while dragging |
| Unsaved | Yellow warning bar | `.notice.notice-warning` | After drop |
| Saving | Status "Saving…" gray | `[data-status="saving"]` | During POST |
| Saved | Status "Saved!" green | `[data-status="success"]` | After 200 OK |
| Error | Status "Error…" red | `[data-status="error"]` | After 4xx/5xx |

### Accessibility Review

- [ ] All interactive elements keyboard-accessible (Tab, Enter, Arrow keys)
- [ ] Screen reader announces "Drag to reorder" on handles
- [ ] ARIA live region for status updates
- [ ] Color not the only indicator (text + icon for states)
- [ ] Focus indicators visible (not removed)
- [ ] Collapse/expand announces expanded state

---

## API Request/Response Cheat Sheet

### GET /counter/v1/admin/taxonomies/category

**Success (200):**
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
      "created_at": 1712345678,
      "children": [
        { "id": 5, "key": "phones", "parent_id": 1, "position": 0, "children": [] }
      ]
    }
  ]
}
```

**Error (403):**
```json
{ "code": "rest_forbidden", "message": "You do not have permission to manage shop content." }
```

### POST /counter/v1/admin/taxonomies/category/reorder

**Request:**
```json
{
  "moves": [
    { "id": 5, "parent_id": 2, "position": 0 },
    { "id": 1, "parent_id": null, "position": 1 }
  ]
}
```

**Success (200):**
```json
{
  "success": true,
  "updated_count": 2,
  "tree": [ {...} ]
}
```

**Bad Request (400):**
```json
{
  "code": "invalid_moves",
  "message": "Move 5→3 would create a cycle",
  "failed_move": { "id": 5, "parent_id": 3 }
}
```

**Not Found (404):**
```json
{
  "code": "not_found",
  "message": "Taxonomy item 99 not found"
}
```

---

## Common Code Patterns

### Iterating a Hierarchy in PHP

```php
function walkTree( array $items, callable $callback, ?int $parentId = null, int $depth = 0 ): void {
    foreach ( $items as $item ) {
        if ( $item['parent_id'] !== $parentId ) continue;
        
        $callback( $item, $depth );
        
        // Recurse to children
        walkTree( $items, $callback, $item['id'], $depth + 1 );
    }
}

// Usage:
walkTree( $allItems, function( $item, $depth ) {
    echo str_repeat( '  ', $depth ) . $item['key'] . "\n";
} );
```

### Validating No Cycles in JavaScript

```javascript
function validateNoCycle( draggedId, targetParentId, itemsMap ) {
    if ( targetParentId === null ) return true;  // Root is always OK
    
    let current = itemsMap[ targetParentId ];
    const visited = new Set();
    
    while ( current ) {
        if ( visited.has( current.id ) ) break;  // Cycle in data!
        visited.add( current.id );
        
        if ( current.id === draggedId ) {
            return false;  // Cycle detected
        }
        
        current = itemsMap[ current.parent_id ];
    }
    
    return true;
}
```

### Building Tree from Flat Array

```php
function buildTree( array $items ): array {
    $indexed = [];
    foreach ( $items as $item ) {
        $indexed[ $item['id'] ] = $item + [ 'children' => [] ];
    }
    
    $roots = [];
    foreach ( $indexed as $id => &$item ) {
        if ( $item['parent_id'] === null ) {
            $roots[] = &$item;
        } else {
            $indexed[ $item['parent_id'] ]['children'][] = &$item;
        }
    }
    
    return $roots;
}
```

---

## Debugging Checklist

### REST Endpoint Not Working?

1. [ ] Check permissions: `current_user_can( 'manage_woocommerce' )`
2. [ ] Verify route registered: Check AdminController `register()` called
3. [ ] Nonce correct: `wp_nonce_field()` in form or X-WP-Nonce header
4. [ ] JSON structure valid: Use online JSON validator
5. [ ] Database table exists: `SELECT * FROM taxonomy_orders LIMIT 1`
6. [ ] No SQL errors: Check `error_log` or browser dev tools Network tab

### Drag Not Working?

1. [ ] SortableJS loaded: `<script src="...sortablejs..."></script>` in HTML
2. [ ] Target element exists: `[data-taxonomy-list]` present
3. [ ] Not disabled: Check CSS `pointer-events: none`
4. [ ] No JS errors: Open browser console (F12), check for red X
5. [ ] Correct handle selector: `.taxonomy-item__drag` must exist
6. [ ] Touch events for mobile: SortableJS includes touch support

### Positions Not Persisting?

1. [ ] POST request succeeds: Check Network tab for 200 OK
2. [ ] Database updated: SELECT position FROM taxonomy_orders WHERE id = X
3. [ ] Cache cleared: Check if WordPress caching layer involved
4. [ ] Transaction committed: No ROLLBACK in error log
5. [ ] Reload page: Browser cache vs. fresh data

---

## Version History & Changelog

**v0.30.0 (Planned)**
- [x] Design taxonomy ordering system
- [ ] Implement schema migration (v5)
- [ ] Build REST API endpoints
- [ ] Build admin pages + SortableJS integration
- [ ] Write tests (target 90% coverage)
- [ ] Documentation + examples
- [ ] Beta release
- [ ] General availability

---

## Related Documentation

1. **IMPLEMENTATION_PLAN_TAXONOMY_ORDERING.md** — Full 16-part implementation plan (this guide references it)
2. **TAXONOMY_ORDERING_ARCHITECTURE.md** — Visual diagrams, data flow, class structure
3. **Schema.php** — Current v4 schema; v5 additions go here
4. **AttributeRepository.php** — Shows existing position usage pattern
5. **AdminController.php** — Shows REST endpoint pattern (lines 76-96)

---

## Quick Links

| Resource | Link | Purpose |
|----------|------|---------|
| SortableJS Docs | https://sortablejs.github.io/Sortable/ | Drag-drop library |
| SQLite Recursion | https://www.sqlite.org/lang_with.html | WITH RECURSIVE syntax |
| WordPress REST | https://developer.wordpress.org/rest-api/ | WP REST conventions |
| Counter Schema | `includes/Schema.php` | Table definitions |
| Container | `includes/Container.php` | DI pattern |
| AdminMenu | `includes/Admin/AdminMenu.php` | Page registration |

---

**Last Updated:** 2026-06-06  
**Status:** Ready for Implementation  
**Owner:** Claude Code
