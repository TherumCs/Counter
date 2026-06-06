<?php
/**
 * Counter by Therum — TaxonomyOrder model.
 *
 * Represents a single hierarchical taxonomy term ordering record.
 */

namespace Counter\Models;

if ( ! defined( 'ABSPATH' ) ) exit;

final class TaxonomyOrder {

	public readonly int $id;
	public readonly string $taxonomy;
	public readonly int $termId;
	public readonly ?int $parentId;
	public readonly int $position;
	public readonly int $createdAt;
	public readonly int $updatedAt;

	public function __construct(
		int $id,
		string $taxonomy,
		int $termId,
		?int $parentId,
		int $position,
		int $createdAt,
		int $updatedAt,
	) {
		$this->id = $id;
		$this->taxonomy = $taxonomy;
		$this->termId = $termId;
		$this->parentId = $parentId;
		$this->position = $position;
		$this->createdAt = $createdAt;
		$this->updatedAt = $updatedAt;
	}

	/**
	 * Hydrate from database row.
	 *
	 * @param array<string,mixed> $row
	 */
	public static function fromRow( array $row ): self {
		return new self(
			id:        (int) $row['id'],
			taxonomy:  (string) $row['taxonomy'],
			termId:    (int) $row['term_id'],
			parentId:  isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			position:  (int) $row['position'],
			createdAt: (int) $row['created_at'],
			updatedAt: (int) $row['updated_at'],
		);
	}

	/**
	 * Convert to array for JSON responses.
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return [
			'id'        => $this->id,
			'taxonomy'  => $this->taxonomy,
			'term_id'   => $this->termId,
			'parent_id' => $this->parentId,
			'position'  => $this->position,
		];
	}
}
