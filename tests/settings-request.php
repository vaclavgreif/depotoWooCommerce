<?php
// Local HTTP fixture: filter_input must see an actual request, not a CLI $_POST assignment.
$options = [ 'depoto_taxes' => [ 'tax_id_1' => '82', 'tax_id_3' => '81' ] ];
$fields = [];
function get_option($key) { global $options; return $options[$key] ?? false; }
function update_option($key, $value, $autoload = null) { global $options; $options[$key] = $value; }
function __($text, $domain = '') { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function add_settings_field($id, $label, $callback, $page, $section, $args) { global $fields; $fields[$id] = [$callback, $args]; }
class WC_Tax {
    public static function get_tax_class_slugs() { return ['reduced']; }
    public static function get_rates_for_tax_class($slug) {
        return [(object) ['tax_rate_id' => $slug === '' ? 1 : 3, 'tax_rate_name' => $slug === '' ? 'Standard' : 'Reduced']];
    }
}
require_once dirname(__DIR__) . '/includes/class-depoto-taxes.php';
$taxes = new Depoto_Taxes([73 => 'Zero / non-taxpayer', 82 => '21%', 81 => '12%']);
$taxes->set_fields_setting();
$taxes->get_depoto_taxes_option();
$taxes->add_fields();
ob_start();$fields['tax_id_1'][0]($fields['tax_id_1'][1]);$html = ob_get_clean();
header('Content-Type: application/json');
echo json_encode(['saved' => $options['depoto_taxes'], 'html' => $html]);
