<?php
/**
 * In-memory stand-in for $wpdb over the wp_options table, for unit tests of
 * PForms_Submission_Lock.
 *
 * It understands exactly the statements the lock issues and fails loudly on
 * anything else, so a change to the lock's SQL cannot silently pass here.
 * It reproduces the two MySQL behaviours the lock depends on:
 *
 *   - INSERT IGNORE on an existing option_name affects 0 rows;
 *   - an UPDATE that writes the value a row already holds reports 0 affected
 *     rows (MySQL counts CHANGED rows), which is why every claim carries a
 *     unique token.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit\Mocks;

/**
 * Minimal wp_options-only $wpdb.
 */
class FakeOptionsWpdb {

    /**
     * Table name, as $wpdb->options.
     *
     * @var string
     */
    public $options = 'wp_options';

    /**
     * Table prefix (PForms_Entry builds its table names from it).
     *
     * @var string
     */
    public $prefix = 'wp_';

    /**
     * option_name => option_value.
     *
     * @var array
     */
    public $rows = array();

    /**
     * Every statement executed, for assertions.
     *
     * @var string[]
     */
    public $log = array();

    /**
     * Substitute %s / %d like wpdb::prepare (quoting is enough for tests).
     *
     * @param string $query Query with placeholders.
     * @param mixed  ...$args Values.
     * @return string
     */
    public function prepare( $query, ...$args ) {
        $i = 0;
        return preg_replace_callback( '/%[sd]/', function ( $m ) use ( &$i, $args ) {
            $v = $args[ $i++ ];
            return '%d' === $m[0] ? (string) (int) $v : "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $v ) . "'";
        }, $query );
    }

    /**
     * Escape LIKE wildcards like wpdb::esc_like.
     *
     * @param string $text Text.
     * @return string
     */
    public function esc_like( $text ) {
        return addcslashes( $text, '_%\\' );
    }

    /**
     * Run a data-changing statement. Returns affected rows.
     *
     * @param string $sql SQL.
     * @return int
     */
    public function query( $sql ) {
        $this->log[] = $sql;
        $sql         = trim( preg_replace( '/\s+/', ' ', $sql ) );
        $s           = "'((?:[^'\\\\]|\\\\.)*)'";

        if ( preg_match( "/^INSERT IGNORE INTO wp_options \(option_name, option_value, autoload\) VALUES \($s, $s, 'no'\)$/", $sql, $m ) ) {
            $name = $this->unq( $m[1] );
            if ( array_key_exists( $name, $this->rows ) ) {
                return 0;
            }
            $this->rows[ $name ] = $this->unq( $m[2] );
            return 1;
        }

        if ( preg_match( "/^INSERT INTO wp_options \(option_name, option_value, autoload\) VALUES \($s, (\d+), 'no'\) ON DUPLICATE KEY UPDATE option_value = VALUES\(option_value\)$/", $sql, $m ) ) {
            $name                = $this->unq( $m[1] );
            $existed             = array_key_exists( $name, $this->rows );
            $changed             = ! $existed || $this->rows[ $name ] !== $m[2];
            $this->rows[ $name ] = $m[2];
            return $existed ? ( $changed ? 2 : 0 ) : 1;
        }

        if ( preg_match( "/^UPDATE wp_options SET option_value = $s WHERE option_name = $s AND option_value = $s$/", $sql, $m ) ) {
            return $this->update_row( $this->unq( $m[2] ), $this->unq( $m[1] ), $this->unq( $m[3] ) );
        }

        if ( preg_match( "/^UPDATE wp_options SET option_value = $s WHERE option_name = $s$/", $sql, $m ) ) {
            return $this->update_row( $this->unq( $m[2] ), $this->unq( $m[1] ), null );
        }

        if ( preg_match( "/^DELETE FROM wp_options WHERE option_name IN \($s, $s\)$/", $sql, $m ) ) {
            $n = 0;
            foreach ( array( $this->unq( $m[1] ), $this->unq( $m[2] ) ) as $name ) {
                if ( array_key_exists( $name, $this->rows ) ) {
                    unset( $this->rows[ $name ] );
                    $n++;
                }
            }
            return $n;
        }

        if ( preg_match( "/^DELETE a, b FROM wp_options a INNER JOIN wp_options b ON b.option_name = CONCAT\( '_transient_timeout_', SUBSTRING\( a.option_name, 12 \) \) WHERE a.option_name LIKE $s AND CAST\( b.option_value AS UNSIGNED \) < (\d+)$/", $sql, $m ) ) {
            $prefix = stripcslashes( rtrim( $this->unq( $m[1] ), '%' ) );
            $now    = (int) $m[2];
            $n      = 0;
            foreach ( array_keys( $this->rows ) as $name ) {
                if ( 0 !== strpos( $name, $prefix ) ) {
                    continue;
                }
                $timeout = '_transient_timeout_' . substr( $name, 11 );
                if ( isset( $this->rows[ $timeout ] ) && (int) $this->rows[ $timeout ] < $now ) {
                    unset( $this->rows[ $name ], $this->rows[ $timeout ] );
                    $n += 2;
                }
            }
            return $n;
        }

        throw new \RuntimeException( 'FakeOptionsWpdb: unexpected query: ' . $sql );
    }

    /**
     * Read a single value.
     *
     * @param string $sql SQL.
     * @return string|null
     */
    public function get_var( $sql ) {
        $this->log[] = $sql;
        $sql         = trim( preg_replace( '/\s+/', ' ', $sql ) );
        if ( preg_match( "/^SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)'$/", $sql, $m ) ) {
            $name = $this->unq( $m[1] );
            return array_key_exists( $name, $this->rows ) ? $this->rows[ $name ] : null;
        }
        throw new \RuntimeException( 'FakeOptionsWpdb: unexpected get_var: ' . $sql );
    }

    /**
     * MySQL-style update: counts only rows whose value actually changed.
     *
     * @param string      $name     Option name.
     * @param string      $value    New value.
     * @param string|null $expected Required current value (compare-and-swap), or null.
     * @return int
     */
    private function update_row( $name, $value, $expected ) {
        if ( ! array_key_exists( $name, $this->rows ) ) {
            return 0;
        }
        if ( null !== $expected && $this->rows[ $name ] !== $expected ) {
            return 0;
        }
        if ( $this->rows[ $name ] === $value ) {
            return 0;
        }
        $this->rows[ $name ] = $value;
        return 1;
    }

    /**
     * Undo prepare()'s escaping.
     *
     * @param string $v Escaped value.
     * @return string
     */
    private function unq( $v ) {
        return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $v );
    }
}
