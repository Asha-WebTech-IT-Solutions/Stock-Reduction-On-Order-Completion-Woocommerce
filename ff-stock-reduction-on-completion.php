<?php
/**
 * Plugin Name: FF Stock Reduction on Completion
 * Description: Reduces product stock ONLY when an order status changes to "Completed (Delivered)". Stock is automatically restored if the order is later moved OUT of that status to anything else, and reduced again if it's moved back in. All other statuses never touch stock. Uses its own independent tracking flag per order. Also handles orders being trashed, restored from trash, or permanently deleted — and includes a self-healing consistency check (manual + daily) to catch and fix any order whose stock state has drifted out of sync for any reason.
 * Version: 3.4.0
 * Author: Asha WebTech IT Solutions Pvt Ltd
 * Author URI: https://ashawebtechitsolutions.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 * Text Domain: ff-stock
 *
 * -----------------------------------------------------------------------
 * Developer / Company Details
 * -----------------------------------------------------------------------
 * Asha WebTech IT Solutions Pvt Ltd
 * Website:     https://ashawebtechitsolutions.com/
 * Also by us:  https://algotrivo.com/ | https://codetunix.com/
 *              https://vanshtechnologies.in/ | https://marketingtech.in/
 *              https://leadmappo.com/
 * Address:     Office No. 69, Ratan Colony, New Jail Road, Huzur,
 *              Bhopal – 462038, India
 * Phone:       +91 7024315567, +91 9755121478
 * Email:       chandrabhan@ashawebtechitsolutions.com
 * Instagram:   https://www.instagram.com/ashawebtech/
 * Facebook:    https://www.facebook.com/ashawebtech/
 * Twitter/X:   https://twitter.com/AshaWebTech
 * YouTube:     https://www.youtube.com/@AshaWebTechITSolution
 * LinkedIn:    https://www.linkedin.com/in/chandrabhan-singh-dhakad/
 * Threads:     https://www.threads.net/@ashawebtech
 * Play Store:  https://play.google.com/store/apps/dev?id=8609403648683130234
 * GitHub:      https://github.com/chandusingh (personal)
 *              https://github.com/Asha-WebTech-IT-Solutions (company)
 *
 * -----------------------------------------------------------------------
 * License Notice
 * -----------------------------------------------------------------------
 * NOTE: The line above declares this plugin GPL v2+ (required by WordPress.org
 * convention for any plugin header), which legally permits anyone to copy,
 * modify, and redistribute this code. That is in direct conflict with the
 * restriction stated below. This plugin is NOT distributed on WordPress.org
 * and is not intended for public distribution, so if the intent is genuinely
 * proprietary/restricted use, the "License" field above should be changed to
 * "Proprietary" (or removed) rather than GPL — please confirm which applies
 * before relying on either statement.
 *
 * Copyright (c) 2026 Asha WebTech IT Solutions Pvt Ltd. All Rights Reserved.
 * This plugin was custom-developed by Asha WebTech IT Solutions Pvt Ltd.
 * Copying, modifying, editing, reverse-engineering, or otherwise changing
 * this plugin or its source code, in whole or in part, without prior
 * written permission from Asha WebTech IT Solutions Pvt Ltd is strictly
 * prohibited.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FF_STOCK_VERSION', '3.4.0' );

final class FF_Stock_Reduction_On_Completion {

	const TARGET_STATUSES    = array( 'completed' );
	const META_KEY           = '_ff_stock_reduced';
	const DB_VERSION_OPTION  = 'ff_stock_db_version';
	const LAST_AUDIT_OPTION  = 'ff_stock_last_audit';
	const ACTIVE_SINCE_OPTION = 'ff_stock_active_since';
	const PER_PAGE            = 50;
	const AUDIT_BATCH_LIMIT   = 200;
	const CRON_HOOK           = 'ff_stock_daily_reconcile';

	public function __construct() {
		// WooCommerce's own automatic stock reduction has used different gate
		// filters across versions — block every one we know of so we don't
		// silently fall through when WooCommerce core changes internals again.
		add_filter( 'woocommerce_can_reduce_order_stock', '__return_false', 999 ); // legacy gate
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', '__return_false', 999 ); // current gate (WC 8.x+)
		add_filter( 'woocommerce_can_restore_order_stock', '__return_false', 999 );

		// Separate mechanism entirely: the classic ADMIN edit-order screen
		// (Add item(s) / Update / Recalculate) adjusts stock per line item
		// directly whenever the order's status is Processing, Completed, or
		// On-hold — via wc_maybe_adjust_line_item_product_stock(), which is
		// not gated by any of the filters/hooks above. This is exactly why
		// admin-created orders still saw premature reductions. This is the
		// official filter WooCommerce provides for it.
		add_filter( 'woocommerce_prevent_adjust_line_item_product_stock', '__return_true', 999 );

		add_action( 'init', array( $this, 'unhook_core_stock_triggers' ), 20 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_change' ), 10, 4 );

		// Universal safety net: this plugin NEVER calls wc_reduce_stock_levels()
		// itself (it adjusts product stock directly instead — see adjust_stock()),
		// so any time WooCommerce's "stock was just reduced" event fires, it can
		// only mean something else did it — WooCommerce core's own automatic
		// trigger, or a payment/COD plugin (e.g. Razorpay) calling the reduction
		// function directly, which no filter can block. Instantly reverse it, so
		// our own logic can still reduce stock exactly once, only on Completed.
		add_action( 'woocommerce_reduce_order_stock', array( $this, 'undo_unexpected_reduction' ), 999 );

		// WooCommerce-native trash/untrash/delete hooks (reliable on HPOS).
		add_action( 'woocommerce_trash_order', array( $this, 'trash_guard' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( $this, 'untrash_guard' ), 10, 1 );
		add_action( 'woocommerce_delete_order', array( $this, 'delete_guard' ), 10, 1 );

		// WordPress-native post hooks — needed because on classic (CPT) order
		// storage, WooCommerce's own trash/delete hooks above are known not to
		// fire reliably when trashing/deleting via the admin UI. Both sets of
		// hooks are safe to have active together: every guard checks our own
		// flag first, so a duplicate call from both hook sets is a harmless no-op.
		add_action( 'wp_trash_post', array( $this, 'trash_guard' ) );
		add_action( 'untrashed_post', array( $this, 'untrash_guard' ) );
		add_action( 'before_delete_post', array( $this, 'delete_guard' ) );

		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );

		// Consistency check: manual button + daily background run.
		add_action( 'admin_post_ff_stock_run_check', array( $this, 'handle_manual_check' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_consistency_check' ) );
		add_action( 'wp', array( $this, 'maybe_schedule_cron' ) );
	}

	public static function activate() {
		self::create_table();
		update_option( self::DB_VERSION_OPTION, FF_STOCK_VERSION );
		add_option( self::ACTIVE_SINCE_OPTION, current_time( 'mysql' ) ); // add_option: never overwrites an existing value
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function maybe_schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public function maybe_upgrade_db() {
		if ( get_option( self::DB_VERSION_OPTION ) !== FF_STOCK_VERSION ) {
			self::create_table();
			update_option( self::DB_VERSION_OPTION, FF_STOCK_VERSION );
		}
		// Never overwrites an existing value — this only sets a cutoff the
		// very first time, so upgrading sites don't suddenly try to scan
		// their entire order history.
		add_option( self::ACTIVE_SINCE_OPTION, current_time( 'mysql' ) );
	}

	private static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ff_stock_log';
	}

	private static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			old_status VARCHAR(50) NOT NULL DEFAULT '',
			new_status VARCHAR(50) NOT NULL DEFAULT '',
			result_type VARCHAR(20) NOT NULL DEFAULT 'skipped',
			result_text TEXT NOT NULL,
			changes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY created_at (created_at),
			KEY result_type (result_type)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function unhook_core_stock_triggers() {
		// Legacy hook pairs (older WooCommerce versions).
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

		// Current-generation hooks (WooCommerce restructured this — as of
		// recent versions it reduces stock on simple per-status hooks rather
		// than the old "from_to" pairs above). Removing both sets keeps this
		// working regardless of which WooCommerce version is running.
		remove_action( 'woocommerce_order_status_processing', 'wc_maybe_reduce_stock_levels' );
		remove_action( 'woocommerce_order_status_on-hold', 'wc_maybe_reduce_stock_levels' );
		remove_action( 'woocommerce_order_status_completed', 'wc_maybe_reduce_stock_levels' );
		remove_action( 'woocommerce_order_status_failed', 'wc_maybe_increase_stock_levels' );
		remove_action( 'woocommerce_order_status_pending', 'wc_maybe_increase_stock_levels' );
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

	private function is_order_post( $post_id ) {
		return 'shop_order' === get_post_type( $post_id );
	}

	/* -----------------------------------------------------------------
	 * Normal status-change handling
	 * --------------------------------------------------------------- */

	public function handle_status_change( $order_id, $old_status, $new_status, $order ) {
		$log = array(
			'order_id'   => $order_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
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

			if ( $entering_target === $leaving_target ) {
				$this->log( $log );
				return;
			}

			$already_reduced_by_us = 'yes' === $order->get_meta( self::META_KEY );

			if ( $entering_target && ! $already_reduced_by_us ) {
				$headline = sprintf(
					/* translators: %s: new order status label */
					__( 'FF Stock: levels reduced — order moved to "%s".', 'ff-stock' ),
					wc_get_order_status_name( $new_status )
				);
				$changes = $this->do_reduce_stock( $order, $headline );

				$log['result']  = empty( $changes ) ? 'entered target, but no stock-managed items found to reduce' : 'stock reduced';
				$log['changes'] = $this->format_changes_line( $changes );

			} elseif ( $entering_target && $already_reduced_by_us ) {
				$log['result'] = 'entering target, but already reduced by this plugin earlier — skipped to avoid double reduction';
			}

			if ( $leaving_target && $already_reduced_by_us ) {
				$headline = sprintf(
					/* translators: %s: new order status label */
					__( 'FF Stock: levels restored — order moved out of "Completed" to "%s".', 'ff-stock' ),
					wc_get_order_status_name( $new_status )
				);
				$changes = $this->do_restore_stock( $order, $headline );

				$log['result']  = empty( $changes ) ? 'left target, but no stock-managed items found to restore' : 'stock restored';
				$log['changes'] = $this->format_changes_line( $changes );

			} elseif ( $leaving_target && ! $already_reduced_by_us ) {
				$log['result'] = 'leaving target, but this plugin had not reduced stock for it — nothing to restore';
			}

			$this->log( $log );

		} catch ( \Throwable $e ) {
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
	 * Fires whenever WooCommerce's stock-reduction event happens, from ANY
	 * source. This plugin never triggers this event itself (adjust_stock()
	 * updates products directly instead), so every call here is something
	 * external jumping the queue — reverse it immediately.
	 *
	 * @param WC_Order $order
	 */
	public function undo_unexpected_reduction( $order ) {
		try {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			$changes = $this->adjust_stock( $order, 'increase' );
			$order->get_data_store()->set_stock_reduced( $order->get_id(), false );

			$order->add_order_note(
				$this->build_note(
					__( 'FF Stock: an external process (WooCommerce core or a payment/COD plugin) reduced stock outside of the Completed status — automatically reversed so stock only changes on Completed.', 'ff-stock' ),
					$changes
				)
			);

			$this->log(
				array(
					'order_id'   => $order->get_id(),
					'old_status' => '(external)',
					'new_status' => $order->get_status(),
					'result'     => empty( $changes ) ? 'blocked an unexpected external reduction, but no stock-managed items found to reverse' : 'stock restored',
					'changes'    => $this->format_changes_line( $changes ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->log(
				array(
					'order_id'   => is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
					'old_status' => '(external)',
					'new_status' => '(external)',
					'result'     => 'ERROR: ' . $e->getMessage(),
					'changes'    => '',
				)
			);
		}
	}

	/* -----------------------------------------------------------------
	 * Trash / untrash / permanent delete handling
	 * --------------------------------------------------------------- */

	public function trash_guard( $order_id ) {
		try {
			if ( ! $this->is_order_post( $order_id ) && ! ( function_exists( 'wc_get_order' ) && wc_get_order( $order_id ) instanceof WC_Order ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || 'yes' !== $order->get_meta( self::META_KEY ) ) {
				return; // nothing to restore
			}

			$status_before = $order->get_status();
			$headline      = __( 'FF Stock: levels restored — order was moved to Trash.', 'ff-stock' );
			$changes       = $this->do_restore_stock( $order, $headline );

			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => $status_before,
					'new_status' => 'trash',
					'result'     => empty( $changes ) ? 'order trashed, but no stock-managed items found to restore' : 'stock restored',
					'changes'    => $this->format_changes_line( $changes ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => 'trash',
					'new_status' => 'trash',
					'result'     => 'ERROR: ' . $e->getMessage(),
					'changes'    => '',
				)
			);
		}
	}

	public function untrash_guard( $order_id ) {
		try {
			if ( ! $this->is_order_post( $order_id ) && ! ( function_exists( 'wc_get_order' ) && wc_get_order( $order_id ) instanceof WC_Order ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}

			$is_target             = in_array( $order->get_status(), self::TARGET_STATUSES, true );
			$already_reduced_by_us = 'yes' === $order->get_meta( self::META_KEY );

			if ( ! $is_target || $already_reduced_by_us ) {
				return;
			}

			$headline = sprintf(
				/* translators: %s: restored order status label */
				__( 'FF Stock: levels reduced — order was restored from Trash back to "%s".', 'ff-stock' ),
				wc_get_order_status_name( $order->get_status() )
			);
			$changes = $this->do_reduce_stock( $order, $headline );

			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => 'trash',
					'new_status' => $order->get_status(),
					'result'     => empty( $changes ) ? 'order untrashed into target status, but no stock-managed items found to reduce' : 'stock reduced',
					'changes'    => $this->format_changes_line( $changes ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => 'trash',
					'new_status' => 'trash',
					'result'     => 'ERROR: ' . $e->getMessage(),
					'changes'    => '',
				)
			);
		}
	}

	public function delete_guard( $order_id ) {
		try {
			if ( ! $this->is_order_post( $order_id ) ) {
				return;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || 'yes' !== $order->get_meta( self::META_KEY ) ) {
				return;
			}

			$status_before = $order->get_status();
			$headline      = __( 'FF Stock: levels restored — order was permanently deleted.', 'ff-stock' );
			$changes       = $this->do_restore_stock( $order, $headline );

			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => $status_before,
					'new_status' => 'deleted',
					'result'     => empty( $changes ) ? 'order deleted, but no stock-managed items found to restore' : 'stock restored',
					'changes'    => $this->format_changes_line( $changes ),
				)
			);
		} catch ( \Throwable $e ) {
			$this->log(
				array(
					'order_id'   => $order_id,
					'old_status' => 'deleted',
					'new_status' => 'deleted',
					'result'     => 'ERROR: ' . $e->getMessage(),
					'changes'    => '',
				)
			);
		}
	}

	/* -----------------------------------------------------------------
	 * Consistency check (self-healing safety net)
	 * --------------------------------------------------------------- */

	/**
	 * Read-only scan: returns order IDs that are out of sync. Deliberately
	 * scoped to orders placed since this plugin was activated — older orders
	 * were never tracked by this plugin and must never be touched by an
	 * automated fix (their stock was already handled, correctly or not, by
	 * whatever was running before this plugin existed).
	 */
	private function get_inconsistent_orders() {
		$since = get_option( self::ACTIVE_SINCE_OPTION );
		if ( ! $since ) {
			$since = current_time( 'mysql' ); // safest possible default: nothing older than "now"
		}

		$need_reduce = wc_get_orders(
			array(
				'status'       => self::TARGET_STATUSES,
				'date_created' => '>=' . strtotime( $since ),
				'limit'        => -1,
				'return'       => 'ids',
				'cache_results' => false,
				'meta_query'   => array( // phpcs:ignore
					'relation' => 'OR',
					array(
						'key'     => self::META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_KEY,
						'value'   => 'yes',
						'compare' => '!=',
					),
				),
			)
		);

		$flagged = wc_get_orders(
			array(
				'date_created'  => '>=' . strtotime( $since ),
				'limit'         => -1,
				'return'        => 'ids',
				'cache_results' => false,
				'meta_key'      => self::META_KEY, // phpcs:ignore
				'meta_value'    => 'yes', // phpcs:ignore
			)
		);

		$need_restore = array();
		foreach ( $flagged as $oid ) {
			$o = wc_get_order( $oid );
			if ( $o && ! in_array( $o->get_status(), self::TARGET_STATUSES, true ) ) {
				$need_restore[] = $oid;
			}
		}

		return array(
			'need_reduce'  => $need_reduce,
			'need_restore' => $need_restore,
			'since'        => $since,
		);
	}

	/**
	 * Runs the scan and fixes what it finds, up to AUDIT_BATCH_LIMIT orders
	 * per run (repeat clicks or the next scheduled run will pick up any
	 * remainder). Every single order is re-checked immediately before it's
	 * touched, so this is always safe to run again — orders already fixed
	 * (by an earlier run, or by anything else) are skipped automatically.
	 */
	public function run_consistency_check() {
		$fixed_reduce  = 0;
		$fixed_restore = 0;

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 120 ); // phpcs:ignore
			}

			$found = $this->get_inconsistent_orders();

			foreach ( array_slice( $found['need_reduce'], 0, self::AUDIT_BATCH_LIMIT ) as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				// Re-check right now — the order may have already been fixed
				// (by an earlier click, the daily cron, or a normal status
				// change) since the scan above ran.
				if ( 'yes' === $order->get_meta( self::META_KEY ) ) {
					continue;
				}

				$headline = __( 'FF Stock: consistency check found this order was Completed but stock had not been reduced — fixed automatically.', 'ff-stock' );
				$changes  = $this->do_reduce_stock( $order, $headline );
				$this->log(
					array(
						'order_id'   => $order_id,
						'old_status' => '(audit)',
						'new_status' => $order->get_status(),
						'result'     => empty( $changes ) ? 'audit: entered target, but no stock-managed items found to reduce' : 'stock reduced',
						'changes'    => $this->format_changes_line( $changes ),
					)
				);
				++$fixed_reduce;
			}

			foreach ( array_slice( $found['need_restore'], 0, self::AUDIT_BATCH_LIMIT ) as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}
				if ( 'yes' !== $order->get_meta( self::META_KEY ) || in_array( $order->get_status(), self::TARGET_STATUSES, true ) ) {
					continue; // already fixed, or legitimately Completed again by now
				}

				$headline = __( 'FF Stock: consistency check found stock was still marked reduced even though this order is not Completed — restored automatically.', 'ff-stock' );
				$changes  = $this->do_restore_stock( $order, $headline );
				$this->log(
					array(
						'order_id'   => $order_id,
						'old_status' => '(audit)',
						'new_status' => $order->get_status(),
						'result'     => empty( $changes ) ? 'audit: leaving target, but no stock-managed items found to restore' : 'stock restored',
						'changes'    => $this->format_changes_line( $changes ),
					)
				);
				++$fixed_restore;
			}

			$summary = array(
				'time'          => current_time( 'mysql' ),
				'fixed_reduce'  => $fixed_reduce,
				'fixed_restore' => $fixed_restore,
			);
			update_option( self::LAST_AUDIT_OPTION, $summary, false );

			return $summary;

		} catch ( \Throwable $e ) {
			update_option(
				self::LAST_AUDIT_OPTION,
				array(
					'time'          => current_time( 'mysql' ),
					'fixed_reduce'  => $fixed_reduce,
					'fixed_restore' => $fixed_restore,
					'error'         => $e->getMessage(),
				),
				false
			);
			return false;
		}
	}

	public function handle_manual_check() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'ff_stock_run_check' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ff-stock' ) );
		}
		$this->run_consistency_check();
		wp_safe_redirect( add_query_arg( array( 'page' => 'ff-stock-reduction', 'ff_audit_done' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* -----------------------------------------------------------------
	 * Shared stock-adjustment helpers
	 * --------------------------------------------------------------- */

	private function do_reduce_stock( WC_Order $order, $headline ) {
		$changes = $this->adjust_stock( $order, 'decrease' );

		$order->update_meta_data( self::META_KEY, 'yes' );
		$order->get_data_store()->set_stock_reduced( $order->get_id(), true );
		$order->save();

		$order->add_order_note( $this->build_note( $headline, $changes ) );

		return $changes;
	}

	private function do_restore_stock( WC_Order $order, $headline ) {
		$changes = $this->adjust_stock( $order, 'increase' );

		$order->update_meta_data( self::META_KEY, 'no' );
		$order->get_data_store()->set_stock_reduced( $order->get_id(), false );
		$order->save();

		$order->add_order_note( $this->build_note( $headline, $changes ) );

		return $changes;
	}

	private function adjust_stock( $order, $direction ) {
		$changes = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product || ! $product->managing_stock() ) {
				continue;
			}

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

	private function build_note( $headline, $changes ) {
		$lines   = array( $headline );

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

	private function log( $entry ) {
		global $wpdb;

		$result_type = 'skipped';
		if ( 0 === strpos( $entry['result'], 'ERROR' ) ) {
			$result_type = 'error';
		} elseif ( 'stock reduced' === $entry['result'] ) {
			$result_type = 'reduced';
		} elseif ( 'stock restored' === $entry['result'] ) {
			$result_type = 'restored';
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'order_id'    => $entry['order_id'],
				'old_status'  => $entry['old_status'],
				'new_status'  => $entry['new_status'],
				'result_type' => $result_type,
				'result_text' => $entry['result'],
				'changes'     => $entry['changes'],
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( 1 === wp_rand( 1, 50 ) ) {
			$table = self::table_name();
			$wpdb->query( "DELETE FROM {$table} WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM {$table} ORDER BY id DESC LIMIT 5000 ) keep_ids )" ); // phpcs:ignore
		}
	}

	/* -----------------------------------------------------------------
	 * Dashboard
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

	public function maybe_enqueue_assets( $hook ) {
		if ( 'woocommerce_page_ff-stock-reduction' !== $hook ) {
			return;
		}
		?>
		<script>
		document.addEventListener('DOMContentLoaded', function () {
			var presetSelect = document.getElementById('ff-stock-preset');
			var customWrap   = document.getElementById('ff-stock-custom-range');
			function toggleCustom() {
				if (!presetSelect || !customWrap) return;
				customWrap.style.display = ( presetSelect.value === 'custom' ) ? 'flex' : 'none';
			}
			if (presetSelect) {
				presetSelect.addEventListener('change', toggleCustom);
				toggleCustom();
			}
		});
		</script>
		<?php
	}

	private function compute_date_range( $preset, $custom_from, $custom_to ) {
		$today = current_time( 'Y-m-d' );

		switch ( $preset ) {
			case 'today':
				return array( $today, $today );
			case '7days':
				return array( date( 'Y-m-d', strtotime( '-6 days', strtotime( $today ) ) ), $today );
			case '30days':
				return array( date( 'Y-m-d', strtotime( '-29 days', strtotime( $today ) ) ), $today );
			case 'this_month':
				return array( date( 'Y-m-01', strtotime( $today ) ), $today );
			case 'last_month':
				$first = date( 'Y-m-01', strtotime( 'first day of last month', strtotime( $today ) ) );
				$last  = date( 'Y-m-t', strtotime( 'last day of last month', strtotime( $today ) ) );
				return array( $first, $last );
			case 'custom':
				return array(
					preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_from ) ? $custom_from : '',
					preg_match( '/^\d{4}-\d{2}-\d{2}$/', $custom_to ) ? $custom_to : '',
				);
			default:
				return array( '', '' );
		}
	}

	public function render_admin_page() {
		global $wpdb;
		$table = self::table_name();

		$target_labels = array_map( 'wc_get_order_status_name', self::TARGET_STATUSES );

		$preset          = isset( $_GET['ff_preset'] ) ? sanitize_text_field( wp_unslash( $_GET['ff_preset'] ) ) : 'all';
		$allowed_presets = array( 'all', 'today', '7days', '30days', 'this_month', 'last_month', 'custom' );
		if ( ! in_array( $preset, $allowed_presets, true ) ) {
			$preset = 'all';
		}
		$custom_from = isset( $_GET['ff_from'] ) ? sanitize_text_field( wp_unslash( $_GET['ff_from'] ) ) : '';
		$custom_to   = isset( $_GET['ff_to'] ) ? sanitize_text_field( wp_unslash( $_GET['ff_to'] ) ) : '';
		list( $from, $to ) = $this->compute_date_range( $preset, $custom_from, $custom_to );

		$result_filter    = isset( $_GET['ff_result'] ) ? sanitize_text_field( wp_unslash( $_GET['ff_result'] ) ) : 'all';
		$allowed_results  = array( 'all', 'reduced', 'restored', 'skipped', 'error' );
		if ( ! in_array( $result_filter, $allowed_results, true ) ) {
			$result_filter = 'all';
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$where  = array( '1=1' );
		$params = array();
		if ( $from ) {
			$where[]  = 'created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$where[]  = 'created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		if ( 'all' !== $result_filter ) {
			$where[]  = 'result_type = %s';
			$params[] = $result_filter;
		}
		$where_sql = implode( ' AND ', $where );

		$stats_where  = array( '1=1' );
		$stats_params = array();
		if ( $from ) {
			$stats_where[]  = 'created_at >= %s';
			$stats_params[] = $from . ' 00:00:00';
		}
		if ( $to ) {
			$stats_where[]  = 'created_at <= %s';
			$stats_params[] = $to . ' 23:59:59';
		}
		$stats_where_sql = implode( ' AND ', $stats_where );

		$stats_sql = "SELECT result_type, COUNT(*) as c FROM {$table} WHERE {$stats_where_sql} GROUP BY result_type";
		$stats_sql = $stats_params ? $wpdb->prepare( $stats_sql, $stats_params ) : $stats_sql; // phpcs:ignore
		$stats_raw = $wpdb->get_results( $stats_sql, ARRAY_A ); // phpcs:ignore

		$stats = array( 'reduced' => 0, 'restored' => 0, 'skipped' => 0, 'error' => 0 );
		foreach ( (array) $stats_raw as $row ) {
			if ( isset( $stats[ $row['result_type'] ] ) ) {
				$stats[ $row['result_type'] ] = (int) $row['c'];
			}
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$count_sql = $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql; // phpcs:ignore
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;

		$rows_sql    = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A ); // phpcs:ignore

		$inconsistent = $this->get_inconsistent_orders();
		$last_audit   = get_option( self::LAST_AUDIT_OPTION );

		?>
		<style>
			.ff-wrap { max-width: 1300px; }
			.ff-hero { background: linear-gradient(135deg,#2c3e91,#4a5cc4); color: #fff !important; border-radius: 10px; padding: 24px 28px; margin: 18px 0 24px; }
			.ff-hero h2, .ff-hero p, .ff-hero * { color: #fff !important; }
			.ff-hero h2 { margin: 0 0 6px; font-size: 20px; }
			.ff-hero p { margin: 0; opacity: .9; }
			.ff-info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px,1fr)); gap: 14px; margin-bottom: 28px; }
			.ff-info-card { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 14px 16px; }
			.ff-info-card .label { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #7a7d85; margin-bottom: 6px; font-weight: 600; }
			.ff-info-card .value { font-size: 13px; color: #23282d; line-height: 1.5; }

			.ff-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 14px; margin-bottom: 24px; }
			.ff-stat { border-radius: 8px; padding: 16px; color: #fff; }
			.ff-stat .num { font-size: 26px; font-weight: 700; line-height: 1; margin-bottom: 4px; }
			.ff-stat .lbl { font-size: 12px; opacity: .9; }
			.ff-stat-reduced { background: #d35400; }
			.ff-stat-restored { background: #1e8449; }
			.ff-stat-skipped { background: #7f8c8d; }
			.ff-stat-error { background: #c0392b; }

			.ff-audit { background:#fff; border:1px solid #e2e4e7; border-radius:8px; padding:18px 20px; margin-bottom:24px; }
			.ff-audit.ff-audit-clean { border-left: 4px solid #1e8449; }
			.ff-audit.ff-audit-dirty { border-left: 4px solid #c0392b; }
			.ff-audit h3 { margin:0 0 8px; font-size:15px; }
			.ff-audit p { margin: 4px 0; color:#444; font-size:13px; }
			.ff-audit .ff-audit-ids { font-family: Consolas, Menlo, monospace; font-size:12px; color:#666; }

			.ff-filters { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; padding: 16px 18px; margin-bottom: 18px; display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
			.ff-filters label { font-weight: 600; font-size: 12px; color: #444; margin-right: 4px; }
			.ff-filters select, .ff-filters input[type="date"] { min-height: 32px; }
			#ff-stock-custom-range { display: none; gap: 8px; align-items: center; }

			.ff-table-wrap { background: #fff; border: 1px solid #e2e4e7; border-radius: 8px; overflow: hidden; }
			.ff-table-wrap table { border: none; }
			.ff-table-wrap thead th { background: #f6f7f9; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #666; }
			.ff-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; }
			.ff-badge-reduced { background: #fdf0e4; color: #b3540a; }
			.ff-badge-restored { background: #e7f6ee; color: #196f3d; }
			.ff-badge-skipped { background: #f1f1f1; color: #666; }
			.ff-badge-error { background: #c0392b; color: #fff; }
			.ff-changes { font-family: Consolas, Menlo, monospace; font-size: 12px; color: #444; }
			.ff-empty { padding: 40px; text-align: center; color: #888; }

			.ff-pagination { display: flex; justify-content: space-between; align-items: center; padding: 14px 18px; flex-wrap: wrap; gap: 10px; }
			.ff-pagination .ff-count { color: #666; font-size: 13px; }
			.ff-pagination .page-numbers { display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 30px; padding: 0 6px; margin-left: 4px; border: 1px solid #dcdcde; border-radius: 4px; text-decoration: none; color: #2271b1; font-size: 13px; }
			.ff-pagination .page-numbers.current { background: #2c3e91; border-color: #2c3e91; color: #fff; }
			.ff-pagination .page-numbers.dots { border: none; }
		</style>

		<div class="wrap ff-wrap">
			<h1><?php esc_html_e( 'FF Stock Reduction on Completion', 'ff-stock' ); ?></h1>

			<?php if ( isset( $_GET['ff_audit_done'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Consistency check complete — see the results below.', 'ff-stock' ); ?></p></div>
			<?php endif; ?>

			<div class="ff-hero">
				<h2><?php esc_html_e( 'Stock reduces only when an order is marked "Completed (Delivered)".', 'ff-stock' ); ?></h2>
				<p><?php esc_html_e( 'Restored automatically if moved away from Completed, and reduced again if it comes back — including trash, restore-from-trash, and permanent deletion.', 'ff-stock' ); ?></p>
			</div>

			<div class="ff-info-grid">
				<div class="ff-info-card">
					<div class="label"><?php esc_html_e( 'Reduces stock when order enters', 'ff-stock' ); ?></div>
					<div class="value"><?php echo esc_html( implode( ', ', $target_labels ) ); ?></div>
				</div>
				<div class="ff-info-card">
					<div class="label"><?php esc_html_e( 'Restores stock when order leaves', 'ff-stock' ); ?></div>
					<div class="value"><?php echo esc_html( implode( ', ', $target_labels ) ); ?> <?php esc_html_e( '(to any other status, or if trashed/deleted)', 'ff-stock' ); ?></div>
				</div>
				<div class="ff-info-card">
					<div class="label"><?php esc_html_e( 'All other statuses', 'ff-stock' ); ?></div>
					<div class="value"><?php esc_html_e( 'Processing, On Hold, Dispatched, Dispatched COD, Carry Forward Order, Draft, Cancelled, Failed — none touch stock.', 'ff-stock' ); ?></div>
				</div>
				<div class="ff-info-card">
					<div class="label"><?php esc_html_e( 'Bulk edits & manual orders', 'ff-stock' ); ?></div>
					<div class="value"><?php esc_html_e( 'Bulk status changes from the Orders list and manually created orders both use the same logic above, so they behave the same way.', 'ff-stock' ); ?></div>
				</div>
				<div class="ff-info-card">
					<div class="label"><?php esc_html_e( 'Self-healing', 'ff-stock' ); ?></div>
					<div class="value"><?php esc_html_e( 'A consistency check runs automatically once a day, and can be run manually below, to catch and fix any order that ever falls out of sync.', 'ff-stock' ); ?></div>
				</div>
			</div>

			<div class="ff-stats">
				<div class="ff-stat ff-stat-reduced"><div class="num"><?php echo esc_html( $stats['reduced'] ); ?></div><div class="lbl"><?php esc_html_e( 'Stock Reduced', 'ff-stock' ); ?></div></div>
				<div class="ff-stat ff-stat-restored"><div class="num"><?php echo esc_html( $stats['restored'] ); ?></div><div class="lbl"><?php esc_html_e( 'Stock Restored', 'ff-stock' ); ?></div></div>
				<div class="ff-stat ff-stat-skipped"><div class="num"><?php echo esc_html( $stats['skipped'] ); ?></div><div class="lbl"><?php esc_html_e( 'Skipped (no action needed)', 'ff-stock' ); ?></div></div>
				<div class="ff-stat ff-stat-error"><div class="num"><?php echo esc_html( $stats['error'] ); ?></div><div class="lbl"><?php esc_html_e( 'Errors', 'ff-stock' ); ?></div></div>
			</div>

			<?php
			$dirty_count = count( $inconsistent['need_reduce'] ) + count( $inconsistent['need_restore'] );
			?>
			<div class="ff-audit <?php echo $dirty_count ? 'ff-audit-dirty' : 'ff-audit-clean'; ?>">
				<h3><?php esc_html_e( 'Stock Consistency Check', 'ff-stock' ); ?></h3>
				<p style="color:#888;font-size:12px;">
					<?php
					printf(
						/* translators: %s: date/time the plugin was activated */
						esc_html__( 'Only checking orders placed since %s (when this plugin was activated) — older orders are never touched.', 'ff-stock' ),
						esc_html( $inconsistent['since'] )
					);
					?>
				</p>
				<?php if ( 0 === $dirty_count ) : ?>
					<p><?php esc_html_e( 'Everything is in sync right now — every Completed order has had its stock reduced, and no other order has stock still marked as reduced.', 'ff-stock' ); ?></p>
				<?php else : ?>
					<?php if ( ! empty( $inconsistent['need_reduce'] ) ) : ?>
						<p>
							<?php
							printf(
								/* translators: %d: number of orders */
								esc_html__( '%d order(s) are Completed but stock was never reduced:', 'ff-stock' ),
								count( $inconsistent['need_reduce'] )
							);
							?>
							<span class="ff-audit-ids">#<?php echo esc_html( implode( ', #', array_slice( $inconsistent['need_reduce'], 0, 20 ) ) ); ?><?php echo count( $inconsistent['need_reduce'] ) > 20 ? esc_html__( ' …and more', 'ff-stock' ) : ''; ?></span>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $inconsistent['need_restore'] ) ) : ?>
						<p>
							<?php
							printf(
								/* translators: %d: number of orders */
								esc_html__( '%d order(s) still have stock marked reduced but are not Completed:', 'ff-stock' ),
								count( $inconsistent['need_restore'] )
							);
							?>
							<span class="ff-audit-ids">#<?php echo esc_html( implode( ', #', array_slice( $inconsistent['need_restore'], 0, 20 ) ) ); ?><?php echo count( $inconsistent['need_restore'] ) > 20 ? esc_html__( ' …and more', 'ff-stock' ) : ''; ?></span>
						</p>
					<?php endif; ?>
					<?php if ( $dirty_count > self::AUDIT_BATCH_LIMIT ) : ?>
						<p style="color:#b3540a;">
							<?php
							printf(
								/* translators: %d: batch size */
								esc_html__( 'More than %d orders need fixing — this run will fix the first batch, and you can click again (or wait for tomorrow\'s automatic run) for the rest.', 'ff-stock' ),
								(int) self::AUDIT_BATCH_LIMIT
							);
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px;">
					<input type="hidden" name="action" value="ff_stock_run_check" />
					<?php wp_nonce_field( 'ff_stock_run_check' ); ?>
					<button type="submit" class="button <?php echo $dirty_count ? 'button-primary' : ''; ?>">
						<?php esc_html_e( 'Run check & fix now', 'ff-stock' ); ?>
					</button>
				</form>

				<?php if ( $last_audit ) : ?>
					<p style="margin-top:10px;color:#888;font-size:12px;">
						<?php
						printf(
							/* translators: 1: date, 2: number reduced, 3: number restored */
							esc_html__( 'Last run: %1$s — fixed %2$d order(s) by reducing, %3$d by restoring.', 'ff-stock' ),
							esc_html( $last_audit['time'] ),
							(int) $last_audit['fixed_reduce'],
							(int) $last_audit['fixed_restore']
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<h2 style="margin-bottom:4px;"><?php esc_html_e( 'Activity Logs', 'ff-stock' ); ?></h2>
			<p style="margin-top:0;color:#666;"><?php esc_html_e( 'Every order status change this plugin sees, with a product-by-product breakdown of any stock adjustment made.', 'ff-stock' ); ?></p>

			<form method="get" class="ff-filters">
				<input type="hidden" name="page" value="ff-stock-reduction" />

				<div>
					<label for="ff-stock-preset"><?php esc_html_e( 'Date range', 'ff-stock' ); ?></label>
					<select name="ff_preset" id="ff-stock-preset">
						<option value="all" <?php selected( $preset, 'all' ); ?>><?php esc_html_e( 'All time', 'ff-stock' ); ?></option>
						<option value="today" <?php selected( $preset, 'today' ); ?>><?php esc_html_e( 'Today', 'ff-stock' ); ?></option>
						<option value="7days" <?php selected( $preset, '7days' ); ?>><?php esc_html_e( 'Last 7 days', 'ff-stock' ); ?></option>
						<option value="30days" <?php selected( $preset, '30days' ); ?>><?php esc_html_e( 'Last 30 days', 'ff-stock' ); ?></option>
						<option value="this_month" <?php selected( $preset, 'this_month' ); ?>><?php esc_html_e( 'This month', 'ff-stock' ); ?></option>
						<option value="last_month" <?php selected( $preset, 'last_month' ); ?>><?php esc_html_e( 'Last month', 'ff-stock' ); ?></option>
						<option value="custom" <?php selected( $preset, 'custom' ); ?>><?php esc_html_e( 'Custom range', 'ff-stock' ); ?></option>
					</select>
				</div>

				<div id="ff-stock-custom-range">
					<label for="ff_from"><?php esc_html_e( 'From', 'ff-stock' ); ?></label>
					<input type="date" name="ff_from" id="ff_from" value="<?php echo esc_attr( $custom_from ); ?>" />
					<label for="ff_to"><?php esc_html_e( 'To', 'ff-stock' ); ?></label>
					<input type="date" name="ff_to" id="ff_to" value="<?php echo esc_attr( $custom_to ); ?>" />
				</div>

				<div>
					<label for="ff_result"><?php esc_html_e( 'Result', 'ff-stock' ); ?></label>
					<select name="ff_result" id="ff_result">
						<option value="all" <?php selected( $result_filter, 'all' ); ?>><?php esc_html_e( 'All', 'ff-stock' ); ?></option>
						<option value="reduced" <?php selected( $result_filter, 'reduced' ); ?>><?php esc_html_e( 'Stock reduced', 'ff-stock' ); ?></option>
						<option value="restored" <?php selected( $result_filter, 'restored' ); ?>><?php esc_html_e( 'Stock restored', 'ff-stock' ); ?></option>
						<option value="skipped" <?php selected( $result_filter, 'skipped' ); ?>><?php esc_html_e( 'Skipped', 'ff-stock' ); ?></option>
						<option value="error" <?php selected( $result_filter, 'error' ); ?>><?php esc_html_e( 'Errors', 'ff-stock' ); ?></option>
					</select>
				</div>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply filters', 'ff-stock' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=ff-stock-reduction' ) ); ?>" class="button"><?php esc_html_e( 'Reset', 'ff-stock' ); ?></a>
			</form>

			<div class="ff-table-wrap">
				<?php if ( empty( $rows ) ) : ?>
					<div class="ff-empty"><?php esc_html_e( 'No activity found for this filter.', 'ff-stock' ); ?></div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:70px;"><?php esc_html_e( 'Order', 'ff-stock' ); ?></th>
								<th style="width:170px;"><?php esc_html_e( 'Transition', 'ff-stock' ); ?></th>
								<th style="width:130px;"><?php esc_html_e( 'Result', 'ff-stock' ); ?></th>
								<th><?php esc_html_e( 'Stock changes (product: before→after)', 'ff-stock' ); ?></th>
								<th style="width:160px;"><?php esc_html_e( 'When', 'ff-stock' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) :
								$order     = wc_get_order( $row['order_id'] );
								$edit_link = $order ? $order->get_edit_order_url() : '#';
								?>
								<tr>
									<td><a href="<?php echo esc_url( $edit_link ); ?>">#<?php echo esc_html( $row['order_id'] ); ?></a></td>
									<td><?php echo esc_html( $row['old_status'] . ' → ' . $row['new_status'] ); ?></td>
									<td><span class="ff-badge ff-badge-<?php echo esc_attr( $row['result_type'] ); ?>"><?php echo esc_html( ucfirst( $row['result_type'] ) ); ?></span></td>
									<td class="ff-changes"><?php echo ! empty( $row['changes'] ) ? esc_html( $row['changes'] ) : '—'; ?></td>
									<td><?php echo esc_html( $row['created_at'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="ff-pagination">
						<div class="ff-count">
							<?php
							printf(
								/* translators: 1: first row number, 2: last row number, 3: total rows */
								esc_html__( 'Showing %1$d–%2$d of %3$d', 'ff-stock' ),
								(int) ( $offset + 1 ),
								(int) min( $offset + self::PER_PAGE, $total ),
								(int) $total
							);
							?>
						</div>
						<div>
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%' ),
										'format'    => '',
										'current'   => $paged,
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
										'type'      => 'plain',
									)
								)
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}

$ff_stock_reduction_plugin = new FF_Stock_Reduction_On_Completion();
register_activation_hook( __FILE__, array( 'FF_Stock_Reduction_On_Completion', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FF_Stock_Reduction_On_Completion', 'deactivate' ) );
