<?php
/**
 * FCRM_WP_Sync_Engine
 *
 * Handles the actual bidirectional synchronisation between WordPress users
 * and FluentCRM contacts.  Uses a re-entrancy guard to prevent infinite
 * loops when each side's "updated" hook fires the other side's sync.
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class FCRM_WP_Sync_Engine {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var FCRM_WP_Sync_Field_Mapper */
    private FCRM_WP_Sync_Field_Mapper $mapper;

    /** Re-entrancy guard: true while a sync is in progress. */
    private bool $syncing = false;

    // -----------------------------------------------------------------------
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->mapper = new FCRM_WP_Sync_Field_Mapper();
        $this->register_hooks();
    }

    // -----------------------------------------------------------------------
    // Hook registration
    // -----------------------------------------------------------------------

    private function register_hooks(): void {
        $settings = get_option( 'fcrm_wp_sync_settings', [] );

        if ( ! empty( $settings['sync_on_user_register'] ) ) {
            add_action( 'user_register',  [ $this, 'on_user_register' ], 20 );
        }
        if ( ! empty( $settings['sync_on_profile_update'] ) ) {
            add_action( 'profile_update', [ $this, 'on_profile_update' ], 20 );
            // Also catches programmatic updates via wp_update_user()
            add_action( 'updated_user_meta', [ $this, 'on_user_meta_updated' ], 20, 4 );
        }
        if ( ! empty( $settings['sync_on_user_delete'] ) ) {
            add_action( 'delete_user',    [ $this, 'on_user_delete' ], 10 );
        }
        if ( ! empty( $settings['sync_on_fcrm_update'] ) ) {
            add_action( 'fluent_crm/contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
            add_action( 'fluent_crm/contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
        }
    }

    // -----------------------------------------------------------------------
    // WordPress hook callbacks
    // -----------------------------------------------------------------------

    public function on_user_register( int $user_id ): void {
        if ( $this->syncing ) {
            return;
        }
        $this->sync_wp_to_fcrm( $user_id );
    }

    public function on_profile_update( int $user_id ): void {
        if ( $this->syncing ) {
            return;
        }
        $this->sync_wp_to_fcrm( $user_id );
    }

    /**
     * Triggered by updated_user_meta; debounce to avoid firing once per meta key.
     * We schedule a single sync via shutdown action.
     */
    public function on_user_meta_updated( int $meta_id, int $user_id, string $meta_key, $meta_value ): void {
        if ( $this->syncing ) {
            return;
        }
        // Only respond to meta keys we actually have mapped
        $mapped_meta_keys = $this->get_mapped_wp_meta_keys();
        if ( ! in_array( $meta_key, $mapped_meta_keys, true ) ) {
            return;
        }
        // Use a one-time shutdown action to batch multiple meta updates
        static $scheduled = [];
        if ( empty( $scheduled[ $user_id ] ) ) {
            $scheduled[ $user_id ] = true;
            add_action( 'shutdown', function () use ( $user_id ) {
                if ( ! $this->syncing ) {
                    $this->sync_wp_to_fcrm( $user_id );
                }
            } );
        }
    }

    public function on_user_delete( int $user_id ): void {
        $subscriber = Subscriber::where( 'user_id', $user_id )->first();
        if ( $subscriber ) {
            // Unlink rather than delete the contact — preserves marketing history.
            $subscriber->user_id = null;
            $subscriber->save();
        }
    }

    // -----------------------------------------------------------------------
    // FluentCRM hook callbacks
    // -----------------------------------------------------------------------

    public function on_fcrm_contact_saved( Subscriber $subscriber ): void {
        if ( $this->syncing ) {
            return;
        }
        $this->sync_fcrm_to_wp( $subscriber );
    }

    // -----------------------------------------------------------------------
    // Core sync: WP → FluentCRM
    // -----------------------------------------------------------------------

    /**
     * Sync a WordPress user to their FluentCRM contact.
     *
     * @param int $user_id
     * @return Subscriber|WP_Error|null
     */
    public function sync_wp_to_fcrm( int $user_id ) {
        $this->syncing = true;
        try {
            $user_info = get_userdata( $user_id );
            if ( ! $user_info ) {
                return null;
            }

            $data          = [];
            $custom_values = [];
            $mappings      = $this->mapper->get_active_mappings();

            foreach ( $mappings as $mapping ) {
                if ( ! in_array( $mapping['sync_direction'], [ 'both', 'wp_to_fcrm' ], true ) ) {
                    continue;
                }

                $raw_value = $this->get_wp_field_value( $user_id, $user_info, $mapping );

                if ( $raw_value === null || $raw_value === '' ) {
                    continue;
                }

                $formatted = $this->format_value(
                    $raw_value,
                    $mapping['field_type'] ?? 'text',
                    'to_fcrm',
                    $mapping
                );

                $fcrm_key = $mapping['fcrm_field_key'];

                if ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' ) {
                    $custom_values[ $fcrm_key ] = $formatted;
                } else {
                    $data[ $fcrm_key ] = $formatted;
                }
            }

            if ( ! empty( $custom_values ) ) {
                $data['custom_values'] = $custom_values;
            }

            // Always ensure email is present
            if ( empty( $data['email'] ) ) {
                $data['email'] = $user_info->user_email;
            }

            $contact = FluentCrmApi( 'contacts' )->createOrUpdate( $data );

            // Link the subscriber to this WP user if not already linked
            if ( $contact && ! $contact->user_id ) {
                $contact->user_id = $user_id;
                $contact->save();
            }

            return $contact;

        } finally {
            $this->syncing = false;
        }
    }

    // -----------------------------------------------------------------------
    // Core sync: FluentCRM → WP
    // -----------------------------------------------------------------------

    /**
     * Sync a FluentCRM contact back to the linked WordPress user.
     *
     * @param Subscriber $subscriber
     */
    public function sync_fcrm_to_wp( Subscriber $subscriber ): void {
        $this->syncing = true;
        try {
            $user_id = $subscriber->user_id;
            if ( ! $user_id ) {
                return;
            }

            $mappings      = $this->mapper->get_active_mappings();
            $custom_fields = $subscriber->custom_fields();
            $wp_user_data  = []; // for wp_update_user()

            foreach ( $mappings as $mapping ) {
                if ( ! in_array( $mapping['sync_direction'], [ 'both', 'fcrm_to_wp' ], true ) ) {
                    continue;
                }

                $fcrm_key = $mapping['fcrm_field_key'];
                $source   = $mapping['fcrm_field_source'] ?? 'default';

                if ( $source === 'custom' ) {
                    $raw_value = $custom_fields[ $fcrm_key ] ?? null;
                } else {
                    $raw_value = $subscriber->{ $fcrm_key } ?? null;
                }

                if ( $raw_value === null || $raw_value === '' ) {
                    continue;
                }

                $formatted = $this->format_value(
                    $raw_value,
                    $mapping['field_type'] ?? 'text',
                    'to_wp',
                    $mapping
                );

                $this->set_wp_field_value( $user_id, $mapping, $formatted, $wp_user_data );
            }

            if ( ! empty( $wp_user_data ) ) {
                $wp_user_data['ID'] = $user_id;
                wp_update_user( $wp_user_data );
            }

        } finally {
            $this->syncing = false;
        }
    }

    // -----------------------------------------------------------------------
    // Field value getters / setters
    // -----------------------------------------------------------------------

    /**
     * Read a WP field value for a given mapping row.
     *
     * @param int      $user_id
     * @param \WP_User $user_info
     * @param array    $mapping
     * @return mixed
     */
    public function get_wp_field_value( int $user_id, \WP_User $user_info, array $mapping ) {
        $key    = $mapping['wp_field_key'];
        $source = $mapping['wp_field_source'] ?? 'user';

        switch ( $source ) {
            case 'user':
                return $user_info->{ $key } ?? null;

            case 'acf':
                if ( function_exists( 'get_field' ) ) {
                    return get_field( $key, 'user_' . $user_id );
                }
                // Fallback to user_meta
                return get_user_meta( $user_id, $key, true ) ?: null;

            case 'meta':
            default:
                $val = get_user_meta( $user_id, $key, true );
                return ( $val !== '' && $val !== false ) ? $val : null;
        }
    }

    /**
     * Write a WP field value for a given mapping row.
     *
     * @param int   $user_id
     * @param array $mapping
     * @param mixed $value
     * @param array &$wp_user_data  Accumulator for wp_update_user() fields
     */
    public function set_wp_field_value( int $user_id, array $mapping, $value, array &$wp_user_data ): void {
        $key    = $mapping['wp_field_key'];
        $source = $mapping['wp_field_source'] ?? 'user';

        // Fields that belong to the WP_User object go through wp_update_user()
        $user_object_keys = [ 'user_email', 'user_url', 'display_name' ];

        switch ( $source ) {
            case 'user':
                if ( in_array( $key, $user_object_keys, true ) ) {
                    $wp_user_data[ $key ] = $value;
                } else {
                    // first_name, last_name etc. live in usermeta too
                    update_user_meta( $user_id, $key, $value );
                }
                break;

            case 'acf':
                if ( function_exists( 'update_field' ) ) {
                    update_field( $key, $value, 'user_' . $user_id );
                } else {
                    update_user_meta( $user_id, $key, $value );
                }
                break;

            case 'meta':
            default:
                update_user_meta( $user_id, $key, $value );
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Value formatting
    // -----------------------------------------------------------------------

    /**
     * Format a raw value according to its field type and sync direction.
     *
     * @param mixed  $value
     * @param string $type       One of: text, date, checkbox, number, email, textarea
     * @param string $direction  'to_fcrm' | 'to_wp'
     * @param array  $mapping    The full mapping row (for date formats etc.)
     * @return mixed
     */
    public function format_value( $value, string $type, string $direction, array $mapping = [] ) {
        switch ( $type ) {
            case 'date':
                return $this->format_date( $value, $direction, $mapping );

            case 'checkbox':
                return $this->format_checkbox( $value, $direction );

            case 'number':
                return is_numeric( $value ) ? (float) $value : $value;

            case 'email':
                return sanitize_email( (string) $value );

            case 'textarea':
            case 'text':
            default:
                return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
        }
    }

    /**
     * Date format conversion.
     *
     * FluentCRM stores dates as Y-m-d.
     * ACF date pickers use the return_format defined on the field (default m/d/Y).
     * Non-ACF WP meta can be anything — we do best-effort strtotime().
     */
    private function format_date( $value, string $direction, array $mapping ): string {
        if ( empty( $value ) ) {
            return '';
        }

        // Convert to Unix timestamp first
        $ts = false;
        if ( is_numeric( $value ) && strlen( (string) $value ) === 8 ) {
            // Compact YYYYMMDD
            $ts = strtotime( substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 ) );
        } else {
            $ts = strtotime( (string) $value );
        }

        if ( $ts === false ) {
            return (string) $value; // give it back unchanged if unparseable
        }

        if ( $direction === 'to_fcrm' ) {
            return date( 'Y-m-d', $ts );
        }

        // to_wp: use the ACF return format if available, else Y-m-d
        $fmt = $mapping['date_format_wp'] ?? 'Y-m-d';
        return date( $fmt, $ts );
    }

    /**
     * Checkbox / multi-select conversion.
     *
     * FluentCRM stores checkbox values as JSON arrays.
     * ACF returns PHP arrays.
     */
    private function format_checkbox( $value, string $direction ) {
        if ( $direction === 'to_fcrm' ) {
            if ( is_array( $value ) ) {
                return wp_json_encode( $value );
            }
            if ( is_string( $value ) ) {
                $decoded = json_decode( $value, true );
                return wp_json_encode( $decoded !== null ? $decoded : ( $value !== '' ? [ $value ] : [] ) );
            }
            return wp_json_encode( [] );
        }

        // to_wp (ACF expects a plain PHP array)
        if ( is_array( $value ) ) {
            return $value;
        }
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( $decoded !== null ) {
                return (array) $decoded;
            }
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) ) {
                return $unserialized;
            }
            return $value !== '' ? [ $value ] : [];
        }
        return [];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the list of WP user_meta keys that appear in any active mapping.
     */
    private function get_mapped_wp_meta_keys(): array {
        $keys = [];
        foreach ( $this->mapper->get_active_mappings() as $m ) {
            if ( in_array( $m['wp_field_source'] ?? '', [ 'meta', 'acf' ], true ) ) {
                $keys[] = $m['wp_field_key'];
            }
        }
        return $keys;
    }

    /**
     * Direct access to the mapper (used by the REST API and admin pages).
     */
    public function get_mapper(): FCRM_WP_Sync_Field_Mapper {
        return $this->mapper;
    }

    /**
     * Whether the engine is currently mid-sync (used externally for debugging).
     */
    public function is_syncing(): bool {
        return $this->syncing;
    }
}
