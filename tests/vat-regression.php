<?php
/** Run without WordPress: php tests/vat-regression.php */
$options = [ 'depoto_taxes' => [] ];
$scenario = [];
$settings_fields = [];
$wc_tax_rates = true;
function __( $text, $domain = '' ) { return $text; }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function esc_html__($text, $domain = '') { return esc_html($text); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES ); }
function add_settings_field( $id, $title, $callback, $page, $section, $args ) { global $settings_fields; $settings_fields[$id] = [ $callback, $args ]; }
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function add_action( ...$args ) {}
function wc_get_order($id) { global $preflight_order; return $preflight_order; }
function get_post_meta( ...$args ) { return ''; }
class WC_Tax {
    public static function get_rate_percent_value( $id ) { return [ 1 => 21, 2 => 0, 3 => 12 ][ $id ] ?? 0; }
    public static function get_tax_class_slugs() { return []; }
    public static function get_rates_for_tax_class( $slug ) { global $wc_tax_rates; if (!$wc_tax_rates) { return []; } return [ (object) [ 'tax_rate_id' => 1, 'tax_rate_name' => 'Standard VAT' ] ]; }
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
    public $notes = [];
    public function get_meta(...$args) { return ''; }
    public function add_order_note($message) { $this->notes[] = $message; }
    public function get_items( $types = null ) { return $types ? [ new Vat_Test_Shipping() ] : [ new Vat_Test_Item() ]; }
    public function get_shipping_total() { return 10; }
    public function get_shipping_tax() { global $scenario; return $scenario['shipping_tax'] ?? 0; }
    public function get_fees() { global $scenario; return !empty($scenario['no_fee']) ? [] : [ new Vat_Test_Fee() ]; }
    public function get_payment_method() { return 'cod'; }
    public function get_payment_method_title() { return 'Cash on delivery'; }
    public function get_taxes() { return []; }
}
require_once dirname( __DIR__ ) . '/includes/class-depoto-order.php';
require_once dirname( __DIR__ ) . '/includes/class-depoto-diagnostics.php';
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
function rejected($mappings, $values, $expected, $label) {
    try { payload($mappings, $values); check(false, $label . ': missing VAT accepted'); }
    catch (UnexpectedValueException $e) { check(strpos($e->getMessage(), $expected) !== false, $label . ': actionable mapping error'); }
}
rejected([], [], '0% DEPOTO VAT', 'Missing configuration');
rejected(['default_vat_id'=>''], [], '0% DEPOTO VAT', 'Cleared default');
rejected(['default_vat_id'=>'0'], [], '0% DEPOTO VAT', 'Record ID zero is invalid');
rejected(['default_vat_id'=>'invalid'], [], '0% DEPOTO VAT', 'Invalid default');
rejected(['tax_id_1'=>'82'], ['product_tax'=>21], '0% DEPOTO VAT', 'Untaxed shipping cannot inherit product tax');
rejected(['default_vat_id'=>'73','tax_id_1'=>'','tax_id_3'=>'81'], ['product_tax'=>21], '21%', 'Taxed lines cannot inherit the zero default or another rate');
$items = payload(['default_vat_id'=>'73']);
check_vat($items, [73,73,73], 'Zero default on all untaxed line types');
$total = array_sum(array_map(function($item) { return $item['price'] * $item['quantity']; }, $items));
check($total == 115, 'Untaxed prices still total 115');
check_vat(payload(['tax_id_2'=>'73']), [73,73,73], 'Existing explicit zero-rate mapping still works');
check_vat(payload(['tax_id_1'=>'82'], ['product_tax'=>21,'shipping_tax'=>2.1,'fee_tax'=>1.05]), [82,82,82], 'Existing taxable mapping');
check_vat(payload(['default_vat_id'=>'73','tax_id_1'=>'82'], ['product_tax'=>21]), [82,73,73], 'Mixed taxable product and untaxed shipping/fee');
check_vat(payload(['default_vat_id'=>'73','tax_id_2'=>'74']), [73,73,73], 'Explicit default takes precedence over legacy mapping');
check_vat(payload(['default_vat_id'=>'73'], ['no_fee'=>true]), [73,73,73], 'Zero-price payment line also receives VAT');
rejected(['default_vat_id'=>'73','tax_id_1'=>'82'], ['product_tax'=>21,'shipping_tax'=>1.2], '12%', 'Unmapped taxable shipping');
rejected(['default_vat_id'=>'73','tax_id_1'=>'82'], ['product_tax'=>21,'fee_tax'=>0.6], '12%', 'Unmapped taxable fee');
$options['depoto_taxes'] = ['tax_id_1'=>'82', 'default_vat_id'=>'73'];
$taxes = new Depoto_Taxes([73=>'Zero / non-taxpayer',82=>'21%'], [73=>'Zero / non-taxpayer']);
$taxes->get_depoto_taxes_option();$taxes->add_fields();
ob_start();$settings_fields['tax_id_1'][0]($settings_fields['tax_id_1'][1]);$html=ob_get_clean();
check(strpos($html, 'Select a DEPOTO VAT record') !== false, 'Tax mapping placeholder explains selection');
check(strpos($html, "value='82' selected")!==false, 'Existing taxable selection retained');
$wc_tax_rates = false;
$settings_fields = [];
$taxes = new Depoto_Taxes([73=>'Zero / non-taxpayer',82=>'21%'], [73=>'Zero / non-taxpayer']);
$taxes->get_depoto_taxes_option();$taxes->add_fields();
check(isset($settings_fields['default_vat_id']), 'No WooCommerce rates: 0% selector still available');
if (isset($settings_fields['default_vat_id'])) {
    ob_start();$settings_fields['default_vat_id'][0]($settings_fields['default_vat_id'][1]);$html=ob_get_clean();
    check(strpos($html, "value='73' selected") !== false, 'Default redisplays selected account record');
    check(strpos($html, "value='82'") === false, 'Nonzero account records are excluded from untaxed selector');
}
check_vat(payload(['default_vat_id'=>'73']), [73,73,73], 'Zero default with no WooCommerce rates');
$taxes = new Depoto_Taxes([82=>'21%'], []);
$taxes->get_depoto_taxes_option();$taxes->add_fields();
ob_start();$settings_fields['default_vat_id'][0]($settings_fields['default_vat_id'][1]);$html=ob_get_clean();
check(strpos($html, 'No 0% VAT records were loaded') !== false, 'Missing zero records produce actionable guidance');
$options['depoto_taxes'] = [];
$scenario = [];
$preflight_order = new Vat_Test_Order();
$api = new class {
    public $writes = 0;
    public function create_address($data) { $this->writes++; return 1; }
    public function create_order($data, $order_id = 0) { $this->writes++; return 1; }
};
$plugin = new Depoto_Order($api);
check($plugin->process_order(1) === false && $api->writes === 0, 'Missing configuration stops before remote writes');
check(count($preflight_order->notes) === 1 && strpos($preflight_order->notes[0], '0% DEPOTO VAT') !== false, 'Order receives actionable missing-mapping note');
if ($failures) { exit(1); }
echo 'PASS: 0% default with no WooCommerce rates, all line types, unchanged prices, taxable mappings, settings filtering and export preflight.' . PHP_EOL;
