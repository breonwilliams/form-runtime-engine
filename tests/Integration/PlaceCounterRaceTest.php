<?php
/**
 * Two connections racing for the last place: exactly one gets it.
 *
 * One connection cannot race itself, so these tests open two INDEPENDENT
 * database connections (two wpdb instances, two MySQL sessions, both
 * autocommit) and interleave their claims step by step: connection A starts
 * its claim, and just before A writes anything to the count row, connection
 * B runs its own whole claim. A version that reads the count and then writes
 * a new one ("count-then-insert") lets both through here; the atomic
 * conditional UPDATE lets exactly one through.
 *
 * Scope of the proof: this shows the claim is atomic in MySQL/InnoDB across
 * sessions. It does not show two real HTTP requests through PHP-FPM behave —
 * that stays a human-run check.
 *
 * The test case's own connection holds a transaction (WP_UnitTestCase) and,
 * after IntegrationTestCase::clear_rate_limits()'s full-scan DELETE, locks
 * on wp_options. set_up() commits it so the two race connections are not
 * blocked by it; tear_down_after_class() removes the committed rows.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * A wpdb whose query() can run a callback just before a matching statement.
 */
class InterleavingWpdb extends \wpdb {

    /**
     * Statements that may trigger $before_write. Default: any write. The
     * lazy-creation test narrows it to INSERT, so B runs only once A has read
     * "no row" and is about to create one.
     *
     * @var string
     */
    public $fire_on = '/^\s*(UPDATE|INSERT|REPLACE|DELETE)\b/i';

    /**
     * Callback( string $sql ): void, run once before the first statement
     * matching $fire_on that touches $this->watch.
     *
     * @var callable|null
     */
    public $before_write;

    /**
     * Substring identifying the row being raced for.
     *
     * @var string
     */
    public $watch = '';

    /**
     * Every statement this connection ran, in order.
     *
     * @var string[]
     */
    public $log = array();

    public function query( $query ) {
        $is_write = (bool) preg_match( $this->fire_on, (string) $query );
        if ( $is_write && $this->before_write && '' !== $this->watch && false !== strpos( (string) $query, $this->watch ) ) {
            $callback           = $this->before_write;
            $this->before_write = null;
            $callback( $query );
        }
        $this->log[] = (string) $query;
        return parent::query( $query );
    }
}

/**
 * PForms_Place_Counter under interleaved claims from two connections.
 */
class PlaceCounterRaceTest extends IntegrationTestCase {

    /**
     * @var InterleavingWpdb
     */
    private $conn_a;

    /**
     * @var InterleavingWpdb
     */
    private $conn_b;

    /**
     * @var string
     */
    private $form_id;

    public function set_up() {
        parent::set_up();

        global $wpdb;
        // Release the test transaction's locks on wp_options (see class doc).
        $wpdb->query( 'COMMIT' );

        $this->conn_a = $this->connect();
        $this->conn_b = $this->connect();

        $this->form_id = 'p2-race-' . strtolower( wp_generate_password( 8, false ) );
    }

    public function tear_down() {
        $this->conn_b->query( $this->conn_b->prepare(
            "DELETE FROM {$this->conn_b->options} WHERE option_name = %s",
            \PForms_Place_Counter::option_name( $this->form_id )
        ) );
        $this->conn_a->close();
        $this->conn_b->close();

        parent::tear_down();
    }

    public static function tear_down_after_class() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pforms\\_places\\_p2-race-%'" );
        $wpdb->query( 'COMMIT' );

        parent::tear_down_after_class();
    }

    /**
     * A fresh, independent connection to the test database.
     *
     * @return InterleavingWpdb
     */
    private function connect() {
        global $wpdb;

        $db = new InterleavingWpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
        $db->set_prefix( $wpdb->prefix );
        $db->suppress_errors( false );
        // A lock we did not expect fails in seconds instead of hanging.
        $db->query( 'SET SESSION innodb_lock_wait_timeout = 5' );
        $db->query( 'SET SESSION autocommit = 1' );

        $this->assertNotSame(
            $wpdb->get_var( 'SELECT CONNECTION_ID()' ),
            $db->get_var( 'SELECT CONNECTION_ID()' ),
            'The race must run on a different MySQL session from the test case.'
        );

        return $db;
    }

    private function count_now() {
        // Read through a third view (B) so nothing in A's state is trusted.
        return ( new \PForms_Place_Counter( $this->conn_b ) )->get( $this->form_id );
    }

    public function test_two_connections_interleaved_for_the_last_place_exactly_one_wins() {
        $cap = 3;
        ( new \PForms_Place_Counter( $this->conn_b ) )->set( $this->form_id, $cap - 1 );
        $this->assertNotSame(
            $this->conn_a->get_var( 'SELECT CONNECTION_ID()' ),
            $this->conn_b->get_var( 'SELECT CONNECTION_ID()' )
        );

        $counter_a = new \PForms_Place_Counter( $this->conn_a );
        $counter_b = new \PForms_Place_Counter( $this->conn_b );

        $b_result                 = null;
        $this->conn_a->watch      = \PForms_Place_Counter::option_name( $this->form_id );
        $this->conn_a->before_write = function () use ( $counter_b, $cap, &$b_result ) {
            // A has started its claim and is about to write. B now runs a
            // complete claim on its own connection.
            $b_result = $counter_b->claim( $this->form_id, $cap );
        };

        $a_result = $counter_a->claim( $this->form_id, $cap );

        $this->assertNotNull( $b_result, 'B must have run inside A\'s claim — otherwise nothing was interleaved.' );
        $this->assertSame(
            1,
            (int) $a_result + (int) $b_result,
            sprintf( 'Exactly one claim may take the last place (A: %s, B: %s).', var_export( $a_result, true ), var_export( $b_result, true ) )
        );
        $this->assertTrue( $b_result, 'B finished first, so B holds the place.' );
        $this->assertSame( $cap, $this->count_now(), 'The count never passes the cap.' );
    }

    public function test_many_interleaved_claims_never_exceed_the_cap() {
        $cap = 3;
        ( new \PForms_Place_Counter( $this->conn_b ) )->set( $this->form_id, 0 );

        $counter_a = new \PForms_Place_Counter( $this->conn_a );
        $counter_b = new \PForms_Place_Counter( $this->conn_b );
        $wins      = 0;

        for ( $round = 0; $round < 5; $round++ ) {
            $b_result                   = null;
            $this->conn_a->watch        = \PForms_Place_Counter::option_name( $this->form_id );
            $this->conn_a->before_write = function () use ( $counter_b, $cap, &$b_result ) {
                $b_result = $counter_b->claim( $this->form_id, $cap );
            };

            $a_result = $counter_a->claim( $this->form_id, $cap );
            $wins    += (int) $a_result + (int) $b_result;
        }

        $this->assertSame( $cap, $wins, '10 interleaved attempts on 3 places: exactly 3 succeed.' );
        $this->assertSame( $cap, $this->count_now() );
    }

    public function test_first_claim_on_a_form_without_a_count_row_is_not_double_counted() {
        // No row yet (a PHP-registered capped form): both connections must
        // create it and claim; with cap 1 exactly one may win. B runs its
        // whole claim INSIDE A's lazy path: after A's increment found no row
        // and A's read() returned null, just before A's INSERT creates it. So
        // A's create must not overwrite the row B made and claimed on.
        $cap  = 1;
        $name = \PForms_Place_Counter::option_name( $this->form_id );

        $counter_a = new \PForms_Place_Counter( $this->conn_a );
        $counter_b = new \PForms_Place_Counter( $this->conn_b );

        $b_result                   = null;
        $a_log_at_hook              = array();
        $this->conn_a->watch        = $name;
        $this->conn_a->fire_on      = '/^\s*INSERT\b/i';
        $this->conn_a->before_write = function () use ( $counter_b, $cap, &$b_result, &$a_log_at_hook ) {
            $a_log_at_hook = $this->conn_a->log;
            $b_result      = $counter_b->claim( $this->form_id, $cap );
        };

        $a_result = $counter_a->claim( $this->form_id, $cap );

        $this->assertNotNull( $b_result, 'B must have run inside A\'s lazy path.' );
        $a_steps = implode( "\n", $a_log_at_hook );
        $this->assertMatchesRegularExpression( '/^\s*UPDATE\b/mi', $a_steps, 'A had tried its increment before B ran.' );
        $this->assertMatchesRegularExpression( '/SELECT option_value FROM/i', $a_steps, 'A had read "no row" before B ran.' );
        $this->assertDoesNotMatchRegularExpression( '/^\s*INSERT\b/mi', $a_steps, 'A had not created the row yet.' );
        $this->assertMatchesRegularExpression( '/^\s*INSERT\b/mi', implode( "\n", $this->conn_b->log ), 'B created the row itself.' );

        $this->assertTrue( $b_result, 'B finished first, so B holds the place.' );
        $this->assertFalse( $a_result, 'A must not overwrite B\'s row and claim again.' );
        $this->assertSame( 1, $this->count_now() );
    }

    public function test_release_on_one_connection_frees_the_place_for_the_other() {
        $cap = 1;
        $counter_a = new \PForms_Place_Counter( $this->conn_a );
        $counter_b = new \PForms_Place_Counter( $this->conn_b );
        $counter_a->set( $this->form_id, 0 );

        $this->assertTrue( $counter_a->claim( $this->form_id, $cap ) );
        $this->assertFalse( $counter_b->claim( $this->form_id, $cap ), 'Full.' );
        $this->assertTrue( $counter_a->release( $this->form_id ) );
        $this->assertTrue( $counter_b->claim( $this->form_id, $cap ), 'The released place is claimable.' );
        $this->assertSame( 1, $this->count_now() );
    }
}
