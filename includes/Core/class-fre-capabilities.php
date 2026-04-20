<?php
/**
 * Capability management for Form Runtime Engine.
 *
 * Centralizes the plugin's custom capabilities so callers never hard-code
 * capability strings. All form and entry management checks go through the
 * MANAGE_FORMS constant.
 *
 * Capability model:
 *   - `fre_manage_forms`: Controls access to form management (CRUD of forms,
 *     viewing/managing entries, running the forms admin UI, operating the
 *     Cowork connector in Phase 2+).
 *
 * Granted to the `administrator` role by default on install and upgrade.
 * Admins can delegate to other roles via any standard role editor or a
 * one-line `add_cap()` filter; no dedicated UI is shipped in v1.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Capability helper.
 */
class FRE_Capabilities {

    /**
     * Primary capability for managing forms, entries, and the Cowork connector.
     *
     * Use `current_user_can( FRE_Capabilities::MANAGE_FORMS )` anywhere a check
     * is needed. Do not hard-code the string elsewhere.
     *
     * @var string
     */
    const MANAGE_FORMS = 'fre_manage_forms';

    /**
     * Roles that receive MANAGE_FORMS by default on install and upgrade.
     *
     * Extendable via the `fre_default_manage_forms_roles` filter so site owners
     * can opt additional roles in at activation time rather than granting the
     * capability manually after the fact.
     *
     * @return array Array of role slugs.
     */
    public static function default_roles() {
        /**
         * Filters the roles that receive the MANAGE_FORMS capability by default.
         *
         * Fires during activation and plugin-version upgrades. Does not fire on
         * every page load.
         *
         * @param array $roles Default roles (administrator only by default).
         */
        return apply_filters(
            'fre_default_manage_forms_roles',
            array( 'administrator' )
        );
    }

    /**
     * Grant the MANAGE_FORMS capability to the default roles.
     *
     * Idempotent: WordPress's `add_cap()` is safe to call repeatedly. Only
     * persists to the database when the capability is not already present
     * on the role.
     *
     * Called from:
     *   - FRE_Upgrader on fresh install
     *   - FRE_Upgrader on plugin-version upgrade
     *   - Plugin activation hook (belt-and-braces for update paths that do
     *     re-activate the plugin)
     */
    public static function grant_default_capabilities() {
        foreach ( self::default_roles() as $role_slug ) {
            $role = get_role( $role_slug );

            if ( null === $role ) {
                continue;
            }

            if ( ! $role->has_cap( self::MANAGE_FORMS ) ) {
                $role->add_cap( self::MANAGE_FORMS );
            }
        }
    }

    /**
     * Remove the MANAGE_FORMS capability from every role.
     *
     * Called only during plugin uninstall. Iterates all roles (not just the
     * default-granted ones) because admins may have delegated the capability
     * to custom roles; uninstall must clean up all traces.
     */
    public static function revoke_all_capabilities() {
        $roles = wp_roles();

        if ( ! $roles instanceof WP_Roles ) {
            return;
        }

        foreach ( array_keys( $roles->role_objects ) as $role_slug ) {
            $role = get_role( $role_slug );

            if ( null === $role ) {
                continue;
            }

            if ( $role->has_cap( self::MANAGE_FORMS ) ) {
                $role->remove_cap( self::MANAGE_FORMS );
            }
        }
    }

    /**
     * Convenience check for the current user.
     *
     * Use sparingly — most call sites should call `current_user_can()` directly
     * so the capability string appears in a WordPress-standard pattern. This
     * helper exists for code paths that test the capability multiple times.
     *
     * @return bool True if the current user has MANAGE_FORMS.
     */
    public static function current_user_can_manage_forms() {
        return current_user_can( self::MANAGE_FORMS );
    }
}
