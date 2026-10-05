<?php

// Analysis-only declarations for the WordPress API used by this package.
const ARRAY_A = 'ARRAY_A';
const ABSPATH = '/';

class wpdb {
	public $prefix;
	public $tables;
	public $charset;
	public $collate;
	public $last_error;
	public $dbh;
	public $insert_id;

	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost ) {}
	public function query( $query ) {}
	public function check_connection( $allow_bail = true ) {}
	public function get_var( $query = null, $x = 0, $y = 0 ) {}
	public function get_row( $query = null, $output = OBJECT, $y = 0 ) {}
	public function get_results( $query = null, $output = OBJECT ) {}
	public function get_col_charset( $table, $column ) {}
	public function get_charset_collate() {}
	public function has_cap( $db_cap ) {}
	public function prepare( $query, ...$args ) {}
	public function suppress_errors( $suppress = true ) {}
	public function close() {}
}

function apply_filters( $hook_name, $value, ...$args ) {}
function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {}
function wp_parse_args( $args, $defaults = array() ) {}
function dbDelta( $queries = '', $execute = true ) {}
function get_option( $option, $default_value = false ) {}
function update_option( $option, $value, $autoload = null ) {}
function wp_cache_delete( $key, $group = '' ) {}
