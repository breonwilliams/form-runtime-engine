<?php
// wp eval-file render-fields.php <form_id>
// Prints the form's hidden inputs (nonce, timing token, form id, honeypot) as
// name<TAB>value lines, exactly as a logged-out visitor's page would carry them.
$form_id = $args[0];
$html    = pforms_render_form( $form_id );
if ( ! preg_match_all( '/<input\b[^>]*>/i', $html, $m ) ) {
	fwrite( STDERR, "no inputs rendered\n" );
	exit( 1 );
}
foreach ( $m[0] as $tag ) {
	if ( ! preg_match( '/\bname="([^"]+)"/', $tag, $n ) ) {
		continue;
	}
	$type  = preg_match( '/\btype="([^"]+)"/', $tag, $t ) ? $t[1] : 'text';
	$value = preg_match( '/\bvalue="([^"]*)"/', $tag, $v ) ? html_entity_decode( $v[1] ) : '';
	if ( in_array( $type, array( 'hidden', 'text' ), true ) && 0 !== strpos( $n[1], 'pforms_field_' ) ) {
		echo $n[1], "\t", $value, "\n";
	}
}
