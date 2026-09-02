<?php
/**
 * Mock WP_Error class for unit testing.
 *
 * @package FormRuntimeEngine\Tests\Unit\Mocks
 */

if ( class_exists( 'WP_Error' ) ) {
    return;
}

/**
 * Minimal WP_Error mock for unit testing.
 */
class WP_Error {

    /**
     * Error codes and messages.
     *
     * @var array
     */
    private $errors = array();

    /**
     * Error data.
     *
     * @var array
     */
    private $error_data = array();

    /**
     * Constructor.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     * @param mixed  $data    Error data.
     */
    public function __construct( $code = '', $message = '', $data = '' ) {
        if ( ! empty( $code ) ) {
            $this->add( $code, $message, $data );
        }
    }

    /**
     * Add an error.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     * @param mixed  $data    Error data.
     */
    public function add( $code, $message, $data = '' ) {
        $this->errors[ $code ][] = $message;
        if ( ! empty( $data ) ) {
            $this->error_data[ $code ] = $data;
        }
    }

    /**
     * Add error data.
     *
     * @param mixed  $data Error data.
     * @param string $code Error code.
     */
    public function add_data( $data, $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        $this->error_data[ $code ] = $data;
    }

    /**
     * Get first error code.
     *
     * @return string|int
     */
    public function get_error_code() {
        $codes = array_keys( $this->errors );
        return ! empty( $codes ) ? $codes[0] : '';
    }

    /**
     * Get all error codes.
     *
     * @return array
     */
    public function get_error_codes() {
        return array_keys( $this->errors );
    }

    /**
     * Get first error message.
     *
     * @param string $code Error code.
     * @return string
     */
    public function get_error_message( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
    }

    /**
     * Get all error messages.
     *
     * @param string $code Error code.
     * @return array
     */
    public function get_error_messages( $code = '' ) {
        if ( empty( $code ) ) {
            $all = array();
            foreach ( $this->errors as $messages ) {
                $all = array_merge( $all, $messages );
            }
            return $all;
        }
        return isset( $this->errors[ $code ] ) ? $this->errors[ $code ] : array();
    }

    /**
     * Get error data.
     *
     * @param string $code Error code.
     * @return mixed
     */
    public function get_error_data( $code = '' ) {
        if ( empty( $code ) ) {
            $code = $this->get_error_code();
        }
        return isset( $this->error_data[ $code ] ) ? $this->error_data[ $code ] : null;
    }

    /**
     * Check if there are any errors.
     *
     * @return bool
     */
    public function has_errors() {
        return ! empty( $this->errors );
    }
}

/**
 * Check if a variable is a WP_Error.
 *
 * @param mixed $thing Variable to check.
 * @return bool
 */
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}
