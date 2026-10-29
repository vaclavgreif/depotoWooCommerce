<?php
/** Run: php tests/lifecycle-regression.php. Real export/webhook paths, local fixtures only. */
$entries = [];
$orders = [];
$action_id = 12;
$options = [ 'depoto_taxes'=>['default_vat_id'=>'73'], 'depoto_payments'=>['cod'=>'id_1'], 'depoto_shippings'=>['flat_rate'=>'ppl'] ];
function wc_get_logger() {
	return new class {
		public function log($level, $message, $context) { global $entries; $entries[] = compact('level','message','context'); }
	};
}
function get_option($key, $default = false) { global $options; return $options[$key] ?? $default; }
function wc_get_order($id) { global $orders; return $orders[$id] ?? false; }
function wc_get_orders($query) { global $orders; return array_values($orders); }
function as_enqueue_async_action(...$args) { global $action_id; return $action_id; }
function as_schedule_single_action(...$args) { global $action_id; return $action_id; }
function __($text, $domain = '') { return $text; }
function add_action(...$args) {}
function add_filter(...$args) {}
function is_admin() { return false; }
function sanitize_text_field($value) { return strip_tags($value); }
function nocache_headers() {}
function current_time($type) { return '2026-10-29 12:00:00'; }
class WP_REST_Response {
	public $data;
	public function __construct($data, $status = 200) { $this->data = $data; }
}
require dirname(__DIR__) . '/includes/class-depoto-diagnostics.php';
require dirname(__DIR__) . '/includes/class-depoto-order.php';
require dirname(__DIR__) . '/includes/class-depoto-webhook.php';
function verify($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
function last_event($fragment) {
	global $entries;
	foreach (array_reverse($entries) as $entry) { if (strpos($entry['message'], $fragment) !== false) { return $entry; } }
	throw new RuntimeException('Expected log event: ' . $fragment);
}
class Lifecycle_Order {
	public $id;
	public $depoto_id = 0;
	public $notes = [];
	public function __construct($id) { $this->id = $id; }
	public function get_id() { return $this->id; }
	public function get_meta($key, $single = true) { return $key === '_depoto_order_id' ? $this->depoto_id : 1; }
	public function update_meta_data($key, $value) { $this->depoto_id = $value; }
	public function save_meta_data() {}
	public function add_order_note($message) { $this->notes[] = $message; }
	public function get_items($type = null) { return $type ? $this->get_shipping_methods() : []; }
	public function get_shipping_methods() { return [new class { public function get_method_id() { return 'flat_rate'; } public function get_name() { return 'Fixture shipping'; } }]; }
	public function get_shipping_total() { return 0; }
	public function get_shipping_tax() { return 0; }
	public function get_fees() { return []; }
	public function get_payment_method() { return 'cod'; }
	public function get_payment_method_title() { return 'Fixture payment'; }
	public function get_currency() { return 'EUR'; }
	public function get_order_number() { return 'PRIVATE-REFERENCE'; }
	public function get_total() { return 0; }
}
$api = new class {
	public $exports = 0;
	public $fail = false;
	public function create_order($data, $id) {
		$this->exports++;
		if ($id === 3) { throw new RuntimeException('fixture-password private@example.invalid'); }
		return 456;
	}
	public function update_order($data) { if ($this->fail) { throw new RuntimeException('fixture-password'); } }
	public function cancel_order($id) { if ($this->fail) { throw new RuntimeException('fixture-password'); } }
	public function get_order_status_by_ID($id) { return ''; }
};
$exporter = new class($api) extends Depoto_Order {
	public function process_address($billing) { return 101; }
};
$orders[1] = new Lifecycle_Order(1);
$orders[3] = new Lifecycle_Order(3);
verify($exporter->process_order(999) === false, 'Missing orders must be logged without a fatal error');
verify(last_event('order not found')['context']['wc_order_id'] === 999, 'Missing order correlation');
verify($exporter->process_order(1) === true && $orders[1]->depoto_id === 456, 'Real export path still saves the returned ID');
verify($exporter->process_order(1) === null && $api->exports === 1, 'Already exported orders are skipped without another API call');
verify(last_event('already exported')['context']['depoto_order_id'] === 456, 'Duplicate skip is diagnosable');
ob_start();$exporter->process_undelivered_orders();ob_end_clean();
$batch = last_event('retry finished');
verify($batch['context']['skipped'] === 1 && $batch['context']['failed'] === 1, 'Batch summary distinguishes skips and failures');
verify(last_event('order export failed')['context']['stage'] === 'create_order', 'Failure identifies its export stage');
$exporter->schedule_order($orders[1]);
verify(last_event('export queued')['context']['action_id'] === 12, 'Queue diagnostics include scheduler ID');
$action_id = 0;
$exporter->schedule_order($orders[1]);
verify(last_event('could not be queued')['level'] === 'error', 'Failed scheduling is visible');
$action_id = 12;
$exporter->update_order_payment_status(1);
$exporter->cancel_order(1);
verify(last_event('marked as paid')['context']['wc_order_id'] === 1, 'Successful payment log');
verify(last_event('order cancelled')['context']['depoto_order_id'] === 456, 'Successful cancellation log');
$api->fail = true;
$exporter->update_order_payment_status(1);
$exporter->cancel_order(1);
verify(last_event('payment update failed')['level'] === 'error', 'Payment failure log');
verify(last_event('cancellation failed')['level'] === 'error', 'Cancellation failure log');
$exporter->update_order_payment_status(999);
$exporter->cancel_order(999);
// Exercise real webhook scheduling/retry paths with untrusted payload fields.
$webhook = new Depoto_Webhook($api);
$request = new class {
	public function get_json_params() { return ['type'=>'order.status','payload'=>['id'=>456,'code'=>'PRIVATE-SKU','email'=>'private@example.invalid','token'=>'fixture-password']]; }
};
$response = $webhook->grab_data($request);
verify($response->data['scheduled'] === true, 'Webhook must still schedule normally');
verify(last_event('scheduled action')['context']['id'] === 456, 'Webhook logs retain the event ID');
$webhook->process_scheduled_record(['type'=>'order.status','id'=>456,'attempts'=>0]);
verify(last_event('rescheduled retry')['context']['reason'] === 'depoto_status_missing', 'Retry reason remains visible');
$webhook->process_scheduled_record(['type'=>'order.status','id'=>456,'attempts'=>2]);
verify(last_event('max attempts reached')['level'] === 'error', 'Exhausted retries are errors');
$webhook->process_scheduled_record(['type'=>'private@example.invalid','id'=>456]);
verify(last_event('unsupported type')['context']['type'] === 'unsupported', 'Arbitrary webhook types are not copied into logs');
$safe = Depoto_Diagnostics::webhook_context(['record'=>['id'=>456,'payload'=>['token'=>'fixture-password']], 'context'=>['reason'=>'depoto_unavailable','email'=>'private@example.invalid']]);
verify($safe === ['id'=>456,'reason'=>'depoto_unavailable'], 'Webhook metadata allowlist discards raw payloads');
$logs = json_encode($entries);
foreach (['fixture-password','private@example.invalid','PRIVATE-SKU','PRIVATE-REFERENCE'] as $secret) {
	verify(strpos($logs, $secret) === false, 'Lifecycle logs leaked customer data or secrets');
}
echo "PASS: Export/skip/batch/queue diagnostics, payment and cancellation results, webhook scheduling/retries, missing orders and data exclusion.\n";
