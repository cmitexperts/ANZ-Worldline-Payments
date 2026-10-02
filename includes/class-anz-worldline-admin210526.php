<?php
/**
 * Admin settings for ANZ Worldline Payments.
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ANZ_Worldline_Admin
 */
class ANZ_Worldline_Admin {

	const PAGE_SLUG = 'anz-worldline-payments';

	/**
	 * @var self
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_menu', array( $this, 'reorder_and_rename_submenu' ), 999 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add top-level menu with WordPress Dashicon (outside Settings).
	 */
	public function add_menu() {
		add_menu_page(
			__( 'ANZ Worldline Payments', 'anz-worldline-payments' ),
			__( 'ANZ Worldline Payments', 'anz-worldline-payments' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-money-alt',
			58
		);
		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Payments', 'anz-worldline-payments' ),
			__( 'Payments', 'anz-worldline-payments' ),
			'manage_options',
			'anz-worldline-payments-payments',
			array( $this, 'render_payments_list' )
		);
	}

	/**
	 * Put Payments first and rename the default submenu to "Settings".
	 */
	public function reorder_and_rename_submenu() {
		global $submenu;
		if ( empty( $submenu[ self::PAGE_SLUG ] ) || count( $submenu[ self::PAGE_SLUG ] ) < 2 ) {
			return;
		}
		$items = $submenu[ self::PAGE_SLUG ];
		$first = $items[0]; // Default (settings page).
		$second = $items[1]; // Payments.
		$first[0] = __( 'Settings', 'anz-worldline-payments' );
		$submenu[ self::PAGE_SLUG ] = array( $second, $first );
	}

	/**
	 * Render payments list using WP_List_Table (WordPress standard).
	 */
	public function render_payments_list() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$list_table = new ANZ_Worldline_Payments_List_Table();
		$list_table->prepare_items();

		?>
		<div class="wrap anz-worldline-payments-list">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Payments', 'anz-worldline-payments' ); ?></h1>
			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="page" value="anz-worldline-payments-payments" />
				<?php $list_table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Register settings and sections.
	 */
	public function register_settings() {
		register_setting(
			'anz_worldline_payments_settings_group',
			ANZ_WORLDLINE_PAYMENTS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);

		add_settings_section(
			'anz_worldline_main',
			__( 'API Configuration', 'anz-worldline-payments' ),
			array( $this, 'section_callback' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'anz_worldline_recaptcha',
			__( 'Google reCAPTCHA', 'anz-worldline-payments' ),
			array( $this, 'section_recaptcha_callback' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'anz_worldline_redirects',
			__( 'Redirect pages', 'anz-worldline-payments' ),
			array( $this, 'section_redirects_callback' ),
			self::PAGE_SLUG
		);

		add_settings_section(
			'anz_worldline_email',
			__( 'Email notifications', 'anz-worldline-payments' ),
			array( $this, 'section_email_callback' ),
			self::PAGE_SLUG
		);

		$fields = array(
			'mode' => array(
				'title' => __( 'Mode', 'anz-worldline-payments' ),
				'type'  => 'select',
				'options' => array(
					'test' => __( 'Test', 'anz-worldline-payments' ),
					'live' => __( 'Live', 'anz-worldline-payments' ),
				),
			),
			'merchant_id' => array(
				'title'       => __( 'Merchant ID', 'anz-worldline-payments' ),
				'type'        => 'text',
				'description' => __( 'Your merchant ID (used in endpoint URL).', 'anz-worldline-payments' ),
			),
			'pspid' => array(
				'title'       => __( 'PSPID', 'anz-worldline-payments' ),
				'type'        => 'text',
				'description' => __( 'Your PSPID (used as merchant ID in API path). Must match the merchant your API key belongs to.', 'anz-worldline-payments' ),
			),
			'api_key_id' => array(
				'title'       => __( 'API Key ID', 'anz-worldline-payments' ),
				'type'        => 'text',
				'description' => __( 'API Key from Merchant Portal > Developer > Payment API.', 'anz-worldline-payments' ),
			),
			'api_secret_id' => array(
				'title'       => __( 'API Secret ID', 'anz-worldline-payments' ),
				'type'        => 'password',
				'description' => __( 'API Secret (save immediately after creation in Merchant Portal).', 'anz-worldline-payments' ),
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'anz_worldline_' . $key,
				$field['title'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'anz_worldline_main',
				array(
					'key'     => $key,
					'field'   => $field,
				)
			);
		}

		$recaptcha_fields = array(
			'recaptcha_site_key' => array(
				'title'       => __( 'reCAPTCHA Site Key', 'anz-worldline-payments' ),
				'type'        => 'text',
				'description' => __( 'Google reCAPTCHA v2 site key (optional). Leave empty to disable.', 'anz-worldline-payments' ),
			),
			'recaptcha_secret_key' => array(
				'title'       => __( 'reCAPTCHA Secret Key', 'anz-worldline-payments' ),
				'type'        => 'password',
				'description' => __( 'Secret key for server-side verification. Required if site key is set.', 'anz-worldline-payments' ),
			),
		);

		foreach ( $recaptcha_fields as $key => $field ) {
			add_settings_field(
				'anz_worldline_' . $key,
				$field['title'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'anz_worldline_recaptcha',
				array(
					'key'     => $key,
					'field'   => $field,
				)
			);
		}

		$redirect_fields = array(
			'thank_you_page_id' => array(
				'title'       => __( 'Thank you page', 'anz-worldline-payments' ),
				'type'        => 'page_select',
				'description' => __( 'Page to redirect to after successful payment (e.g. /thankyou).', 'anz-worldline-payments' ),
			),
			'payment_error_page_id' => array(
				'title'       => __( 'Payment error page', 'anz-worldline-payments' ),
				'type'        => 'page_select',
				'description' => __( 'Page to redirect to when payment fails or return link is invalid (e.g. /payment-error).', 'anz-worldline-payments' ),
			),
		);

		foreach ( $redirect_fields as $key => $field ) {
			add_settings_field(
				'anz_worldline_' . $key,
				$field['title'],
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'anz_worldline_redirects',
				array(
					'key'     => $key,
					'field'   => $field,
				)
			);
		}

		add_settings_field(
			'anz_worldline_email_recipient',
			__( 'Email recipient', 'anz-worldline-payments' ),
			array( $this, 'render_field' ),
			self::PAGE_SLUG,
			'anz_worldline_email',
			array(
				'key'   => 'email_recipient',
				'field' => array(
					'title'       => __( 'Email recipient', 'anz-worldline-payments' ),
					'type'        => 'email',
					'description' => __( 'Email address to receive payment notification when a payment is successful. Leave empty to disable.', 'anz-worldline-payments' ),
				),
			)
		);
	}

	/**
	 * Section description.
	 */
	public function section_callback() {
		$test_url = 'https://payment.preprod.anzworldline-solutions.com.au/{merchantId}/hostedcheckouts';
		$live_url = 'https://payment.anzworldline-solutions.com.au/{merchantId}/hostedcheckouts';
		echo '<p>' . esc_html__( 'Configure your ANZ Worldline hosted checkout credentials. Hosted checkout sessions are valid for 3 hours.', 'anz-worldline-payments' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Endpoint URLs:', 'anz-worldline-payments' ) . '</strong></p>';
		echo '<ul style="list-style:disc; margin-left: 20px;">';
		echo '<li><strong>Test:</strong> <code>' . esc_html( $test_url ) . '</code></li>';
		echo '<li><strong>Live:</strong> <code>' . esc_html( $live_url ) . '</code></li>';
		echo '</ul>';
	}

	/**
	 * reCAPTCHA section description.
	 */
	public function section_recaptcha_callback() {
		echo '<p>' . esc_html__( 'Optional: protect the payment form with Google reCAPTCHA v2. Get keys at https://www.google.com/recaptcha/admin', 'anz-worldline-payments' ) . '</p>';
		if ( defined( 'APP_RECAPTCHA_SITE_KEY' ) && APP_RECAPTCHA_SITE_KEY !== '' ) {
			echo '<p><em>' . esc_html__( 'Site key and secret are currently defined in wp-config.php (APP_RECAPTCHA_SITE_KEY, APP_RECAPTCHA_SECRET_KEY) and take precedence over the fields below.', 'anz-worldline-payments' ) . '</em></p>';
		}
	}

	/**
	 * Redirect pages section description.
	 */
	public function section_redirects_callback() {
		echo '<p>' . esc_html__( 'Choose where to send the customer after payment. If not set, defaults to /thankyou and /payment-error.', 'anz-worldline-payments' ) . '</p>';
	}

	/**
	 * Email notifications section description.
	 */
	public function section_email_callback() {
		echo '<p>' . esc_html__( 'Send a payment confirmation email to the recipient and to the customer when a payment is successful.', 'anz-worldline-payments' ) . '</p>';
	}

	/**
	 * Render a settings field.
	 *
	 * @param array $args Field args.
	 */
	public function render_field( $args ) {
		$key   = $args['key'];
		$field = $args['field'];
		$opt   = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		$value = isset( $opt[ $key ] ) ? $opt[ $key ] : '';

		if ( 'password' === $field['type'] && $value !== '' ) {
			$value = ''; // Don't show stored secret in UI; user re-enters to change.
		}

		if ( 'select' === $field['type'] ) {
			echo '<select name="' . esc_attr( ANZ_WORLDLINE_PAYMENTS_OPTION . '[' . $key . ']' ) . '" id="anz_worldline_' . esc_attr( $key ) . '">';
			foreach ( $field['options'] as $opt_val => $opt_label ) {
				echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'page_select' === $field['type'] ) {
			$pages = get_pages( array( 'sort_column' => 'menu_order,post_title' ) );
			$options = array( '' => __( '— Select page —', 'anz-worldline-payments' ) );
			foreach ( $pages as $page ) {
				$options[ $page->ID ] = $page->post_title;
			}
			echo '<select name="' . esc_attr( ANZ_WORLDLINE_PAYMENTS_OPTION . '[' . $key . ']' ) . '" id="anz_worldline_' . esc_attr( $key ) . '">';
			foreach ( $options as $opt_val => $opt_label ) {
				echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( (string) $value, (string) $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
			}
			echo '</select>';
		} elseif ( 'email' === $field['type'] ) {
			echo '<input type="email" name="' . esc_attr( ANZ_WORLDLINE_PAYMENTS_OPTION . '[' . $key . ']' ) . '" id="anz_worldline_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="email" />';
		} else {
			$type = 'text' === $field['type'] ? 'text' : 'password';
			echo '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( ANZ_WORLDLINE_PAYMENTS_OPTION . '[' . $key . ']' ) . '" id="anz_worldline_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="off" />';
		}

		if ( ! empty( $field['description'] ) ) {
			echo '<p class="description">' . esc_html( $field['description'] ) . '</p>';
		}
	}

	/**
	 * Sanitize settings; preserve existing API secret if new one is empty.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		if ( ! is_array( $input ) ) {
			return array();
		}
		$current = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		$out = array(
			'mode'                    => isset( $input['mode'] ) && in_array( $input['mode'], array( 'test', 'live' ), true ) ? $input['mode'] : 'test',
			'merchant_id'             => isset( $input['merchant_id'] ) ? sanitize_text_field( $input['merchant_id'] ) : '',
			'pspid'                   => isset( $input['pspid'] ) ? sanitize_text_field( $input['pspid'] ) : '',
			'api_key_id'              => isset( $input['api_key_id'] ) ? sanitize_text_field( $input['api_key_id'] ) : '',
			'api_secret_id'           => isset( $input['api_secret_id'] ) ? $input['api_secret_id'] : '',
			'recaptcha_site_key'      => isset( $input['recaptcha_site_key'] ) ? sanitize_text_field( $input['recaptcha_site_key'] ) : '',
			'recaptcha_secret_key'    => isset( $input['recaptcha_secret_key'] ) ? $input['recaptcha_secret_key'] : '',
			'thank_you_page_id'       => isset( $input['thank_you_page_id'] ) ? absint( $input['thank_you_page_id'] ) : 0,
			'payment_error_page_id'   => isset( $input['payment_error_page_id'] ) ? absint( $input['payment_error_page_id'] ) : 0,
			'email_recipient'          => isset( $input['email_recipient'] ) ? sanitize_email( $input['email_recipient'] ) : '',
		);
		if ( $out['api_secret_id'] === '' && ! empty( $current['api_secret_id'] ) ) {
			$out['api_secret_id'] = $current['api_secret_id'];
		} else {
			$out['api_secret_id'] = sanitize_text_field( $out['api_secret_id'] );
		}
		if ( $out['recaptcha_secret_key'] === '' && ! empty( $current['recaptcha_secret_key'] ) ) {
			$out['recaptcha_secret_key'] = $current['recaptcha_secret_key'];
		} else {
			$out['recaptcha_secret_key'] = sanitize_text_field( $out['recaptcha_secret_key'] );
		}
		return $out;
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Page hook.
	 */
	public function enqueue_scripts( $hook ) {
		$settings_hook = 'toplevel_page_' . self::PAGE_SLUG;
		$payments_hook = self::PAGE_SLUG . '_page_anz-worldline-payments-payments';
		if ( $settings_hook !== $hook && $payments_hook !== $hook ) {
			return;
		}
		wp_enqueue_style( 'anz-worldline-admin', ANZ_WORLDLINE_PAYMENTS_PLUGIN_URL . 'assets/admin.css', array(), ANZ_WORLDLINE_PAYMENTS_VERSION );
	}

	/**
	 * Render settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap anz-worldline-admin">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'anz_worldline_payments_settings_group' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'anz-worldline-payments' ) );
				?>
			</form>
			<hr />
			<h2><?php esc_html_e( 'Shortcode', 'anz-worldline-payments' ); ?></h2>
			<p><?php esc_html_e( 'Use this shortcode to display the payment form (no login required):', 'anz-worldline-payments' ); ?></p>
			<p><code>[anz_worldline_payment_form]</code></p>
		</div>
		<?php
	}
}
