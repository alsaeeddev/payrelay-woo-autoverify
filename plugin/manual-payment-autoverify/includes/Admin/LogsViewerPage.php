<?php
/**
 * Logs Viewer Page — inspects immutable state transitions and audit trails.
 *
 * @package ManualPaymentAutoVerify\Admin
 */

declare(strict_types=1);

namespace ManualPaymentAutoVerify\Admin;

/**
 * LogsViewerPage displays paginated audit logs for manual payment claims.
 */
class LogsViewerPage {

	/** @var \wpdb */
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $wpdb = null ) {
		global $wpdb;
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Renders the audit logs page.
	 */
	public function render(): void {
		$table = $this->wpdb->prefix . 'mpav_audit_log';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$claim_id = isset( $_GET['claim_id'] ) ? absint( $_GET['claim_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );
		$per_page = 30;
		$offset   = ( $paged - 1 ) * $per_page;

		$where_clauses = array();
		$values        = array();

		if ( $claim_id > 0 ) {
			$where_clauses[] = 'claim_id = %d';
			$values[]        = $claim_id;
		}

		if ( $order_id > 0 ) {
			$where_clauses[] = 'order_id = %d';
			$values[]        = $order_id;
		}

		$where_sql = ! empty( $where_clauses ) ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

		// Total count query.
		$count_sql = "SELECT COUNT(*) FROM `{$table}` {$where_sql}";
		$total_rows = empty( $values )
			? (int) $this->wpdb->get_var( $count_sql ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			: (int) $this->wpdb->get_var( $this->wpdb->prepare( $count_sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Results query.
		$results_sql = "SELECT * FROM `{$table}` {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$query_values = array_merge( $values, array( $per_page, $offset ) );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $results_sql, $query_values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$total_pages = (int) ceil( $total_rows / $per_page );

		$template_path = dirname( __DIR__, 2 ) . '/templates/admin/logs-viewer.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}
}

