<?php
// One form per Status-column state, for the wp-admin check. Remove with admin-cleanup.php.
$fields = array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ) );
$make = function ( $id, $title, $availability ) use ( $fields ) {
	$cfg = array( 'title' => $title, 'fields' => $fields, 'settings' => array( 'notification' => array( 'enabled' => false ) ) );
	if ( null !== $availability ) { $cfg['settings']['availability'] = $availability; }
	$r = pforms_save_db_form( $id, $title, wp_json_encode( $cfg ) );
	echo $id, ': ', is_wp_error( $r ) ? $r->get_error_message() : 'ok', "\n";
};
$c = new PForms_Place_Counter();
$make( 'hc-admin-plain',  'HC admin: plain (no rules)', null );
$make( 'hc-admin-capped', 'HC admin: capped, open', array( 'max_entries' => 10, 'closes_at' => '2099-07-20 17:00' ) );
$c->set( 'hc-admin-capped', 3 );
$make( 'hc-admin-before', 'HC admin: before opening', array( 'opens_at' => '2099-01-15 09:00' ) );
$make( 'hc-admin-past',   'HC admin: past closing', array( 'closes_at' => '2020-01-20 17:00' ) );
$make( 'hc-admin-full',   'HC admin: full', array( 'max_entries' => 2 ) );
$c->set( 'hc-admin-full', 2 );
$make( 'hc-admin-uncap',  'HC admin: cap removed, still counting', array( 'max_entries' => 5 ) );
$c->set( 'hc-admin-uncap', 4 );
$make( 'hc-admin-uncap',  'HC admin: cap removed, still counting', null );
