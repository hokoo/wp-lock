<?php

namespace iTRON\WP_Lock\helpers;

final class Database {
	public static function register_table( $key, $name = false ) {
		global $wpdb;

		if ( ! $name ) {
			$name = $key;
		}

		$wpdb->tables[] = $name;
		$wpdb->$key     = $wpdb->prefix . $name;
	}

	public static function install_table( $key, $columns, $opts = array() ) {
		global $wpdb;

		$full_table_name = $wpdb->prefix . $key;

		if ( is_string( $opts ) ) {
			$opts = array( 'upgrade_method' => $opts );
		}

		$opts = wp_parse_args(
			$opts,
			array(
				'upgrade_method' => 'dbDelta',
				'table_options'  => '',
			)
		);

		$charset_collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			if ( ! empty( $wpdb->charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
			}
			if ( ! empty( $wpdb->collate ) ) {
				$charset_collate .= " COLLATE $wpdb->collate";
			}
		}

		$table_options = $charset_collate . ' ' . $opts['table_options'];

		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Preserve the helper's existing option coercion.
		if ( 'dbDelta' == $opts['upgrade_method'] ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( "CREATE TABLE $full_table_name ( $columns ) $table_options" );

			return;
		}

		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Preserve the helper's existing option coercion.
		if ( 'delete_first' == $opts['upgrade_method'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Legacy schema helper supplies SQL identifiers, not query values.
			$wpdb->query( "DROP TABLE IF EXISTS $full_table_name;" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Legacy schema helper supplies a table, column list and DDL options, not query values.
		$wpdb->query( "CREATE TABLE IF NOT EXISTS $full_table_name ( $columns ) $table_options;" );
	}
}
