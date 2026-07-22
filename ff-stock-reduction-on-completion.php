<?php
/**
 * Plugin Name: FF Stock Reduction on Completion
 * Description: Reduces product stock ONLY when an order status changes to "Completed (Delivered)". Stock is automatically restored if the order is later moved OUT of that status to anything else, and reduced again if it's moved back in. All other statuses (Processing, On Hold, Dispatched, Dispatched COD, Carry Forward Order, Draft, etc.) never touch stock. Uses its own independent tracking flag per order (rather than WooCommerce's shared internal flag), so it behaves correctly even if other plugins/automations on the site touch stock outside our control. Adjusts stock product-by-product using the same core WooCommerce quantity filter the Attribute Stock multiplier plugin hooks into, so weight/multiplier based deductions keep working.
 * Version: 2.0.0
 * Author: Custom
 * Requires Plugins: woocommerce
 * Text Domain: ff-stock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FF_STOCK_VERSION', '2.0.0' );

final class FF_Stock_Reduction_On_Completion {

	/**
	 * Order status slugs (WITHOUT the "wc-" prefix) that should trigger a stock
	 * REDUCTION when an order enters them, and a stock RESTORE when an order
	 * leaves them.
	 *
	 * "Completed (Delivered)" in your dashboard is the core WooCommerce
	 * "completed" status with its label changed — its slug is still
	 * "completed". If that ever changes, update this array.
	 */
	const TARGET_STATUSES = array( 'completed' );

	/** Option name storing the rolling activity/diagnostic log shown on the dashboard. */
	const LOG_OPTION = 'ff_stock_activity_log';

	/**
	 * Order meta key this plugin uses to track whether IT has reduced stock
	 * for a given order. Deliberately separate from WooCommerce's own
	 * "_order_stock_reduced" meta, because other code on this site can (and
	 * does) set that flag outside of this plugin's control — relying on it
	 * caused orders to be treated as "already reduced" before we ever touched
	 * them.
	 */
	const META_KEY = '_ff_stock_reduced';

	public function __construct() {
		// Belt: stop WooCommerce's own automatic reduction from firing via its
		// "can we reduce stock" gate.
		add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 );
		add_filter( 'woocommerce_can_restore_order_stock', '__return_false', 999 );

		// Suspenders: directly unhook the specific core actions that trigger
		// automatic reduce/restore, in case a filter alone isn't catching
		// everything on this install. Done on init(20) so WooCommerce has
		// already registered its own hooks by the time we remove them.
		add_action( 'init', array( $this, 'unhook_core_stock_triggers' ), 20 );

		// Our own reduce/restore logic — entirely independent of whatever
		// else may be touching WooCommerce's built-in stock-reduced flag.
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 4 );

		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
	}

	public function unhook_core_stock_triggers() {
		$reduce_hooks = array(
			'woocommerce_order_status_pending_to_processing',
			'woocommerce_order_status_pending_to_completed',
			'woocommerce_order_status_pending_to_on-hold',
			'woocommerce_order_status_failed_to_processing',
			'woocommerce_order_status_failed_to_completed',
			'woocommerce_order_status_failed_to_on-hold',
			'woocommerce_order_status_cancelled_to_processing',
			'woocommerce_order_status_cancelled_to_completed',
			'woocommerce_order_status_cancelled_to_on-hold',
		);
		foreach ( $reduce_hooks as $hook ) {
			remove_action( $hook, 'wc_maybe_reduce_stock_levels' );
		}
		remove_action( 'woocommerce_order_status_cancelled', 'wc_maybe_increase_stock_levels' );
		remove_action( 'woocommerce_payment_complete', 'wc_maybe_reduce_stock_levels' );
	}

	public function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				__FILE__,
				true
			);
		}
	}

	/**
	 * @param int      $order_id
	 * @param string   $old_status Status slug without "wc-" prefix.
	 * @param string   $new_status Status slug without "wc-" prefix.
	 * @param WC_Order $order
	 */
	public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
		$log = array(
			'order_id'   => $order_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
			'time'       => current_time( 'mysql' ),
			'result'     => 'skipped (not entering/leaving target status)',
			'changes'    => '',
		);

		try {
			if ( ! $order instanceof WC_Order ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! $order ) {
				$log['result'] = 'error: could not load order object';
				$this->log( $log );
				return;
			}

			$entering_target = in_array( $new_status, self::TARGET_STATUSES, true );
			$leaving_target  = in_array( $old_status, self::TARGET_STATUSES, true );

			// Moving between two non-target statuses — never touch stock.
			if ( $entering_target === $leaving_target ) {
				$this->log( $log );
				return;
			}

			$already_reduced_by_us = 'yes' === $order->get_meta( self::META_KEY );

			// --- Moving INTO the target status (e.g. -> Completed) ---
			if ( $entering_target && ! $already_reduced_by_us ) {
				$changes = $this->adjust_stock( $order, 'decrease' );

				$order->update_meta_data( self::META_KEY, 'yes' );
				$order->get_data_store()->set_stock_reduced( $order_id, true ); // keep core flag in sync for other plugins/reports that read it
				$order->save();

				$order->add_order_note( $this->format_note( 'reduced', $new_status, $changes ) );

				$log['result']  = empty( $changes ) ? 'entered target, but no stock-managed items found to reduce' : 'stock reduced';
				$log['changes'] = $this->format_changes_line( $changes );

			} elseif ( $entering_target && $already_reduced_by_us ) {
				$log['result'] = 'entering target, but already reduced by this plugin earlier — skipped to avoid double reduction';
			}

			// --- Moving OUT of the target status (e.g. Completed -> anything else) ---
			if ( $leaving_target && $already_reduced_by_us ) {
				$changes = $this->adjust_stock( $order, 'increase' );

				$order->update_meta_data( self::META_KEY, 'no' );
				$order->get_data_store()->set_stock_reduced( $order_id, false );
				$order->save();

				$order->add_order_note( $this->format_note( 'restored', $new_status, $changes ) );

				$log['result']  = empty( $changes ) ? 'left target, but no stock-managed items found to restore' : 'stock restored';
				$log['changes'] = $this->format_changes_line( $changes );

			} elseif ( $leaving_target && ! $already_reduced_by_us ) {
				$log['result'] = 'leaving target, but this plugin had not reduced stock for it — nothing to restore';
			}

			$this->log( $log );

		} catch ( \Throwable $e ) {
			// Catch EVERYTHING, including fatal-type errors (PHP 7+ Errors
			// implement Throwable). Without this, an error here can silently
			// kill the rest of the status-change process with zero trace.
			$log['result'] = 'ERROR: ' . $e->getMessage() . ' (in ' . $e->getFile() . ' line ' . $e->getLine() . ')';
			$this->log( $log );

			if ( isset( $order ) && $order instanceof WC_Order ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: error message */
						__( 'FF Stock: an error occurred while processing stock — %s', 'ff-stock' ),
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Adjust stock for every stock-managed line item on the order.
	 * Returns an array of change rows: [ 'name', 'from', 'to', 'qty' ].
	 */
	private function adjust_stock( $order, $direction ) {
		$changes = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() ) {
				continue; // Not a stock-managed item.
			}

			// Same quantity filter WooCommerce core (and the Attribute Stock
			// multiplier plugin) use, so weight/multiplier based deductions
			// keep working exactly as configured.
			$qty = apply_filters( 'woocommerce_order_item_quantity', $item->get_quantity(), $order, $item );
			if ( $qty <= 0 ) {
				continue;
			}

			$stock_before = $product->get_stock_quantity();
			$stock_after  = wc_update_product_stock( $product, $qty, $direction );

			if ( false === $stock_after ) {
				continue;
			}

			$changes[] = array(
				'name' => $item->get_name(),
				'from' => $stock_before,
				'to'   => $stock_after,
				'qty'  => $qty,
			);
		}

		return $changes;
	}

	private function format_note( $action, $new_status, $changes ) {
		$lines   = array();
		$lines[] = 'reduced' === $action
			? sprintf(
				/* translators: %s: new order status label */
				__( 'FF Stock: levels reduced — order moved to "%s".', 'ff-stock' ),
				wc_get_order_status_name( $new_status )
			)
			: sprintf(
				/* translators: %s: new order status label */
				__( 'FF Stock: levels restored — order moved out of "Completed" to "%s".', 'ff-stock' ),
				wc_get_order_status_name( $new_status )
			);

		if ( empty( $changes ) ) {
			$lines[] = __( 'No stock-managed items were found on this order.', 'ff-stock' );
		} else {
			foreach ( $changes as $c ) {
				$lines[] = sprintf( '%s: %s → %s (qty %s)', $c['name'], $c['from'], $c['to'], $c['qty'] );
			}
		}

		return implode( "\n", $lines );
	}

	private function format_changes_line( $changes ) {
		if ( empty( $changes ) ) {
			return '';
		}
		$parts = array();
		foreach ( $changes as $c ) {
			$parts[] = sprintf( '%s: %s→%s', $c['name'], $c['from'], $c['to'] );
		}
		return implode( ' | ', $parts );
	}

	/**
	 * Rolling log (last 30) of every status change this hook has seen,
	 * regardless of whether it did anything — this is the sole source of
	 * truth for the dashboard's Activity Logs table.
	 */
	private function log( $entry ) {
		$log = get_option( self::LOG_OPTION, array() );
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 30 );
		update_option( self::LOG_OPTION, $log, false );
	}

	/* -----------------------------------------------------------------
	 * Simple dashboard page
	 * --------------------------------------------------------------- */

	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Stock Reduction Settings', 'ff-stock' ),
			__( 'Stock Reduction', 'ff-stock' ),
			'manage_woocommerce',
			'ff-stock-reduction',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page() {
		$target_labels = array_map( 'wc_get_order_status_name', self::TARGET_STATUSES );
		$log           = get_option( self::LOG_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'FF Stock Reduction on Completion', 'ff-stock' ); ?></h1>

			<div class="notice notice-info" style="padding:16px; font-size:14px;">
				<p style="font-size:16px; margin-top:0;">
					<strong><?php esc_html_e( 'Stock reduces only when an order is marked "Completed (Delivered)".', 'ff-stock' ); ?></strong>
				</p>
				<p><?php esc_html_e( 'That\'s the whole plugin in one sentence. Details below.', 'ff-stock' ); ?></p>
			</div>

			<table class="widefat striped" style="max-width:900px; margin-top:20px;">
				<tbody>
					<tr>
						<td style="width:260px;"><strong><?php esc_html_e( 'Reduces stock when order enters', 'ff-stock' ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', $target_labels ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Restores stock when order leaves', 'ff-stock' ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', $target_labels ) ); ?> <?php esc_html_e( '(to any other status)', 'ff-stock' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'All other statuses', 'ff-stock' ); ?></strong></td>
						<td><?php esc_html_e( 'Processing, On Hold, Dispatched, Dispatched COD, Carry Forward Order, Draft, Cancelled, Failed — none of these touch stock by themselves.', 'ff-stock' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Re-completing an order', 'ff-stock' ); ?></strong></td>
						<td><?php esc_html_e( 'If a Completed order is moved away and later marked Completed again, stock is reduced again (no double counting either way).', 'ff-stock' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Tracking', 'ff-stock' ); ?></strong></td>
						<td><?php esc_html_e( 'Uses its own independent per-order flag rather than WooCommerce\'s shared internal one, so it stays correct even if other plugins/automations on the site touch stock separately.', 'ff-stock' ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Stock level (main product vs variation)', 'ff-stock' ); ?></strong></td>
						<td><?php esc_html_e( 'Uses whatever WooCommerce is already configured to manage — currently your main product level, as normal — including the attribute stock multiplier plugin\'s quantity adjustments.', 'ff-stock' ); ?></td>
					</tr>
				</tbody>
			</table>

			<h2 style="margin-top:30px;"><?php esc_html_e( 'Activity Logs (last 30)', 'ff-stock' ); ?></h2>
			<p><?php esc_html_e( 'Every order status change this plugin sees, with a product-by-product breakdown of any stock adjustment made. If you mark an order Completed and nothing appears here, the hook itself did not fire. If a row shows ERROR, that\'s the exact cause.', 'ff-stock' ); ?></p>
			<?php if ( empty( $log ) ) : ?>
				<p><?php esc_html_e( 'No status changes recorded yet since this plugin was activated.', 'ff-stock' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1300px;">
					<thead>
						<tr>
							<th style="width:70px;"><?php esc_html_e( 'Order', 'ff-stock' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'Transition', 'ff-stock' ); ?></th>
							<th style="width:280px;"><?php esc_html_e( 'Result', 'ff-stock' ); ?></th>
							<th><?php esc_html_e( 'Stock changes (product: before→after)', 'ff-stock' ); ?></th>
							<th style="width:150px;"><?php esc_html_e( 'When', 'ff-stock' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) :
							$order     = wc_get_order( $entry['order_id'] );
							$edit_link = $order ? $order->get_edit_order_url() : '#';
							$is_error  = ( 0 === strpos( $entry['result'], 'ERROR' ) );
							?>
							<tr>
								<td><a href="<?php echo esc_url( $edit_link ); ?>">#<?php echo esc_html( $entry['order_id'] ); ?></a></td>
								<td><?php echo esc_html( $entry['old_status'] . ' → ' . $entry['new_status'] ); ?></td>
								<td style="<?php echo $is_error ? 'color:#a94442;font-weight:600;' : ''; ?>"><?php echo esc_html( $entry['result'] ); ?></td>
								<td><?php echo ! empty( $entry['changes'] ) ? esc_html( $entry['changes'] ) : '—'; ?></td>
								<td><?php echo esc_html( $entry['time'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

new FF_Stock_Reduction_On_Completion();
