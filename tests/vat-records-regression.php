<?php
/** Run: php tests/vat-records-regression.php. Read-only API fixtures; no network. */
require dirname(__DIR__) . '/includes/depoto-api/class-depoto-api.php';
function verify($condition, $message) {
	if (!$condition) { throw new RuntimeException($message); }
}
function fixture_api($client) {
	$api = (new ReflectionClass(Depoto_API::class))->newInstanceWithoutConstructor();
	$property = new ReflectionProperty(Depoto_API::class, 'depoto');
	if (PHP_VERSION_ID < 80100) { $property->setAccessible(true); }
	$property->setValue($api, $client);
	return $api;
}
$client = new class {
	public $calls = [];
	public function query($name, $arguments, $fields) {
		$this->calls[] = [$name, $arguments, $fields];
		return [
			'paginator' => ['last'=>2],
			'items' => $arguments['page'] === 1
				? [['id'=>82,'name'=>'Standard','percent'=>21], ['id'=>80,'name'=>'Unknown','percent'=>null]]
				: [['id'=>73,'name'=>'Untaxed','percent'=>0], ['id'=>74,'name'=>'Other untaxed','percent'=>'0.0']],
		];
	}
};
$api = fixture_api($client);
$all = $api->get_taxes_pairs();
$zero = $api->get_taxes_pairs(true);
verify(array_keys($all) === [82,80,73,74], 'Load every page of account VAT records');
verify(array_keys($zero) === [73,74], 'Only numeric 0% records can be selected as the untaxed default; null is not zero');
verify(strpos($zero[73], '0%') !== false && strpos($zero[73], 'ID 73') !== false, 'Labels identify percentage and record ID');
verify(count($client->calls) === 2 && in_array('percent', $client->calls[0][2]['items'], true), 'All/zero dropdowns reuse one paginated fetch with the actual percent field');
$failed = fixture_api(new class {
	public function query($name, $arguments, $fields) {
		if ($arguments['page'] === 2) { throw new RuntimeException('Fixture request failure'); }
		return ['paginator'=>['last'=>2], 'items'=>[['id'=>73,'name'=>'Untaxed','percent'=>0]]];
	}
});
verify($failed->get_taxes_pairs(true) === [], 'Failed pagination must not expose a misleading partial VAT list');
echo "PASS: DEPOTO percent filtering, null/zero distinction, pagination, shared fetch, labels and API failure handling.\n";
