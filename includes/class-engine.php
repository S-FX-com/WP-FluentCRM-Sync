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

    /**
     * Re-entrancy guards — kept separate so that a WP→FCRM sync does not
     * suppress the FCRM→WP hook that FluentCRM fires synchronously during
     * createOrUpdate(), and vice-versa.
     */
    private bool $syncing_to_fcrm = false;
    private bool $syncing_to_wp   = false;

    /**
     * Allow the mismatch resolver (or other callers) to activate / deactivate
     * the re-entrancy guards from outside the normal sync methods.
     */
    public function set_syncing_to_fcrm( bool $state ): void {
        $this->syncing_to_fcrm = $state;
    }

    public function set_syncing_to_wp( bool $state ): void {
        $this->syncing_to_wp = $state;
    }

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
            // New-style hooks (FluentCRM 2.x+)
            add_action( 'fluent_crm/contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
            add_action( 'fluent_crm/contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
            // Legacy hooks fired by the FluentCRM UI in older versions
            add_action( 'fluentcrm_contact_created', [ $this, 'on_fcrm_contact_saved' ], 20 );
            add_action( 'fluentcrm_contact_updated', [ $this, 'on_fcrm_contact_saved' ], 20 );
        }
    }

    // -----------------------------------------------------------------------
    // WordPress hook callbacks
    // -----------------------------------------------------------------------

    public function on_user_register( int $user_id ): void {
        if ( $this->syncing_to_wp ) {
            return;
        }
        $this->sync_wp_to_fcrm( $user_id );
    }

    public function on_profile_update( int $user_id ): void {
        if ( $this->syncing_to_wp ) {
            return;
        }
        $this->sync_wp_to_fcrm( $user_id );
    }

    /**
     * Triggered by updated_user_meta; debounce to avoid firing once per meta key.
     * We schedule a single sync via shutdown action.
     */
    public function on_user_meta_updated( int $meta_id, int $user_id, string $meta_key, $meta_value ): void {
        if ( $this->syncing_to_wp ) {
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
                if ( ! $this->syncing_to_wp ) {
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
        if ( $this->syncing_to_fcrm ) {
            return;
        }
        // Deduplicate: multiple hooks (legacy + new) may fire for the same save.
        static $processed = [];
        if ( ! empty( $processed[ $subscriber->id ] ) ) {
            return;
        }
        $processed[ $subscriber->id ] = true;
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
    public function sync_wp_to_fcrm( int $user_id, array $field_ids = [] ) {
        $this->syncing_to_fcrm = true;
        try {
            $user_info = get_userdata( $user_id );
            if ( ! $user_info ) {
                return null;
            }

            // Find the subscriber that is actually linked to this WP user so we
            // can use their FluentCRM email as the createOrUpdate() lookup key.
            // Without this, when the WP email differs from the FCRM subscriber's
            // email, createOrUpdate() fails to find the existing contact and
            // creates a duplicate instead of updating the correct record.
            $existing_sub = Subscriber::where( 'user_id', $user_id )->first();
            if ( ! ( $existing_sub instanceof Subscriber ) ) {
                $existing_sub = Subscriber::where( 'email', $user_info->user_email )->first();
            }

            $data          = [];
            $custom_values = [];
            $mappings      = $this->mapper->get_active_mappings();

            if ( ! empty( $field_ids ) ) {
                $mappings = array_filter( $mappings, fn( $m ) => in_array( $m['id'] ?? '', $field_ids, true ) );
            }

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

            // Resolve the lookup email for createOrUpdate().
            // Always use the subscriber's existing FCRM email as the key so the
            // correct contact is found.  If the email field itself is being synced
            // to a new value, update the subscriber's email directly first so that
            // the subsequent createOrUpdate() (keyed on the new email) still finds
            // the right record rather than creating a duplicate.
            $intended_email = null;
            if ( $existing_sub instanceof Subscriber ) {
                $mapped_email = $data['email'] ?? null;
                if ( $mapped_email && $mapped_email !== $existing_sub->email ) {
                    // Only update the email if no other subscriber already owns
                    // the target address — changing it would violate the UNIQUE
                    // constraint and throw a SQL duplicate-entry error.
                    $conflict = Subscriber::where( 'email', $mapped_email )
                        ->where( 'id', '!=', $existing_sub->id )
                        ->first();
                    if ( $conflict instanceof Subscriber ) {
                        // Conflict — keep the FCRM email as the lookup key and
                        // drop the new email from the update payload.
                        $data['email'] = $existing_sub->email;
                    } else {
                        // Safe to move the email — update the model first so
                        // createOrUpdate() can find the contact by the new address.
                        $existing_sub->email = $mapped_email;
                        $existing_sub->save();
                        // $data['email'] already holds $mapped_email → lookup works.
                    }
                } elseif ( empty( $data['email'] ) ) {
                    $data['email'] = $existing_sub->email;
                }
            } elseif ( empty( $data['email'] ) ) {
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
            $this->syncing_to_fcrm = false;
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
    public function sync_fcrm_to_wp( Subscriber $subscriber, array $field_ids = [] ): void {
        $this->syncing_to_wp = true;
        try {
            $user_id = $subscriber->user_id;
            if ( ! $user_id ) {
                return;
            }

            $mappings = $this->mapper->get_active_mappings();

            if ( ! empty( $field_ids ) ) {
                $mappings = array_filter( $mappings, fn( $m ) => in_array( $m['id'] ?? '', $field_ids, true ) );
            }
            $custom_fields = $subscriber->custom_fields();
            $wp_user_data  = [];

            foreach ( $mappings as $mapping ) {
                if ( ! in_array( $mapping['sync_direction'] ?? '', [ 'both', 'fcrm_to_wp' ], true ) ) {
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
            $this->syncing_to_wp = false;
        }
    }

    // -----------------------------------------------------------------------
    // Preview: read current values for all active mappings for one user
    // -----------------------------------------------------------------------

    /**
     * Return both WP-side and FluentCRM-side values for every active mapping
     * for the given WordPress user.  Used by the Sample Data Preview feature.
     *
     * @param int $user_id
     * @return array[]  [ [ 'id', 'wp_label', 'fcrm_label', 'direction', 'wp_value', 'fcrm_value', 'match' ], … ]
     */
    public function get_field_values_for_user( int $user_id ): array {
        $user_info = get_userdata( $user_id );
        if ( ! $user_info ) {
            return [];
        }

        $mappings = $this->mapper->get_active_mappings();
        if ( empty( $mappings ) ) {
            return [];
        }

        // Try to find a linked FluentCRM contact.
        $contact       = null;
        $custom_fields = [];
        if ( class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            $found = \FluentCrm\App\Models\Subscriber::where( 'user_id', $user_id )->first();
            if ( $found instanceof \FluentCrm\App\Models\Subscriber ) {
                $contact       = $found;
                $custom_fields = $contact->custom_fields() ?: [];
            }
        }

        $rows = [];
        foreach ( $mappings as $mapping ) {
            // WP side
            $wp_raw = $this->get_wp_field_value( $user_id, $user_info, $mapping );

            // FluentCRM side
            $fcrm_raw = null;
            if ( $contact ) {
                $fcrm_key = $mapping['fcrm_field_key'];
                if ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' ) {
                    $fcrm_raw = $custom_fields[ $fcrm_key ] ?? null;
                } else {
                    $fcrm_raw = $contact->{ $fcrm_key } ?? null;
                }
            }

            // Flatten arrays/objects to a readable string.
            $wp_display   = is_array( $wp_raw )   ? implode( ', ', $wp_raw )   : (string) ( $wp_raw   ?? '' );
            $fcrm_display = is_array( $fcrm_raw ) ? implode( ', ', $fcrm_raw ) : (string) ( $fcrm_raw ?? '' );

            // For date fields, normalise both sides to Y-m-d before comparing
            // so that e.g. "08/23/1992" and "1992-08-23" are treated as equal.
            if ( ( $mapping['field_type'] ?? 'text' ) === 'date'
                && ( $wp_display !== '' || $fcrm_display !== '' )
            ) {
                $match = $this->normalize_date( $wp_display, $mapping )
                      === $this->normalize_date( $fcrm_display, $mapping );
            } else {
                $match = $wp_display === $fcrm_display;
            }

            $rows[] = [
                'id'         => $mapping['id'] ?? '',
                'wp_label'   => $mapping['wp_field_label']   ?? $mapping['wp_field_key'],
                'fcrm_label' => $mapping['fcrm_field_label'] ?? $mapping['fcrm_field_key'],
                'direction'  => $mapping['sync_direction'] ?? 'both',
                'wp_value'   => $wp_display,
                'fcrm_value' => $fcrm_display,
                'match'      => $match,
            ];
        }

        return $rows;
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
                    $val = get_field( $key, 'user_' . $user_id );
                    // For date fields: normalise the ACF-formatted value to
                    // canonical Y-m-d right here, before the rest of the engine
                    // or the mismatch detector ever sees it.  ACF's get_field()
                    // applies the field's "Return Format" setting (m/d/Y, d/m/Y,
                    // Y-m-d, …).  We use get_field_object() to discover that
                    // exact format and parse it with DateTime::createFromFormat()
                    // — which is unambiguous — rather than relying on strtotime().
                    if ( ( $mapping['field_type'] ?? 'text' ) === 'date'
                        && $val !== null && $val !== false && $val !== ''
                    ) {
                        return $this->acf_date_to_ymd( $key, 'user_' . $user_id, (string) $val );
                    }
                    return $val ?: null;
                }
                // Fallback to user_meta
                return get_user_meta( $user_id, $key, true ) ?: null;

            case 'pmp':
                // Paid Memberships Pro — read from the user's active membership level.
                if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
                    return null;
                }
                $level = pmpro_getMembershipLevelForUser( $user_id );
                if ( ! $level ) {
                    return null;
                }
                switch ( $key ) {
                    case 'startdate':
                        // Stored as a Unix timestamp; convert to Y-m-d for the formatter.
                        return ! empty( $level->startdate )
                            ? date( 'Y-m-d', (int) $level->startdate )
                            : null;
                    case 'enddate':
                        // Null / 0 means no expiry.
                        return ! empty( $level->enddate )
                            ? date( 'Y-m-d', (int) $level->enddate )
                            : null;
                    case 'level_name':
                        return $level->name ?? null;
                    case 'level_id':
                        return isset( $level->id )
                            ? (int) $level->id
                            : ( isset( $level->ID ) ? (int) $level->ID : null );
                    default:
                        return null;
                }

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

        // PMP fields are managed entirely by Paid Memberships Pro — never write back.
        if ( $source === 'pmp' ) {
            return;
        }

        // Fields that belong to the WP_User object go through wp_update_user()
        $user_object_keys = [ 'user_email', 'user_url', 'display_name' ];

        switch ( $source ) {
            case 'user':
                // WordPress user ID and login are immutable — never write back from FluentCRM.
                if ( in_array( $key, [ 'ID', 'user_login' ], true ) ) {
                    return;
                }
                if ( in_array( $key, $user_object_keys, true ) ) {
                    $wp_user_data[ $key ] = $value;
                } else {
                    // first_name, last_name etc. live in usermeta too
                    update_user_meta( $user_id, $key, $value );
                }
                break;

            case 'acf':
                // ACF date pickers store dates internally in Ymd format
                // (e.g. "20190108").  Convert before writing so that ACF's
                // get_field() can parse the stored value correctly.
                if ( ( $mapping['field_type'] ?? 'text' ) === 'date' && $value !== '' && $value !== null ) {
                    $canonical = $this->normalize_date( (string) $value, $mapping );
                    if ( $canonical !== '' ) {
                        $dt = \DateTime::createFromFormat( 'Y-m-d', $canonical );
                        if ( $dt ) {
                            $value = $dt->format( 'Ymd' );
                        }
                    }
                }

                // Write directly to user meta instead of using ACF's
                // update_field() wrapper.  update_field() relies on ACF's
                // field-group location rules to resolve the field object;
                // those rules frequently fail to match in wp-admin AJAX
                // requests for *user* fields, causing the write to silently
                // produce no effect.  update_user_meta() bypasses that
                // lookup entirely and is guaranteed to persist the value.
                // ACF's get_field() will still read it correctly because the
                // field-reference meta key (_<name> → field_xxx) already
                // exists from the initial profile save.
                update_user_meta( $user_id, $key, $value );
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
     * @param string $type       One of: text, select, date, checkbox, number, email, textarea
     * @param string $direction  'to_fcrm' | 'to_wp'
     * @param array  $mapping    The full mapping row (for date formats, value_map, etc.)
     * @return mixed
     */
    public function format_value( $value, string $type, string $direction, array $mapping = [] ) {
        switch ( $type ) {
            case 'date':
                return $this->format_date( $value, $direction, $mapping );

            case 'checkbox':
                return $this->format_checkbox( $value, $direction );

            case 'select':
                return $this->format_select( $value, $direction, $mapping );

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
     * Non-ACF WP meta can be anything — we use DateTime::createFromFormat() with
     * the configured WP format, falling back to strtotime() for ISO strings from
     * the FluentCRM side.
     */
    private function format_date( $value, string $direction, array $mapping ): string {
        if ( empty( $value ) ) {
            return '';
        }

        $canonical = $this->normalize_date( (string) $value, $mapping );

        if ( $canonical === '' ) {
            return (string) $value; // unparseable — return unchanged
        }

        if ( $direction === 'to_fcrm' ) {
            return $canonical; // already Y-m-d
        }

        // to_wp: reformat from Y-m-d to the configured WP format
        $fmt  = $mapping['date_format_wp'] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat( 'Y-m-d', $canonical );
        return $date ? $date->format( $fmt ) : $canonical;
    }

    /**
     * Parse any supported date string to a canonical Y-m-d string.
     *
     * Tries (in order):
     *   1. Compact YYYYMMDD integer (e.g. 19920823)
     *   2. The WP format configured for this mapping (e.g. m/d/Y)
     *   3. strtotime() as a final fallback for ISO and other common formats
     *
     * Returns '' when the value cannot be parsed.
     */
    public function normalize_date( string $value, array $mapping ): string {
        if ( $value === '' ) {
            return '';
        }

        // 1. Compact YYYYMMDD (ACF raw storage format)
        if ( is_numeric( $value ) && strlen( $value ) === 8 ) {
            $iso = substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
            $ts  = strtotime( $iso );
            return $ts !== false ? date( 'Y-m-d', $ts ) : '';
        }

        // 2. Try canonical Y-m-d FIRST — this is the format used by FluentCRM
        //    and by our own acf_date_to_ymd() converter.  Checking this before
        //    the WP format prevents a value like "2019-08-01" from accidentally
        //    matching a WP pattern and being misinterpreted.
        $date = \DateTime::createFromFormat( 'Y-m-d', $value );
        if ( $date && $date->format( 'Y-m-d' ) === $value ) {
            return $value;
        }

        // 3. Parse using the known WP format (avoids strtotime() locale ambiguity)
        $wp_fmt = $mapping['date_format_wp'] ?? 'Y-m-d';
        if ( $wp_fmt !== 'Y-m-d' ) {
            $date = \DateTime::createFromFormat( $wp_fmt, $value );
            if ( $date && $date->format( $wp_fmt ) === $value ) {
                return $date->format( 'Y-m-d' );
            }
        }

        // 4. Fallback — handles other unambiguous formats via strtotime().
        //    Note: strtotime() treats "/" as US m/d/Y which can swap month/day
        //    for d/m/Y values.  Steps 2-3 above should catch those cases.
        $ts = strtotime( $value );
        return $ts !== false ? date( 'Y-m-d', $ts ) : '';
    }

    /**
     * Convenience: normalize only if the mapping is a date field.
     * Used by the mismatch-resolver verification step to compare values
     * that may be in different surface formats (Ymd vs Y-m-d vs m/d/Y).
     */
    public function normalize_date_if_date( string $value, array $mapping ): string {
        if ( ( $mapping['field_type'] ?? 'text' ) === 'date' && $value !== '' ) {
            $canonical = $this->normalize_date( $value, $mapping );
            return $canonical !== '' ? $canonical : $value;
        }
        return $value;
    }

    /**
     * Convert an ACF-formatted date string to canonical Y-m-d.
     *
     * ACF's get_field() returns dates formatted per the field's "Return
     * Format" (e.g. "m/d/Y", "d/m/Y", "Y-m-d").  We use get_field_object()
     * to discover the exact format in use, then parse with
     * DateTime::createFromFormat() — which is unambiguous — rather than
     * handing the locale-sensitive string to strtotime().
     *
     * Falls back through compact Ymd detection and strtotime() for fields
     * that are not registered in ACF (i.e. plain user_meta keys that happen
     * to be mapped as date fields).
     *
     * @param string $key     ACF field name or key.
     * @param string $context ACF context string, e.g. 'user_42'.
     * @param string $val     The already-formatted date string from get_field().
     * @return string         Canonical Y-m-d, or $val if unparseable.
     */
    private function acf_date_to_ymd( string $key, string $context, string $val ): string {
        // 1. Ask ACF for the field definition to get its Return Format.
        if ( function_exists( 'get_field_object' ) ) {
            $field_obj = get_field_object( $key, $context );
            $fmt       = $field_obj['return_format'] ?? null;
            if ( $fmt ) {
                $dt = \DateTime::createFromFormat( $fmt, $val );
                if ( $dt && $dt->format( $fmt ) === $val ) {
                    return $dt->format( 'Y-m-d' );
                }
            }
        }

        // 2. Compact Ymd integer stored directly (e.g. "20250107").
        if ( is_numeric( $val ) && strlen( $val ) === 8 ) {
            return substr( $val, 0, 4 ) . '-' . substr( $val, 4, 2 ) . '-' . substr( $val, 6, 2 );
        }

        // 3. Last resort — handles ISO Y-m-d and other strtotime-parseable strings.
        $ts = strtotime( $val );
        return $ts !== false ? date( 'Y-m-d', $ts ) : $val;
    }

    /**
     * Checkbox / multi-select conversion.
     *
     * FluentCRM's createOrUpdate() API expects a plain PHP array for checkbox/
     * multiselect custom fields — it handles serialisation internally.
     * ACF also returns and expects PHP arrays, so both directions use arrays.
     */
    private function format_checkbox( $value, string $direction ) {
        // Normalise any incoming value to a PHP array first.
        if ( is_array( $value ) ) {
            return array_values( $value );
        }
        if ( is_string( $value ) && $value !== '' ) {
            $decoded = json_decode( $value, true );
            if ( $decoded !== null ) {
                return array_values( (array) $decoded );
            }
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) ) {
                return array_values( $unserialized );
            }
            return [ $value ];
        }
        return [];
    }

    /**
     * Select / radio field conversion.
     *
     * Applies an optional admin-configured value_map to translate option values
     * between the WP and FluentCRM option sets (e.g. 'yes' → '1', 'Gold' → 'gold').
     * If no map is configured the raw string value passes through unchanged.
     *
     * Direction semantics:
     *   to_fcrm  → apply map as-is (wp_value => fcrm_value)
     *   to_wp    → apply reverse map (fcrm_value => wp_value)
     */
    private function format_select( $value, string $direction, array $mapping ): string {
        $str_value = is_array( $value ) ? (string) reset( $value ) : (string) $value;
        $value_map = $mapping['value_map'] ?? [];

        if ( empty( $value_map ) || ! is_array( $value_map ) ) {
            return $str_value;
        }

        if ( $direction === 'to_fcrm' ) {
            return isset( $value_map[ $str_value ] ) ? (string) $value_map[ $str_value ] : $str_value;
        }

        // to_wp: reverse the map
        $reverse_map = [];
        foreach ( $value_map as $wp_val => $fcrm_val ) {
            $reverse_map[ (string) $fcrm_val ] = (string) $wp_val;
        }
        return isset( $reverse_map[ $str_value ] ) ? $reverse_map[ $str_value ] : $str_value;
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
        return $this->syncing_to_fcrm || $this->syncing_to_wp;
    }
}
