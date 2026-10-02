<?php
/**
 * Handle return from ANZ Worldline Hosted Checkout: get payment status and redirect to thank you or payment error page.
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ANZ_Worldline_Return_Handler
 */
class ANZ_Worldline_Return_Handler {

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
		add_action( 'template_redirect', array( $this, 'handle_return' ), 5 );
	}

	/**
	 * Get redirect URL for thank you page (success) or payment error page.
	 *
	 * @param bool $success True for thank you, false for payment error.
	 * @return string
	 */
	private function get_redirect_url( $success ) {
		$opts = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		if ( $success ) {
			$page_id = isset( $opts['thank_you_page_id'] ) ? (int) $opts['thank_you_page_id'] : 0;
			if ( $page_id > 0 ) {
				$url = get_permalink( $page_id );
				if ( $url ) {
					return $url;
				}
			}
			return home_url( '/thankyou' );
		}
		$page_id = isset( $opts['payment_error_page_id'] ) ? (int) $opts['payment_error_page_id'] : 0;
		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/payment-error' );
	}

	/**
	 * If URL has anz_worldline_return=1 and (token or hostedCheckoutId), get status and redirect to thank you or payment error page.
	 */
	public function handle_return() {
		$has_return = ! empty( $_GET['anz_worldline_return'] );
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$hosted_checkout_id_get = isset( $_GET['hostedCheckoutId'] ) ? sanitize_text_field( wp_unslash( $_GET['hostedCheckoutId'] ) ) : '';
		if ( ! $has_return || ( $token === '' && $hosted_checkout_id_get === '' ) ) {
			return;
		}

		$stored = null;
		$transient_key_by_token = '';
		if ( $token !== '' ) {
			$transient_key_by_token = ANZ_Worldline_Shortcode::TRANSIENT_PREFIX . $token;
			$stored = get_transient( $transient_key_by_token );
		}
		if ( ( ! $stored || empty( $stored['hosted_checkout_id'] ) ) && $hosted_checkout_id_get !== '' ) {
			$stored = get_transient( ANZ_Worldline_Shortcode::HCID_TRANSIENT_PREFIX . $hosted_checkout_id_get );
		}

		$hosted_checkout_id = $hosted_checkout_id_get !== '' ? $hosted_checkout_id_get : ( $stored && ! empty( $stored['hosted_checkout_id'] ) ? $stored['hosted_checkout_id'] : '' );

		if ( $hosted_checkout_id === '' ) {
			wp_safe_redirect( $this->get_redirect_url( false ) );
			exit;
		}

		$result = ANZ_Worldline_Api::get_hosted_checkout_status( $hosted_checkout_id );

		if ( $transient_key_by_token !== '' ) {
			delete_transient( $transient_key_by_token );
		}
		delete_transient( ANZ_Worldline_Shortcode::HCID_TRANSIENT_PREFIX . $hosted_checkout_id );

		if ( ! $result['success'] ) {
			if ( $stored ) {
				ANZ_Worldline_Payment_Post_Type::log_payment( $stored, $result, false );
			}
			wp_safe_redirect( $this->get_redirect_url( false ) );
			exit;
		}

		$payment_status_category = isset( $result['payment_status_category'] ) ? $result['payment_status_category'] : '';
		$status_code = isset( $result['status'] ) ? (int) $result['status'] : null;
		$payment = isset( $result['payment'] ) ? $result['payment'] : array();
		$success = ( $payment_status_category === 'SUCCESSFUL' ) || ( $status_code === 5 || $status_code === 9 );

		if ( $stored ) {
			ANZ_Worldline_Payment_Post_Type::log_payment( $stored, $result, $success );
			if ( $success ) {
				$this->send_payment_notification_emails( $stored, $result );
			}
		}

		wp_safe_redirect( $this->get_redirect_url( $success ) );
		exit;
	}

	/**
	 * Send payment notification email to admin recipient and to customer (same format as live site).
	 *
	 * @param array $stored Stored payment data (company, email, invoice_number, amount_cents, currency).
	 * @param array $result API result (created_payment_id, etc.).
	 */
	private function send_payment_notification_emails( $stored, $result ) {
		$opts = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		$recipient = isset( $opts['email_recipient'] ) ? sanitize_email( $opts['email_recipient'] ) : '';
		if ( $recipient === '' ) {
			return;
		}

		$site_name = get_bloginfo( 'name' );
		$amount_cents = isset( $stored['amount_cents'] ) ? (int) $stored['amount_cents'] : 0;
		$invoice_amount_cents = isset( $stored['invoice_amount_cents'] ) ? (int) $stored['invoice_amount_cents'] : $amount_cents;
		$invoice_total = number_format( $invoice_amount_cents / 100, 2 );
		$surcharge_total = number_format( ( $amount_cents - $invoice_amount_cents ) / 100, 2 );
		$grand_total = number_format( $amount_cents / 100, 2 );
		$currency = isset( $stored['currency'] ) ? $stored['currency'] : 'AUD';
		$transaction = isset( $result['created_payment_id'] ) ? $result['created_payment_id'] : ( isset( $stored['hosted_checkout_id'] ) ? $stored['hosted_checkout_id'] : '' );

		$body  = sprintf(
			/* translators: 1: site name, 2: site URL */
			__( 'A new payment has been made on %1$s -- %2$s', 'anz-worldline-payments' ),
			$site_name,
			home_url( '/' )
		) . "<br/>";
		$body .= 'DATE: ' . gmdate( 'Y-m-d H:i:s' ) . "<br/>";
		$body .= 'TRANSACTION NUMBER: ' . $transaction . "<br/>";
		$body .= 'COMPANY NAME: ' . ( isset( $stored['company'] ) ? esc_html( $stored['company'] ) : '' ) . "<br/>";
		$body .= 'COMPANY EMAIL: ' . ( isset( $stored['email'] ) ? esc_html( $stored['email'] ) : '' ) . "<br/>";
		$body .= 'INVOICE NUMBER: ' . ( isset( $stored['invoice_number'] ) ? esc_html( $stored['invoice_number'] ) : '' ) . "<br/>";
		$body .= 'INVOICE TOTAL: ' . $invoice_total . ' ' . $currency . "<br/>";
		$body .= 'SURCHARGE TOTAL: ' . $surcharge_total . ' ' . $currency . "<br/>";
		$body .= 'GRAND TOTAL: ' . $grand_total . ' ' . $currency . "<br/>";

		$subject = sprintf(
			/* translators: %s: site name */
			__( '%s – New Payment', 'anz-worldline-payments' ),
			$site_name
		);
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		wp_mail( $recipient, $subject, $body, $headers );

		$customer_email = isset( $stored['email'] ) ? sanitize_email( $stored['email'] ) : '';
		if ( $customer_email !== '' && $customer_email !== $recipient ) {
			wp_mail( $customer_email, $subject, $body, $headers );
		}
	}
}
