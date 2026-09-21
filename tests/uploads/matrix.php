<?php
/**
 * Upload inspection matrix — measures both directions on real files.
 *
 *   php tests/uploads/matrix.php                       # committed fixtures
 *   php tests/uploads/matrix.php --corpus=/path/to/dir # plus a local corpus
 *   php tests/uploads/matrix.php --types=png,jpg,pdf   # a different field
 *   php tests/uploads/matrix.php --verbose             # every file
 *
 * A corpus directory holds `genuine/` and/or `malicious/` subdirectories.
 * Genuine files that are NOT really the format their name says (a gzip file
 * named .pdf, a JavaScript module named .svg) can be listed one per line in
 * `genuine/NOT-REAL.txt`; they are reported separately and must be refused.
 *
 * Exit code 1 when a genuine file is refused, a NOT-REAL file is accepted, or
 * a malicious file is accepted (other than the documented exceptions in
 * tests/Unit/UploadInspectorTest.php::ACCEPTED_BY_DESIGN). This runs no
 * WordPress: it loads the inspector and the MIME validator with stubs, so it
 * measures exactly the code that ships.
 *
 * Measured 2026-09-21 (numbers and method in tests/uploads/README.md): 0 of
 * 440 valid files refused, where 1.10.1 refused 83% of real artwork and every
 * Illustrator export; 48 of 49 crafted attacks refused, the one accepted
 * being viewer-side PDF JavaScript (by design).
 *
 * @package FormRuntimeEngine\Tests
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );

// Minimal WordPress stubs: everything the inspector and validator call.
if ( ! function_exists( '__' ) ) {
    function __( $text ) { return $text; }
    function apply_filters( $hook, $value ) { return $value; }
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
    class WP_Error {
        private $code;
        private $message;
        public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
    }
}

require $root . '/includes/Uploads/class-fre-mime-validator.php';
require $root . '/includes/Uploads/class-fre-upload-inspector.php';

$options = getopt( '', array( 'corpus:', 'types:', 'verbose' ) );
$types   = isset( $options['types'] ) ? array_filter( array_map( 'trim', explode( ',', strtolower( $options['types'] ) ) ) ) : array( 'png', 'jpg', 'jpeg', 'pdf', 'ai', 'eps', 'dst', 'svg' );
$verbose = isset( $options['verbose'] );
$roots   = array( $root . '/tests/Fixtures/uploads' );
foreach ( (array) ( isset( $options['corpus'] ) ? $options['corpus'] : array() ) as $corpus ) {
    $roots[] = rtrim( $corpus, '/' );
}

$accepted_by_design = array( 'pdf-openaction-js.pdf' );
$inspector          = new PForms_Upload_Inspector();
$failures           = array();
$tally              = array();

foreach ( $roots as $base ) {
    foreach ( array( 'genuine', 'malicious' ) as $set ) {
        $dir = $base . '/' . $set;
        if ( ! is_dir( $dir ) ) {
            continue;
        }
        $not_real = is_file( $dir . '/NOT-REAL.txt' ) ? array_filter( array_map( 'trim', file( $dir . '/NOT-REAL.txt' ) ) ) : array();

        foreach ( glob( $dir . '/*' ) as $path ) {
            $name = basename( $path );
            if ( 'NOT-REAL.txt' === $name || ! is_file( $path ) ) {
                continue;
            }
            $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
            if ( 'genuine' === $set && ! in_array( $ext, $types, true ) ) {
                continue; // Not something this field accepts; the extension check refuses it before inspection.
            }

            $started = microtime( true );
            $result  = $inspector->inspect( $path, $ext, $types );
            $ms      = ( microtime( true ) - $started ) * 1000;
            $ok      = ! is_wp_error( $result );
            $code    = $ok ? 'accepted as .' . $result['ext'] : $result->get_error_code();

            $group = 'genuine' === $set && in_array( $name, $not_real, true ) ? 'not-real' : $set;
            $key   = $group . '|' . $ext;
            if ( ! isset( $tally[ $key ] ) ) {
                $tally[ $key ] = array( 'n' => 0, 'accepted' => 0 );
            }
            $tally[ $key ]['n']++;
            $tally[ $key ]['accepted'] += $ok ? 1 : 0;

            $wrong = ( 'genuine' === $group && ! $ok )
                || ( 'not-real' === $group && $ok )
                || ( 'malicious' === $group && $ok && ! in_array( $name, $accepted_by_design, true ) );
            if ( $wrong ) {
                $failures[] = sprintf( '%-9s %s — %s', $group, $path, $ok ? 'ACCEPTED' : $code . ': ' . $result->get_error_message() );
            }
            if ( $verbose ) {
                printf( "%-9s %-8s %7.1f ms  %-40s %s\n", $group, $wrong ? 'WRONG' : 'ok', $ms, substr( $name, 0, 40 ), $code );
            }
        }
    }
}

ksort( $tally );
echo "\nField accepts: " . implode( ', ', $types ) . "\n\n";
printf( "%-10s %-6s %6s %9s %9s\n", 'set', 'type', 'files', 'accepted', 'refused' );
$sum = array();
foreach ( $tally as $key => $row ) {
    list( $set, $ext ) = explode( '|', $key );
    printf( "%-10s %-6s %6d %9d %9d\n", $set, $ext, $row['n'], $row['accepted'], $row['n'] - $row['accepted'] );
    if ( ! isset( $sum[ $set ] ) ) {
        $sum[ $set ] = array( 0, 0 );
    }
    $sum[ $set ][0] += $row['n'];
    $sum[ $set ][1] += $row['accepted'];
}
echo "\n";
if ( isset( $sum['genuine'] ) ) {
    printf( "Genuine refused:     %d of %d\n", $sum['genuine'][0] - $sum['genuine'][1], $sum['genuine'][0] );
}
if ( isset( $sum['not-real'] ) ) {
    printf( "Not-real accepted:   %d of %d\n", $sum['not-real'][1], $sum['not-real'][0] );
}
if ( isset( $sum['malicious'] ) ) {
    printf( "Malicious accepted:  %d of %d (by design: %s)\n", $sum['malicious'][1], $sum['malicious'][0], implode( ', ', $accepted_by_design ) );
}

if ( $failures ) {
    echo "\nREGRESSIONS:\n  " . implode( "\n  ", $failures ) . "\n";
    exit( 1 );
}
echo "\nOK\n";
