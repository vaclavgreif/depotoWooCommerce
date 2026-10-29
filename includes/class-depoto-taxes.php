<?php

/**
 * @package         Depoto_Taxes
 */
class Depoto_Taxes {

	private $taxes                  = [];
	private $depoto_api_taxes_pairs  = [];
	private $depoto_zero_taxes_pairs = [];
	private $depoto_taxes_options   = [];

	public function __construct( $depoto_api_taxes_pairs, $depoto_zero_taxes_pairs = [] ) {
		$this->depoto_api_taxes_pairs  = $depoto_api_taxes_pairs;
		$this->depoto_zero_taxes_pairs = $depoto_zero_taxes_pairs;
		$this->taxes                  = $this->get_tax_fields();
	}

	public function get_depoto_taxes_option(): void {
		$options             = (array) get_option( 'depoto_taxes', [] );
		$depoto_taxes_options = [];
		foreach ( $this->taxes as $tax_id => $tax_title ) {
			$depoto_taxes_options[ $tax_id ] = $options[ $tax_id ] ?? '';
		}
		$this->depoto_taxes_options = $depoto_taxes_options;
	}

	public function set_admin_hooks() {
		add_action( 'admin_init', [ $this, 'section_init' ] );
		add_action( 'admin_init', [ $this, 'add_fields' ] );
	}

	/** Settings field IDs and labels, including a default independent of WooCommerce tax rates. */
	private function get_tax_fields(): array {
		$class_slugs = WC_Tax::get_tax_class_slugs();
		array_unshift( $class_slugs, '' ); // Standard rate has no slug, so we have to add it this way

		$tax_rates = [];
		foreach ( $class_slugs as $class_slug ) {
			$tax_rates[] = WC_Tax::get_rates_for_tax_class( $class_slug );
		}

		$available_taxes_pairs = [
			'default_vat_id' => __( '0% DEPOTO VAT for untaxed items (required)', 'depoto' ),
		];
		foreach ( $tax_rates as $tax_obj ) {
			$tax_obj = current( $tax_obj ); // Preserve the existing first-rate-per-class mapping.
			if ( ! empty( $tax_obj ) ) {
				$available_taxes_pairs[ 'tax_id_' . $tax_obj->tax_rate_id ] = $tax_obj->tax_rate_name;
			}
		}

		return $available_taxes_pairs;
	}

	public function add_fields() {
		foreach ( $this->taxes as $id => $value ) {
			$pairs = 'default_vat_id' === $id ? $this->depoto_zero_taxes_pairs : $this->depoto_api_taxes_pairs;
			$this->add_field( $id, $value, $pairs );
		}
	}

	private function add_field( $id, $value, $depoto_taxes_pairs ) {
		$placeholder        = 'default_vat_id' === $id ? __( 'Select a 0% DEPOTO VAT record', 'depoto' ) : __( 'Select a DEPOTO VAT record', 'depoto' );
		$depoto_taxes_pairs = array_replace( [ '' => $placeholder ], $depoto_taxes_pairs );
		add_settings_field(
			$id,
			__( $value, 'depoto' ),
			function ( $args ) use ( $depoto_taxes_pairs ) {
				$id = esc_attr( $args['label_for'] );

				echo "<select name='$id' id='$id'>";
				foreach ( $depoto_taxes_pairs as $depoto_id => $depoto_title ) {
					$selected      = '';
					$depoto_tax_id = $this->depoto_taxes_options[ $id ] ?? '';
					if ( (string) $depoto_id === (string) $depoto_tax_id ) {
						$selected = 'selected';
					}
					echo "<option value='" . esc_attr( $depoto_id ) . "' " . $selected . ">" . esc_html( $depoto_title ) . "</option>";
				}
				echo '</select>';
				if ( 'default_vat_id' === $id && empty( $this->depoto_zero_taxes_pairs ) ) {
					echo '<p role="alert">' . esc_html__( 'No 0% VAT records were loaded. Check your DEPOTO connection or create a 0% VAT record in DEPOTO, then reload this page.', 'depoto' ) . '</p>';
				}
			},
			'depoto',
			'depoto_taxes',
			array(
				'label_for' => $id,
				'class'     => 'depoto-taxes-' . $id,
			)
		);
	}

	/**
	 * Initializes Depoto taxes section
	 */
	public function section_init(): void {
		register_setting( 'depoto', 'depoto_taxes' );

		add_settings_section(
			'depoto_taxes',
			__( 'Taxes', 'depoto' ),
			function () {
				echo '<p>' . esc_html__( 'DEPOTO requires a VAT record even when WooCommerce has no tax rates. Select a 0% DEPOTO VAT record for untaxed products, shipping and payment fees. This sends the record ID without adding tax to the order. Map taxable WooCommerce rates separately.', 'depoto' ) . '</p>';
			},
			'depoto'
		);
	}

	public function set_fields_setting(): bool {
		$depoto_taxes = get_option( 'depoto_taxes', [] );
		if ( ! is_array( $depoto_taxes ) ) {
			$depoto_taxes = [];
		}

		foreach ( $this->taxes as $tax_id => $tax_title ) {
			$tax_item = filter_input( INPUT_POST, $tax_id, FILTER_DEFAULT );

			if ( null !== $tax_item && false !== $tax_item ) {
				// Accept the untaxed default only if DEPOTO returned it as an actual 0% record.
				if ( 'default_vat_id' === $tax_id && '' !== $tax_item && ! array_key_exists( $tax_item, $this->depoto_zero_taxes_pairs ) ) {
					add_settings_error( 'depoto_messages', 'depoto_zero_vat', __( 'Select an available 0% DEPOTO VAT record. The previous setting was kept.', 'depoto' ), 'error' );
					Depoto_Diagnostics::write( 'warning', 'DEPOTO untaxed VAT selection rejected: record is not an available 0% VAT.', [] );
					continue;
				}
				if ( (string) ( $depoto_taxes[ $tax_id ] ?? '' ) !== $tax_item ) {
					Depoto_Diagnostics::write( 'info', 'DEPOTO VAT mapping changed.', [ 'field' => $tax_id, 'depoto_vat_id' => '' === $tax_item ? null : (int) $tax_item ] );
				}
				$depoto_taxes[ $tax_id ] = $tax_item;
			}
		}

		update_option( 'depoto_taxes', $depoto_taxes, true );

		return true;
	}
}
