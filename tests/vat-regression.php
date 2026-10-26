<?php
/** Run without WordPress: php tests/vat-regression.php */
$options = [ 'depoto_taxes' => [] ];
$scenario = [];
$settings_fields = [];
function __( $text, $domain = '' ) { return $text; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function add_settings_field( $id, $title, $callback, $page, $section, $args ) { global $settings_fields; $settings_fields[$id] = [ $callback, $args ]; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function add_action( ...$args ) {}
function get_post_meta( ...$args ) { return ''; }
class WC_Tax {
    public static function get_rate_percent_value( $id ) { return [ 1 => 21, 2 => 0, 3 => 12 ][ $id ] ?? 0; }
    public static function get_tax_class_slugs() { return []; }
    public static function get_rates_for_tax_class( $slug ) { return [ (object) [ 'tax_rate_id' => 1, 'tax_rate_name' => 'Standard VAT' ] ]; }
}
class Vat_Test_Product {
    public function get_id() { return 1; }
    public function get_sku() { return 'TEST-PRODUCT'; }
    public function get_price() { return 100; }
}
class Vat_Test_Item {
    public function get_product() { return new Vat_Test_Product(); }
    public function get_name() { return 'Test product'; }
    public function get_quantity() { return 1; }
    public function get_subtotal() { return 100; }
    public function get_subtotal_tax() { global $scenario; return $scenario['product_tax'] ?? 0; }
}
class Vat_Test_Shipping {
    public function get_method_id() { return 'flat_rate'; }
    public function get_name() { return 'Shipping'; }
}
class Vat_Test_Fee {
    public function get_total() { return 5; }
    public function get_total_tax() { global $scenario; return $scenario['fee_tax'] ?? 0; }
}
class Vat_Test_Order {
    public function get_items( $types = null ) { return $types ? [ new Vat_Test_Shipping() ] : [ new Vat_Test_Item() ]; }
    public function get_shipping_total() { return 10; }
    public function get_shipping_tax() { global $scenario; return $scenario['shipping_tax'] ?? 0; }
    public function get_fees() { global $scenario; return !empty($scenario['no_fee']) ? [] : [ new Vat_Test_Fee() ]; }
    public function get_payment_method() { return 'cod'; }
    public function get_payment_method_title() { return 'Cash on delivery'; }
    public function get_taxes() { return []; }
}
require_once dirname( __DIR__ ) . '/includes/class-depoto-order.php';
require_once dirname( __DIR__ ) . '/includes/class-depoto-taxes.php';
function payload( $mappings, $values = [] ) {
    global $options, $scenario;
    $options['depoto_taxes'] = $mappings;
    $scenario = $values;
    $plugin = new Depoto_Order( new stdClass() );
    $prepare = Closure::bind( function () { $this->order = new Vat_Test_Order(); return $this->process_order_items(); }, $plugin, Depoto_Order::class );
    return $prepare();
}
$failures = 0;
function check( $condition, $message ) {
    global $failures;
    if ( !$condition ) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); $failures++; }
}
function check_vat( $items, $expected, $label ) {
    foreach ( $items as $index => $item ) {
        $value = $expected[$index];
        check( $value === null ? !array_key_exists('vat', $item) : (isset($item['vat']) && $item['vat'] === $value), $label . ': ' . $item['type'] . ' expected ' . ($value === null ? 'no vat field' : 'VAT ID '.$value) . ', got ' . (array_key_exists('vat', $item) ? var_export($item['vat'],true) : 'no field') );
    }
}
$items = payload([]);
check_vat($items, [null,null,null], 'No tax mappings');
$total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
check($total == 115, 'Untaxed fixture total remains 115');
check_vat(payload(['tax_id_1'=>'']), [null,null,null], 'Explicit No VAT');
check_vat(payload(['tax_id_1'=>'82'], ['product_tax'=>21,'shipping_tax'=>2.1,'fee_tax'=>1.05]), [82,82,82], 'Configured taxable mapping');
check_vat(payload(['tax_id_2'=>'73']), [73,73,73], 'Configured zero-percent mapping is a real ID');
check_vat(payload(['tax_id_1'=>'','tax_id_3'=>'81'], ['product_tax'=>21,'shipping_tax'=>2.1,'fee_tax'=>1.05]), [null,null,null], 'No VAT must not fall back to another rate');
check_vat(payload(['tax_id_1'=>'82'], ['product_tax'=>21]), [82,null,null], 'Untaxed shipping and fees must not inherit product VAT');
check_vat(payload([], ['no_fee'=>true]), [null,null,null], 'No payment fee');
check_vat(payload(['tax_id_1'=>'0','tax_id_2'=>'0']), [null,null,null], 'Zero is not a DEPOTO VAT record');
check_vat(payload(['tax_id_1'=>'invalid','tax_id_2'=>'invalid']), [null,null,null], 'Invalid stored IDs are omitted');
$options['depoto_taxes'] = ['tax_id_1'=>'82'];
$taxes = new Depoto_Taxes([73=>'Zero / non-taxpayer',82=>'21%']);
$taxes->get_depoto_taxes_option();$taxes->add_fields();
ob_start();$settings_fields['tax_id_1'][0]($settings_fields['tax_id_1'][1]);$html=ob_get_clean();
check((bool)preg_match('/<option value=\'\'[^>]*>No VAT<\/option>/', $html), 'Tax settings expose an explicit No VAT option');
check(strpos($html, "value='82' selected")!==false, 'Existing selected mapping is retained in the dropdown');
if ($failures) { exit(1); }
echo 'PASS: 9 payload scenarios, unchanged untaxed total, and explicit No VAT setting.' . PHP_EOL;
