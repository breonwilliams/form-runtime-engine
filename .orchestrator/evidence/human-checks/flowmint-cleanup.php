<?php
// Remove everything flowmint.sh creates on the Local site.
global $wpdb;
$e = new PForms_Entry();
foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id = 'hc-flow-form'" ) as $id ) { $e->delete( (int) $id ); }
$runs = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fmw_workflow_runs WHERE workflow_id = 'hc-flow'" );
if ( $runs ) {
	$in = implode( ',', array_map( 'intval', $runs ) );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}fmw_workflow_run_steps WHERE run_id IN ($in)" );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}fmw_workflow_runs WHERE id IN ($in)" );
}
if ( FMW_Workflow_Repository::get( 'hc-flow' ) ) { FMW_Workflow_Repository::delete( 'hc-flow', true ); }
pforms_delete_db_form( 'hc-flow-form' );
echo 'cleaned: ', count( $runs ), " runs\n";
