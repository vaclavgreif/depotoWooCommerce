<?php

/**
 * @package Depoto_Webhook
 */
class Depoto_Webhook
{
	/** @var Depoto_API */
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

	private const LOG_FILE = __DIR__ . '/log.txt';
	private const ACTION_HOOK = 'depoto_process_webhook_record';
	private const ACTION_GROUP = 'depoto';
	private const MAX_ATTEMPTS = 3;

	private const RESULT_SUCCESS = 'success';
	private const RESULT_RETRY = 'retry';
	private const RESULT_SKIP = 'skip';

	public function __construct($depoto_api)
	{
		$this->order_status_pairs = get_option('depoto_order_statuses');
		if (!is_array($this->order_status_pairs)) {
			$this->order_status_pairs = [];
		}

		$this->depoto_api = $depoto_api;

		add_action('rest_api_init', [$this, 'register_route']);
		add_action(self::ACTION_HOOK, [$this, 'process_scheduled_record'], 10, 1);
		add_filter('woocommerce_product_data_store_cpt_get_products_query', [$this, 'handle_depoto_product_query_var'], 10, 2);
	}

	private function log_debug(string $message, array $context = []): void
	{
		if (!defined('depoto_log') || constant('depoto_log') !== true) {
			return;
		}

		$timestamp = gmdate('Y-m-d H:i:s');
		$payload = $context ? ' ' . wp_json_encode($context) : '';
		error_log("[{$timestamp}] {$message}{$payload}" . PHP_EOL, 3, self::LOG_FILE);
	}

	private function result(string $status, array $context = []): array
	{
		return [
			'status' => $status,
			'context' => $context,
		];
	}

	public function register_route()
	{
		register_rest_route(
			'depoto/v1',
			'/shop',
			[
				'methods' => 'POST',
				'callback' => [$this, 'grab_data'],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			'depoto/v1',
			'/process-stack',
			[
				'methods' => 'GET',
				'callback' => [$this, 'process_webhook_stack'],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Receives webhook payload and schedules one async Action Scheduler job per event.
	 *
	 * @param WP_REST_Request $data
	 * @return WP_REST_Response
	 */
	public function grab_data($data)
	{
		nocache_headers();

		$data_array = $data->get_json_params();
		$this->log_debug('grab_data: received payload', ['data' => $data_array]);

		$record = [
			'type' => sanitize_text_field($data_array['type'] ?? ''),
			'id' => intval($data_array['payload']['id'] ?? 0),
			'code' => sanitize_text_field($data_array['payload']['code'] ?? ''),
			'payload' => is_array($data_array['payload'] ?? null) ? $data_array['payload'] : [],
			'attempts' => 0,
			'received_at' => current_time('mysql'),
		];

		if (empty($record['type']) || empty($record['id'])) {
			$this->log_debug('grab_data: invalid record, skipping schedule', ['record' => $record]);

			return new WP_REST_Response([
				'scheduled' => false,
				'reason' => 'invalid_record',
				'record' => $record,
			], 400);
		}

		if (!function_exists('as_enqueue_async_action')) {
			$this->log_debug('grab_data: action scheduler missing', ['record' => $record]);

			return new WP_REST_Response([
				'scheduled' => false,
				'reason' => 'action_scheduler_missing',
				'record' => $record,
			], 500);
		}

		$action_id = as_enqueue_async_action(self::ACTION_HOOK, [$record], self::ACTION_GROUP);

		if (empty($action_id)) {
			$this->log_debug('grab_data: failed to schedule action', ['record' => $record]);

			return new WP_REST_Response([
				'scheduled' => false,
				'reason' => 'schedule_failed',
				'record' => $record,
			], 500);
		}

		$this->log_debug('grab_data: scheduled action', [
			'action_id' => $action_id,
			'record' => $record,
		]);

		return new WP_REST_Response([
			'scheduled' => true,
			'action_id' => $action_id,
			'record' => $record,
		]);
	}

	/**
	 * Kept only for backward compatibility / manual triggering visibility.
	 *
	 * @return void
	 */
	public function process_webhook_stack()
	{
		nocache_headers();

		$message = __('Action Scheduler handles Depoto webhook processing automatically.', 'depoto');
		echo esc_html($message);
		echo '<br><br>';

		$this->log_debug('process_webhook_stack: no-op, action scheduler handles processing');
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param array $record
	 * @return void
	 */
	public function process_scheduled_record($record): void
	{
		if (!is_array($record)) {
			$this->log_debug('process_scheduled_record: invalid record');
			return;
		}

		$record['attempts'] = intval($record['attempts'] ?? 0) + 1;

		$this->log_debug('process_scheduled_record: start', [
			'type' => $record['type'] ?? '',
			'id' => intval($record['id'] ?? 0),
			'attempts' => $record['attempts'],
		]);

		switch ($record['type'] ?? '') {
			case 'product.availability':
				$result = $this->process_product_availability($record);
				break;

			case 'order.processStatus':
			case 'order.status':
			case 'order.update':
				$result = $this->process_order_status($record);
				break;

			default:
				$this->log_debug('process_scheduled_record: skipped unsupported type', [
					'type' => $record['type'] ?? '',
					'id' => intval($record['id'] ?? 0),
				]);
				return;
		}

		if (!is_array($result) || empty($result['status'])) {
			$result = $this->result(self::RESULT_RETRY, ['reason' => 'invalid_processor_result']);
		}

		if ($result['status'] === self::RESULT_SUCCESS) {
			$this->log_debug('process_scheduled_record: processed successfully', [
				'type' => $record['type'] ?? '',
				'id' => intval($record['id'] ?? 0),
				'attempts' => $record['attempts'],
				'context' => $result['context'] ?? [],
			]);
			return;
		}

		if ($result['status'] === self::RESULT_SKIP) {
			$this->log_debug('process_scheduled_record: skipped permanently', [
				'type' => $record['type'] ?? '',
				'id' => intval($record['id'] ?? 0),
				'attempts' => $record['attempts'],
				'context' => $result['context'] ?? [],
			]);
			return;
		}

		if ($record['attempts'] >= self::MAX_ATTEMPTS) {
			$this->log_debug('process_scheduled_record: max attempts reached, giving up', [
				'type' => $record['type'] ?? '',
				'id' => intval($record['id'] ?? 0),
				'attempts' => $record['attempts'],
				'context' => $result['context'] ?? [],
			]);
			return;
		}

		$next_action_id = as_schedule_single_action(
			time() + 60,
			self::ACTION_HOOK,
			[$record],
			self::ACTION_GROUP
		);

		if (empty($next_action_id)) {
			$this->log_debug('process_scheduled_record: failed to schedule retry', [
				'type' => $record['type'] ?? '',
				'id' => intval($record['id'] ?? 0),
				'attempts' => $record['attempts'],
				'context' => $result['context'] ?? [],
			]);
			return;
		}

		$this->log_debug('process_scheduled_record: rescheduled retry', [
			'type' => $record['type'] ?? '',
			'id' => intval($record['id'] ?? 0),
			'attempts' => $record['attempts'],
			'next_action_id' => $next_action_id,
			'context' => $result['context'] ?? [],
		]);
	}

	/**
	 * Process product availability.
	 *
	 * SUCCESS:
	 * - product stock updated
	 *
	 * RETRY:
	 * - depoto temporarily unavailable
	 *
	 * SKIP:
	 * - product missing locally
	 *
	 * @param array $record
	 * @return array
	 */
	private function process_product_availability($record): array
	{
		nocache_headers();

		$id = intval($record['id'] ?? 0);
		$this->log_debug('process_product_availability: start', ['id' => $id]);

		$quantity_available = $this->depoto_api->get_product_availability_by_ID($id);
		if (-1 === $quantity_available) {
			$this->log_debug('process_product_availability: depoto returned -1', ['id' => $id]);
			return $this->result(self::RESULT_RETRY, ['reason' => 'depoto_unavailable']);
		}

		$product = $this->get_product_by_depoto_id($id);
		if (empty($product)) {
			$this->log_debug('process_product_availability: product not found', ['id' => $id]);
			return $this->result(self::RESULT_SKIP, ['reason' => 'product_not_found']);
		}

		echo $id . ' setted product availability to ' . $quantity_available . '<br>';

		$product->set_manage_stock(true);
		$product->set_stock_quantity($quantity_available);
		$product->save();

		$this->log_debug('process_product_availability: updated stock', [
			'id' => $id,
			'quantity' => $quantity_available,
		]);

		return $this->result(self::RESULT_SUCCESS, ['quantity' => $quantity_available]);
	}

	/**
	 * Get WooCommerce product by Depoto ID.
	 *
	 * @param int $depoto_id
	 * @return null|WC_Product
	 */
	private function get_product_by_depoto_id(int $depoto_id)
	{
		if (empty($depoto_id) || !is_numeric($depoto_id)) {
			return null;
		}

		$products = wc_get_products([
			'depoto_id' => $depoto_id,
			'limit' => 1,
			'return' => 'objects',
			'type' => ['simple', 'variable', 'variation'],
			'status' => ['publish', 'private', 'draft', 'pending', 'future'],
		]);

		if (empty($products)) {
			return null;
		}

		return current($products) ?: null;
	}

	public function handle_depoto_product_query_var($query, $query_vars)
	{
		if (!empty($query_vars['depoto_id'])) {
			if (!isset($query['meta_query'])) {
				$query['meta_query'] = [];
			}

			$query['meta_query'][] = [
				'key' => '_depoto_id',
				'value' => sanitize_text_field((string) $query_vars['depoto_id']),
			];
		}

		return $query;
	}

	/**
	 * Process order status.
	 *
	 * SUCCESS:
	 * - order updated
	 * - status already matches
	 *
	 * RETRY:
	 * - depoto API did not return status
	 * - order not found locally yet
	 *
	 * SKIP:
	 * - depoto status has no Woo mapping
	 *
	 * @param array $record
	 * @return array
	 */
	private function process_order_status($record): array
	{
		$id = intval($record['id'] ?? 0);
		$this->log_debug('process_order_status: start', [
			'id' => $id,
			'type' => $record['type'] ?? '',
		]);

		$depoto_order_status = $this->depoto_api->get_order_status_by_ID($id);
		if (!$depoto_order_status) {
			$this->log_debug('process_order_status: depoto status missing', ['id' => $id]);
			return $this->result(self::RESULT_RETRY, ['reason' => 'depoto_status_missing']);
		}

		$woocommerce_order = $this->get_order_by_depoto_id($id);
		if (empty($woocommerce_order)) {
			$this->log_debug('process_order_status: order not found', ['id' => $id]);
			return $this->result(self::RESULT_RETRY, ['reason' => 'order_not_found']);
		}

		$woocommerce_order_state = 'wc-' . $woocommerce_order->get_status();

		if (
			isset($this->order_status_pairs[$woocommerce_order_state]) &&
			$this->order_status_pairs[$woocommerce_order_state] === $depoto_order_status
		) {
			$this->log_debug('process_order_status: status already matches', [
				'id' => $id,
				'status' => $depoto_order_status,
			]);
			return $this->result(self::RESULT_SUCCESS, ['reason' => 'already_matches']);
		}

		$woocommerce_order_new_state_key = array_search($depoto_order_status, $this->order_status_pairs, true);
		if ($woocommerce_order_new_state_key === false) {
			$this->log_debug('process_order_status: no matching woo state', [
				'id' => $id,
				'depoto_status' => $depoto_order_status,
			]);
			return $this->result(self::RESULT_SKIP, [
				'reason' => 'no_matching_woo_state',
				'depoto_status' => $depoto_order_status,
			]);
		}

		$new_status = substr($woocommerce_order_new_state_key, 3);

		$woocommerce_order->set_status(
			$new_status,
			sprintf(
				__('Depoto Webhook: Order status updated to %s', 'depoto'),
				wc_get_order_status_name($new_status)
			)
		);
		$woocommerce_order->save();

		printf(__('%s setted order status to %s', 'depoto'), $id, $woocommerce_order_new_state_key);
		echo '<br>';

		$this->log_debug('process_order_status: updated', [
			'id' => $id,
			'new_status' => $woocommerce_order_new_state_key,
		]);

		return $this->result(self::RESULT_SUCCESS, [
			'new_status' => $woocommerce_order_new_state_key,
			'depoto_status' => $depoto_order_status,
		]);
	}

	/**
	 * Get WooCommerce order by Depoto ID.
	 *
	 * @param int $depoto_id
	 * @return null|WC_Order
	 */
	private function get_order_by_depoto_id(int $depoto_id)
	{
		$order = current(wc_get_orders([
			'limit' => 1,
			'type' => 'shop_order',
			'meta_query' => [[
				                 'key' => '_depoto_order_id',
				                 'value' => $depoto_id,
			                 ]],
		]));

		return $order ?: null;
	}
}
