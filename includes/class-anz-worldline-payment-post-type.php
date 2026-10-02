<?php
/**
 * Payment history: register pap_payment post type if not already registered, and log payments.
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ANZ_Worldline_Payment_Post_Type
 */
class ANZ_Worldline_Payment_Post_Type {

	const POST_TYPE = 'pap_payment';

	/**
	 * Register post type only if not already registered (e.g. by theme on live site).
	 */
	public static function register_if_needed() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => __( 'Payments', 'anz-worldline-payments' ),
					'singular_name'      => __( 'Payment', 'anz-worldline-payments' ),
					'menu_name'          => __( 'Payments', 'anz-worldline-payments' ),
					'add_new'            => __( 'Add New', 'anz-worldline-payments' ),
					'add_new_item'       => __( 'Add New Payment', 'anz-worldline-payments' ),
					'edit_item'          => __( 'Edit Payment', 'anz-worldline-payments' ),
					'new_item'           => __( 'New Payment', 'anz-worldline-payments' ),
					'view_item'          => __( 'View Payment', 'anz-worldline-payments' ),
					'search_items'       => __( 'Search Payments', 'anz-worldline-payments' ),
					'not_found'          => __( 'No payments found.', 'anz-worldline-payments' ),
					'not_found_in_trash' => __( 'No payments found in Trash.', 'anz-worldline-payments' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
				'supports'           => array( 'title', 'custom-fields' ),
				'has_archive'        => false,
				'rewrite'            => false,
				'query_var'          => false,
			)
		);
	}

	/**
	 * Insert a payment post and meta (same structure as live site).
	 *
	 * @param array $stored   Stored data from return (company, email, invoice_number, amount_cents, currency, etc.).
	 * @param array $result   API result (payment_status_category, status, payment, created_payment_id, etc.).
	 * @param bool  $success  Whether payment was successful.
	 * @return int|false Post ID or false on failure.
	 */
	public static function log_payment( $stored, $result, $success ) {
		$data = wp_parse_args(
			array(),
			array(
				'post_title'  => 'Payment @ ' . gmdate( 'Y-m-d H:i:s' ),
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
			)
		);

		$post_id = wp_insert_post( $data );
		if ( ! $post_id || is_wp_error( $post_id ) ) {
			return false;
		}

		$company      = isset( $stored['company'] ) ? $stored['company'] : '';
		$email        = isset( $stored['email'] ) ? $stored['email'] : '';
		$invoice      = isset( $stored['invoice_number'] ) ? $stored['invoice_number'] : '';
		$amount_cents = isset( $stored['amount_cents'] ) ? (int) $stored['amount_cents'] : 0;
		$invoice_amount_cents = isset( $stored['invoice_amount_cents'] ) ? (int) $stored['invoice_amount_cents'] : $amount_cents;
		$value        = $amount_cents / 100; // Grand total (dollars).
		$invoice_value = $invoice_amount_cents / 100; // Invoice total before surcharge (dollars).
		$transaction  = isset( $result['created_payment_id'] ) ? $result['created_payment_id'] : ( isset( $stored['hosted_checkout_id'] ) ? $stored['hosted_checkout_id'] : '' );
		$status       = $success ? 'success' : 'failed';
		$response     = $result;
		$mode         = isset( $stored['mode'] ) && $stored['mode'] === 'test' ? 'test' : 'live';

		update_post_meta( $post_id, '_pap_payment_value', $value );
		update_post_meta( $post_id, '_pap_payment_company_name', $company );
		update_post_meta( $post_id, '_pap_payment_company_email', $email );
		update_post_meta( $post_id, '_pap_payment_invoice_number', $invoice );
		update_post_meta( $post_id, '_pap_payment_invoice_value', $invoice_value );
		update_post_meta( $post_id, '_pap_payment_transaction_number', $transaction );
		update_post_meta( $post_id, '_pap_payment_status', $status );
		update_post_meta( $post_id, '_pap_payment_mode', $mode );
		update_post_meta( $post_id, '_pap_payment_response', $response );

		return $post_id;
	}
}
