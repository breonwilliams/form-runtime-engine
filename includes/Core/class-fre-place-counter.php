<?php
/**
 * Place counter: how many of a capped form's places are taken.
 *
 * Backs settings.availability.max_entries. The count is a number of
 * ACCEPTED SUBMISSIONS, kept apart from the entry rows (Q-001):
 *
 *   - spam never takes a place: storing a spam entry, marking an entry spam
 *     and "Mark as Not Spam" never touch the count;
 *   - deleting entries (admin, connector, FlowMint retention — all through
 *     PForms_Entry::delete()) never gives a place back;
 *   - it works with store_entries off, because nothing here reads entries
 *     after the count exists;
 *   - when a cap is first added to a form, the count starts at the number of
 *     non-spam entries stored for that form at that moment (seed()).
 *
 * ONCE COUNTED, ALWAYS COUNTED (Q-010)
 *
 * A form that has had a cap keeps its count row, and keeps counting accepted
 * submissions while the cap is off, so a restored cap is exact and a save
 * without a cap (an admin edit, or a connector update whose config leaves out
 * settings.availability) never erases the count. Only deleting the form
 * (PForms_Forms_Repository::delete()) removes it; a form re-created under the
 * same ID is a new form and is seeded again. DB-stored and PHP-registered
 * forms behave the same: removing a cap in code keeps the row too, and the
 * cap resumes from it when it returns. claim() with a null cap is the
 * "uncapped, but counting" path.
 *
 * ONE ROW PER FORM, WRITTEN ONLY THROUGH $wpdb
 *
 * The count is one wp_options row, `pforms_places_{form_id}` (autoload off),
 * read and written with SQL and nothing else. Never pass that name to
 * get_option(), update_option(), the transient API or wp_cache_*: on a site
 * with a persistent object cache those read and write a different store, and
 * read-modify-write through them is not atomic (1.11.0's submission-lock
 * incident, see PForms_Submission_Lock). The `pforms_` prefix means the
 * opt-in "remove all data" uninstall removes it with the other options, and
 * the default uninstall keeps it, like the forms it belongs to.
 *
 * THE CLAIM IS ONE STATEMENT
 *
 *   UPDATE ... SET n = n + 1 WHERE option_name = ? AND n < cap
 *
 * InnoDB locks the row for the UPDATE and re-evaluates the WHERE after any
 * wait, so two requests racing for the last place cannot both see "n < cap":
 * exactly one changes the row, the other affects 0 rows and is refused. There
 * is no separate read whose answer could go stale before the write.
 *
 * Who calls what: the submission handler counts EVERY accepted submission,
 * capped or not, before it stores it — take() on a capped form (so it knows
 * whether it took the last place), claim() with a null cap otherwise — and
 * calls release() if storing then fails; never for a honeypot/spam
 * submission or a dry run. An admin
 * control adjusts the count with set() (P6). PForms_Availability::check()
 * reads it with get().
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Atomic per-form place counter.
 */
class PForms_Place_Counter {

    /**
     * Option-name prefix of a form's count row.
     */
    const OPTION_PREFIX = 'pforms_places_';

    /**
     * Longest form ID used verbatim in the row name (option_name is
     * varchar(191)); longer IDs are hashed.
     */
    const MAX_ID_LENGTH = 150;

    /**
     * Database connection.
     *
     * @var wpdb
     */
    private $db;

    /**
     * Constructor.
     *
     * @param wpdb|null $db Database connection. Default the global $wpdb.
     *                      Tests pass independent connections to race claims.
     */
    public function __construct( $db = null ) {
        if ( null === $db ) {
            global $wpdb;
            $db = $wpdb;
        }
        $this->db = $db;
    }

    /**
     * Keep the count in step with saved forms (DB-stored forms only).
     *
     * Saving a form WITH a cap seeds the count if the form has none yet — the
     * moment "a cap is first added". Saving it WITHOUT a cap leaves any count
     * alone (Q-010). Deleting the form removes the count.
     *
     * @return void
     */
    public static function init_hooks() {
        add_action( 'pforms_form_saved', array( __CLASS__, 'on_form_saved' ), 10, 2 );
        add_action( 'pforms_form_deleted', array( __CLASS__, 'on_form_deleted' ), 10, 1 );
    }

    /**
     * `pforms_form_saved` listener.
     *
     * @param string $form_id Form ID.
     * @param array  $record  Saved form record (config is a JSON string).
     * @return void
     */
    public static function on_form_saved( $form_id, $record ) {
        $config = is_array( $record ) && isset( $record['config'] ) ? json_decode( (string) $record['config'], true ) : null;
        $cap    = is_array( $config ) && isset( $config['settings']['availability']['max_entries'] )
            ? $config['settings']['availability']['max_entries']
            : null;

        // No cap: nothing to do. An existing count is kept and keeps counting
        // (claim() with a null cap), so restoring the cap resumes from it.
        if ( is_int( $cap ) ) {
            ( new self() )->seed( $form_id );
        }
    }

    /**
     * `pforms_form_deleted` listener.
     *
     * @param string $form_id Form ID.
     * @return void
     */
    public static function on_form_deleted( $form_id ) {
        ( new self() )->delete( $form_id );
    }

    /**
     * Count one accepted submission. Atomic.
     *
     * Capped form: takes a place if one is left — one conditional UPDATE, so
     * of N racing calls for the last place exactly one succeeds.
     *
     * Uncapped form (null cap): always succeeds. If the form has a count (it
     * has had a cap), the count goes up by one, unconditionally, so a cap put
     * back later resumes exactly (Q-010). If it has none, nothing is created.
     * Either way this is ONE statement — a single-row UPDATE by option_name's
     * unique key, no read — which on a never-capped form changes 0 rows.
     *
     * @param string   $form_id Form ID.
     * @param int|null $cap     The form's max_entries; null = unlimited.
     * @return bool True if the submission may be accepted.
     */
    public function claim( $form_id, $cap ) {
        if ( null === $cap ) {
            $this->db->query( $this->db->prepare(
                "UPDATE {$this->db->options}
                    SET option_value = CAST( option_value AS UNSIGNED ) + 1
                  WHERE option_name = %s",
                self::option_name( $form_id )
            ) );

            return true;
        }

        return false !== $this->take( $form_id, $cap );
    }

    /**
     * Take a place on a capped form and report the count this claim made.
     *
     * The same atomic claim as claim() with a cap, but it answers "which
     * place did I get?" as well as "did I get one?" — so the submission that
     * takes the LAST place (the returned count equals the cap) is known
     * exactly, even when other requests claim in between (P3's
     * `pforms_form_full` action). The number comes from the claim's own
     * UPDATE through MySQL's per-connection LAST_INSERT_ID(expr), never from
     * a separate read that another request's claim could change.
     *
     * @param string $form_id Form ID.
     * @param int    $cap     The form's max_entries.
     * @return int|false The count after this claim, or false if refused.
     */
    public function take( $form_id, $cap ) {
        $cap = (int) $cap;
        if ( $cap <= 0 ) {
            return false;
        }

        $taken = $this->increment_below( $form_id, $cap );
        if ( false !== $taken ) {
            return $taken;
        }

        // 0 rows: either the form is full, or it has no count row yet (a
        // PHP-registered form, or one capped before this code). Create the
        // row from the stored entries — INSERT IGNORE, so a racing request
        // that created it first wins and nothing is counted twice — and try
        // the same atomic increment once more.
        if ( null === $this->read( $form_id ) ) {
            $this->seed_if_absent( $form_id );
            return $this->increment_below( $form_id, $cap );
        }

        return false;
    }

    /**
     * Give one place back (e.g. the claim's submission then failed to save).
     * Works capped or not; does nothing on a form with no count. Never goes
     * below 0. Atomic.
     *
     * @param string $form_id Form ID.
     * @return bool True if a place was returned.
     */
    public function release( $form_id ) {
        $changed = $this->db->query( $this->db->prepare(
            "UPDATE {$this->db->options}
                SET option_value = CAST( option_value AS UNSIGNED ) - 1
              WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) > 0",
            self::option_name( $form_id )
        ) );

        return 1 === (int) $changed;
    }

    /**
     * Places taken.
     *
     * A form with no count row yet reports what its count would start at:
     * the non-spam entries stored for it now. Reading never writes.
     *
     * @param string $form_id Form ID.
     * @return int
     */
    public function get( $form_id ) {
        $stored = $this->read( $form_id );

        return null === $stored ? $this->count_accepted_entries( $form_id ) : $stored;
    }

    /**
     * Whether the form has a count row.
     *
     * @param string $form_id Form ID.
     * @return bool
     */
    public function exists( $form_id ) {
        return null !== $this->read( $form_id );
    }

    /**
     * Set the count to an exact number (the owner's adjust / reset control).
     *
     * @param string $form_id Form ID.
     * @param int    $count   Places taken, >= 0.
     * @return bool
     */
    public function set( $form_id, $count ) {
        $result = $this->db->query( $this->db->prepare(
            "INSERT INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            self::option_name( $form_id ),
            max( 0, (int) $count )
        ) );

        return false !== $result;
    }

    /**
     * Start the count when a cap is first added: the number of non-spam
     * entries stored for the form at this moment. Leaves an existing count
     * alone (changing a cap from 30 to 40 keeps the places already taken).
     *
     * @param string $form_id Form ID.
     * @return int The count after seeding.
     */
    public function seed( $form_id ) {
        $this->seed_if_absent( $form_id );

        return $this->get( $form_id );
    }

    /**
     * Remove the form's count row (the form was deleted).
     *
     * @param string $form_id Form ID.
     * @return bool
     */
    public function delete( $form_id ) {
        $result = $this->db->query( $this->db->prepare(
            "DELETE FROM {$this->db->options} WHERE option_name = %s",
            self::option_name( $form_id )
        ) );

        return false !== $result;
    }

    /**
     * Name of a form's count row.
     *
     * @param string $form_id Form ID.
     * @return string
     */
    public static function option_name( $form_id ) {
        $form_id = (string) $form_id;
        if ( strlen( $form_id ) > self::MAX_ID_LENGTH ) {
            $form_id = md5( $form_id );
        }

        return self::OPTION_PREFIX . $form_id;
    }

    /**
     * The atomic check-and-claim.
     *
     * LAST_INSERT_ID(expr) both writes the new count and remembers it for
     * this connection only, so the follow-up SELECT reports this statement's
     * result, not a later claim's. It is read only when the UPDATE changed
     * the row (a 0-row UPDATE leaves an older value behind).
     *
     * @param string $form_id Form ID.
     * @param int    $cap     Cap, > 0.
     * @return int|false The count after this statement took a place, or false.
     */
    private function increment_below( $form_id, $cap ) {
        $changed = $this->db->query( $this->db->prepare(
            "UPDATE {$this->db->options}
                SET option_value = LAST_INSERT_ID( CAST( option_value AS UNSIGNED ) + 1 )
              WHERE option_name = %s AND CAST( option_value AS UNSIGNED ) < %d",
            self::option_name( $form_id ),
            $cap
        ) );

        if ( 1 !== (int) $changed ) {
            return false;
        }

        return (int) $this->db->get_var( 'SELECT LAST_INSERT_ID()' );
    }

    /**
     * Create the count row from the stored non-spam entries, unless it exists.
     *
     * @param string $form_id Form ID.
     * @return void
     */
    private function seed_if_absent( $form_id ) {
        $this->db->query( $this->db->prepare(
            "INSERT IGNORE INTO {$this->db->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')",
            self::option_name( $form_id ),
            $this->count_accepted_entries( $form_id )
        ) );
    }

    /**
     * Stored count, or null when the form has no row.
     *
     * @param string $form_id Form ID.
     * @return int|null
     */
    private function read( $form_id ) {
        $value = $this->db->get_var( $this->db->prepare(
            "SELECT option_value FROM {$this->db->options} WHERE option_name = %s",
            self::option_name( $form_id )
        ) );

        return null === $value ? null : max( 0, (int) $value );
    }

    /**
     * Non-spam entries stored for a form (the seed value).
     *
     * @param string $form_id Form ID.
     * @return int
     */
    private function count_accepted_entries( $form_id ) {
        $table = $this->db->prefix . 'fre_entries';

        // Entries store sanitize_key()'d form IDs (PForms_Entry::create()).
        $count = $this->db->get_var( $this->db->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE form_id = %s AND is_spam = 0",
            sanitize_key( $form_id )
        ) );

        return max( 0, (int) $count );
    }
}
