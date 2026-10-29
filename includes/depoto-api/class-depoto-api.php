<?php

require __DIR__ . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/class-depoto-diagnostics.php';

use Depoto\Client;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * @package         Depoto_API
 */
class Depoto_API {
	private $depoto;
	private $url;
	private $username;
	private $password;
	private $vat_records;

	public function __construct() {
		if ( ! defined( 'CURL_SSLVERSION_TLSv1_2' ) ) {
			define( 'CURL_SSLVERSION_TLSv1_2', 6 );
		}
		$this->set_login_params();
		$this->init_connection();
	}

	public function init_connection() {
		$this->vat_records = null;
		try {
			$httpClient   = new Psr18Client(); // PSR-18 Http client
			$psr17Factory = new Psr17Factory(); // PSR-17 HTTP Factories,  PSR-7 HTTP message
			$cache        = new Psr16Cache( new ArrayAdapter() ); // PSR-16 Simple cache
			$logger       = new Logger( 'Depoto', [ new NullHandler() ] ); // SDK debug messages contain OAuth secrets; use sanitized diagnostics below.

			$depoto = new Client( $httpClient, $psr17Factory, $psr17Factory, $cache, $logger );
			$depoto
				->setBaseUrl( $this->url )
				// Stage (for testing): https://server1.depoto.cz.tomatomstage.cz
				// Prod: https://server1.depoto.cz
				->setUsername( $this->username )
				->setPassword( $this->password );
			$this->depoto = $depoto;
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO client initialization failed.', $e );
		}
	}

	/**
	 * Set logins parameters saved in WP options
	 *
	 * @return void
	 */
	public function set_login_params(): void {
		$depoto_settings = get_option( 'depoto_settings', [] );

		$this->url      = $depoto_settings['url'] ?? '';
		$this->username = $depoto_settings['username'] ?? '';
		$this->password = $depoto_settings['password'] ?? '';
	}


	/**
	 * Get payment methods from Depoto
	 *
	 * @return array Associative array of Depoto payments as ['id_of_payment' => 'name_of_payment']
	 */
	public function get_payments_pairs(): array {
		$return = [];
		try {
			$result = $this->depoto->query(
				'payments',
				[],
				[
					'items' => [
						'id',
						'type' => [
							'name',
						],
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Payment methods could not be loaded.', $e );
			return $return;
		}

		if ( empty( $result ) ) {
			return $return;
		}

		foreach ( $result['items'] as $item ) {
			if ( ! empty( $item ) ) {
				$return[ 'id_' . $item['id'] ] = $item['type']['name'];
			}
		}

		return $return;
	}

	/**
	 * Get payment methods from Depoto
	 *
	 * @return array Associative array of Depoto payments as ['id_of_payment' => 'name_of_payment']
	 */
	public function get_checkouts(): array {
		$return = [];
		try {
			$result = $this->depoto->query(
				'checkouts',
				[],
				[
					'items' => [
						'id',
						'name',
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Checkouts could not be loaded.', $e );
			return $return;
		}

		if ( empty( $result ) ) {
			return $return;
		}


		foreach ( $result['items'] as $item ) {
			if ( ! empty( $item ) ) {
				$return[ $item['id'] ] = $item['name'];
			}
		}

		return $return;
	}

	/**
	 * Get carriers methods from Depoto
	 *
	 * @return array Associative array of Depoto carriers as ['id_of_carrier' => 'name_of_carrier']
	 */
	public function get_carriers_pairs(): array {
		$return = [];
		try {
			$result = $this->depoto->query(
				'companyCarriers',
				[],
				[
					'items' => [
						'carrier' => [
							'id',
							'name',
						],
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Carriers could not be loaded.', $e );
			return $return;
		}

		if ( empty( $result ) ) {
			return $return;
		}

		foreach ( $result['items'] as $item ) {
			if ( ! empty( $item ) ) {
				$return[ $item['carrier']['id'] ] = $item['carrier']['name'];
			}
		}

		return $return;
	}

	/**
	 * Get order statuses from Depoto
	 *
	 * @return array Associative array of Depoto order statuses as ['id_of_order_state' => 'name_of_order_state']
	 */
	public function get_order_statuses_pairs(): array {
		/**
		 * Get payment methods from Depoto
		 *
		 * @return array Associative array of Depoto payments as ['id_of_payment' => 'name_of_payment']
		 */

		$return = [];
		try {
			$result = $this->depoto->query(
				'processStatuses',
				[],
				[
					'items' => [
						'id',
						'name',
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Order statuses could not be loaded.', $e );
			return $return;
		}

		if ( empty( $result ) ) {
			return $return;
		}

		foreach ( $result['items'] as $item ) {
			if ( ! empty( $item ) ) {
				$return[ $item['id'] ] = $item['name'];
			}
		}

		return $return;
	}

	/** Return account VAT IDs and labels, optionally restricted to genuine 0% records. */
	public function get_taxes_pairs( bool $zero_only = false ): array {
		if ( null === $this->vat_records ) {
			$records = [];
			try {
				$page = 1;
				do {
					$result = $this->depoto->query(
						'vats',
						[ 'page' => $page ],
						[ 'items' => [ 'id', 'name', 'percent' ], 'paginator' => [ 'last' ] ]
					);
					$records = array_merge( $records, $result['items'] ?? [] );
					$last_page = (int) ( $result['paginator']['last'] ?? 1 );
					$page++;
				} while ( $page <= $last_page );
			} catch ( Exception $e ) {
				Depoto_Diagnostics::failure( 'DEPOTO VAT records could not be loaded.', $e );
				return [];
			}
			$this->vat_records = $records;
		}

		$return = [];
		foreach ( $this->vat_records as $item ) {
			$percent = $item['percent'] ?? null;
			$is_zero = is_numeric( $percent ) && 0.0 === (float) $percent;
			if ( ! $zero_only || $is_zero ) {
				$return[ $item['id'] ] = $item['name'] . ( is_numeric( $percent ) ? ' (' . $percent . '%)' : '' ) . ' [ID ' . (int) $item['id'] . ']';
			}
		}
		return $return;
	}

	/**
	 * Get products from Depoto
	 *
	 * @return array Array of Depoto products
	 */
	public function get_products_pairs(): array {
		$return = [];
		try {
			$result_paginator = $this->depoto->query(
				'products',
				[],
				[ 'paginator' => [ 'last' ] ]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Products could not be loaded.', $e );
			return $return;
		}

		$number_of_pages = $result_paginator['paginator']['last'];

		$all_products = [];

		for ( $i = 1; $i <= $number_of_pages; $i ++ ) {
			$result = $this->depoto->query(
				'products',
				[ 'page' => $i ],
				[
					'items' => [
						'id',
						'name',
						'ean',
						'code',
					],
				],
			);

			$all_products = array_merge( $all_products, $result['items'] );
		}

		foreach ( $all_products as $product ) {
			$return[ $product['code'] ] = [ 'id' => $product['id'], 'name' => $product['name'] ];
		}


		return $return;
	}

	/**
	 * Get product availability by Id from Depoto
	 *
	 * @param int $id
	 *
	 * @return int
	 */
	public function get_product_availability_by_ID( int $id ): int {
		$return = - 1;
		try {
			$result = $this->depoto->query(
				'product',
				[ 'id' => $id ],
				[
					'data' => [
						'quantities' => [
							'quantityAvailable',
						],
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Product availability lookup failed.', $e, [ 'depoto_id' => (int) $id ] );
			return $return;
		}


		$return = intval( $result["data"]["quantities"][0]["quantityAvailable"] ) ?? - 1;

		return $return;
	}

	/**
	 * Get order status by Id from Depoto
	 *
	 * @param int $id
	 *
	 * @return string
	 */
	public function get_order_status_by_ID( int $id ): string {
		$return = '';
		try {
			$result = $this->depoto->query(
				'order',
				[ 'id' => $id ],
				[
					'data' => [
						'processStatus' => [
							'id',
						],
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Order status lookup failed.', $e, [ 'depoto_id' => (int) $id ] );
			return $return;
		}


		$return = $result["data"]["processStatus"]["id"] ?? $return;

		return $return;
	}


	/**
	 * Create address in depoto
	 *
	 * @return int ID of the result address in depoto
	 */
	public function create_address( $order_address ): int {
		try {
			$resultAddress = $this->depoto->mutation(
				'createAddress',
				$order_address,
				[ 'data' => [ 'id' ] ]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO createAddress failed.', $e );
			return 0;
		}

		return $resultAddress['data']['id'];
	}

	/**
	 * Create address in depoto
	 *
	 * @return int ID of the result address in depoto
	 * @throws Exception
	 */
	public function update_order( array $data ) {
		if ( ! $data['id'] ) {
			throw new Exception( 'Order ID is missing' );
		}

		return $this->depoto->mutation(
			'updateOrder',
			$data,
			[ 'data' => [ 'id' ], 'errors' ] );
	}

	/**
	 * Create address in depoto
	 *
	 * @return int ID of the result address in depoto
	 * @throws Exception
	 */
	public function cancel_order( $id ) {
		return $this->depoto->mutation(
			'deleteReservation',
			[ 'id' => $id ],
			[ 'errors' ] );
	}

	public function create_order( $data, $wc_order_id = 0 ): int {
		$context = [ 'wc_order_id' => (int) $wc_order_id, 'items' => Depoto_Diagnostics::items( $data['items'] ?? [] ) ];
		Depoto_Diagnostics::write( 'info', 'DEPOTO createOrder request (sanitized).', $context );
		try {
			$result = $this->depoto->mutation( 'createOrder', $data, [ 'data' => [ 'id' ] ] );
		} catch ( \Throwable $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO createOrder failed.', $e, $context );
			throw $e;
		}
		Depoto_Diagnostics::write( 'info', 'DEPOTO createOrder succeeded.', [ 'wc_order_id' => (int) $wc_order_id, 'depoto_order_id' => (int) $result['data']['id'] ] );
		return $result['data']['id'];
	}

	public function create_product( $data ): int {
		try {
			$result = $this->depoto->mutation( 'createProduct', $data, [ 'data' => [ 'id' ] ] );
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Product creation failed.', $e );
			return 0;
		}

		return $result['data']['id'];
	}

	public function create_file( $data ): int {
		try {
			$result = $this->depoto->mutation( 'createFile', $data, [ 'data' => [ 'id' ] ] );
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO File upload failed.', $e );
			return 0;
		}

		return $result['data']['id'];
	}

	/**
	 * Check if the connection is established by simple query
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		$return = false;

		// This is just a random query, which find out vats, we can use anything else to test the connection
		try {
			$result = $this->depoto->query(
				'vats',
				[],
				[
					'items' => [
						'id',
						'name',
					],
				]
			);
		} catch ( Exception $e ) {
			Depoto_Diagnostics::failure( 'DEPOTO Connection check failed.', $e );
			return $return;
		}

		return true;
	}
}
