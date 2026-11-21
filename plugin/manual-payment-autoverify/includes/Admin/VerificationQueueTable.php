<?php
/**
 * Verification Queue Table — WP_List_Table for inspecting and actioning claims.
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

use ManualPaymentAutoVerify\Core\ClaimRepository;
use ManualPaymentAutoVerify\Util\TidNormalizer;
use ManualPaymentAutoVerify\Util\Money;
use ManualPaymentAutoVerify\Util\PhoneNumberMasker;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * VerificationQueueTable extends WP_List_Table to display payment claims.
 */
class VerificationQueueTable extends \WP_List_Table {

	/** @var ClaimRepository */
	private ClaimRepository $claim_repo;

	public function __construct( ?ClaimRepository $claim_repo = null ) {
		parent::__construct(
			array(
				'singular' => 'claim',
				'plural'   => 'claims',
				'ajax'     => false,
			)
		);

		$this->claim_repo = $claim_repo ?? new ClaimRepository();
	}

	/**
	 * Define table columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'            => '<input type="checkbox" />',
			'claim_id'      => __( 'Claim / Order', 'manual-payment-autoverify' ),
			'status'        => __( 'Status', 'manual-payment-autoverify' ),
			'provider'      => __( 'Provider', 'manual-payment-autoverify' ),
			'tid'           => __( 'Transaction ID (TID)', 'manual-payment-autoverify' ),
			'amount'        => __( 'Amount', 'manual-payment-autoverify' ),
			'sender_number' => __( 'Sender Number', 'manual-payment-autoverify' ),
			'matched_sms'   => __( 'Matched SMS', 'manual-payment-autoverify' ),
			'created_at'    => __( 'Submitted / Expires', 'manual-payment-autoverify' ),
			'actions'       => __( 'Actions', 'manual-payment-autoverify' ),
		);
	}

	/**
	 * Define sortable columns.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'claim_id'   => array( 'id', false ),
			'amount'     => array( 'amount_minor', false ),
			'provider'   => array( 'provider', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Define bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'approve' => __( 'Approve Selected', 'manual-payment-autoverify' ),
			'reject'  => __( 'Reject Selected', 'manual-payment-autoverify' ),
		);
	}

	/**
	 * Render views filter bar (All, Pending, Flagged, Verified, Rejected, Expired).
	 *
	 * @return array<string, string>
	 */
	protected function get_views(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_status = sanitize_text_field( wp_unslash( $_GET['status'] ?? 'all' ) );
		$base_url       = admin_url( 'admin.php?page=mpav-queue' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider_param = ! empty( $_GET['provider'] ) ? '&provider=' . sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$provider_param .= '&s=' . rawurlencode( sanitize_text_field( wp_unslash( $_GET['s'] ) ) );
		}

		$counts = array(
			'all'           => $this->claim_repo->count_queue(),
			'pending'       => $this->claim_repo->count_queue( array( 'status' => 'pending' ) ),
			'flagged'       => $this->claim_repo->count_queue( array( 'status' => 'flagged' ) ),
			'auto_verified' => $this->claim_repo->count_queue( array( 'status' => 'auto_verified' ) ),
			'rejected'      => $this->claim_repo->count_queue( array( 'status' => 'rejected' ) ),
			'expired'       => $this->claim_repo->count_queue( array( 'status' => 'expired' ) ),
		);

		$views = array();

		$views['all'] = sprintf(
			'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
			esc_url( $base_url . $provider_param ),
			'all' === $current_status ? 'current' : '',
			esc_html__( 'All', 'manual-payment-autoverify' ),
			$counts['all']
		);

		$labels = array(
			'pending'       => __( 'Pending', 'manual-payment-autoverify' ),
			'flagged'       => __( 'Flagged', 'manual-payment-autoverify' ),
			'auto_verified' => __( 'Verified', 'manual-payment-autoverify' ),
			'rejected'      => __( 'Rejected', 'manual-payment-autoverify' ),
			'expired'       => __( 'Expired', 'manual-payment-autoverify' ),
		);

		foreach ( $labels as $status_key => $status_label ) {
			$views[ $status_key ] = sprintf(
				'<a href="%1$s" class="%2$s">%3$s <span class="count">(%4$d)</span></a>',
				esc_url( add_query_arg( 'status', $status_key, $base_url . $provider_param ) ),
				$current_status === $status_key ? 'current' : '',
				esc_html( $status_label ),
				$counts[ $status_key ]
			);
		}

		return $views;
	}

	/**
	 * Extra table navigation: provider filter dropdown.
	 *
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected_provider = sanitize_text_field( wp_unslash( $_GET['provider'] ?? '' ) );
		?>
		<div class="alignleft actions">
			<select name="provider" id="filter-by-provider">
				<option value=""><?php esc_html_e( 'All Providers', 'manual-payment-autoverify' ); ?></option>
				<option value="jazzcash" <?php selected( $selected_provider, 'jazzcash' ); ?>><?php esc_html_e( 'JazzCash', 'manual-payment-autoverify' ); ?></option>
				<option value="easypaisa" <?php selected( $selected_provider, 'easypaisa' ); ?>><?php esc_html_e( 'Easypaisa', 'manual-payment-autoverify' ); ?></option>
				<option value="bank" <?php selected( $selected_provider, 'bank' ); ?>><?php esc_html_e( 'Bank Transfer', 'manual-payment-autoverify' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'manual-payment-autoverify' ), '', 'filter_action', false, array( 'id' => 'post-query-submit' ) ); ?>
		</div>
		<?php
		// Single search field (Order # or TID) on the same row as the filter, right side.
		$this->render_search_box();
	}

	/**
	 * Current search term from ?s= (empty string when not searching).
	 */
	public function get_search_term(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
	}

	/**
	 * URL of this queue with the search removed but status/provider filters kept.
	 */
	public function get_clear_search_url(): string {
		$url = admin_url( 'admin.php?page=mpav-queue' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$status   = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) );
		$provider = sanitize_key( wp_unslash( $_GET['provider'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' !== $status && 'all' !== $status ) {
			$url = add_query_arg( 'status', $status, $url );
		}
		if ( '' !== $provider ) {
			$url = add_query_arg( 'provider', $provider, $url );
		}

		return $url;
	}

	/**
	 * Single search field (Order # or TID) with a visible Clear link while searching.
	 */
	public function render_search_box(): void {
		$term      = $this->get_search_term();
		$clear_url = $this->get_clear_search_url();
		?>
		<div class="mpav-search-inline">
			<label class="screen-reader-text" for="mpav-queue-search-input"><?php esc_html_e( 'Search by Order # or Transaction ID', 'manual-payment-autoverify' ); ?></label>
			<input type="search" id="mpav-queue-search-input" name="s" value="<?php echo esc_attr( $term ); ?>" placeholder="<?php esc_attr_e( 'Order # or Transaction ID', 'manual-payment-autoverify' ); ?>" />
			<?php submit_button( __( 'Search', 'manual-payment-autoverify' ), '', '', false, array( 'id' => 'mpav-search-submit' ) ); ?>
			<?php if ( '' !== $term ) : ?>
				<a href="<?php echo esc_url( $clear_url ); ?>" class="button mpav-search-clear"><?php esc_html_e( 'Clear', 'manual-payment-autoverify' ); ?></a>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var input = document.getElementById( 'mpav-queue-search-input' );
			if ( ! input ) { return; }
			// Native "x" button inside the search field: empty the field and reload without ?s=.
			input.addEventListener( 'search', function () {
				if ( '' === input.value && <?php echo '' !== $term ? 'true' : 'false'; ?> ) {
					window.location.href = <?php echo wp_json_encode( $clear_url ); ?>;
				}
			} );
			// Enter submits the search. Without this, the browser "clicks" the FIRST submit
			// button of the form (the bulk-actions Apply button, which comes before the
			// search field), so Enter did nothing. Escape clears an active search.
			input.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key && ! e.isComposing ) {
					e.preventDefault();
					var searchBtn = document.getElementById( 'mpav-search-submit' );
					if ( searchBtn ) { searchBtn.click(); }
					return;
				}
				if ( 'Escape' === e.key && <?php echo '' !== $term ? 'true' : 'false'; ?> ) {
					window.location.href = <?php echo wp_json_encode( $clear_url ); ?>;
				}
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Banner shown above the table while a search is active.
	 */
	public function render_search_summary(): void {
		$term = $this->get_search_term();
		if ( '' === $term ) {
			return;
		}

		$total = (int) $this->get_pagination_arg( 'total_items' );

		printf(
			'<div class="mpav-search-summary" role="status"><span class="dashicons dashicons-search" aria-hidden="true"></span> <span>%s</span></div>',
			wp_kses(
				sprintf(
					/* translators: 1: number of results, 2: search term */
					_n(
						'Showing %1$s result for <strong>&ldquo;%2$s&rdquo;</strong>',
						'Showing %1$s results for <strong>&ldquo;%2$s&rdquo;</strong>',
						$total,
						'manual-payment-autoverify'
					),
					number_format_i18n( $total ),
					esc_html( $term )
				),
				array( 'strong' => array() )
			)
		);
	}

	/**
	 * Empty-state message, search-aware.
	 */
	public function no_items(): void {
		$term = $this->get_search_term();
		if ( '' === $term ) {
			esc_html_e( 'No claims found.', 'manual-payment-autoverify' );
			return;
		}

		printf(
			/* translators: %s: search term */
			esc_html__( 'No claims match “%s”. Try an order number or part of the transaction ID.', 'manual-payment-autoverify' ),
			esc_html( $term )
		);
	}

	/**
	 * Escapes $text and wraps case-insensitive occurrences of $needle in <mark>.
	 *
	 * @param string $text   Raw text.
	 * @param string $needle Raw search fragment.
	 * @return string Safe HTML.
	 */
	private function highlight( string $text, string $needle ): string {
		if ( '' === $needle ) {
			return esc_html( $text );
		}

		$parts = preg_split( '/(' . preg_quote( $needle, '/' ) . ')/i', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( ! is_array( $parts ) ) {
			return esc_html( $text );
		}

		$out = '';
		foreach ( $parts as $i => $part ) {
			$out .= ( 1 === $i % 2 )
				? '<mark class="mpav-hit">' . esc_html( $part ) . '</mark>'
				: esc_html( $part );
		}

		return $out;
	}

	/**
	 * Prepare query items and configure pagination.
	 */
	public function prepare_items(): void {
		// Set the column headers explicitly. WordPress may already have cached an empty
		// column list for this screen (Screen Options renders before this table is
		// built), which would otherwise render an empty header and empty rows.
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'claim_id',
		);

		$per_page = 20;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = sanitize_text_field( wp_unslash( $_GET['status'] ?? '' ) );
		if ( 'all' === $status || '' === $status ) {
			$status_arg = null;
		} else {
			$status_arg = $status;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$provider = sanitize_text_field( wp_unslash( $_GET['provider'] ?? '' ) );
		$provider_arg = ! empty( $provider ) ? $provider : null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? 'created_at' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = sanitize_text_field( wp_unslash( $_GET['order'] ?? 'DESC' ) );

		$args = array(
			'page'     => $paged,
			'per_page' => $per_page,
			'orderby'  => $orderby,
			'order'    => $order,
		);

		if ( $status_arg ) {
			$args['status'] = $status_arg;
		}

		if ( $provider_arg ) {
			$args['provider'] = $provider_arg;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		if ( '' !== $search ) {
			$args['search'] = $search;
		}

		$total_items = $this->claim_repo->count_queue( $args );
		$this->items = $this->claim_repo->get_queue( $args );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	// ------------------------------------------------------------------ //
	// Column Renderers
	// ------------------------------------------------------------------ //

	/**
	 * Checkbox column for bulk operations.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="claim_ids[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Claim and Order ID column with row actions.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_claim_id( $item ): string {
		$order_id  = (int) $item->order_id;
		$order_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );

		// HPOS support: check if custom order tables are active.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$order_url = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id );
		}

		$search_term = ltrim( $this->get_search_term(), '#' );
		$order_label = ( '' !== $search_term && (string) $order_id === $search_term )
			? '<mark class="mpav-hit">' . $order_id . '</mark>'
			: (string) $order_id;

		$title = sprintf(
			'<strong>#%1$d</strong> &mdash; <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s #%4$s</a>',
			(int) $item->id,
			esc_url( $order_url ),
			esc_html__( 'Order', 'manual-payment-autoverify' ),
			$order_label
		);

		$actions = array();
		$actions['view_order'] = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $order_url ),
			esc_html__( 'View Order', 'manual-payment-autoverify' )
		);

		if ( in_array( $item->status, array( 'pending', 'flagged' ), true ) ) {
			$actions['approve'] = sprintf(
				'<a href="#" class="mpav-action-approve" data-claim-id="%d">%s</a>',
				(int) $item->id,
				esc_html__( 'Approve', 'manual-payment-autoverify' )
			);

			$actions['reject'] = sprintf(
				'<a href="#" class="mpav-action-reject" data-claim-id="%d">%s</a>',
				(int) $item->id,
				esc_html__( 'Reject', 'manual-payment-autoverify' )
			);
		}

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Status badge column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_status( $item ): string {
		$status = esc_attr( (string) $item->status );
		$labels = array(
			'pending'       => __( 'Pending', 'manual-payment-autoverify' ),
			'auto_verified' => __( 'Auto-Verified', 'manual-payment-autoverify' ),
			'flagged'       => __( 'Flagged (Review)', 'manual-payment-autoverify' ),
			'rejected'      => __( 'Rejected', 'manual-payment-autoverify' ),
			'expired'       => __( 'Expired', 'manual-payment-autoverify' ),
		);

		$label = $labels[ $item->status ] ?? ucfirst( (string) $item->status );

		return sprintf(
			'<span class="mpav-badge mpav-badge--%s">%s</span>',
			$status,
			esc_html( $label )
		);
	}

	/**
	 * Provider badge column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_provider( $item ): string {
		$provider = esc_attr( (string) $item->provider );
		$names    = array(
			'jazzcash'  => 'JazzCash',
			'easypaisa' => 'Easypaisa',
			'bank'      => __( 'Bank Transfer', 'manual-payment-autoverify' ),
		);

		$name = $names[ $item->provider ] ?? ucfirst( (string) $item->provider );

		return sprintf(
			'<span class="mpav-provider-pill mpav-provider-pill--%s">%s</span>',
			$provider,
			esc_html( $name )
		);
	}

	/**
	 * TID column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_tid( $item ): string {
		$normalized_plain = (string) $item->tid_normalized;
		$normalized        = esc_html( $normalized_plain );
		$raw               = esc_html( (string) $item->tid_raw );

		// Highlight the matched part when the queue is being searched.
		$needle = TidNormalizer::normalize( ltrim( $this->get_search_term(), '#' ) );
		$output = sprintf( '<code>%s</code>', $this->highlight( $normalized_plain, $needle ) );

		if ( $normalized !== $raw ) {
			$output .= sprintf( '<div class="mpav-raw-tid" title="%s">%s: %s</div>', esc_attr__( 'Raw TID entered by customer', 'manual-payment-autoverify' ), esc_html__( 'Raw', 'manual-payment-autoverify' ), $raw );
		}

		return $output;
	}

	/**
	 * Amount column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_amount( $item ): string {
		$minor = (int) $item->amount_minor;
		$text  = method_exists( Money::class, 'format_display' )
			? (string) Money::format_display( $minor )
			: number_format( $minor / 100, 2 );

		return sprintf( '<strong>%s</strong>', esc_html( $text ) );
	}

	/**
	 * Masked sender phone number column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_sender_number( $item ): string {
		$sender = (string) $item->sender_number;
		if ( '' === trim( $sender ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return esc_html( PhoneNumberMasker::mask( $sender ) );
	}

	/**
	 * Matched SMS column.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_matched_sms( $item ): string {
		if ( empty( $item->matched_sms_id ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return sprintf(
			'<span class="dashicons dashicons-yes-alt mpav-icon-success"></span> SMS #%d',
			(int) $item->matched_sms_id
		);
	}

	/**
	 * Created & Expiry timestamps.
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_created_at( $item ): string {
		$created_ts = strtotime( $item->created_at . ' UTC' );
		$created    = false !== $created_ts ? wp_date( 'Y-m-d H:i:s', $created_ts ) : $item->created_at;

		$expires_ts = strtotime( $item->expires_at . ' UTC' );
		$expires    = false !== $expires_ts ? wp_date( 'Y-m-d H:i:s', $expires_ts ) : $item->expires_at;

		$is_expired = ( false !== $expires_ts && time() > $expires_ts && 'pending' === $item->status );

		$output = sprintf( '<div>%s</div>', esc_html( (string) $created ) );

		if ( $is_expired ) {
			$output .= sprintf( '<div class="mpav-expired-notice">%s</div>', esc_html__( 'Expired', 'manual-payment-autoverify' ) );
		} else {
			$output .= sprintf( '<div class="description">%s: %s</div>', esc_html__( 'Expires', 'manual-payment-autoverify' ), esc_html( (string) $expires ) );
		}

		return $output;
	}

	/**
	 * Actions column (Approve / Reject buttons).
	 *
	 * @param object $item DB row.
	 * @return string
	 */
	protected function column_actions( $item ): string {
		if ( ! in_array( $item->status, array( 'pending', 'flagged' ), true ) ) {
			return '<span class="description">&mdash;</span>';
		}

		return sprintf(
			'<button type="button" class="button button-small button-primary mpav-btn-approve" data-claim-id="%1$d">%2$s</button> ' .
			'<button type="button" class="button button-small mpav-btn-reject" data-claim-id="%1$d">%3$s</button>',
			(int) $item->id,
			esc_html__( 'Approve', 'manual-payment-autoverify' ),
			esc_html__( 'Reject', 'manual-payment-autoverify' )
		);
	}

	/**
	 * Fallback column default.
	 *
	 * @param object $item        DB row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '' ) );
	}
}