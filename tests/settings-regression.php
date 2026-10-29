<?php
/** Run: php tests/settings-regression.php. Uses a short-lived localhost PHP server. */
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$socket) { throw new RuntimeException($error); }
$address = stream_socket_get_name($socket, false);
fclose($socket);
$log = tempnam(sys_get_temp_dir(), 'depoto-vat-test-');
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/settings-request.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start PHP test server'); }
fclose($pipes[0]);
try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($connection) { fclose($connection); $ready = true; break; }
        usleep(100000);
    }
    if (!$ready) { throw new RuntimeException('PHP test server did not become ready'); }
    $request = function ($data) use ($address) {
        $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data), 'timeout' => 5]]);
        return json_decode(file_get_contents('http://' . $address . '/', false, $context), true, 512, JSON_THROW_ON_ERROR);
    };
    $cleared = $request(['tax_id_1' => '']);
    if ($cleared['saved']['tax_id_1'] !== '' || $cleared['saved']['tax_id_3'] !== '81' || strpos($cleared['html'], "value='' selected>Select a DEPOTO VAT record") === false) {
        throw new RuntimeException('Blank selection did not clear and redisplay the saved mapping');
    }
    $selected = $request(['tax_id_1' => '73']);
    if ($selected['saved']['tax_id_1'] !== '73') { throw new RuntimeException('Valid VAT selection was not saved'); }
    $missing = $request(['unrelated' => 'value']);
    if ($missing['saved']['tax_id_1'] !== '82' || $missing['saved']['tax_id_3'] !== '81') { throw new RuntimeException('Absent fields overwrote existing mappings'); }
    $default = $request(['no_wc_rates' => '1', 'default_vat_id' => '74']);
    if ($default['saved']['default_vat_id'] !== '74' || strpos($default['html'], "value='74' selected") === false || $default['saved']['tax_id_1'] !== '82') {
        throw new RuntimeException('0% default did not save and redisplay without WooCommerce rates');
    }
    foreach (['82', '999', 'invalid', '0'] as $invalid) {
        $rejected = $request(['no_wc_rates' => '1', 'default_vat_id' => $invalid]);
        if ($rejected['saved']['default_vat_id'] !== '73' || count($rejected['errors']) !== 1) { throw new RuntimeException('Invalid/nonzero record was accepted'); }
    }
    $clearedDefault = $request(['no_wc_rates' => '1', 'default_vat_id' => '']);
    if ($clearedDefault['saved']['default_vat_id'] !== '') { throw new RuntimeException('Default cannot be cleared'); }
    $missingDefault = $request(['no_wc_rates' => '1']);
    if ($missingDefault['saved']['default_vat_id'] !== '73') { throw new RuntimeException('Absent default field overwrote existing selection'); }
    echo 'PASS: VAT mappings save/clear; 0% default works without WooCommerce rates; invalid/nonzero defaults rejected; absent fields preserved.' . PHP_EOL;
} finally {
    proc_terminate($process);
    proc_close($process);
    unlink($log);
}
