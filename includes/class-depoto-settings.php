<?php

/**
 * @package         Depoto_Settings
 */
class Depoto_Settings
{
	private $depoto_settings;
	private $depoto_api;

	public function __construct($depoto_api)
	{
		$this->depoto_api = $depoto_api;
	}

	public function get_depoto_settings_option()
	{
		$this->depoto_settings = get_option('depoto_settings') ?? [];
	}

	public function set_admin_hooks()
	{
		add_action('admin_init', [$this, 'section_init']);
		add_action('admin_init', [$this, 'add_fields']);
	}


	public function add_fields()
	{
		$url_value = $this->depoto_settings['url'] ?? '';
		$this->add_field('url', 'URL', $url_value, esc_html__('TEST: https://server1.depoto.cz.tomatomstage.cz, PROD: https://server1.depoto.cz', 'depoto'));

		$username_value = $this->depoto_settings['username'] ?? '';
		$this->add_field('username', 'Username', $username_value);

		$password_value = $this->depoto_settings['password'] ?? '';
		$this->add_field('password', 'Password', $password_value);
	}

	private function add_field($id, $label, $value, $tip = '')
	{
		add_settings_field(
			$id,
			__($label, 'depoto'),
			function ($args) {
				$id = esc_attr($args['label_for']);
				$value = esc_attr($args['value']);
				$tip = $args['tip'];
				echo "<input type='text' name='$id' id='$id' value='$value'>";
				echo "<span class='url-tip'>$tip</span>";
			},
			'depoto_settings',
			'depoto_settings',
			[
				'label_for' => $id,
				'class' => 'depoto-settings-' . $id,
				'value' => $value,
				'tip' => $tip
			]
		);
	}

	/**
	 * Initializes Depoto Order statuses section
	 */
	public function section_init(): void
	{
		register_setting('depoto_settings', 'depoto_settings');

		add_settings_section(
			'depoto_settings',
			__('Depoto Settings', 'depoto'),
			'',
			'depoto_settings'
		);
	}

	public function set_fields_setting(): bool
	{

		$depoto_settings = get_option('depoto_settings');
		if (empty($depoto_settings)) {
			$depoto_settings = [];
		}

		$fields = ['url', 'username', 'password'];
		foreach ($fields as $field) {
			$value = filter_input(INPUT_POST, $field, FILTER_DEFAULT);

			if (!empty($value)) {
				$depoto_settings[$field] = $value;
			}
		}

		update_option('depoto_settings', $depoto_settings, true);

		// Here we set new params and init connection with the possible new parameters
		$this->depoto_api->set_login_params();
		$this->depoto_api->init_connection();

		return true;
	}
}
