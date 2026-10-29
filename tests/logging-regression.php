<?php
/** Run: php tests/logging-regression.php. No network requests or real orders. */
$entries = [];
$logger_fails = false;
function wc_get_logger() {
	return new class {
		public function log($level, $message, $context) {
			global $entries, $logger_fails;
			if ($logger_fails) { throw new RuntimeException('Fixture logging failure'); }
			$entries[] = compact('level', 'message', 'context');
		}
	};
}
function get_option($name, $default = []) {
	return ['url'=>'https://example.invalid', 'username'=>'fixture-user', 'password'=>'fixture-password'];
}
require dirname(__DIR__) . '/includes/depoto-api/class-depoto-api.php';
function verify($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}
$http = new class implements \Psr\Http\Client\ClientInterface {
	public $requests = [];
	public $fail = false;
	public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface {
		$this->requests[] = $request;
		if (strpos((string)$request->getUri(), '/oauth/') !== false) {
			return new \Nyholm\Psr7\Response(200, [], json_encode(['access_token'=>'fixture-token', 'expires_in'=>3600]));
		}
		$body = $this->fail
			? ['errors'=>[['message'=>"SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'vat_id' cannot be null; fixture-password private@example.invalid"]]]
			: ['data'=>['createOrder'=>['data'=>['id'=>456]]]];
		return new \Nyholm\Psr7\Response(200, [], json_encode($body));
	}
};
$api = new Depoto_API();
$api_reflection = new ReflectionProperty(Depoto_API::class, 'depoto');
if (PHP_VERSION_ID < 80100) { $api_reflection->setAccessible(true); }
$client = $api_reflection->getValue($api);
$http_property = new ReflectionProperty(\Depoto\Client::class, 'httpClient');
if (PHP_VERSION_ID < 80100) { $http_property->setAccessible(true); }
$http_property->setValue($client, $http);
$client_logger_property = new ReflectionProperty(\Depoto\Client::class, 'logger');
if (PHP_VERSION_ID < 80100) { $client_logger_property->setAccessible(true); }
$handlers = $client_logger_property->getValue($client)->getHandlers();
verify($handlers[0] instanceof \Monolog\Handler\NullHandler, 'SDK raw credential/payload logging must be disabled');
$payload = [
	'reservationNumber'=>'PRIVATE-REFERENCE',
	'items'=>[
		['type'=>'product','name'=>'PRIVATE-NAME','code'=>'PRIVATE-SKU','quantity'=>1,'price'=>100],
		['type'=>'shipping','quantity'=>1,'price'=>10,'vat'=>73],
		['type'=>'payment','quantity'=>1,'price'=>0],
	],
];
verify($api->create_order($payload, 123) === 456, 'Success return value must be preserved');
verify(count($http->requests) === 2, 'Exercise real SDK authentication and GraphQL serialization');
$query = json_decode((string)$http->requests[1]->getBody(), true)['query'];
verify(substr_count($query, 'vat:') === 1, 'Actual serialized request must contain exactly the one mapped VAT field');
verify(strpos($query, 'PRIVATE-NAME') !== false, 'Normal payload data must still reach the API unchanged');
$context = $entries[0]['context'];
verify($context['source'] === 'depoto-diagnostics' && $context['wc_order_id'] === 123, 'Source and order correlation');
verify($context['items'][0]['vat_present'] === false && !array_key_exists('vat', $context['items'][0]), 'Omitted VAT must be visible as omitted in diagnostics');
verify($context['items'][1]['vat_present'] === true && $context['items'][1]['vat'] === 73, 'Mapped VAT ID must appear in diagnostics');
verify($entries[1]['context']['depoto_order_id'] === 456, 'Successful DEPOTO ID must be recorded');
$http->fail = true;
try {
	$api->create_order($payload, 124);
	throw new RuntimeException('Expected SDK exception');
} catch (\Depoto\Exception\ErrorException $e) {
	verify(strpos($e->getMessage(), 'vat_id') !== false, 'Original exception must still reach the caller');
}
$failure = end($entries);
verify($failure['level'] === 'error' && $failure['context']['wc_order_id'] === 124, 'Failure must carry order context');
verify($failure['context']['sqlstate'] === '23000' && $failure['context']['http_status'] === 200, 'SQLSTATE and HTTP response metadata');
verify($failure['context']['error_category'] === 'Column vat_id cannot be null (1048)', 'Known failure must remain diagnosable');
verify($failure['context']['items'][0]['vat_present'] === false, 'Failed request summary must be available even when info logging is filtered');
Depoto_Diagnostics::failure('Unknown fixture error.', new RuntimeException('fixture-password private@example.invalid'));
$unsafe_items = Depoto_Diagnostics::items([['type'=>'PRIVATE-TYPE','vat'=>'fixture-password','price'=>'PRIVATE-PRICE']]);
verify($unsafe_items[0]['type'] === 'other' && $unsafe_items[0]['vat'] === '[non-numeric]' && $unsafe_items[0]['price'] === '[non-numeric]', 'Untrusted item fields must be sanitized');
$logs = json_encode($entries);
foreach (['fixture-password','fixture-user','fixture-token','private@example.invalid','PRIVATE-NAME','PRIVATE-SKU','PRIVATE-REFERENCE'] as $secret) {
	verify(strpos($logs, $secret) === false, 'Diagnostics leaked fixture secret/customer data');
}
$logger_fails = true;
$http->fail = false;
verify($api->create_order($payload, 125) === 456, 'Logger failure must not interrupt an export');
echo "PASS: Real SDK serialization, omitted/mapped VAT, success and failure logs, error signatures, secret redaction, and logging failure isolation.\n";
