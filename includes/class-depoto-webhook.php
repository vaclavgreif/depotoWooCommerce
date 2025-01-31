<?php

/**
 * @package         Depoto_Webhook
 */
class Depoto_Webhook
{
	/** @var Depoto_API $depoto_api */
	private $depoto_api;
	/*
	 $order_status_pairs is array in this form
	 --------
	 key = Woocommerce value,
	 value = Depoto value
	[
		wc-pending => "dispatched",
		wc-processing => "received",
		wc-on-hold => "received",
		wc-completed => "received",
		...
	]
	 */
	private $order_status_pairs;

	public function __construct($depoto_api)
	{
		$this->order_status_pairs = get_option('depoto_order_statuses');
		$this->depoto_api = $depoto_api;
		add_action('rest_api_init', [$this, 'register_route']);
	}

	public function register_route()
	{
		register_rest_route(
			'depoto/v1',
			'/shop',
			array(
				'methods' => 'POST',
				'callback' => [$this, 'grab_data'],
				'permission_callback' => '__return_true'
			)
		);
		register_rest_route(
			'depoto/v1',
			'/process-stack',
			array(
				'methods' => 'GET',
				'callback' => [$this, 'process_webhook_stack'],
				'permission_callback' => '__return_true'
			)
		);
	}

	/**
	 * grab_data
	 *
	 * @param  WP_REST_Request $data
	 * @return void
	 */
	public function grab_data($data)
	{

		/* $data_array
		----------------
		["type"]=>"product.availability"
  		["payload"] => [
			["id"] => int(12346789),
 			["ean"] => NULL,
			["code"] => "bowling-shirt-beige-l"
			.
			.
			.
		]
		 */
		$data_array = $data->get_json_params();

		//Pick up just the data we need
		$record['type'] = htmlspecialchars($data_array['type'] ?? '');
		$record['id'] = intval($data_array['payload']['id'] ?? 0);
		$record['code'] = htmlentities($data_array['payload']['code'] ?? '');

		$depoto_webhook_stack = get_option('depoto_webhook_stack');
		if (empty($depoto_webhook_stack)) {
			$depoto_webhook_stack = [];
		}

		$depoto_webhook_stack[] = $record;

		update_option('depoto_webhook_stack', $depoto_webhook_stack);
	}

	public function process_webhook_stack()
	{
		$stack = get_option('depoto_webhook_stack');
		if (!$stack) {
			_e('There are no records in the stack.', 'depoto');
			echo '<br><br>';
			return;
		}

		foreach ($stack as $record) {
			switch ($record['type']) {
				case 'product.availability':
					$this->process_product_availability($record);
				case 'order.processStatus':
					$this->process_order_status($record);
				default:
					//
					break;
			}
		}

		update_option('depoto_webhook_stack', '');
	}

	/**
	 * Process product availability
	 *
	 * Gets quantity from depoto and set quantity in Woocommerce
	 *
	 * @param  array $record ['type', 'id', 'code']
	 * @return bool
	 */
	private function process_product_availability($record): bool
	{
		$id = $record['id'];

		$quantityAvailable = $this->depoto_api->get_product_availability_by_ID($id);
		if (-1 === $quantityAvailable) {
			return false;
		}

		/** @var WC_Product $product */
		$product = $this->get_product_by_depoto_id($id);
		if (empty($product)) {
			return false;
		}

		echo $id . ' setted product availability to ' . $quantityAvailable . '<br>';
		$product->set_manage_stock(true);
		$product->set_stock_quantity($quantityAvailable);
		$product->save();
		return true;
	}

	/**
	 * Get Woocommerce product by deptoto ID
	 *
	 * @param  int $depoto_id
	 * @return null|WC_Product
	 */
	private function get_product_by_depoto_id(int $depoto_id)
	{
		if (empty($depoto_id) || !is_numeric($depoto_id)) {
			return null;
		}
		/** @var WC_Product[] $products*/
		$products = wc_get_products(['limit' => -1]);

		foreach ($products as $product) {
			$meta_depoto_id = $product->get_meta('_depoto_id');
			if (!empty($meta_depoto_id)) {
				if ($meta_depoto_id == $depoto_id) {
					return $product;
				}
			}
			if ('variable' == $product->get_type()) {
				/** @var WC_Product_Variable $product */
				$variations = $product->get_available_variations('objects');
				foreach ($variations as $variation) {
					$variation_meta_depoto_id = $variation->get_meta('_depoto_id');
					if (!empty($variation_meta_depoto_id)) {
						if ($variation_meta_depoto_id == $depoto_id) {
							return $variation;
						}
					}
				}
			}
		}
		return null;
	}


	/**
	 * Process order status
	 *
	 * Gets order status from depoto and set order status in Woocommerce
	 *
	 * @param  array $record ['type', 'id', 'code']
	 * @return bool
	 */
	private function process_order_status($record): bool
	{
		$id = intval($record['id']);

		$depoto_order_status = $this->depoto_api->get_order_status_by_ID($id);
		if (!$depoto_order_status) {
			return false;
		}
		/** @var WC_Order */
		$woocommerce_order = $this->get_order_by_depoto_id($id);
		if (empty($woocommerce_order)) {
			return false;
		}

		// we need to add prefix wc- because get_status() returns state without it
		$woocommerce_order_state = 'wc-' . $woocommerce_order->get_status();

		// we check if the depoto status is the same as actual state of the Woocommerce order
		// if yes, we do not change the state because more Woocommerce states can have associated the same Depoto state
		if ($this->order_status_pairs[$woocommerce_order_state] == $depoto_order_status) {
			return false;
		}

		$woocommerce_order_new_state_key = array_search($depoto_order_status, $this->order_status_pairs);
		if (!$woocommerce_order_new_state_key) {
			return false;
		}

		$woocommerce_order_new_state = $this->order_status_pairs[$woocommerce_order_new_state_key];
		if (!$woocommerce_order_new_state) {
			return false;
		}
		// substr remove 'wc-' prefix which si not needed for set_status() method
		$woocommerce_order->set_status(substr($woocommerce_order_new_state_key, 3));
		$woocommerce_order->save();

		printf(__('%s setted order status to %s', 'depoto'), $id, $woocommerce_order_new_state_key);
		echo '<br>';

		return true;
	}

	/**
	 * Get woocommerce order by depoto ID
	 *
	 * @param  int $depoto_id
	 * @return null|WC_Order
	 */
	private function get_order_by_depoto_id(int $depoto_id)
	{
		$order = current(wc_get_orders(
			[
				'limit' => 1,
				'type' => 'shop_order',
				'meta_query' => [[
					'key' => '_depoto_order_id',
					'value' => $depoto_id
				]]
			]
		));

		return $order ?? null;
	}
}
