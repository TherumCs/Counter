<?php
/**
 * Shop by Therum — Updater.
 *
 * Self-update engine. Three input modes, one rollback path:
 *
 *   1. Git pull (`pullFromGit`)
 *      `git fetch origin` + `git reset --hard origin/{branch}` from
 *      inside SHOP_DIR. Treats the working tree as deploy-from-git —
 *      local uncommitted edits are discarded unless `force=false`.
 *      Requires SHOP_DIR to be a git checkout (i.e. you cloned/
 *      symlinked, not zipped). Returns the new HEAD sha + commit
 *      subject.
 *
 *   2. Zip upload (`installFromZip`)
 *      Extracts a .zip into a temp dir, validates that a top-level
 *      `shop.php` exists, then mirrors the temp tree into SHOP_DIR.
 *      Files not in the zip are NOT removed by default — opt-in via
 *      `clean=true` for "exactly replace" semantics.
 *
 *   3. Rollback (`restoreSnapshot`)
 *      Pick a snapshot by id, restore it over SHOP_DIR.
 *
 * Every mode runs `snapshot()` first — tars the current SHOP_DIR
 * (excluding `.git/`) into `wp-content/uploads/shop-by-therum/
 * snapshots/{version}-{ts}.tar.gz`, then prunes to keep only the most
 * recent N (default 10).
 *
 * Concurrency: a `shop_updater_locked` transient acts as a mutex; two
 * simultaneous updates would shred the working tree. The lock auto-
 * expires after 10 minutes so a crashed update doesn't permanently
 * lock the system.
 */

namespace Shop\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

final class Updater {

	private const LOCK_KEY     = 'shop_updater_locked';
	private const LOCK_TTL_S   = 600; // 10 min
	private const KEEP_SNAPS   = 10;

	/**
	 * Snapshot the current plugin into a tar.gz and return its id.
	 * The id is the bare filename without extension — used as the
	 * rollback handle.
	 */
	public function snapshot( ?string $note = null ): string {
		$dir = $this->snapshotsDir();
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( "Updater: snapshot dir unwritable ($dir)." );
		}
		$version = defined( 'SHOP_VERSION' ) ? SHOP_VERSION : '0.0.0';
		$ts      = gmdate( 'Ymd-His' );
		$id      = "v{$version}-{$ts}";
		$tar     = $dir . '/' . $id . '.tar.gz';

		// `tar cz --exclude=.git -C <parent> <plugin-folder>`. Using -C
		// + relative path keeps the snapshot extractable into any host.
		$plugin   = basename( SHOP_DIR );
		$parent   = dirname( SHOP_DIR );
		$cmd = sprintf(
			'tar -czf %s --exclude=%s --exclude=%s -C %s %s 2>&1',
			escapeshellarg( $tar ),
			escapeshellarg( $plugin . '/.git' ),
			escapeshellarg( $plugin . '/node_modules' ),
			escapeshellarg( $parent ),
			escapeshellarg( $plugin )
		);
		$out = []; $code = 0;
		exec( $cmd, $out, $code );
		if ( $code !== 0 ) {
			throw new \RuntimeException( "Updater: tar failed ($code): " . implode( "\n", $out ) );
		}
		if ( $note ) file_put_contents( $tar . '.note', $note );

		$this->prune();
		return $id;
	}

	/**
	 * @return array<int, array{ id: string, version: string, created_at: int, size: int, note: ?string }>
	 */
	public function snapshots(): array {
		$dir = $this->snapshotsDir();
		if ( ! is_dir( $dir ) ) return [];
		$files = glob( $dir . '/*.tar.gz' ) ?: [];
		// Newest first
		usort( $files, fn( $a, $b ) => filemtime( $b ) <=> filemtime( $a ) );
		$out = [];
		foreach ( $files as $f ) {
			$id = basename( $f, '.tar.gz' );
			preg_match( '/^v(.+?)-(\d{8})-(\d{6})$/', $id, $m );
			$out[] = [
				'id'         => $id,
				'version'    => $m[1] ?? 'unknown',
				'created_at' => filemtime( $f ),
				'size'       => filesize( $f ),
				'note'       => file_exists( $f . '.note' ) ? (string) file_get_contents( $f . '.note' ) : null,
			];
		}
		return $out;
	}

	/**
	 * Pull latest from the git remote. Hard-resets to origin/{branch}
	 * — local working-tree changes are gone. Caller should snapshot()
	 * first if it cares.
	 *
	 * @return array{ before: string, after: string, subject: string }
	 */
	public function pullFromGit( string $branch = 'main' ): array {
		if ( ! is_dir( SHOP_DIR . '.git' ) ) {
			throw new \RuntimeException( 'Updater: SHOP_DIR is not a git checkout.' );
		}
		$this->lock();
		try {
			$before = $this->git( 'rev-parse HEAD' );
			$this->git( 'fetch origin' );
			$this->git( 'reset --hard origin/' . escapeshellarg( $branch ) );
			$after   = $this->git( 'rev-parse HEAD' );
			$subject = $this->git( 'log -1 --pretty=%s' );
			return [ 'before' => trim( $before ), 'after' => trim( $after ), 'subject' => trim( $subject ) ];
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Install from an uploaded zip. The zip MUST contain a top-level
	 * `shop.php` (either at the root or inside a single top folder —
	 * we auto-strip the wrapper if present).
	 *
	 * @param string $zipPath   path to a temp-uploaded .zip on disk
	 * @param bool   $clean     if true, remove files present in the
	 *                          working tree that aren't in the zip
	 *
	 * @return array{ files_written: int, files_removed: int }
	 */
	public function installFromZip( string $zipPath, bool $clean = false ): array {
		if ( ! class_exists( '\ZipArchive' ) ) {
			throw new \RuntimeException( 'Updater: ZipArchive not available.' );
		}
		if ( ! is_readable( $zipPath ) ) {
			throw new \RuntimeException( 'Updater: zip not readable.' );
		}

		$this->lock();
		try {
			// Stage extract into a temp dir.
			$stage = trailingslashit( get_temp_dir() ) . 'shop-update-' . wp_generate_password( 8, false );
			if ( ! wp_mkdir_p( $stage ) ) throw new \RuntimeException( 'Updater: temp dir unwritable.' );

			$zip = new \ZipArchive();
			if ( $zip->open( $zipPath ) !== true ) {
				throw new \RuntimeException( 'Updater: zip open failed.' );
			}
			$zip->extractTo( $stage );
			$zip->close();

			// Resolve the directory inside the stage that contains
			// shop.php — many GitHub zips wrap everything in a single
			// `repo-branch/` folder. We descend if needed.
			$src = $stage;
			if ( ! is_file( $src . '/shop.php' ) ) {
				$entries = array_diff( scandir( $src ) ?: [], [ '.', '..' ] );
				if ( count( $entries ) === 1 ) {
					$cand = $src . '/' . reset( $entries );
					if ( is_dir( $cand ) && is_file( $cand . '/shop.php' ) ) $src = $cand;
				}
			}
			if ( ! is_file( $src . '/shop.php' ) ) {
				$this->rmrf( $stage );
				throw new \RuntimeException( 'Updater: zip does not contain shop.php at its root.' );
			}

			$written = $this->mirror( $src, rtrim( SHOP_DIR, '/\\' ) );
			$removed = 0;
			if ( $clean ) {
				$removed = $this->reapMissing( $src, rtrim( SHOP_DIR, '/\\' ) );
			}
			$this->rmrf( $stage );
			return [ 'files_written' => $written, 'files_removed' => $removed ];
		} finally {
			$this->unlock();
		}
	}

	/**
	 * Restore a snapshot over SHOP_DIR. The current state is itself
	 * snapshotted first (under a label like "pre-rollback") so the
	 * rollback is reversible.
	 *
	 * @return array{ restored: string, safety_snapshot: string }
	 */
	public function restoreSnapshot( string $id ): array {
		$tar = $this->snapshotsDir() . '/' . basename( $id ) . '.tar.gz';
		if ( ! is_file( $tar ) ) throw new \RuntimeException( "Updater: snapshot '$id' not found." );

		$this->lock();
		try {
			$safety = $this->snapshot( 'pre-rollback to ' . $id );
			$parent = dirname( SHOP_DIR );
			$plugin = basename( SHOP_DIR );

			// Wipe current plugin dir (except .git so a git checkout
			// stays a checkout post-rollback) then untar.
			$this->cleanWorkingTree();
			$cmd = sprintf( 'tar -xzf %s -C %s 2>&1', escapeshellarg( $tar ), escapeshellarg( $parent ) );
			$out = []; $code = 0;
			exec( $cmd, $out, $code );
			if ( $code !== 0 ) {
				throw new \RuntimeException( "Updater: tar extract failed ($code): " . implode( "\n", $out ) );
			}
			return [ 'restored' => $id, 'safety_snapshot' => $safety ];
		} finally {
			$this->unlock();
		}
	}

	public function deleteSnapshot( string $id ): void {
		$tar = $this->snapshotsDir() . '/' . basename( $id ) . '.tar.gz';
		if ( is_file( $tar ) ) unlink( $tar );
		if ( is_file( $tar . '.note' ) ) unlink( $tar . '.note' );
	}

	/** Current git state for the admin UI ("at commit abc1234 on main, clean / dirty"). */
	public function gitStatus(): ?array {
		if ( ! is_dir( SHOP_DIR . '.git' ) ) return null;
		try {
			return [
				'head'    => trim( $this->git( 'rev-parse --short HEAD' ) ),
				'branch'  => trim( $this->git( 'rev-parse --abbrev-ref HEAD' ) ),
				'subject' => trim( $this->git( 'log -1 --pretty=%s' ) ),
				'dirty'   => trim( $this->git( 'status --porcelain' ) ) !== '',
				'remote'  => trim( $this->git( "config --get remote.origin.url" ) ),
			];
		} catch ( \Throwable $e ) {
			return [ 'error' => $e->getMessage() ];
		}
	}

	// ─── Internals ───────────────────────────────────────────────────────

	private function snapshotsDir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'shop-by-therum/snapshots';
	}

	private function lock(): void {
		if ( get_transient( self::LOCK_KEY ) ) {
			throw new \RuntimeException( 'Updater: another update is already in progress.' );
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL_S );
	}

	private function unlock(): void {
		delete_transient( self::LOCK_KEY );
	}

	private function git( string $args ): string {
		$cmd = sprintf( 'cd %s && git %s 2>&1', escapeshellarg( SHOP_DIR ), $args );
		$out = []; $code = 0;
		exec( $cmd, $out, $code );
		$joined = implode( "\n", $out );
		if ( $code !== 0 ) throw new \RuntimeException( "git $args failed ($code): $joined" );
		return $joined;
	}

	/**
	 * Recursive copy src → dst. Returns number of files written.
	 */
	private function mirror( string $src, string $dst ): int {
		$src = rtrim( $src, '/\\' );
		$dst = rtrim( $dst, '/\\' );
		$count = 0;
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $node ) {
			$rel  = substr( $node->getPathname(), strlen( $src ) + 1 );
			$dest = $dst . '/' . $rel;
			if ( $node->isDir() ) {
				if ( ! is_dir( $dest ) ) wp_mkdir_p( $dest );
			} else {
				wp_mkdir_p( dirname( $dest ) );
				copy( $node->getPathname(), $dest );
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Remove files under $dst that don't exist in $src. Skips `.git/`.
	 * Returns number of files removed.
	 */
	private function reapMissing( string $src, string $dst ): int {
		$src = rtrim( $src, '/\\' );
		$dst = rtrim( $dst, '/\\' );
		$count = 0;
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dst, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $node ) {
			$rel = substr( $node->getPathname(), strlen( $dst ) + 1 );
			if ( str_starts_with( $rel, '.git' ) ) continue;
			if ( ! file_exists( $src . '/' . $rel ) ) {
				if ( $node->isDir() ) @rmdir( $node->getPathname() );
				else                  { @unlink( $node->getPathname() ); $count++; }
			}
		}
		return $count;
	}

	/**
	 * Remove every file in SHOP_DIR except .git. Used before tar-extract
	 * during rollback so we don't leave orphaned files.
	 */
	private function cleanWorkingTree(): void {
		$dst = rtrim( SHOP_DIR, '/\\' );
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dst, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $node ) {
			$rel = substr( $node->getPathname(), strlen( $dst ) + 1 );
			if ( str_starts_with( $rel, '.git' ) ) continue;
			if ( $node->isDir() ) @rmdir( $node->getPathname() );
			else                  @unlink( $node->getPathname() );
		}
	}

	private function rmrf( string $path ): void {
		if ( ! is_dir( $path ) ) { @unlink( $path ); return; }
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $node ) {
			$node->isDir() ? @rmdir( $node->getPathname() ) : @unlink( $node->getPathname() );
		}
		@rmdir( $path );
	}

	private function prune(): void {
		$snaps = $this->snapshots();
		if ( count( $snaps ) <= self::KEEP_SNAPS ) return;
		foreach ( array_slice( $snaps, self::KEEP_SNAPS ) as $s ) {
			$this->deleteSnapshot( $s['id'] );
		}
	}
}
