<?php
/**
 * Availability against real WordPress timezone handling.
 *
 * The Unit suite stubs wp_timezone(); this pins the same flips with core's
 * own wp_timezone() / wp_date() reading the real timezone_string option.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * PForms_Availability with real site-timezone settings.
 */
class FormAvailabilityTimezoneTest extends IntegrationTestCase {

    public function set_up() {
        parent::set_up();
        update_option( 'timezone_string', 'Pacific/Auckland' );
        update_option( 'date_format', 'F j, Y' );
        update_option( 'time_format', 'g:i a' );
    }

    private function utc( $utc ) {
        return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    private function register( array $availability ) {
        $this->assertTrue( pforms_register_form( 'tz-form', array(
            'fields'   => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
            'settings' => array( 'availability' => $availability ),
        ) ) );
    }

    public function test_flips_at_local_minutes_with_real_wp_timezone() {
        // 09:00 / 17:00 NZDT (UTC+13) = 20:00 on the 14th / 04:00 on the 20th UTC.
        $this->register( array( 'opens_at' => '2027-03-15 09:00', 'closes_at' => '2027-03-20 17:00' ) );

        $at = function ( $utc ) {
            return \PForms_Availability::check( 'tz-form', array( 'now' => $this->utc( $utc ), 'count' => 0 ) );
        };

        $this->assertSame( 'not_yet_open', $at( '2027-03-14 19:59:00' )['reason'] );
        $this->assertTrue( $at( '2027-03-14 20:00:00' )['open'] );
        $this->assertTrue( $at( '2027-03-14 20:01:00' )['open'] );
        $this->assertTrue( $at( '2027-03-20 03:59:00' )['open'] );
        $this->assertSame( 'closed_date', $at( '2027-03-20 04:00:00' )['reason'] );
        $this->assertSame( 'closed_date', $at( '2027-03-20 04:01:00' )['reason'] );

        // Prefix check (Q-011): the timezone label is pinned by P4's tests.
        $this->assertStringStartsWith(
            'This form opens on March 15, 2027 at 9:00 am',
            $at( '2027-03-01 00:00:00' )['message']
        );
    }

    public function test_registered_form_without_availability_is_open() {
        $this->assertTrue( pforms_register_form( 'plain', array(
            'fields' => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
        ) ) );

        $result = \PForms_Availability::check( 'plain', array( 'count' => 100000 ) );

        $this->assertTrue( $result['open'] );
        $this->assertSame( 'open', $result['reason'] );
    }
}
