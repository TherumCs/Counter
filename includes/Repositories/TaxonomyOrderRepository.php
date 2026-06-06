<?php
/**
 * Counter by Therum — TaxonomyOrderRepository.
 *
 * Read/write interface for taxonomy term ordering (hierarchical).
 */

namespace Counter\Repositories;

use Counter\DB;
use Counter\Models\TaxonomyOrder;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TaxonomyOrderRepository {

	/**
	 * Get the full tree for a taxonomy in hierarchical order.
	 *
	 * @param string $taxonomy Taxonomy slug (e.g., 'product_categories', 'vendors')
	 * @return TaxonomyOrder[]
	 */
	public function getTree( string $taxonomy ): array {
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT * FROM taxonomy_orders
			  WHERE taxonomy = :tax
			  ORDER BY parent_id, position ASC"
		);
		$stmt->execute( [ ':tax' => $taxonomy ] );

		$items = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$items[] = TaxonomyOrder::fromRow( $row );
		}
		return $items;
	}

	/**
	 * Get children of a parent term.
	 *
	 * @param string $taxonomy
	 * @param int|null $parentId NULL returns root-level terms
	 * @return TaxonomyOrder[]
	 */
	public function getChildren( string $taxonomy, ?int $parentId = null ): array {
		$pdo = DB::pdo();
		if ( $parentId === null ) {
			$stmt = $pdo->prepare(
				"SELECT * FROM taxonomy_orders
				  WHERE taxonomy = :tax AND parent_id IS NULL
				  ORDER BY position ASC"
			);
			$stmt->execute( [ ':tax' => $taxonomy ] );
		} else {
			$stmt = $pdo->prepare(
				"SELECT * FROM taxonomy_orders
				  WHERE taxonomy = :tax AND parent_id = :parent
				  ORDER BY position ASC"
			);
			$stmt->execute( [ ':tax' => $taxonomy, ':parent' => $parentId ] );
		}

		$items = [];
		foreach ( $stmt->fetchAll() as $row ) {
			$items[] = TaxonomyOrder::fromRow( $row );
		}
		return $items;
	}

	/**
	 * Get a single term's order record (or null if not ordered).
	 *
	 * @param string $taxonomy
	 * @param int $termId
	 */
	public function findByTerm( string $taxonomy, int $termId ): ?TaxonomyOrder {
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"SELECT * FROM taxonomy_orders WHERE taxonomy = :tax AND term_id = :tid"
		);
		$stmt->execute( [ ':tax' => $taxonomy, ':tid' => $termId ] );
		$row = $stmt->fetch();
		return $row ? TaxonomyOrder::fromRow( $row ) : null;
	}

	/**
	 * Create or update a term's ordering record.
	 *
	 * @param string $taxonomy
	 * @param int $termId
	 * @param int $position
	 * @param int|null $parentId
	 */
	public function upsert( string $taxonomy, int $termId, int $position, ?int $parentId = null ): TaxonomyOrder {
		// Prevent circular references
		if ( $parentId !== null ) {
			$this->validateNoCircularReference( $taxonomy, $termId, $parentId );
		}

		$pdo = DB::pdo();
		$existing = $this->findByTerm( $taxonomy, $termId );

		if ( $existing ) {
			$stmt = $pdo->prepare(
				"UPDATE taxonomy_orders
				  SET position = :pos, parent_id = :parent, updated_at = unixepoch()
				  WHERE taxonomy = :tax AND term_id = :tid"
			);
			$stmt->execute( [
				':pos'    => $position,
				':parent' => $parentId,
				':tax'    => $taxonomy,
				':tid'    => $termId,
			] );
		} else {
			$stmt = $pdo->prepare(
				"INSERT INTO taxonomy_orders (taxonomy, term_id, position, parent_id, created_at, updated_at)
				  VALUES (:tax, :tid, :pos, :parent, unixepoch(), unixepoch())"
			);
			$stmt->execute( [
				':tax'    => $taxonomy,
				':tid'    => $termId,
				':pos'    => $position,
				':parent' => $parentId,
			] );
		}

		return $this->findByTerm( $taxonomy, $termId )
			?? throw new \RuntimeException( "Failed to upsert taxonomy order: $taxonomy:$termId" );
	}

	/**
	 * Batch reorder multiple terms atomically.
	 *
	 * @param string $taxonomy
	 * @param array<int, array{position: int, parent_id?: int|null}> $updates Keyed by term_id
	 */
	public function batchReorder( string $taxonomy, array $updates ): void {
		$pdo = DB::pdo();
		$pdo->beginTransaction();

		try {
			foreach ( $updates as $termId => $data ) {
				$this->upsert(
					$taxonomy,
					(int) $termId,
					(int) $data['position'],
					$data['parent_id'] ?? null
				);
			}
			$pdo->commit();
		} catch ( \Throwable $e ) {
			$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * Remove a term's ordering record.
	 *
	 * @param string $taxonomy
	 * @param int $termId
	 */
	public function delete( string $taxonomy, int $termId ): bool {
		$pdo = DB::pdo();
		$stmt = $pdo->prepare(
			"DELETE FROM taxonomy_orders WHERE taxonomy = :tax AND term_id = :tid"
		);
		return $stmt->execute( [ ':tax' => $taxonomy, ':tid' => $termId ] );
	}

	/**
	 * Prevent circular parent/child references.
	 *
	 * @throws \DomainException if circular reference detected
	 */
	private function validateNoCircularReference( string $taxonomy, int $termId, int $parentId ): void {
		if ( $termId === $parentId ) {
			throw new \DomainException( "Term cannot be its own parent" );
		}

		// Walk up the parent chain — if we hit $termId, it's circular
		$current = $parentId;
		$visited = [];
		while ( $current !== null ) {
			if ( $current === $termId ) {
				throw new \DomainException( "Circular parent relationship detected" );
			}
			if ( isset( $visited[ $current ] ) ) {
				break; // Cycle in existing hierarchy, but not with $termId
			}
			$visited[ $current ] = true;

			$parent = $this->findByTerm( $taxonomy, $current );
			$current = $parent?->parentId;
		}
	}
}
