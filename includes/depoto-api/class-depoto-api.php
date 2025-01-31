<?php
require __DIR__ . '/vendor/autoload.php';

use Depoto\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpClient\Psr18Client;

/**
 * @package         Depoto_API
 */
class Depoto_API
{
	private $depoto;
	private $url;
	private $username;
	private $password;

	public function __construct()
	{
		if (!defined('CURL_SSLVERSION_TLSv1_2')) {
			define('CURL_SSLVERSION_TLSv1_2', 6);
		}
		$this->set_login_params();
		$this->init_connection();
	}

	public function init_connection()
	{
		try {
			$httpClient = new Psr18Client(); // PSR-18 Http client
			$psr17Factory = new Psr17Factory(); // PSR-17 HTTP Factories,  PSR-7 HTTP message
			$cache = new Psr16Cache(new ArrayAdapter()); // PSR-16 Simple cache
			$logger = new Logger('Depoto', [new StreamHandler('depoto.log', Logger::DEBUG)]); // PSR-3 Logger

			$depoto = new Client($httpClient, $psr17Factory, $psr17Factory, $cache, $logger);
			$depoto
				->setBaseUrl($this->url) 
                                // Stage (for testing): https://server1.depoto.cz.tomatomstage.cz
				// Prod: https://server1.depoto.cz
				->setUsername($this->username)
				->setPassword($this->password);
			$this->depoto = $depoto;
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	/**
	 * Set logins parameters saved in WP options
	 *
	 * @return void
	 */
	public function set_login_params(): void
	{
		$depoto_settings = get_option('depoto_settings', []);

		$this->url = $depoto_settings['url'] ?? '';
		$this->username = $depoto_settings['username'] ?? '';
		$this->password = $depoto_settings['password'] ?? '';
	}


	/**
	 * Get payment methods from Depoto
	 *
	 * @return array Associative array of Depoto payments as ['id_of_payment' => 'name_of_payment']
	 */
	public function get_payments_pairs(): array
	{
		$return = [];
		try {

			$result = $this->depoto->query(
				'payments',
				[],
				[
					'items' => [
						'id',
						'type' => [
							'name'
						]
					]
				]
			);
		} catch (Exception $e) {
			return $return;
		}

		if (empty($result)) {
			return $return;
		}

		foreach ($result['items'] as $item) {
			if (!empty($item)) {
				$return['id_' . $item['id']] = $item['type']['name'];
			}
		}
		return $return;
	}

	/**
	 * Get carriers methods from Depoto
	 *
	 * @return array Associative array of Depoto carriers as ['id_of_carrier' => 'name_of_carrier']
	 */
	public function get_carriers_pairs(): array
	{
		$return = [];
		try {
			$result = $this->depoto->query(
				'companyCarriers',
				[],
				[
					'items' => [
						'carrier' => [
							'id', 'name'
						]
					]
				]
			);
		} catch (Exception $e) {
			return $return;
		}

		if (empty($result)) {
			return $return;
		}

		foreach ($result['items'] as $item) {
			if (!empty($item)) {
				$return[$item['carrier']['id']] = $item['carrier']['name'];
			}
		}
		return $return;
	}

	/**
	 * Get order statuses from Depoto
	 * Depoto does not have an endpoint for the list of order statuses so this method is instead of that
	 *
	 * @return array Associative array of Depoto order statuses as ['id_of_order_state' => 'name_of_order_state']
	 */
	public function get_order_statuses_pairs(): array
	{

		$return = [
			'recieved' => 'Přijatá',
			'picking' => 'Vyskladnění',
			'packing' => 'Balení',
			'packed' =>	'Zabaleno',
			'dispatched' =>	'Předáno dopravci',
			'delivered' => 'Doručeno',
			'returned' => 'Nedoručeno (Vráceno)',
			'picking_error' => 'Chyba vyskladnění',
			'cancelled' => 'Zrušeno'
		];

		return $return;
	}
	/**
	 * Get taxes from Depoto
	 *
	 * @return array Associative array of Depoto order statuses as ['id_of_order_state' => 'name_of_order_state']
	 */
	public function get_taxes_pairs(): array
	{
		$return = [];
		try {

			$result = $this->depoto->query(
				'vats',
				[],
				[
					'items' => [
						'id', 'name'
					]
				]
			);
		} catch (Exception $e) {
			return $return;
		}

		if (empty($result)) {
			return $return;
		}

		foreach ($result['items'] as $item) {
			if (!empty($item)) {
				$return[$item['id']] = $item['name'];
			}
		}
		return $return;
	}

	/**
	 * Get products from Depoto
	 *
	 * @return array Array of Depoto products
	 */
	public function get_products_pairs(): array
	{
		$return = [];
		try {

			$result_paginator = $this->depoto->query(
				'products',
				[],
				['paginator' => ['last']]
			);
		} catch (Exception $e) {
			return $return;
		}

		$number_of_pages = $result_paginator['paginator']['last'];

		$all_products = [];

		for ($i = 1; $i <= $number_of_pages; $i++) {

			$result = $this->depoto->query(
				'products',
				['page' => $i],
				['items' => [
					'id', 'name', 'ean', 'code',
				]],
			);

			$all_products = array_merge($all_products, $result['items']);
		}

		foreach ($all_products as $product) {
			$return[$product['code']] = ['id' => $product['id'], 'name' => $product['name']];
		}


		return $return;
	}

	/**
	 * Get product availability by Id from Depoto
	 *
	 * @param  int $id
	 * @return int
	 */
	public function get_product_availability_by_ID(int $id): int
	{
		$return = -1;
		try {
			$result = $this->depoto->query(
				'product',
				['id' => $id],
				['data' => [
					'quantities' => [
						'quantityAvailable'
					]
				]]
			);
		} catch (Exception $e) {
			return $return;
		}


		$return = intval($result["data"]["quantities"][0]["quantityAvailable"]) ?? -1;

		return $return;
	}

	/**
	 * Get order status by Id from Depoto
	 *
	 * @param  int $id
	 * @return string
	 */
	public function get_order_status_by_ID(int $id): string
	{
		$return = '';
		try {
			$result = $this->depoto->query(
				'order',
				['id' => $id],
				['data' => [
					'processStatus' => [
						'id'
					]
				]]
			);
		} catch (Exception $e) {
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
	public function create_address($order_address): int
	{
		try {

			$resultAddress = $this->depoto->mutation(
				'createAddress',
				$order_address,
				['data' => ['id']]
			);
		} catch (Exception $e) {
			return 0;
		}

		return $resultAddress['data']['id'];
	}


	public function create_order($data): int
	{
		try {

			$result = $this->depoto->mutation('createOrder', $data, ['data' => ['id']]);
		} catch (Exception $e) {
			return 0;
		}
		return $result['data']['id'];
	}
	/**
	 * Check if the connection is established by simple query
	 *
	 * @return bool
	 */
	public function is_connected(): bool
	{

		$return = false;

		// This is just a random query, which find out vats, we can use anything else to test the connection
		try {
			$result = $this->depoto->query(
				'vats',
				[],
				[
					'items' => [
						'id', 'name'
					]
				]
			);
		} catch (Exception $e) {
			return $return;
		}

		return true;
	}
}
