<?php
// Render the real Forms list page as the current (admin) user and print each
// hc-admin-* row's Status cell: its data attributes and its visible text.
// Run with --user=<admin>.
set_current_screen( 'toplevel_page_pforms-forms' );
ob_start();
PForms_Forms_Manager::render_page();
$html = ob_get_clean();

$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
$xp = new DOMXPath( $doc );

foreach ( $xp->query( '//tr' ) as $tr ) {
	if ( ! preg_match( '/hc-admin-[a-z]+/', $doc->saveHTML( $tr ), $id ) ) {
		continue;
	}
	$cell = $xp->query( './/*[@data-reason]', $tr )->item( 0 );
	if ( ! $cell ) {
		echo $id[0], " (status cell not found)\n";
		continue;
	}
	$parts = array();
	foreach ( $xp->query( './/text()', $cell ) as $t ) {
		$s = trim( preg_replace( '/\s+/', ' ', $t->nodeValue ) );
		if ( '' !== $s ) {
			$parts[] = $s;
		}
	}
	printf( "%-16s open=%-5s reason=%-13s | %s\n", $id[0], $cell->getAttribute( 'data-open' ), $cell->getAttribute( 'data-reason' ), implode( ' | ', $parts ) );
}
