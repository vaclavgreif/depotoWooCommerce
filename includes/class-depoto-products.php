<?php

use Automattic\WooCommerce\Admin\RemoteInboxNotifications\Transformers\ArraySearch;

/**
 * @package         Depoto_Products
 */
class Depoto_Products {

	private $depoto_products;

	public function __construct( $depoto_products ) {
		$this->depoto_products = $depoto_products;
		add_action( 'admin_init', array( $this, 'schedule_product_import' ) );
	}

	/**
	 * Get all woocommerce products
	 *
	 * @return
	 */
	public function get_woocommerce_products() {
		$products = wc_get_products( [ 'limit' => - 1, 'status' => 'publish' ] );

		/**
		 * @var WC_Product $product
		 */
		foreach ( $products as $product ) {
			$children = $product->get_children();
			if ( ! empty( $children ) ) {
				foreach ( $children as $child_id ) {
					$child_product = wc_get_product( $child_id );

					if ( ! empty( $child_product ) ) {
						$this->process_wc_product( $child_product );
					}
				}
			} else {
				$this->process_wc_product( $product );
			}
		}
	}

	/**
	 * Process Simple or Variation product
	 * Checks SKU against deopoto product SKU and if there is the product then update custom meta data depoto_id
	 *
	 * @param WC_Product $product
	 *
	 * @return void
	 */
	private function process_wc_product( $product ): void {
		$product_sku = $product->get_sku();

		$found_key = array_key_exists( $product_sku, $this->depoto_products );

		if ( $found_key ) {
			echo $product->get_name() . ' --> ' . $this->depoto_products[ $product_sku ]['id'] . '<br>';
			$product->update_meta_data( '_depoto_id', $this->depoto_products[ $product_sku ]['id'] );
			$product->save_meta_data();
		} else {
			echo '<strong style="color:red">' . $product->get_name() . '</strong> --> ' . __( 'Depoto product ID not found' ) . '<br>';
		}
	}


	public function add_import_to_depoto() {
		$products_to_import = get_option( 'depoto_products_to_import', [] );
		?>
		<h3><?php _e( 'Import products to Depoto', 'depoto' ) ?></h3>
		<p><?php _e( 'By clicking on the button you can import the products that are not linked to Depoto. The products will be imported by batches of 20.', 'depoto' ) ?></p>
		<p><?php printf( __( 'There are currently %s products to import in the queue', 'depoto' ), count( $products_to_import ) ) ?></p>
		<a href="<?php echo add_query_arg( [
			'action' => 'depoto_schedule_product_import',
			'nonce'  => wp_create_nonce( 'depoto_schedule_product_import' ),
		], admin_url( 'admin.php?page=depoto&tab=products' ) ) ?>" class="button button-primary">Import products to Depoto
		</a>
	<?php }

	public function schedule_product_import() {
		$action = $_GET['action'] ?? '';
		$nonce  = $_GET['nonce'] ?? '';
		if ( $action !== 'depoto_schedule_product_import' || ! wp_verify_nonce( $nonce, 'depoto_schedule_product_import' ) ) {
			return;
		}

		$products  = wc_get_products( [ 'limit' => - 1, 'status' => 'publish' ] );
		$to_import = [];
		foreach ( $products as $product ) {
			// Always create parent products
			if ( ! $product->get_meta( '_depoto_id' ) && $product->get_sku() ) {
				$to_import[] = $product->get_id();
			}
			$children = $product->get_children();
			if ( ! empty( $children ) ) {
				foreach ( $children as $child_id ) {
					$child_product = wc_get_product( $child_id );
					if ( ! $child_product->get_meta( '_depoto_id' ) && $child_product->get_sku() ) {
						$to_import[] = $child_product->get_id();
					}
				}
			}
		}
		update_option( 'depoto_products_to_import', array_unique( $to_import ) );
		if ( ! as_next_scheduled_action( 'depoto_import_products' ) ) {
			as_enqueue_async_action( 'depoto_import_products' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=depoto&tab=products' ) );
		exit;
	}
}
