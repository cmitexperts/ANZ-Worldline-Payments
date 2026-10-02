<?php
/**
 * Shortcode: payment form (Company, Email, Invoice Number, Amount, Pay Now).
 * No login required; guests can pay.
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ANZ_Worldline_Shortcode
 */
class ANZ_Worldline_Shortcode {

	const SHORTCODE = 'anz_worldline_payment_form';
	const TRANSIENT_PREFIX = 'anz_worldline_rt_';
	/** Transient key prefix for lookup by hostedCheckoutId (Worldline redirects with this). */
	const HCID_TRANSIENT_PREFIX = 'anz_worldline_hcid_';

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
		add_shortcode( self::SHORTCODE, array( $this, 'render_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_anz_worldline_create_checkout', array( $this, 'ajax_create_checkout' ) );
		add_action( 'wp_ajax_nopriv_anz_worldline_create_checkout', array( $this, 'ajax_create_checkout' ) );
	}

	/**
	 * Get reCAPTCHA site key (wp-config.php constant or saved option).
	 *
	 * @return string
	 */
	public static function get_recaptcha_site_key() {
		if ( defined( 'APP_RECAPTCHA_SITE_KEY' ) && APP_RECAPTCHA_SITE_KEY !== '' ) {
			return is_string( APP_RECAPTCHA_SITE_KEY ) ? trim( APP_RECAPTCHA_SITE_KEY ) : '';
		}
		$opts = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		return isset( $opts['recaptcha_site_key'] ) ? trim( (string) $opts['recaptcha_site_key'] ) : '';
	}

	/**
	 * Get reCAPTCHA secret key (wp-config.php constant or saved option).
	 *
	 * @return string
	 */
	public static function get_recaptcha_secret_key() {
		if ( defined( 'APP_RECAPTCHA_SECRET_KEY' ) && APP_RECAPTCHA_SECRET_KEY !== '' ) {
			return is_string( APP_RECAPTCHA_SECRET_KEY ) ? trim( APP_RECAPTCHA_SECRET_KEY ) : '';
		}
		$opts = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		return isset( $opts['recaptcha_secret_key'] ) ? trim( (string) $opts['recaptcha_secret_key'] ) : '';
	}

	/**
	 * Enqueue front-end assets only when shortcode is present.
	 */
	public function enqueue_assets() {
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}
		$recaptcha_site_key = self::get_recaptcha_site_key();

		wp_enqueue_style(
			'anz-worldline-form',
			ANZ_WORLDLINE_PAYMENTS_PLUGIN_URL . 'assets/form.css',
			array(),
			ANZ_WORLDLINE_PAYMENTS_VERSION
		);
		if ( $recaptcha_site_key !== '' ) {
			wp_enqueue_script(
				'google-recaptcha',
				'https://www.google.com/recaptcha/api.js',
				array(),
				null,
				true
			);
		}
		wp_enqueue_script(
			'anz-worldline-form',
			ANZ_WORLDLINE_PAYMENTS_PLUGIN_URL . 'assets/form.js',
			array( 'jquery' ),
			ANZ_WORLDLINE_PAYMENTS_VERSION,
			true
		);
		wp_localize_script( 'anz-worldline-form', 'anzWorldlineForm', array(
			'ajax_url'         => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'anz_worldline_create_checkout' ),
			'recaptcha_enabled' => $recaptcha_site_key !== '',
		) );
	}

	/**
	 * Render payment form HTML.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_form( $atts ) {
		$atts = shortcode_atts( array(
			'currency' => 'AUD',
		), $atts, self::SHORTCODE );

		$recaptcha_site_key = self::get_recaptcha_site_key();

		ob_start();
		?>
		<div class="anz-worldline-payment-form-wrapper">
			<form id="anz-worldline-payment-form" class="anz-worldline-payment-form" method="post" action="">
				<?php wp_nonce_field( 'anz_worldline_payment_form', 'anz_worldline_nonce' ); ?>
				<input type="hidden" name="currency" value="<?php echo esc_attr( $atts['currency'] ); ?>" />
				<div class="anz-worldline-form-body animate-item" data-animate="fadeInUp" data-duration="0.5s" data-delay="1.0s" data-iteration="1">
					<p class="anz-worldline-field">
						<input type="text" id="anz-worldline-company" name="company" required class="wide" placeholder="<?php esc_html_e( 'Company', 'anz-worldline-payments' ); ?>" />
					</p>
					<p class="anz-worldline-field">
						<input type="email" id="anz-worldline-email" name="email" required class="wide" placeholder="<?php esc_html_e( 'Email', 'anz-worldline-payments' ); ?>" />
					</p>
					<p class="anz-worldline-field">
						<input type="text" id="anz-worldline-invoice" name="invoice_number" required class="wide" placeholder="<?php esc_html_e( 'Invoice Number', 'anz-worldline-payments' ); ?>" />
					</p>
					<p class="anz-worldline-field">
						<input type="number" id="anz-worldline-amount" name="amount" required min="1" step="0.01" class="wide" placeholder="<?php esc_html_e( 'Amount', 'anz-worldline-payments' ); ?> (<?php echo esc_html( $atts['currency'] ); ?>)" pattern="^\d+(?:\.\d{1,2})?$" />
						<div class="anz-worldline-help-text">
							<!-- Surcharge notice disabled 02/10/26
							<em>* A 3% credit card surcharge will automatically be added to your transaction</em>
							-->
							<!-- edited by YM 26/3/26 <br/><em>** Amex is NOT accepted</em> -->
						</div><!-- /.pap-help-text -->
					</p>
					<p class="anz-worldline-submit">
						<button type="submit" id="anz-worldline-pay-now" class="anz-worldline-btn<?php echo $recaptcha_site_key !== '' ? ' g-recaptcha' : ''; ?>"<?php echo $recaptcha_site_key !== '' ? ' data-callback="anzWorldlineRecaptchaSubmit" data-action="submit" data-sitekey="' . esc_attr( $recaptcha_site_key ) . '"' : ''; ?>><?php esc_html_e( 'Pay Now', 'anz-worldline-payments' ); ?></button>
						<span class="anz-worldline-loader" id="anz-worldline-loader" aria-hidden="true" style="display:none;"></span>
					</p>
					<div id="anz-worldline-message" class="anz-worldline-message" role="alert" aria-live="polite" style="display:none;"></div>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * AJAX: Create hosted checkout and return redirect URL (or store token for return URL).
	 */
	public function ajax_create_checkout() {
		check_ajax_referer( 'anz_worldline_create_checkout', 'nonce' );

		$recaptcha_secret = self::get_recaptcha_secret_key();
		if ( $recaptcha_secret !== '' ) {
			$token = isset( $_POST['g-recaptcha-response'] ) ? sanitize_text_field( wp_unslash( $_POST['g-recaptcha-response'] ) ) : '';
			if ( $token === '' ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'anz-worldline-payments' ) ) );
			}
			$verify = wp_remote_post(
				'https://www.google.com/recaptcha/api/siteverify',
				array(
					'body' => array(
						'secret'   => $recaptcha_secret,
						'response' => $token,
						'remoteip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
					),
					'timeout' => 10,
				)
			);
			if ( is_wp_error( $verify ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'anz-worldline-payments' ) ) );
			}
			$code = wp_remote_retrieve_response_code( $verify );
			$body = json_decode( wp_remote_retrieve_body( $verify ), true );
			if ( $code !== 200 || empty( $body['success'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'anz-worldline-payments' ) ) );
			}
			$expected_host = wp_parse_url( get_option( 'site_url' ), PHP_URL_HOST );
			if ( ! empty( $expected_host ) && ! empty( $body['hostname'] ) && strtolower( $body['hostname'] ) !== strtolower( $expected_host ) ) {
				wp_send_json_error( array( 'message' => __( 'Security check failed. Please try again.', 'anz-worldline-payments' ) ) );
			}
		}

		$company       = isset( $_POST['company'] ) ? sanitize_text_field( wp_unslash( $_POST['company'] ) ) : '';
		$email         = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$invoice_number = isset( $_POST['invoice_number'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_number'] ) ) : '';
		$amount        = isset( $_POST['amount'] ) ? floatval( $_POST['amount'] ) : 0;
		$currency      = isset( $_POST['currency'] ) ? sanitize_text_field( wp_unslash( $_POST['currency'] ) ) : 'AUD';

		if ( ! $company || ! $email || ! $invoice_number || $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Please fill all fields with valid values.', 'anz-worldline-payments' ) ) );
		}

		// Surcharge calculation disabled 02/10/26 — charge the entered amount only.
		// 3% credit card surcharge (same as live site).
		// $surcharge    = round( $amount * 0.03, 2 );
		// $total        = $amount + $surcharge;
		// $amount_cents = (int) round( $total * 100 );
		$amount_cents         = (int) round( $amount * 100 );
		$invoice_amount_cents = (int) round( $amount * 100 );

		if ( $amount_cents <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid amount.', 'anz-worldline-payments' ) ) );
		}

		$return_token = wp_generate_password( 32, false );
		$return_url   = add_query_arg(
			array(
				'anz_worldline_return' => 1,
				'token'                => $return_token,
			),
			isset( $_POST['form_page_url'] ) ? esc_url_raw( wp_unslash( $_POST['form_page_url'] ) ) : home_url( '/' )
		);

		$result = ANZ_Worldline_Api::create_hosted_checkout( array(
			'amount'             => $amount_cents,
			'currency'           => $currency,
			'return_url'         => $return_url,
			'merchant_reference' => $invoice_number,
		) );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		$opts = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		$mode = isset( $opts['mode'] ) && $opts['mode'] === 'test' ? 'test' : 'live';

		$stored_data = array(
			'hosted_checkout_id'     => $result['hosted_checkout_id'],
			'invoice_number'         => $invoice_number,
			'email'                  => $email,
			'company'                => $company,
			'amount_cents'           => $amount_cents,
			'invoice_amount_cents'   => $invoice_amount_cents,
			'currency'               => $currency,
			'mode'                   => $mode,
		);
		$ttl = 3 * HOUR_IN_SECONDS;
		// By token (used when return URL keeps our query params).
		set_transient( self::TRANSIENT_PREFIX . $return_token, $stored_data, $ttl );
		// By hostedCheckoutId (used when Worldline redirects with hostedCheckoutId; more reliable).
		if ( ! empty( $result['hosted_checkout_id'] ) ) {
			set_transient( self::HCID_TRANSIENT_PREFIX . $result['hosted_checkout_id'], $stored_data, $ttl );
		}

		wp_send_json_success( array(
			'redirect_url' => $result['redirect_url'],
		) );
	}
}
