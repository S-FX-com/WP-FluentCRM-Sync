<?php
/**
 * FCRM_WP_Sync_Field_Mapper
 *
 * Discovers available fields on both sides (WordPress + FluentCRM) and
 * manages the saved field-mapping configuration.
 *
 * Mapping record shape stored in wp_options:
 * [
 *   'id'               => 'map_abc123',
 *   'wp_field_key'     => 'first_name',
 *   'wp_field_source'  => 'user' | 'meta' | 'acf',
 *   'wp_field_label'   => 'First Name',
 *   'fcrm_field_key'   => 'first_name',
 *   'fcrm_field_source'=> 'default' | 'custom',
 *   'fcrm_field_label' => 'First Name',
 *   'field_type'       => 'text' | 'date' | 'checkbox' | 'number' | 'email' | 'textarea',
 *   'sync_direction'   => 'both' | 'wp_to_fcrm' | 'fcrm_to_wp',
 *   'enabled'          => true,
 *   'date_format_wp'   => 'm/d/Y',   // ACF return format for date pickers
 *   'date_format_fcrm' => 'Y-m-d',   // FluentCRM always uses Y-m-d
 * ]
 */

defined( 'ABSPATH' ) || exit;

class FCRM_WP_Sync_Field_Mapper {

    // -----------------------------------------------------------------------
    // WordPress side
    // -----------------------------------------------------------------------

    /**
     * Well-known WP_User object properties (not in user_meta).
     */
    private static array $wp_user_object_fields = [
        'user_login'        => 'Username (user_login)',
        'user_email'        => 'Email (user_email)',
        'user_url'          => 'Website (user_url)',
        'display_name'      => 'Display Name',
        'user_registered'   => 'Registration Date (user_registered)',
    ];

    /**
     * Well-known core user_meta keys (saved via wp_update_user / usermeta).
     */
    private static array $wp_core_meta_fields = [
        'first_name'        => 'First Name',
        'last_name'         => 'Last Name',
        'nickname'          => 'Nickname',
        'description'       => 'Biographical Info',
    ];

    /**
     * Returns all WP fields: object props + core meta + ACF user fields +
     * any additional user_meta keys discovered in the DB.
     *
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_wp_fields(): array {
        $fields = [];

        // 1. WP_User object properties
        foreach ( self::$wp_user_object_fields as $key => $label ) {
            $fields[ 'user__' . $key ] = [
                'key'    => $key,
                'source' => 'user',
                'label'  => $label,
                'type'   => $key === 'user_registered' ? 'date' : 'text',
            ];
        }

        // 2. Core user_meta
        foreach ( self::$wp_core_meta_fields as $key => $label ) {
            $fields[ 'meta__' . $key ] = [
                'key'    => $key,
                'source' => 'meta',
                'label'  => $label . ' (user_meta)',
                'type'   => 'text',
            ];
        }

        // 3. ACF user fields (if ACF is active)
        if ( function_exists( 'acf_get_field_groups' ) ) {
            $acf_fields = $this->get_acf_user_fields();
            foreach ( $acf_fields as $f ) {
                $uid = 'acf__' . $f['key'];
                if ( ! isset( $fields[ $uid ] ) ) {
                    $fields[ $uid ] = $f;
                }
            }
        }

        // 4. Extra user_meta keys found in DB (excluding ACF internal keys)
        $db_meta_keys = $this->get_db_user_meta_keys();
        foreach ( $db_meta_keys as $meta_key ) {
            $uid = 'meta__' . $meta_key;
            if ( ! isset( $fields[ $uid ] ) ) {
                $fields[ $uid ] = [
                    'key'    => $meta_key,
                    'source' => 'meta',
                    'label'  => $meta_key . ' (user_meta)',
                    'type'   => 'text',
                ];
            }
        }

        return $fields;
    }

    /**
     * Get ACF field definitions scoped to the user form.
     */
    private function get_acf_user_fields(): array {
        $result = [];
        $groups = acf_get_field_groups( [ 'user_form' => 'all' ] );
        foreach ( $groups as $group ) {
            $acf_fields = acf_get_fields( $group );
            if ( ! is_array( $acf_fields ) ) {
                continue;
            }
            foreach ( $acf_fields as $field ) {
                $result[] = [
                    'key'    => $field['name'],
                    'source' => 'acf',
                    'label'  => $field['label'] . ' (ACF)',
                    'type'   => $this->map_acf_type_to_sync_type( $field['type'] ),
                    'acf_key'        => $field['key'],
                    'acf_field_type' => $field['type'],
                    // Store ACF date return format when applicable
                    'date_format_wp' => $field['return_format'] ?? 'm/d/Y',
                ];
            }
        }
        return $result;
    }

    /**
     * Map ACF field type to our internal sync type.
     */
    private function map_acf_type_to_sync_type( string $acf_type ): string {
        $map = [
            'date_picker'      => 'date',
            'date_time_picker' => 'date',
            'time_picker'      => 'text',
            'checkbox'         => 'checkbox',
            'radio'            => 'text',
            'select'           => 'text',
            'number'           => 'number',
            'email'            => 'email',
            'textarea'         => 'textarea',
            'wysiwyg'          => 'textarea',
            'url'              => 'text',
        ];
        return $map[ $acf_type ] ?? 'text';
    }

    /**
     * Fetch distinct meta_key values from usermeta, excluding internal WP/ACF keys.
     */
    private function get_db_user_meta_keys(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $keys = $wpdb->get_col(
            "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}
             WHERE meta_key NOT LIKE '\_%'
             AND meta_key NOT LIKE 'session_tokens'
             AND meta_key NOT LIKE 'community-events-location'
             ORDER BY meta_key
             LIMIT 300"
        );

        // Filter out ACF internal key prefixes and other WP internals
        return array_filter( $keys, function ( $k ) {
            if ( strpos( $k, 'field_' ) === 0 ) {
                return false; // ACF field keys
            }
            $skip = [
                'wp_capabilities', 'wp_user_level', 'wp_user-settings',
                'wp_user-settings-time', 'dismissed_wp_pointers',
                'show_admin_bar_front', 'show_welcome_panel',
                'managenav-menuscolumnshidden', 'metaboxhidden_',
                'closedpostboxes_', 'wp_dashboard_quick_press_last_post_id',
            ];
            foreach ( $skip as $prefix ) {
                if ( strpos( $k, $prefix ) === 0 ) {
                    return false;
                }
            }
            return true;
        } );
    }

    // -----------------------------------------------------------------------
    // FluentCRM side
    // -----------------------------------------------------------------------

    /**
     * FluentCRM default subscriber fields.
     */
    private static array $fcrm_default_fields = [
        'prefix'        => [ 'label' => 'Prefix',        'type' => 'text' ],
        'first_name'    => [ 'label' => 'First Name',    'type' => 'text' ],
        'last_name'     => [ 'label' => 'Last Name',     'type' => 'text' ],
        'email'         => [ 'label' => 'Email',         'type' => 'email' ],
        'phone'         => [ 'label' => 'Phone',         'type' => 'text' ],
        'address_line_1'=> [ 'label' => 'Address Line 1','type' => 'text' ],
        'address_line_2'=> [ 'label' => 'Address Line 2','type' => 'text' ],
        'city'          => [ 'label' => 'City',          'type' => 'text' ],
        'state'         => [ 'label' => 'State',         'type' => 'text' ],
        'postal_code'   => [ 'label' => 'Postal Code',   'type' => 'text' ],
        'country'       => [ 'label' => 'Country',       'type' => 'text' ],
        'date_of_birth' => [ 'label' => 'Date of Birth', 'type' => 'date' ],
        'gender'        => [ 'label' => 'Gender',        'type' => 'text' ],
    ];

    /**
     * Returns all FluentCRM fields: defaults + custom fields.
     *
     * @return array<string, array{key:string, source:string, label:string, type:string}>
     */
    public function get_fcrm_fields(): array {
        $fields = [];

        // 1. Default fields
        foreach ( self::$fcrm_default_fields as $key => $def ) {
            $fields[ 'default__' . $key ] = [
                'key'    => $key,
                'source' => 'default',
                'label'  => $def['label'],
                'type'   => $def['type'],
            ];
        }

        // 2. Custom fields from FluentCRM options
        $custom_field_defs = fluentcrm_get_option( 'contact_custom_fields', [] );
        if ( is_array( $custom_field_defs ) ) {
            foreach ( $custom_field_defs as $cf ) {
                if ( empty( $cf['slug'] ) ) {
                    continue;
                }
                $fields[ 'custom__' . $cf['slug'] ] = [
                    'key'     => $cf['slug'],
                    'source'  => 'custom',
                    'label'   => ( $cf['label'] ?? $cf['slug'] ) . ' (custom)',
                    'type'    => $this->map_fcrm_type_to_sync_type( $cf['type'] ?? 'text' ),
                    'options' => $cf['options'] ?? [],
                ];
            }
        }

        return $fields;
    }

    /**
     * Map FluentCRM field type to our internal sync type.
     */
    private function map_fcrm_type_to_sync_type( string $fcrm_type ): string {
        $map = [
            'date'      => 'date',
            'date_time' => 'date',
            'number'    => 'number',
            'checkbox'  => 'checkbox',
            'select'    => 'text',
            'radio'     => 'text',
            'textarea'  => 'textarea',
        ];
        return $map[ $fcrm_type ] ?? 'text';
    }

    // -----------------------------------------------------------------------
    // Saved mappings CRUD
    // -----------------------------------------------------------------------

    /**
     * Returns the array of saved field mappings.
     *
     * @return array<int, array>
     */
    public function get_saved_mappings(): array {
        $raw = get_option( 'fcrm_wp_sync_field_mappings', [] );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Replaces the saved mappings with a new array.
     *
     * @param array $mappings
     */
    public function save_mappings( array $mappings ): void {
        update_option( 'fcrm_wp_sync_field_mappings', $mappings );
    }

    /**
     * Returns only the enabled mappings.
     */
    public function get_active_mappings(): array {
        return array_filter( $this->get_saved_mappings(), fn( $m ) => ! empty( $m['enabled'] ) );
    }

    /**
     * Build a unique mapping ID string.
     */
    public static function generate_id(): string {
        return 'map_' . wp_generate_password( 8, false );
    }
}
