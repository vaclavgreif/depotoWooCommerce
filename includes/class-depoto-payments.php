<?php

/**
 * @package         Depoto_Payments
 */
class Depoto_Payments {

	private $methods = [];
	private $depoto_api_payments_pairs = [];
	private $depoto_api_checkouts = [];
	private $depoto_payments_options = [];
	private $gws;

	public function __construct( $depoto_api_payments_pairs, $depoto_api_checkouts ) {
		$this->depoto_api_payments_pairs = $depoto_api_payments_pairs;
		$this->depoto_api_checkouts      = $depoto_api_checkouts;

		$this->methods = $this->get_woocommerce_available_payment_methods();
	}

	public function get_depoto_payments_option(): void {
		$options = get_option( 'depoto_payments' );

		$depoto_payments_options = [];

		foreach ( $this->methods as $method_id => $method_title ) {
			$depoto_payments_options[ $method_id ] = $options[ $method_id ] ?? '';
		}
		$this->depoto_payments_options = $depoto_payments_options;
	}

	public function set_admin_hooks() {
		add_action( 'admin_init', [ $this, 'section_init' ] );
		add_action( 'admin_init', [ $this, 'add_fields' ] );
	}


	/**
	 * Get available Woocommerce payments method as pair ['method_id' => 'method_title']
	 *
	 * @return array
	 */
	private function get_woocommerce_available_payment_methods(): array {
		$available_payments = WC()->payment_gateways()->get_available_payment_gateways();
		if ( empty( $available_payments ) ) {
			return [];
		}
		$available_payments_pairs = [];
		foreach ( $available_payments as $method_id => $method_obj ) {
			$available_payments_pairs[ $method_id ] = $method_obj->get_title();
		}

		return $available_payments_pairs;
	}

	public function add_fields() {
		foreach ( $this->methods as $id => $value ) {
			$this->add_field( $id, $value, $this->depoto_api_payments_pairs );
		}
		$checkout_id = get_option( 'depoto_checkout_id' );

		$this->add_field( 'checkout_id', 'Checkout', $this->depoto_api_checkouts, $checkout_id );
	}

	private function add_field( $id, $value, $depoto_payments_pairs, $selected = '' ) {
		$depoto_payments_pairs = array_replace(['' => __('-- Select --', 'depoto')], $depoto_payments_pairs);

		add_settings_field(
			$id,
			__( $value, 'depoto' ),
			function ( $args ) use ( $depoto_payments_pairs, $selected ) {
				$id = esc_attr( $args['label_for'] );
				if (!$selected) {
					$selected = $this->depoto_payments_options[ $id ] ?? '';
				}

				echo "<select name='$id' id='$id' >";
				foreach ( $depoto_payments_pairs as $val => $depoto_title ) {
					echo "<option value='$val' " . ( ( strval($val) === strval($selected) ) ? 'selected' : '' ) . ">$depoto_title</option>";
				}
				echo '</select>';
			},
			'depoto',
			'depoto_payments',
			array(
				'label_for' => $id,
				'class'     => 'depoto-payment-' . $id,
			)
		);
	}

	/**
	 * Initializes Depoto Payments section
	 */
	public function section_init(): void {
		register_setting( 'depoto', 'depoto_payments' );

		add_settings_section(
			'depoto_payments',
			__( 'Payments', 'depoto' ),
			'',
			'depoto'
		);
	}

	public function set_fields_setting(): bool {
		$depoto_payments = get_option( 'depoto_payments' );
		if ( empty( $depoto_payments ) ) {
			$depoto_payments = [];
		}


		foreach ( $this->methods as $method_id => $method_title ) {
			$method_item = filter_input( INPUT_POST, $method_id, FILTER_DEFAULT );

			if ( ! empty( $method_item ) ) {
				$depoto_payments[ $method_id ] = $method_item;
			}
		}

		update_option( 'depoto_payments', $depoto_payments, true );

		update_option( 'depoto_checkout_id', filter_input( INPUT_POST, 'checkout_id', FILTER_DEFAULT ), 'no' );

		return true;
	}
}
