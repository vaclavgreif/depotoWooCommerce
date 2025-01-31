<?php
if (!current_user_can('manage_options')) {
	return;
}

if (isset($_GET['settings-updated'])) {
	// add settings saved message with the class of "updated"
	add_settings_error('depoto_messages', 'depoto_message', __('Settings Saved', 'depoto'), 'updated');
}

//Get the active tab from the $_GET param
$tab = $_GET['tab'] ?? 'pairing';

// show error/update messages
settings_errors('depoto_messages');
?>

<div class="wrap">
	<h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="?page=depoto&tab=pairing" class="nav-tab <?php echo $tab === 'pairing' ? 'nav-tab-active' : '' ?>"><?php _e('Pairing', 'depoto'); ?></a>
		<a href="?page=depoto&tab=products" class="nav-tab <?php echo $tab === 'products' ? 'nav-tab-active' : '' ?>"><?php _e('Products', 'depoto'); ?></a>
		<a href="?page=depoto&tab=orders" class="nav-tab <?php echo $tab === 'orders' ? 'nav-tab-active' : '' ?>"><?php _e('Orders', 'depoto'); ?></a>
		<a href="?page=depoto&tab=webhook" class="nav-tab <?php echo $tab === 'webhook' ? 'nav-tab-active' : '' ?>"><?php _e('Webhook', 'depoto'); ?></a>
		<a href="?page=depoto&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : '' ?>"><?php _e('Settings', 'depoto'); ?></a>
	</nav>

	<div class="tab-content">
		<?php
		switch ($tab):
			case 'pairing':
				/** @var Depoto $this */
				$this->show_tab_pairing();
				break;
			case 'products':
				/** @var Depoto $this */
				$this->show_tab_products();
				break;
			case 'orders':
				/** @var Depoto $this */
				$this->show_tab_orders();
				break;
			case 'webhook':
				/** @var Depoto $this */
				$this->show_tab_webhook();
				break;
			case 'settings':
				/** @var Depoto $this */
				$this->show_tab_settings();
				break;
			default:
				/** @var Depoto $this */
				$this->show_tab_pairing();
		endswitch;
		?>
	</div>
</div>