<?php
/**
 * FCRM_WP_Sync_Mismatch_Detector
 *
 * Compares WordPress user field values against FluentCRM contact field values
 * (for all active mappings) and reports any discrepancies.
 *
 * Mismatch record shape:
 * [
 *   'user_id'       => 42,
 *   'user_email'    => 'jane@example.com',
 *   'user_display'  => 'Jane Doe',
 *   'subscriber_id' => 17,
 *   'fields'        => [
 *     [
 *       'mapping_id'    => 'map_abc123',
 *       'field_label'   => 'Phone',
 *       'field_type'    => 'text',
 *       'wp_value'      => '555-0100',
 *       'fcrm_value'    => '555-0199',
 *     ],
 *     ...
 *   ],
 * ]
 */

defined( 'ABSPATH' ) || exit;

use FluentCrm\App\Models\Subscriber;

class FCRM_WP_Sync_Mismatch_Detector {

    /** @var FCRM_WP_Sync_Field_Mapper */
    private FCRM_WP_Sync_Field_Mapper $mapper;

    /** @var FCRM_WP_Sync_Engine */
    private FCRM_WP_Sync_Engine $engine;

    public function __construct() {
        $this->mapper = new FCRM_WP_Sync_Field_Mapper();
        $this->engine = FCRM_WP_Sync_Engine::get_instance();
    }

    // -----------------------------------------------------------------------
    // Detection
    // -----------------------------------------------------------------------

    /**
     * Return an array of mismatch records for a paginated slice of WP users
     * that have a linked FluentCRM contact.
     *
     * @param int $page      1-based page number
     * @param int $per_page
     * @return array{items: array, total: int, pages: int}
     */
    public function get_mismatches( int $page = 1, int $per_page = 10 ): array {
        $mappings = $this->mapper->get_active_mappings();
        if ( empty( $mappings ) ) {
            return [ 'items' => [], 'total' => 0, 'pages' => 0 ];
        }

        // Scan ALL users so that pagination is over mismatch *results*, not over
        // raw user rows.  Without this, a page of 20 users that happen to all be
        // in-sync would return 0 items even though later users have mismatches.
        $users     = get_users( [ 'fields' => 'all', 'number' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
        $all_items = [];

        foreach ( $users as $wp_user ) {
            $subscriber = $this->find_subscriber_for_user( $wp_user );
            if ( ! $subscriber ) {
                continue;
            }

            $field_mismatches = $this->compare_fields( $wp_user->ID, $wp_user, $subscriber, $mappings );

            if ( ! empty( $field_mismatches ) ) {
                $all_items[] = [
                    'user_id'       => $wp_user->ID,
                    'user_email'    => $wp_user->user_email,
                    'user_display'  => $wp_user->display_name,
                    'subscriber_id' => $subscriber->id,
                    'fields'        => $field_mismatches,
                ];
            }
        }

        $total  = count( $all_items );
        $pages  = $total > 0 ? (int) ceil( $total / $per_page ) : 0;
        $offset = ( max( 1, $page ) - 1 ) * $per_page;

        return [
            'items' => array_slice( $all_items, $offset, $per_page ),
            'total' => $total,
            'pages' => $pages,
        ];
    }

    /**
     * Quick count of users with at least one mismatch.
     * Scans all users — call sparingly on large sites.
     */
    public function count_mismatches_total( array $mappings = [] ): int {
        if ( empty( $mappings ) ) {
            $mappings = $this->mapper->get_active_mappings();
        }
        if ( empty( $mappings ) ) {
            return 0;
        }

        $count = 0;
        $users = get_users( [ 'fields' => 'all', 'number' => -1 ] );

        foreach ( $users as $wp_user ) {
            $subscriber = $this->find_subscriber_for_user( $wp_user );
            if ( ! $subscriber ) {
                continue;
            }
            $mismatches = $this->compare_fields( $wp_user->ID, $wp_user, $subscriber, $mappings );
            if ( ! empty( $mismatches ) ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Compare all mapped fields for one user↔subscriber pair.
     *
     * @return array  Array of mismatch detail rows (empty = fully in sync).
     */
    private function compare_fields( int $user_id, \WP_User $wp_user, Subscriber $subscriber, array $mappings ): array {
        $mismatches    = [];
        $custom_fields = $subscriber->custom_fields();

        foreach ( $mappings as $mapping ) {
            // Only compare fields that sync in both directions
            if ( ( $mapping['sync_direction'] ?? 'both' ) !== 'both' ) {
                continue;
            }

            // --- WP value ---
            $wp_raw = $this->engine->get_wp_field_value( $user_id, $wp_user, $mapping );

            // --- FCRM value ---
            $fcrm_key  = $mapping['fcrm_field_key'];
            $fcrm_src  = $mapping['fcrm_field_source'] ?? 'default';
            $fcrm_raw  = ( $fcrm_src === 'custom' )
                ? ( $custom_fields[ $fcrm_key ] ?? null )
                : ( $subscriber->{ $fcrm_key } ?? null );

            // Normalise both sides to a comparable canonical form
            $wp_norm   = $this->normalise( $wp_raw,   $mapping );
            $fcrm_norm = $this->normalise( $fcrm_raw, $mapping );

            if ( $this->values_differ( $wp_norm, $fcrm_norm ) ) {
                $mismatches[] = [
                    'mapping_id'  => $mapping['id'] ?? '',
                    'field_label' => $this->get_label( $mapping ),
                    'field_type'  => $mapping['field_type'] ?? 'text',
                    'wp_value'    => $this->display_value( $wp_raw,   $mapping['field_type'] ?? 'text' ),
                    'fcrm_value'  => $this->display_value( $fcrm_raw, $mapping['field_type'] ?? 'text' ),
                ];
            }
        }

        return $mismatches;
    }

    // -----------------------------------------------------------------------
    // Resolution
    // -----------------------------------------------------------------------

    /**
     * Resolve ALL mismatches for a single user by syncing in the chosen direction.
     *
     * @param int    $user_id
     * @param string $direction  'use_wp' | 'use_fcrm'
     * @return bool
     */
    public function resolve_user( int $user_id, string $direction ): bool {
        if ( $direction === 'use_wp' ) {
            $this->engine->sync_wp_to_fcrm( $user_id );
            return true;
        }

        if ( $direction === 'use_fcrm' ) {
            $wp_user    = get_userdata( $user_id );
            $subscriber = $wp_user ? $this->find_subscriber_for_user( $wp_user ) : null;
            if ( $subscriber ) {
                $this->engine->sync_fcrm_to_wp( $subscriber );
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a SINGLE field mismatch.
     *
     * @param int    $user_id
     * @param string $mapping_id  The mapping 'id' to resolve.
     * @param string $direction   'use_wp' | 'use_fcrm'
     * @return bool
     */
    public function resolve_field( int $user_id, string $mapping_id, string $direction ): bool {
        $mappings = $this->mapper->get_saved_mappings();
        $mapping  = null;
        foreach ( $mappings as $m ) {
            if ( ( $m['id'] ?? '' ) === $mapping_id ) {
                $mapping = $m;
                break;
            }
        }
        if ( ! $mapping ) {
            return false;
        }

        $wp_user = get_userdata( $user_id );
        if ( ! $wp_user ) {
            return false;
        }

        if ( $direction === 'use_wp' ) {
            // Push the WP value to FCRM
            $raw   = $this->engine->get_wp_field_value( $user_id, $wp_user, $mapping );
            $value = $this->engine->format_value(
                $raw,
                $mapping['field_type'] ?? 'text',
                'to_fcrm',
                $mapping
            );

            $subscriber = $this->find_subscriber_for_user( $wp_user );
            if ( ! $subscriber ) {
                return false;
            }

            $fcrm_key = $mapping['fcrm_field_key'];
            if ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' ) {
                $subscriber->updateCustomFieldValues( [ $fcrm_key => $value ] );
            } else {
                $subscriber->{ $fcrm_key } = $value;
                $subscriber->save();
            }
            return true;
        }

        if ( $direction === 'use_fcrm' ) {
            // Push the FCRM value to WP
            $subscriber = $this->find_subscriber_for_user( $wp_user );
            if ( ! $subscriber ) {
                return false;
            }

            $fcrm_key     = $mapping['fcrm_field_key'];
            $custom_fields = $subscriber->custom_fields();
            $raw = ( ( $mapping['fcrm_field_source'] ?? 'default' ) === 'custom' )
                ? ( $custom_fields[ $fcrm_key ] ?? null )
                : ( $subscriber->{ $fcrm_key } ?? null );

            $value = $this->engine->format_value(
                $raw,
                $mapping['field_type'] ?? 'text',
                'to_wp',
                $mapping
            );

            $dummy_wp_user_data = [];
            $this->engine->set_wp_field_value( $user_id, $mapping, $value, $dummy_wp_user_data );
            if ( ! empty( $dummy_wp_user_data ) ) {
                $dummy_wp_user_data['ID'] = $user_id;
                wp_update_user( $dummy_wp_user_data );
            }
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Find the FluentCRM subscriber for a WP user.
     * First tries user_id, then email.
     */
    public function find_subscriber_for_user( \WP_User $wp_user ): ?Subscriber {
        $sub = Subscriber::where( 'user_id', $wp_user->ID )->first();
        if ( $sub ) {
            return $sub;
        }
        return Subscriber::where( 'email', $wp_user->user_email )->first() ?: null;
    }

    /**
     * Normalise a value to a canonical string form for comparison.
     */
    private function normalise( $value, array $mapping ): string {
        if ( $value === null || $value === '' || $value === false ) {
            return '';
        }

        $type = $mapping['field_type'] ?? 'text';

        switch ( $type ) {
            case 'date':
                $ts = strtotime( (string) $value );
                return $ts !== false ? date( 'Y-m-d', $ts ) : (string) $value;

            case 'checkbox':
                if ( is_string( $value ) ) {
                    $decoded = json_decode( $value, true );
                    if ( $decoded !== null ) {
                        $value = $decoded;
                    } else {
                        $value = maybe_unserialize( $value );
                    }
                }
                if ( is_array( $value ) ) {
                    sort( $value );
                    return implode( '|', $value );
                }
                return (string) $value;

            case 'number':
                return is_numeric( $value ) ? (string) (float) $value : (string) $value;

            default:
                return strtolower( trim( (string) $value ) );
        }
    }

    private function values_differ( string $a, string $b ): bool {
        return $a !== $b;
    }

    private function display_value( $value, string $type ): string {
        if ( $value === null || $value === '' ) {
            return '(empty)';
        }
        if ( $type === 'checkbox' && is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) {
                return implode( ', ', $decoded );
            }
        }
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }
        return (string) $value;
    }

    private function get_label( array $mapping ): string {
        // Prefer stored labels, fall back to keys
        $wp_label   = $mapping['wp_field_label']   ?? $mapping['wp_field_key']   ?? '?';
        $fcrm_label = $mapping['fcrm_field_label']  ?? $mapping['fcrm_field_key'] ?? '?';
        return "{$wp_label} ↔ {$fcrm_label}";
    }
}
