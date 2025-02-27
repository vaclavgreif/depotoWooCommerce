<?php

/**
 * @package         Depoto_Order_Statuses
 */
class Depoto_Order_Statuses
{

	private $order_statuses = [];
	private $depoto_api_order_statuses_pairs = [];
	private $depoto_order_statuses_options = [];

	public function __construct($depoto_api_order_statuses_pairs)
	{
		$this->order_statuses = $this->get_woocommerce_order_statuses();
		$this->depoto_api_order_statuses_pairs = $depoto_api_order_statuses_pairs;
	}

	public function get_depoto_order_statuses_option(): void
	{
		$options = get_option('depoto_order_statuses');

		$depoto_order_statuses_options = [];

		foreach ($this->order_statuses as $order_state_id => $order_state_title) {
			$depoto_order_statuses_options[$order_state_id] = $options[$order_state_id] ?? '';
		}
		$this->depoto_order_statuses_options = $depoto_order_statuses_options;
	}

	public function set_admin_hooks()
	{
		add_action('admin_init', [$this, 'section_init']);
		add_action('admin_init', [$this, 'add_fields']);
	}


	/**
	 * Get available Woocommerce order statuses as pair ['order_state_id' => 'order_state_title']
	 *
	 * @return array
	 */
	private function get_woocommerce_order_statuses(): array
	{
		$available_order_statuses = wc_get_order_statuses();

		if (empty($available_order_statuses)) {
			return [];
		}

		return $available_order_statuses;
	}

	public function add_fields()
	{
		foreach ($this->order_statuses as $id => $value) {
			$this->add_field($id, $value, $this->depoto_api_order_statuses_pairs);
		}

		foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
			$option_id = 'depoto_paid_order_statuses_'. $gateway->id;
			add_settings_field(
				$option_id,
				sprintf(__('Paid order statuses for %s', 'depoto'), $gateway->id),
				function () use ($gateway, $option_id) {
					$args = [
						'label_for' => $option_id,
					];
					$id = esc_attr(sprintf('%s_%s', $args['label_for'], $gateway->id)) . '[]';
					$name = esc_attr(sprintf('%s[]', $option_id));
					$paid_order_statuses = get_option($option_id) ?: [];
					echo "<select name='$name' id='$id' multiple='multiple'>";
					foreach ($this->order_statuses as $key => $label) {
						echo "<option value='$key' " . (in_array($key, $paid_order_statuses) ? 'selected' : '') . ">$label</option>";
					}
					echo '</select>';
				},
				'depoto',
				'depoto_order_statuses',
				array(
					'label_for' => $id,
					'class' => 'depoto-order_statuses-' . $id,
				)
			);

		}


	}

	private function add_field($id, $value, $depoto_order_statuses_pairs)
	{
		add_settings_field(
			$id,
			__($value, 'depoto'),
			function ($args) use ($depoto_order_statuses_pairs) {
				$id = esc_attr($args['label_for']);

				echo "<select name='$id' id='$id' >";
				foreach ($depoto_order_statuses_pairs as $depoto_id => $depoto_title) {
					echo "<option value='$depoto_id' " . (($depoto_id === $this->depoto_order_statuses_options[$id]) ? 'selected' : '') . ">$depoto_title</option>";
				}
				echo '</select>';
			},
			'depoto',
			'depoto_order_statuses',
			array(
				'label_for' => $id,
				'class' => 'depoto-order_statuses-' . $id,
			)
		);
	}

	/**
	 * Initializes Depoto Order statuses section
	 */
	public function section_init(): void
	{
		register_setting('depoto', 'depoto_order_statuses');

		add_settings_section(
			'depoto_order_statuses',
			__('Order statuses', 'depoto'),
			'',
			'depoto'
		);
	}

	public function set_fields_setting(): bool
	{

		$depoto_order_statuses = get_option('depoto_order_statuses');
		if (empty($depoto_order_statuses)) {
			$depoto_order_statuses = [];
		}


		foreach ($this->order_statuses as $order_state_id => $order_state_title) {
			$order_state_item = filter_input(INPUT_POST, $order_state_id, FILTER_DEFAULT);

			if (!empty($order_state_item)) {
				$depoto_order_statuses[$order_state_id] = $order_state_item;
			}
		}

		update_option('depoto_order_statuses', $depoto_order_statuses, true);

		foreach ( WC()->payment_gateways()->payment_gateways as $gateway ) {
			$option_id = 'depoto_paid_order_statuses_'. $gateway->id;
			$depoto_paid_order_statuses = !empty($_POST[$option_id]) ? $_POST[$option_id] : [];

			if ( $depoto_paid_order_statuses ) {
				$depoto_paid_order_statuses = array_filter( $depoto_paid_order_statuses, function ( $value ) {
					return in_array( $value, array_keys( $this->order_statuses ) );
				} );
			}

			update_option( $option_id, $depoto_paid_order_statuses, true );

		}

		return true;
	}
}
