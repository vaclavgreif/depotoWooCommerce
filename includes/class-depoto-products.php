<?php

use Automattic\WooCommerce\Admin\RemoteInboxNotifications\Transformers\ArraySearch;

/**
 * @package         Depoto_Products
 */
class Depoto_Products
{

	private $depoto_products;

	public function __construct($depoto_products)
	{
		$this->depoto_products = $depoto_products;
	}

	/**
	 * Get all woocommerce products
	 *
	 * @return
	 */
	public function get_woocommerce_products()
	{
		$products = wc_get_products(['limit' => -1, 'status' => 'publish']);

		/**
		 * @var WC_Product $product
		 */
		foreach ($products as $product) {

			$children = $product->get_children();
			if (!empty($children)) {
				foreach ($children as $child_id) {
					$child_product = wc_get_product($child_id);

					if (!empty($child_product)) {
						$this->process_wc_product($child_product);
					}
				}
			} else {
				$this->process_wc_product($product);
			}
		}
	}

	/**
	 * Process Simple or Variation product
	 * Checks SKU against deopoto product SKU and if there is the product then update custom meta data depoto_id
	 *
	 * @param  WC_Product $product
	 * @return void
	 */
	private function process_wc_product($product): void
	{
		$product_sku = $product->get_sku();

		$found_key = array_key_exists($product_sku, $this->depoto_products);

		if ($found_key) {
			echo $product->get_name() . ' --> ' . $this->depoto_products[$product_sku]['id'] . '<br>';
			$product->update_meta_data('_depoto_id', $this->depoto_products[$product_sku]['id']);
			$product->save_meta_data();
		} else {
			echo '<strong style="color:red">' . $product->get_name() . '</strong> --> ' . __('Depoto product ID not found') . '<br>';
		}
	}
}
