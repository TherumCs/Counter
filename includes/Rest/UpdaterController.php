<?php
/**
 * Shop by Therum — REST: updater.
 *
 *   GET    /shop/v1/admin/updater/status            git status + snapshot list
 *   POST   /shop/v1/admin/updater/snapshot          take a manual snapshot
 *   POST   /shop/v1/admin/updater/pull              git fetch + reset --hard
 *   POST   /shop/v1/admin/updater/install-zip       multipart upload + extract
 *   POST   /shop/v1/admin/updater/rollback          { id }
 *   DELETE /shop/v1/admin/updater/snapshot/{id}     delete a snapshot
 *
 * Auth is gated on `manage_options` (NOT manage_woocommerce) because
 * this can rewrite the filesystem — shop admins shouldn't be able to
 * hot-swap the plugin while a WP admin can.
 *
 * The pull / rollback / install flows auto-snapshot before mutating
 * the working tree, so rollback always has a candidate.
 */

namespace Shop\Rest;

use Shop\Services\Updater;

if ( ! defined( 'ABSPATH' ) ) exit;

final class UpdaterController {

	public const NAMESPACE = 'shop/v1';

	public function __construct( private readonly Updater $updater ) {}

	public function register(): void {
		$auth = fn(): bool => current_user_can( 'manage_options' );

		register_rest_route( self::NAMESPACE, '/admin/updater/status', [
			'methods' => 'GET', 'callback' => [ $this, 'status' ], 'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/updater/snapshot', [
			'methods' => 'POST', 'callback' => [ $this, 'snapshot' ], 'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/updater/pull', [
			'methods' => 'POST', 'callback' => [ $this, 'pull' ], 'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/updater/install-zip', [
			'methods' => 'POST', 'callback' => [ $this, 'installZip' ], 'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/updater/rollback', [
			'methods' => 'POST', 'callback' => [ $this, 'rollback' ], 'permission_callback' => $auth,
		] );
		register_rest_route( self::NAMESPACE, '/admin/updater/snapshot/(?P<id>[\w\-\.]+)', [
			'methods' => 'DELETE', 'callback' => [ $this, 'deleteSnapshot' ], 'permission_callback' => $auth,
		] );
	}

	public function status( \WP_REST_Request $req ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'version'   => defined( 'SHOP_VERSION' ) ? SHOP_VERSION : null,
			'git'       => $this->updater->gitStatus(),
			'snapshots' => $this->updater->snapshots(),
			'locked'    => (bool) get_transient( 'shop_updater_locked' ),
		], 200 );
	}

	public function snapshot( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$body = $req->get_json_params() ?: [];
			$note = isset( $body['note'] ) ? sanitize_text_field( (string) $body['note'] ) : null;
			$id   = $this->updater->snapshot( $note );
			return new \WP_REST_Response( [ 'ok' => true, 'id' => $id ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}

	public function pull( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$body   = $req->get_json_params() ?: [];
			$branch = isset( $body['branch'] ) ? sanitize_text_field( (string) $body['branch'] ) : 'main';
			$snap   = $this->updater->snapshot( 'pre-pull from origin/' . $branch );
			$pulled = $this->updater->pullFromGit( $branch );
			return new \WP_REST_Response( [ 'ok' => true, 'snapshot' => $snap, 'pulled' => $pulled ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}

	public function installZip( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$files = $req->get_file_params();
			if ( empty( $files['zip']['tmp_name'] ) ) {
				return new \WP_REST_Response( [ 'error' => 'No file uploaded.' ], 400 );
			}
			if ( ( $files['zip']['error'] ?? UPLOAD_ERR_OK ) !== UPLOAD_ERR_OK ) {
				return new \WP_REST_Response( [ 'error' => 'Upload error code ' . $files['zip']['error'] ], 400 );
			}
			$clean  = (bool) ( $req->get_param( 'clean' ) ?? false );
			$snap   = $this->updater->snapshot( 'pre-zip-install ' . ( $files['zip']['name'] ?? '' ) );
			$result = $this->updater->installFromZip( (string) $files['zip']['tmp_name'], $clean );
			return new \WP_REST_Response( [ 'ok' => true, 'snapshot' => $snap, 'result' => $result ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}

	public function rollback( \WP_REST_Request $req ): \WP_REST_Response {
		try {
			$body = $req->get_json_params() ?: [];
			$id   = (string) ( $body['id'] ?? '' );
			if ( $id === '' ) return new \WP_REST_Response( [ 'error' => 'Snapshot id required.' ], 400 );
			$res  = $this->updater->restoreSnapshot( $id );
			return new \WP_REST_Response( [ 'ok' => true, 'result' => $res ], 200 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( [ 'error' => $e->getMessage() ], 500 );
		}
	}

	public function deleteSnapshot( \WP_REST_Request $req ): \WP_REST_Response {
		$this->updater->deleteSnapshot( (string) $req->get_param( 'id' ) );
		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}
}
