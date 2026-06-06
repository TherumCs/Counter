# Counter Taxonomy Ordering System - Architecture Diagram

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         COUNTER ADMIN                           │
│  (WordPress Admin Dashboard)                                    │
└────┬────────────────────────────────────────────────────────────┘
     │
     ├─► Counter Admin Menu (AdminMenu.php)
     │   ├─► Settings
     │   ├─► Products
     │   ├─► Orders
     │   │
     │   └─► [NEW] Taxonomy Ordering Pages
     │       ├─► Category Ordering (CategoryOrderingPage)
     │       ├─► Variant Options (VariantOptionOrderingPage)
     │       └─► Custom Taxonomies (CustomTaxonomyOrderingPage)
     │
     └─► Assets Enqueue (AdminMenu::assets())
         ├─► sortablejs (from CDN, 1.15.0)
         ├─► taxonomy-ordering.css (new)
         └─► taxonomy-ordering.js (new)
```

## Data Flow Diagram

### Reading Taxonomy Order

```
User Opens Category Ordering Page
    │
    ├─► PHP: CategoryOrderingPage::render()
    │   └─► Renders empty div with [data-taxonomy-type="category"]
    │
    └─► Browser: taxonomy-ordering.js
        │
        ├─► fetchTaxonomyTree( 'category' )
        │   │
        │   └─► REST GET /wp-json/counter/v1/admin/taxonomies/category
        │       │
        │       └─► TaxonomyOrderingController::listTaxonomies()
        │           │
        │           └─► TaxonomyOrderingService::getDisplayTree( 'category' )
        │               │
        │               └─► TaxonomyOrderRepository::getWithHierarchy( 'category' )
        │                   │
        │                   └─► Query: taxonomy_orders table
        │                       (WITH RECURSIVE if needed)
        │
        ├─► Receive JSON tree
        │
        └─► renderTaxonomyTree( tree, listElement )
            ├─► Create nested <ul><li> elements
            ├─► Attach drag handles
            ├─► Attach expand/collapse toggles
            └─► Attach SortableJS
```

### Reordering (Drag-Drop)

```
User Drags Item
    │
    ├─► SortableJS detects drop
    │   └─► Calls onEnd() handler
    │
    └─► JavaScript in taxonomy-ordering.js
        │
        ├─► serializeTree( listElement )
        │   └─► Walk DOM tree, collect position/parent changes
        │
        ├─► Validate no cycles (validateNoCycle())
        │   └─► Walk up ancestor chain
        │
        ├─► Mark unsaved (showUnsavedWarning())
        │   └─► Display yellow notice bar
        │
        └─► [Waiting for user to click "Save"]
            │
            └─► saveOrdering( type, moves )
                │
                └─► REST POST /wp-json/counter/v1/admin/taxonomies/{type}/reorder
                    │
                    └─► TaxonomyOrderingController::reorderBatch()
                        │
                        └─► TaxonomyOrderingService::persistReorderFromPayload()
                            │
                            └─► TaxonomyOrderRepository::reorderBatch()
                                │
                                └─► SQLite Transaction
                                    ├─► UPDATE taxonomy_orders
                                    │   SET position = ?, parent_id = ?
                                    │   WHERE id = ? AND taxonomy_type = ?
                                    │
                                    └─► COMMIT (all or nothing)
```

## Class Diagram

```
┌─────────────────────────────────┐
│   TaxonomyOrderRepository       │
├─────────────────────────────────┤
│ - pdo                           │
├─────────────────────────────────┤
│ + getByType()                   │
│ + getWithHierarchy()            │
│ + upsert()                      │
│ + reorderBatch()                │
│ + delete()                      │
│ + moveSubtree()                 │
└──────────────┬──────────────────┘
               │ uses
               ↓
        ┌────────────────┐
        │ SQLite DB      │
        ├────────────────┤
        │ taxonomy_orders│
        │ (position,     │
        │  parent_id,    │
        │  enabled,...)  │
        └────────────────┘


┌──────────────────────────────────┐
│ TaxonomyOrderingService          │
├──────────────────────────────────┤
│ - repo: TaxonomyOrderRepository  │
│ - attrRepo: AttributeRepository  │
├──────────────────────────────────┤
│ + applyHierarchy()               │
│ + getDisplayTree()               │
│ + validateParentExists()         │
│ + validateNoCycles()             │
│ + persistReorderFromPayload()    │
└──────────────┬───────────────────┘
               │ uses
               ↓
        ┌──────────────────┐
        │ TaxonomyOrder    │
        │ (Value Object)   │
        ├──────────────────┤
        │ + id             │
        │ + taxonomyType   │
        │ + key            │
        │ + parentId       │
        │ + position       │
        │ + enabled        │
        │ + children[]     │
        └──────────────────┘


┌───────────────────────────────────┐
│ TaxonomyOrderingController        │
├───────────────────────────────────┤
│ - service: TaxonomyOrderingService│
├───────────────────────────────────┤
│ + listTaxonomies()                │ → GET /counter/v1/admin/taxonomies/{type}
│ + reorderBatch()                  │ → POST /counter/v1/admin/taxonomies/{type}/reorder
│ + updateItem()                    │ → PUT /counter/v1/admin/taxonomies/{type}/{id}
│ + deleteItem()                    │ → DELETE /counter/v1/admin/taxonomies/{type}/{id}
└─────────────────────────────────┘


┌────────────────────────────────┐
│ TaxonomyOrderingPage (Abstract)│
├────────────────────────────────┤
│ - service                      │
├────────────────────────────────┤
│ + render() [shared template]   │
│ # getTaxonomyType()            │
│ # getTaxonomyLabel()           │
└──────────┬─────────────────────┘
           │
           ├─── CategoryOrderingPage
           │    └─ getTaxonomyType() → 'category'
           │
           ├─── VariantOptionOrderingPage
           │    └─ getTaxonomyType() → 'variant_option'
           │
           └─── CustomTaxonomyOrderingPage
                └─ getTaxonomyType() → user-selected custom type
```

## Database Schema

### New Table: taxonomy_orders

```
┌──────────────────────────────────────────────────────┐
│            taxonomy_orders (v5 addition)             │
├──────────────────────────────────────────────────────┤
│ id              INTEGER PRIMARY KEY AUTOINCREMENT    │
│ taxonomy_type   TEXT NOT NULL                        │
│                 ['category', 'variant_option',       │
│                  'vendor', 'collection', ...]        │
│ taxonomy_key    TEXT NOT NULL                        │
│                 [slug/identifier of the item]        │
│ parent_id       INTEGER REFERENCES taxonomy_orders   │
│                 [NULL for root items]                │
│ position        INTEGER NOT NULL DEFAULT 0           │
│                 [0-based order among siblings]       │
│ enabled         INTEGER NOT NULL DEFAULT 1           │
│ created_at      INTEGER NOT NULL (Unix timestamp)   │
│ updated_at      INTEGER NOT NULL (Unix timestamp)   │
│                                                      │
│ UNIQUE(taxonomy_type, taxonomy_key, parent_id)     │
│ FK: parent_id → taxonomy_orders(id) ON DELETE CASCADE
│                                                      │
│ INDEX: (taxonomy_type, parent_id, position)         │
│ INDEX: (taxonomy_type, taxonomy_key)                │
└──────────────────────────────────────────────────────┘
```

**Example Data:**

```
id  | taxonomy_type  | taxonomy_key | parent_id | position | enabled
----|----------------|--------------|-----------|----------|--------
 1  | category       | electronics  | NULL      | 0        | 1
 2  | category       | clothing     | NULL      | 1        | 1
 3  | category       | books        | NULL      | 2        | 1
 5  | category       | phones       | 1         | 0        | 1
 6  | category       | laptops      | 1         | 1        | 1
 7  | category       | tablets      | 1         | 2        | 1
 15 | vendor         | printful     | NULL      | 0        | 1
 16 | vendor         | teespring    | NULL      | 1        | 1
 25 | variant_option | red          | NULL      | 0        | 1
 26 | variant_option | blue         | NULL      | 1        | 1
```

## REST API Routes

```
GET /wp-json/counter/v1/admin/taxonomies/category
├─ Params: ?include_tree=true, ?parent_id=1
└─ Response:
   {
     "type": "category",
     "items": [
       {
         "id": 1,
         "key": "electronics",
         "parent_id": null,
         "position": 0,
         "children": [...]
       },
       ...
     ]
   }

POST /wp-json/counter/v1/admin/taxonomies/category/reorder
├─ Body:
   {
     "moves": [
       { "id": 5, "parent_id": 1, "position": 0 },
       { "id": 1, "parent_id": null, "position": 1 }
     ]
   }
└─ Response:
   {
     "success": true,
     "updated_count": 2,
     "tree": [...]
   }

PUT /wp-json/counter/v1/admin/taxonomies/category/5
├─ Body: { "parent_id": 2, "position": 0 }
└─ Response: { "id": 5, "parent_id": 2, "position": 0, ... }

DELETE /wp-json/counter/v1/admin/taxonomies/category/5
└─ Response: { "deleted": true, "id": 5 }
```

## Frontend Component Tree

```
CategoryOrderingPage (renders to #counter-admin)
│
├─ <div class="counter-admin">
│  │
│  ├─ <h1 class="counter-admin__title">
│  │  └─ "Category Ordering"
│  │
│  ├─ <div class="counter-taxonomy-ordering__controls">
│  │  ├─ <input data-taxonomy-search />
│  │  └─ <input type="checkbox" data-taxonomy-show-disabled />
│  │
│  ├─ <div class="counter-taxonomy-ordering__list" 
│  │       data-taxonomy-type="category"
│  │       data-taxonomy-list>
│  │  │ [Rendered by JavaScript]
│  │  │
│  │  ├─ <ul class="taxonomy-list" data-sortable>
│  │  │  ├─ <li data-taxonomy-item-id="1">
│  │  │  │  ├─ <span class="taxonomy-item__drag">⋮</span>
│  │  │  │  ├─ <button class="taxonomy-item__toggle">▼</button>
│  │  │  │  ├─ <span>Electronics</span>
│  │  │  │  │
│  │  │  │  └─ <ul class="taxonomy-list__children">
│  │  │  │     ├─ <li data-taxonomy-item-id="5">
│  │  │  │     │  └─ <span>Phones</span>
│  │  │  │     │
│  │  │  │     └─ <li data-taxonomy-item-id="6">
│  │  │  │        └─ <span>Laptops</span>
│  │  │  │
│  │  │  └─ <li data-taxonomy-item-id="2">
│  │  │     └─ <span>Clothing</span>
│  │  │
│  │  └─ [Sortable.js handles reordering]
│  │
│  ├─ <div class="counter-taxonomy-ordering__status" 
│  │       data-taxonomy-status
│  │       hidden></div>
│  │
│  └─ <div class="notice notice-warning"
│       id="counter-taxonomy-ordering-unsaved"
│       hidden>
│     Unsaved changes...
│  </div>
```

## Drag-and-Drop State Machine

```
                    ┌─────────────────┐
                    │   Page Loads    │
                    └────────┬────────┘
                             │
                             ↓
                    ┌─────────────────┐
                    │  Fetch Data     │ (REST GET)
                    │  from REST      │
                    └────────┬────────┘
                             │
                             ↓
                  ┌──────────────────────┐
                  │  Render Tree in DOM  │
                  │  Attach SortableJS   │
                  └────────┬─────────────┘
                           │
                           ↓
                   ┌────────────────┐
        ┌──────────┤  Awaiting Drag │◄────────────┐
        │          └────────┬───────┘             │
        │                   │                     │
        │                   │ (User drags item)   │
        │                   ↓                     │
        │          ┌─────────────────┐            │
        │          │  Item Dragging  │            │
        │          │  (ghost shown)  │            │
        │          └────────┬────────┘            │
        │                   │                     │
        │                   ↓                     │
        │    ┌──────────────────────┐             │
        │    │  Item Dropped        │             │
        │    │  (onEnd triggered)   │             │
        │    └────────┬─────────────┘             │
        │             │                           │
        │             ↓                           │
        │   ┌──────────────────────┐              │
        │   │ Validate Tree        │              │
        │   │ (check cycles)       │──(invalid)──┤
        │   └────────┬─────────────┘              │
        │            │ (valid)                    │
        │            ↓                            │
        │   ┌──────────────────────┐              │
        │   │ Mark Unsaved         │              │
        │   │ (show warning bar)   │              │
        │   │ (enable save btn)    │              │
        │   └────────┬─────────────┘              │
        │            │                            │
        └────────────┤                            │
                     │ (Next drag)                │
                     ↓                            │
        ┌──────────────────────────┐              │
        │ Awaiting User Action     │              │
        ├──────────────────────────┤              │
        │ - Save (POST to REST)    │              │
        │ - Discard (reload)       │              │
        │ - Continue dragging      │──────────────┘
        └──────────────────────────┘
              │          │
              │ Save     │ Discard
              ↓          ↓
        ┌─────────┐  ┌─────────┐
        │ Saving… │  │ Reloading│
        └────┬────┘  └────┬────┘
             │            │
             ↓            ↓
        ┌─────────────────────────┐
        │ REST Response Received  │
        └────┬────────────────────┘
             │
             ├─(success)─→ ✓ Show "Saved!"
             │             Hide warning bar
             │             Ready for next drag
             │
             └─(error)───→ ✗ Show error message
                           Offer retry
```

## Data Transformation Examples

### Example 1: Flat List → Tree

**Input (from database):**
```json
[
  { "id": 1, "key": "electronics", "parent_id": null, "position": 0 },
  { "id": 5, "key": "phones", "parent_id": 1, "position": 0 },
  { "id": 6, "key": "laptops", "parent_id": 1, "position": 1 },
  { "id": 2, "key": "clothing", "parent_id": null, "position": 1 }
]
```

**Output (tree):**
```json
[
  {
    "id": 1,
    "key": "electronics",
    "parent_id": null,
    "position": 0,
    "children": [
      {
        "id": 5,
        "key": "phones",
        "parent_id": 1,
        "position": 0,
        "children": []
      },
      {
        "id": 6,
        "key": "laptops",
        "parent_id": 1,
        "position": 1,
        "children": []
      }
    ]
  },
  {
    "id": 2,
    "key": "clothing",
    "parent_id": null,
    "position": 1,
    "children": []
  }
]
```

### Example 2: DOM Tree → Move Commands

**User Action:** Drag "Phones" under "Clothing" (move id=5 from parent 1 to parent 2)

**Serialized Output:**
```json
{
  "moves": [
    {
      "id": 5,
      "parent_id": 2,     // Changed from 1
      "position": 0       // New position under new parent
    },
    {
      "id": 6,
      "parent_id": 1,     // Shifted up
      "position": 0       // Now first under Electronics
    },
    {
      "id": 1,
      "parent_id": null,
      "position": 0       // Unchanged
    },
    {
      "id": 2,
      "parent_id": null,
      "position": 1       // Unchanged
    }
  ]
}
```

**Database Transactions:**
```sql
BEGIN TRANSACTION;

UPDATE taxonomy_orders 
SET parent_id = 2, position = 0, updated_at = unixepoch() 
WHERE id = 5 AND taxonomy_type = 'category';

UPDATE taxonomy_orders 
SET position = 0, updated_at = unixepoch() 
WHERE id = 6 AND taxonomy_type = 'category';

-- (1 and 2 unchanged, can omit from batch)

COMMIT;
```

---

## Integration Points with Existing Counter

### 1. Attribute System (Already Sorted by Position)

```php
// AttributeRepository::variantAttributesFor()
// Line 49: ORDER BY pa.position ASC, a.position ASC
// ✓ Already respects position — no changes needed

// Line 61: ORDER BY av.position ASC, av.id ASC
// ✓ Already respects attribute_values.position
// ✓ Our taxonomy_orders system can be used for category hierarchies
```

### 2. Product Display (Respects Position)

```php
// When rendering variants in product detail:
$variants = $repo->getVariantsForProduct( $product_id );
// Variants are fetched with ORDER BY position
// ✓ Frontend automatically shows in custom order
```

### 3. Future Enhancements (Out of Scope)

```php
// Potential future integration:
$categories = TaxonomyOrderRepository::getWithHierarchy( 'category' );
// Use in breadcrumbs, navigation menus, etc.
// Once integrated, categories will be displayed in admin-set order
```

---

**Architecture designed by:** Claude Code  
**Date:** 2026-06-06  
**Status:** Ready for Implementation
