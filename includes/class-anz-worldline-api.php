<?php
/**
 * ANZ Worldline API client (Hosted Checkout).
 * Uses the official PHP SDK (online-payments/sdk-php).
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OnlinePayments\Sdk\CommunicatorConfiguration;
use OnlinePayments\Sdk\Authentication\V1HmacAuthenticator;
use OnlinePayments\Sdk\Communicator;
use OnlinePayments\Sdk\Client;
use OnlinePayments\Sdk\Domain\CreateHostedCheckoutRequest;
use OnlinePayments\Sdk\Domain\Order;
use OnlinePayments\Sdk\Domain\AmountOfMoney;
use OnlinePayments\Sdk\Domain\HostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Domain\PaymentProductFiltersHostedCheckout;
use OnlinePayments\Sdk\Domain\PaymentProductFilter;
use OnlinePayments\Sdk\Domain\CardPaymentMethodSpecificInputBase;
use OnlinePayments\Sdk\Domain\MobilePaymentMethodHostedCheckoutSpecificInput;
use OnlinePayments\Sdk\Merchant\Products\GetPaymentProductsParams;
use OnlinePayments\Sdk\ApiException;
use OnlinePayments\Sdk\ReferenceException;
use OnlinePayments\Sdk\ResponseException;
use OnlinePayments\Sdk\AuthorizationException;

/**
 * Class ANZ_Worldline_Api
 */
class ANZ_Worldline_Api {

	/**
	 * Get API settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'mode'                => 'test',
			'merchant_id'         => '',
			'pspid'               => '',
			'api_key_id'          => '',
			'api_secret_id'       => '',
			'authorization_mode'  => self::DEFAULT_AUTHORIZATION_MODE,
		);
		$saved = get_option( ANZ_WORLDLINE_PAYMENTS_OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	/**
	 * Get base URL for current mode (no trailing slash).
	 * SDK adds path (e.g. /v2/...) internally.
	 *
	 * @return string
	 */
	public static function get_base_url() {
		$s = self::get_settings();
		if ( ! empty( $s['mode'] ) && 'live' === $s['mode'] ) {
			return 'https://payment.anzworldline-solutions.com.au';
		}
		return 'https://payment.preprod.anzworldline-solutions.com.au';
	}

	/**
	 * Build and return the SDK Client for Hosted Checkout calls.
	 *
	 * @return Client|null Client instance or null if config invalid.
	 */
	private static function get_sdk_client() {
		$s = self::get_settings();
		if ( empty( $s['api_key_id'] ) || empty( $s['api_secret_id'] ) ) {
			return null;
		}
		$api_endpoint = self::get_base_url();
		// Use same integrator as working lib/demo.php so requests match.
		$integrator   = 'cmitexperts';
		$config       = new CommunicatorConfiguration(
			$s['api_key_id'],
			$s['api_secret_id'],
			$api_endpoint,
			$integrator,
			null
		);
		$authenticator = new V1HmacAuthenticator( $config );
		$communicator  = new Communicator( $config, $authenticator );
		return new Client( $communicator );
	}

	/**
	 * Get merchant ID for API path. Must match the value used in lib/demo.php (PSPID).
	 * API key is tied to this merchant; wrong value causes ACCESS_TO_MERCHANT_NOT_ALLOWED.
	 *
	 * @return string
	 */
	private static function get_merchant_id() {
		$s = self::get_settings();
		$pspid = isset( $s['pspid'] ) ? trim( (string) $s['pspid'] ) : '';
		$mid   = isset( $s['merchant_id'] ) ? trim( (string) $s['merchant_id'] ) : '';
		return $pspid !== '' ? $pspid : $mid;
	}

	/**
	 * Allowed authorizationMode values for CreateHostedCheckoutRequest.
	 *
	 * @return string[]
	 */
	public static function get_allowed_authorization_modes() {
		return array( 'SALE', 'FINAL_AUTHORIZATION', 'PRE_AUTHORIZATION' );
	}

	/**
	 * Default authorizationMode (Worldline: payment captured at approval).
	 */
	const DEFAULT_AUTHORIZATION_MODE = 'SALE';

	/**
	 * Resolve authorizationMode for hosted checkout (settings or $args override).
	 *
	 * @param array $args Optional authorization_mode key.
	 * @return string
	 */
	public static function get_authorization_mode( $args = array() ) {
		$allowed = self::get_allowed_authorization_modes();
		if ( ! empty( $args['authorization_mode'] ) ) {
			$mode = strtoupper( trim( (string) $args['authorization_mode'] ) );
			if ( in_array( $mode, $allowed, true ) ) {
				return $mode;
			}
		}
		$s    = self::get_settings();
		$mode = isset( $s['authorization_mode'] ) ? strtoupper( trim( (string) $s['authorization_mode'] ) ) : self::DEFAULT_AUTHORIZATION_MODE;
		if ( in_array( $mode, $allowed, true ) ) {
			return $mode;
		}
		return self::DEFAULT_AUTHORIZATION_MODE;
	}

	/**
	 * Create Hosted Checkout session via SDK.
	 *
	 * @param array $args order amount (in cents), currency, return_url, merchant_reference, etc.
	 * @return array{ success: bool, redirect_url?: string, hosted_checkout_id?: string, error?: string }
	 */
	public static function create_hosted_checkout( $args ) {
		$s = self::get_settings();
		if ( empty( $s['api_key_id'] ) || empty( $s['api_secret_id'] ) ) {
			return array( 'success' => false, 'error' => __( 'API credentials not configured.', 'anz-worldline-payments' ) );
		}
		$merchant_id = self::get_merchant_id();
		if ( empty( $merchant_id ) ) {
			return array( 'success' => false, 'error' => __( 'Merchant ID not configured.', 'anz-worldline-payments' ) );
		}

		$amount   = isset( $args['amount'] ) ? (int) $args['amount'] : 0;
		$currency = isset( $args['currency'] ) ? $args['currency'] : 'AUD';
		$return_url = isset( $args['return_url'] ) ? $args['return_url'] : '';
		$merchant_reference = isset( $args['merchant_reference'] ) ? $args['merchant_reference'] : '';

		if ( $amount <= 0 ) {
			return array( 'success' => false, 'error' => __( 'Invalid amount.', 'anz-worldline-payments' ) );
		}

		$client = self::get_sdk_client();
		if ( ! $client ) {
			return array( 'success' => false, 'error' => __( 'API client could not be created.', 'anz-worldline-payments' ) );
		}

		try {
			$merchant_client = $client->merchant( $merchant_id );
			$allowed_product_ids = array();

			// Fetch payment products (same as lib/demo.php). On 403 / ACCESS_TO_MERCHANT_NOT_ALLOWED, skip filter and try create anyway.
			try {
				$products_params = new GetPaymentProductsParams();
				$products_params->setCountryCode( 'AU' );
				$products_params->setCurrencyCode( $currency );
				$products_params->setAmount( $amount );
				$products_response = $merchant_client->products()->getPaymentProducts( $products_params );
				$payment_products = $products_response->getPaymentProducts();
				if ( is_array( $payment_products ) && ! empty( $payment_products ) ) {
					$allowed_product_ids = array_map( function ( $p ) {
						return $p->getId();
					}, $payment_products );
				}
			} catch ( \Exception $products_ex ) {
				// If getPaymentProducts fails (e.g. 403), continue without product filter so createHostedCheckout can be tried.
				$allowed_product_ids = array();
			}

			$order = new Order();
			$amount_of_money = new AmountOfMoney();
			$amount_of_money->setCurrencyCode( $currency );
			$amount_of_money->setAmount( $amount );
			$order->setAmountOfMoney( $amount_of_money );

			$hosted_input = new HostedCheckoutSpecificInput();
			$hosted_input->setReturnUrl( $return_url );
			$hosted_input->setLocale( 'en_AU' );
			if ( ! empty( $allowed_product_ids ) ) {
				$product_filter = new PaymentProductFilter();
				$product_filter->setProducts( $allowed_product_ids );
				$payment_product_filters = new PaymentProductFiltersHostedCheckout();
				$payment_product_filters->setRestrictTo( $product_filter );
				$hosted_input->setPaymentProductFilters( $payment_product_filters );
			}

			$request = new CreateHostedCheckoutRequest();
			$request->setOrder( $order );
			$request->setHostedCheckoutSpecificInput( $hosted_input );

			$authorization_mode = self::get_authorization_mode( $args );
			$card_input = new CardPaymentMethodSpecificInputBase();
			$card_input->setAuthorizationMode( $authorization_mode );
			$request->setCardPaymentMethodSpecificInput( $card_input );

			$mobile_input = new MobilePaymentMethodHostedCheckoutSpecificInput();
			$mobile_input->setAuthorizationMode( $authorization_mode );
			$request->setMobilePaymentMethodSpecificInput( $mobile_input );

			$response = $merchant_client->hostedCheckout()->createHostedCheckout( $request );
			$redirect_url = $response->getRedirectUrl();
			if ( empty( $redirect_url ) ) {
				return array( 'success' => false, 'error' => __( 'Could not create payment session.', 'anz-worldline-payments' ) );
			}
			return array(
				'success'            => true,
				'redirect_url'       => $redirect_url,
				'hosted_checkout_id' => $response->getHostedCheckoutId() ?: '',
				'return_mac'         => $response->getRETURNMAC() ?: '',
			);
		} catch ( AuthorizationException $e ) {
			$msg = self::format_sdk_error( $e, __( 'Authorization failed (403).', 'anz-worldline-payments' ) );
			if ( strpos( $msg, 'ACCESS_TO_MERCHANT_NOT_ALLOWED' ) !== false ) {
				$msg .= ' ' . __( 'Use the PSPID that your API key is linked to in the Merchant Portal (API credentials).', 'anz-worldline-payments' );
			} else {
				$msg .= ' ' . __( 'Check API endpoint, PSPID, and credentials.', 'anz-worldline-payments' );
			}
			return array( 'success' => false, 'error' => $msg );
		} catch ( ApiException $e ) {
			return array( 'success' => false, 'error' => self::format_sdk_error( $e ) );
		} catch ( ReferenceException $e ) {
			return array( 'success' => false, 'error' => self::format_sdk_error( $e ) );
		} catch ( \Exception $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}

	/**
	 * Build a user-facing error string from an SDK ResponseException (includes platform error code/message).
	 *
	 * @param ResponseException $e     SDK exception.
	 * @param string            $fallback Fallback if no errors in response.
	 * @return string
	 */
	private static function format_sdk_error( ResponseException $e, $fallback = '' ) {
		$parts = array();
		$errors = $e->getErrors();
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $err ) {
				$code = method_exists( $err, 'getErrorCode' ) ? $err->getErrorCode() : '';
				$msg  = method_exists( $err, 'getMessage' ) ? $err->getMessage() : '';
				if ( $code || $msg ) {
					$parts[] = trim( $code . ' ' . $msg );
				}
			}
		}
		if ( empty( $parts ) ) {
			$error_id = $e->getErrorId();
			if ( $error_id !== '' ) {
				$parts[] = $error_id;
			}
		}
		if ( ! empty( $parts ) ) {
			return implode( '; ', $parts );
		}
		return $fallback !== '' ? $fallback : $e->getMessage();
	}

	/**
	 * Get Hosted Checkout status via SDK.
	 *
	 * @param string $hosted_checkout_id Hosted checkout ID from CreateHostedCheckout response.
	 * @return array{ success: bool, status?: int, status_category?: string, payment_status_category?: string, payment?: array, created_payment_id?: string, error?: string }
	 */
	public static function get_hosted_checkout_status( $hosted_checkout_id ) {
		$s = self::get_settings();
		if ( empty( $s['api_key_id'] ) || empty( $s['api_secret_id'] ) || empty( $hosted_checkout_id ) ) {
			return array( 'success' => false, 'error' => __( 'Invalid request.', 'anz-worldline-payments' ) );
		}

		$merchant_id = self::get_merchant_id();
		if ( empty( $merchant_id ) ) {
			return array( 'success' => false, 'error' => __( 'Merchant ID not configured.', 'anz-worldline-payments' ) );
		}

		$client = self::get_sdk_client();
		if ( ! $client ) {
			return array( 'success' => false, 'error' => __( 'API client could not be created.', 'anz-worldline-payments' ) );
		}

		try {
			$response = $client->merchant( $merchant_id )->hostedCheckout()->getHostedCheckout( $hosted_checkout_id );
			$created_output = $response->getCreatedPaymentOutput();
			$payment_status_category = '';
			$payment = array();
			$created_payment_id = '';
			$status = null;
			$status_category = '';

			if ( $created_output ) {
				$payment_status_category = $created_output->getPaymentStatusCategory() ?: '';
				$payment_obj = $created_output->getPayment();
				if ( $payment_obj ) {
					$created_payment_id = $payment_obj->getId() ?: '';
					$status_output = $payment_obj->getStatusOutput();
					if ( $status_output ) {
						$status = $status_output->getStatusCode();
						$status_category = $status_output->getStatusCategory() ?: '';
					}
					$payment = json_decode( json_encode( $payment_obj->toObject() ), true );
					if ( ! is_array( $payment ) ) {
						$payment = array();
					}
				}
			}

			return array(
				'success'                 => true,
				'status'                  => $status,
				'status_category'         => $status_category,
				'payment_status_category' => $payment_status_category,
				'payment'                 => $payment,
				'created_payment_id'      => $created_payment_id,
			);
		} catch ( AuthorizationException $e ) {
			return array( 'success' => false, 'error' => self::format_sdk_error( $e, __( 'Authorization failed (403).', 'anz-worldline-payments' ) ) );
		} catch ( ApiException $e ) {
			return array( 'success' => false, 'error' => self::format_sdk_error( $e ) );
		} catch ( ReferenceException $e ) {
			return array( 'success' => false, 'error' => self::format_sdk_error( $e ) );
		} catch ( \Exception $e ) {
			return array( 'success' => false, 'error' => $e->getMessage() );
		}
	}
}
