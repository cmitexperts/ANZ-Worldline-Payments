<?php
/**
 * WordPress list table for pap_payment posts (Payments).
 *
 * @package ANZ_Worldline_Payments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ANZ_Worldline_Payments_List_Table
 */
class ANZ_Worldline_Payments_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payment',
				'plural'   => 'payments',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'date'         => __( 'Date', 'anz-worldline-payments' ),
			'mode'         => __( 'Mode', 'anz-worldline-payments' ),
			'company'      => __( 'Company', 'anz-worldline-payments' ),
			'email'        => __( 'Email', 'anz-worldline-payments' ),
			'invoice'      => __( 'Invoice', 'anz-worldline-payments' ),
			'amount'       => __( 'Amount', 'anz-worldline-payments' ),
			'status'       => __( 'Status', 'anz-worldline-payments' ),
			'transaction'  => __( 'Transaction', 'anz-worldline-payments' ),
		);
	}

	/**
	 * Bulk actions (none by default; can be extended).
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'date'    => array( 'date', true ),
			'mode'    => array( 'mode', false ),
			'company' => array( 'company', false ),
			'amount'  => array( 'amount', false ),
			'status'  => array( 'status', false ),
		);
	}

	/**
	 * Default column output.
	 *
	 * @param WP_Post $item        Post object.
	 * @param string  $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		$company     = get_post_meta( $item->ID, '_pap_payment_company_name', true );
		$email       = get_post_meta( $item->ID, '_pap_payment_company_email', true );
		$invoice     = get_post_meta( $item->ID, '_pap_payment_invoice_number', true );
		$value       = get_post_meta( $item->ID, '_pap_payment_value', true );
		$invoice_val = get_post_meta( $item->ID, '_pap_payment_invoice_value', true );
		$status      = get_post_meta( $item->ID, '_pap_payment_status', true );
		$transaction = get_post_meta( $item->ID, '_pap_payment_transaction_number', true );
		$mode        = get_post_meta( $item->ID, '_pap_payment_mode', true );

		// Value = grand total (dollars). Legacy: invoice_value was stored in cents.
		$amount = ( '' !== $value && is_numeric( $value ) ) ? (float) $value : ( ( '' !== $invoice_val && is_numeric( $invoice_val ) ) ? (float) $invoice_val / 100 : '' );
		if ( $amount !== '' && is_numeric( $amount ) ) {
			$amount = number_format( (float) $amount, 2 );
		}

		switch ( $column_name ) {
			case 'date':
				return get_the_date( 'Y-m-d H:i', $item );
			case 'mode':
				if ( $mode === 'test' ) {
					return '<span class="anz-worldline-mode anz-worldline-mode-test">' . esc_html__( 'Test', 'anz-worldline-payments' ) . '</span>';
				}
				// Empty/missing meta = legacy payment, show as Live.
				return '<span class="anz-worldline-mode anz-worldline-mode-live">' . esc_html__( 'Live', 'anz-worldline-payments' ) . '</span>';
			case 'company':
				return esc_html( $company );
			case 'email':
				return esc_html( $email );
			case 'invoice':
				return esc_html( $invoice );
			case 'amount':
				return esc_html( $amount );
			case 'status':
				return '<span class="anz-worldline-status anz-worldline-status-' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span>';
			case 'transaction':
				return esc_html( $transaction );
			default:
				return '';
		}
	}

	/**
	 * Checkbox column.
	 *
	 * @param WP_Post $item Post object.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="payment[]" value="%s" />', (int) $item->ID );
	}

	/**
	 * Extra table nav: filter by mode (All / Live only / Test only). Default: Live only.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ) {
		if ( $which !== 'top' ) {
			return;
		}
		$current = isset( $_GET['payment_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_mode'] ) ) : 'live';
		if ( ! in_array( $current, array( 'live', 'test', 'all' ), true ) ) {
			$current = 'live';
		}
		$base = remove_query_arg( array( 'payment_mode', 'paged' ) );
		$url_live = add_query_arg( 'payment_mode', 'live', $base );
		$url_test = add_query_arg( 'payment_mode', 'test', $base );
		$url_all  = add_query_arg( 'payment_mode', 'all', $base );
		?>
		<div class="alignleft actions">
			<label for="filter-by-mode" class="screen-reader-text"><?php esc_html_e( 'Filter by mode', 'anz-worldline-payments' ); ?></label>
			<select name="payment_mode" id="filter-by-mode">
				<option value="live" data-url="<?php echo esc_url( $url_live ); ?>" <?php selected( $current, 'live' ); ?>><?php esc_html_e( 'Live only', 'anz-worldline-payments' ); ?></option>
				<option value="test" data-url="<?php echo esc_url( $url_test ); ?>" <?php selected( $current, 'test' ); ?>><?php esc_html_e( 'Test only', 'anz-worldline-payments' ); ?></option>
				<option value="all" data-url="<?php echo esc_url( $url_all ); ?>" <?php selected( $current, 'all' ); ?>><?php esc_html_e( 'All', 'anz-worldline-payments' ); ?></option>
			</select>
		</div>
		<script type="text/javascript">
		document.getElementById('filter-by-mode').addEventListener('change', function() {
			var url = this.options[this.selectedIndex].getAttribute('data-url');
			if ( url ) { window.location.href = url; }
		});
		</script>
		<?php
	}

	/**
	 * Prepare items: query, sort, paginate.
	 */
	public function prepare_items() {
		$per_page              = 20;
		$current_page          = $this->get_pagenum();
		$orderby               = isset( $_GET['orderby'] ) ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) ) : 'date';
		$order                  = isset( $_GET['order'] ) && strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) === 'ASC' ? 'ASC' : 'DESC';
		$allowed_orderby       = array( 'date', 'mode', 'company', 'amount', 'status' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'date';
		}

		$payment_mode = isset( $_GET['payment_mode'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_mode'] ) ) : 'live';
		if ( ! in_array( $payment_mode, array( 'live', 'test', 'all' ), true ) ) {
			$payment_mode = 'live';
		}

		$args = array(
			'post_type'      => ANZ_Worldline_Payment_Post_Type::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => $per_page,
			'paged'          => $current_page,
			'order'          => $order,
		);

		if ( $payment_mode === 'test' ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_pap_payment_mode',
					'value' => 'test',
				),
			);
		} elseif ( $payment_mode === 'live' ) {
			// Live only: explicit live OR legacy (no mode meta).
			$args['meta_query'] = array(
				'relation' => 'OR',
				array(
					'key'   => '_pap_payment_mode',
					'value' => 'live',
				),
				array(
					'key'     => '_pap_payment_mode',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		if ( 'date' === $orderby ) {
			$args['orderby'] = 'date';
		} else {
			$args['orderby']  = 'meta_value';
			$args['meta_key'] = '_pap_payment_' . ( 'company' === $orderby ? 'company_name' : ( 'amount' === $orderby ? 'invoice_value' : ( 'mode' === $orderby ? 'mode' : 'status' ) ) );
		}

		$query   = new WP_Query( $args );
		$items   = $query->posts;
		$total   = $query->found_posts;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $items;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'   => $per_page,
				'total_pages' => ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Message when no items.
	 */
	public function no_items() {
		esc_html_e( 'No payments found.', 'anz-worldline-payments' );
	}
}
