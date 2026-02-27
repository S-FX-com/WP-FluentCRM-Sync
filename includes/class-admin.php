<?php
/**
 * FCRM_WP_Sync_Admin
 *
 * Registers the WordPress admin menu and renders three sub-pages:
 *  1. Field Mapping   – build the WP ↔ FluentCRM field map.
 *  2. Sync            – bulk-sync and view live status.
 *  3. Mismatches      – compare records side-by-side and resolve conflicts.
 */

defined( 'ABSPATH' ) || exit;

class FCRM_WP_Sync_Admin {

    /** @var self|null */
    private static ?self $instance = null;

    /** @var FCRM_WP_Sync_Field_Mapper */
    private FCRM_WP_Sync_Field_Mapper $mapper;

    /** @var FCRM_WP_Sync_Mismatch_Detector */
    private FCRM_WP_Sync_Mismatch_Detector $detector;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->mapper   = new FCRM_WP_Sync_Field_Mapper();
        $this->detector = new FCRM_WP_Sync_Mismatch_Detector();

        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX handlers
        add_action( 'wp_ajax_fcrm_wp_sync_save_mappings',    [ $this, 'ajax_save_mappings' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_save_settings',    [ $this, 'ajax_save_settings' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_get_fields',       [ $this, 'ajax_get_fields' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_bulk_sync',        [ $this, 'ajax_bulk_sync' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_resolve_mismatch', [ $this, 'ajax_resolve_mismatch' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_get_mismatches',   [ $this, 'ajax_get_mismatches' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_save_pmp_settings', [ $this, 'ajax_save_pmp_settings' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_search_users',     [ $this, 'ajax_search_users' ] );
        add_action( 'wp_ajax_fcrm_wp_sync_sample_data',      [ $this, 'ajax_sample_data' ] );
    }

    // -----------------------------------------------------------------------
    // Admin menu
    // -----------------------------------------------------------------------

    public function register_menu(): void {
        add_menu_page(
            __( 'FluentCRM Sync', 'fcrm-wp-sync' ),
            __( 'FluentCRM Sync', 'fcrm-wp-sync' ),
            'manage_options',
            'fcrm-wp-sync',
            [ $this, 'render_field_mapping_page' ],
            'dashicons-randomize',
            56
        );

        add_submenu_page(
            'fcrm-wp-sync',
            __( 'Field Mapping', 'fcrm-wp-sync' ),
            __( 'Field Mapping', 'fcrm-wp-sync' ),
            'manage_options',
            'fcrm-wp-sync',
            [ $this, 'render_field_mapping_page' ]
        );

        add_submenu_page(
            'fcrm-wp-sync',
            __( 'Sync & Settings', 'fcrm-wp-sync' ),
            __( 'Sync & Settings', 'fcrm-wp-sync' ),
            'manage_options',
            'fcrm-wp-sync-sync',
            [ $this, 'render_sync_page' ]
        );

        add_submenu_page(
            'fcrm-wp-sync',
            __( 'Mismatch Resolver', 'fcrm-wp-sync' ),
            __( 'Mismatch Resolver', 'fcrm-wp-sync' ),
            'manage_options',
            'fcrm-wp-sync-mismatches',
            [ $this, 'render_mismatches_page' ]
        );

        // Only show the PMP Integration page when PMPro is active.
        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            add_submenu_page(
                'fcrm-wp-sync',
                __( 'PMP Integration', 'fcrm-wp-sync' ),
                __( 'PMP Integration', 'fcrm-wp-sync' ),
                'manage_options',
                'fcrm-wp-sync-pmp',
                [ $this, 'render_pmp_page' ]
            );
        }
    }

    // -----------------------------------------------------------------------
    // Asset enqueuing
    // -----------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        $pages = [
            'toplevel_page_fcrm-wp-sync',
            'fluentcrm-sync_page_fcrm-wp-sync-sync',
            'fluentcrm-sync_page_fcrm-wp-sync-mismatches',
            'fluentcrm-sync_page_fcrm-wp-sync-pmp',
        ];
        if ( ! in_array( $hook, $pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'fcrm-wp-sync-admin',
            FCRM_WP_SYNC_URL . 'admin/css/admin.css',
            [],
            FCRM_WP_SYNC_VERSION
        );

        wp_enqueue_script(
            'fcrm-wp-sync-admin',
            FCRM_WP_SYNC_URL . 'admin/js/admin.js',
            [ 'jquery', 'wp-util' ],
            FCRM_WP_SYNC_VERSION,
            true
        );

        wp_localize_script( 'fcrm-wp-sync-admin', 'fcrmWpSync', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'fcrm_wp_sync_nonce' ),
            'restUrl'   => rest_url( 'fcrm-wp-sync/v1' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'dateFormat' => get_option( 'date_format', 'm/d/Y' ),
            'i18n'      => [
                'saving'           => __( 'Saving…', 'fcrm-wp-sync' ),
                'saved'            => __( 'Saved!', 'fcrm-wp-sync' ),
                'error'            => __( 'Error. Please try again.', 'fcrm-wp-sync' ),
                'syncing'          => __( 'Syncing…', 'fcrm-wp-sync' ),
                'syncDone'         => __( 'Sync complete.', 'fcrm-wp-sync' ),
                'resolving'        => __( 'Resolving…', 'fcrm-wp-sync' ),
                'resolved'         => __( 'Resolved!', 'fcrm-wp-sync' ),
                'confirmDelete'    => __( 'Remove this mapping row?', 'fcrm-wp-sync' ),
                'loading'          => __( 'Loading…', 'fcrm-wp-sync' ),
                'noMappings'       => __( 'No active mappings to preview.', 'fcrm-wp-sync' ),
                'noFluentCRM'      => __( 'No linked FluentCRM contact found for this user.', 'fcrm-wp-sync' ),
                'previewWpField'   => __( 'WordPress Field', 'fcrm-wp-sync' ),
                'previewWpVal'     => __( 'WP Value', 'fcrm-wp-sync' ),
                'previewFcrmField' => __( 'FluentCRM Field', 'fcrm-wp-sync' ),
                'previewFcrmVal'   => __( 'FCRM Value', 'fcrm-wp-sync' ),
                'previewMatch'     => __( 'Match?', 'fcrm-wp-sync' ),
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Page: Field Mapping
    // -----------------------------------------------------------------------

    public function render_field_mapping_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'fcrm-wp-sync' ) );
        }

        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();
        $mappings    = $this->mapper->get_saved_mappings();

        // Sort WP fields for display; keep FCRM fields in natural FluentCRM order
        // (default fields first, then custom), sorted by label within each group.
        uasort( $wp_fields, fn( $a, $b ) => strcmp( $a['label'], $b['label'] ) );

        // Stable sort: default fields before custom, then alphabetical within each group.
        uasort( $fcrm_fields, function ( $a, $b ) {
            $ga = $a['source'] === 'default' ? 0 : 1;
            $gb = $b['source'] === 'default' ? 0 : 1;
            if ( $ga !== $gb ) {
                return $ga - $gb;
            }
            return strcmp( $a['label'], $b['label'] );
        } );

        // Index saved mappings by fcrm uid so we can pre-fill rows.
        $saved_by_fcrm = [];
        foreach ( $mappings as $m ) {
            $uid = ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' );
            $saved_by_fcrm[ $uid ][] = $m;
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'FluentCRM WP Sync – Field Mapping', 'fcrm-wp-sync' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Every FluentCRM field is listed below. Choose a WordPress field to map it to, or leave "— Don\'t map —" to skip. Field Type is set automatically from the FluentCRM field. Use "+ Add Row" for extra custom pairings.', 'fcrm-wp-sync' ); ?>
            </p>

            <div id="fcrm-mapping-notice" class="fcrm-notice" style="display:none"></div>

            <div class="fcrm-mapping-toolbar">
                <button id="fcrm-add-row" class="button button-secondary">
                    + <?php esc_html_e( 'Add Row', 'fcrm-wp-sync' ); ?>
                </button>
                <button id="fcrm-save-mappings" class="button button-primary">
                    <?php esc_html_e( 'Save Mappings', 'fcrm-wp-sync' ); ?>
                </button>
            </div>

            <div class="fcrm-mapping-table-wrap">
                <table class="widefat fcrm-mapping-table" id="fcrm-mapping-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'FluentCRM Field', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'WordPress Field', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'Field Type', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'Sync Direction', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'Enabled', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'Remove', 'fcrm-wp-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="fcrm-mapping-rows">
                        <?php
                        $rendered_fcrm_uids = [];

                        // One row per FluentCRM field, pre-filled with any saved WP mapping.
                        foreach ( $fcrm_fields as $uid => $fcrm_field ) {
                            $rendered_fcrm_uids[] = $uid;
                            if ( isset( $saved_by_fcrm[ $uid ] ) ) {
                                foreach ( $saved_by_fcrm[ $uid ] as $m ) {
                                    $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                                }
                            } else {
                                // Synthetic mapping: FCRM side pre-set, WP side blank.
                                $this->render_mapping_row(
                                    [
                                        'fcrm_field_key'    => $fcrm_field['key'],
                                        'fcrm_field_source' => $fcrm_field['source'],
                                        'fcrm_field_label'  => $fcrm_field['label'],
                                        'field_type'        => $fcrm_field['type'],
                                        'sync_direction'    => 'both',
                                        'enabled'           => false,
                                    ],
                                    $wp_fields,
                                    $fcrm_fields
                                );
                            }
                        }

                        // Orphaned saved mappings whose FCRM field no longer exists.
                        foreach ( $mappings as $m ) {
                            $fcrm_uid = ( $m['fcrm_field_source'] ?? '' ) . '__' . ( $m['fcrm_field_key'] ?? '' );
                            if ( ! in_array( $fcrm_uid, $rendered_fcrm_uids, true ) ) {
                                $this->render_mapping_row( $m, $wp_fields, $fcrm_fields );
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Hidden row template (cloned by JS for "+ Add Row") -->
            <template id="fcrm-row-template">
                <?php $this->render_mapping_row( [], $wp_fields, $fcrm_fields, true ); ?>
            </template>

            <!-- Serialised field data passed to JS -->
            <script id="fcrm-wp-fields-data" type="application/json">
                <?php echo wp_json_encode( array_values( $wp_fields ) ); ?>
            </script>
            <script id="fcrm-fcrm-fields-data" type="application/json">
                <?php echo wp_json_encode( array_values( $fcrm_fields ) ); ?>
            </script>

            <!-- Sample Data Preview -->
            <div class="fcrm-section" id="fcrm-preview-section" style="margin-top:28px">
                <h2><?php esc_html_e( 'Sample Data Preview', 'fcrm-wp-sync' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Select a WordPress user to preview how their data currently sits in both WordPress and FluentCRM, side by side.', 'fcrm-wp-sync' ); ?>
                </p>

                <div class="fcrm-preview-search">
                    <div class="fcrm-user-search-wrap">
                        <input type="text"
                               id="fcrm-preview-user-input"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'Search by name, email or username…', 'fcrm-wp-sync' ); ?>"
                               autocomplete="off">
                        <div id="fcrm-user-suggestions" class="fcrm-user-suggestions" style="display:none"></div>
                    </div>
                    <button id="fcrm-preview-load" class="button button-primary" disabled>
                        <?php esc_html_e( 'Preview Data', 'fcrm-wp-sync' ); ?>
                    </button>
                </div>

                <div id="fcrm-preview-results" style="display:none; margin-top:16px"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single mapping table row (or a blank template row).
     *
     * Column order: FluentCRM Field | WordPress Field | Field Type | Direction | Enabled | Remove
     */
    private function render_mapping_row( array $mapping, array $wp_fields, array $fcrm_fields, bool $is_template = false ): void {
        $id           = $mapping['id']                 ?? '';
        $wp_key       = $mapping['wp_field_key']       ?? '';
        $wp_src       = $mapping['wp_field_source']    ?? '';
        $fcrm_key     = $mapping['fcrm_field_key']     ?? '';
        $fcrm_src     = $mapping['fcrm_field_source']  ?? '';
        $field_type   = $mapping['field_type']         ?? 'text';
        $direction    = $mapping['sync_direction']     ?? 'both';
        $enabled      = ! empty( $mapping['enabled'] );
        $date_fmt_wp  = $mapping['date_format_wp']     ?? 'm/d/Y';
        $value_map    = $mapping['value_map']          ?? [];

        $row_id = $is_template ? '__TEMPLATE__' : ( $id ?: FCRM_WP_Sync_Field_Mapper::generate_id() );

        // Human-readable labels used for hint text beneath each dropdown.
        $wp_source_labels = [
            'user' => 'WordPress User',
            'meta' => 'User Meta',
            'acf'  => 'ACF',
            'pmp'  => 'Paid Memberships Pro',
        ];
        $acf_type_labels  = [
            'date_picker'      => 'Date Picker',
            'date_time_picker' => 'Date & Time',
            'time_picker'      => 'Time Picker',
            'checkbox'         => 'Checkbox',
            'radio'            => 'Radio Button',
            'select'           => 'Dropdown',
            'number'           => 'Number',
            'email'            => 'Email',
            'textarea'         => 'Textarea',
            'wysiwyg'          => 'Rich Text',
            'url'              => 'URL',
            'text'             => 'Text',
        ];
        $sync_type_labels = [
            'text'     => 'Text',
            'email'    => 'Email',
            'date'     => 'Date',
            'number'   => 'Number',
            'select'   => 'Dropdown',
            'checkbox' => 'Checkbox',
            'textarea' => 'Textarea',
        ];

        // Helper: type-label string for a WP field entry.
        $wp_type_label = function ( array $f ) use ( $acf_type_labels, $sync_type_labels ): string {
            if ( $f['source'] === 'acf' && ! empty( $f['acf_field_type'] ) ) {
                return $acf_type_labels[ $f['acf_field_type'] ] ?? ucfirst( str_replace( '_', ' ', $f['acf_field_type'] ) );
            }
            return $sync_type_labels[ $f['type'] ] ?? ucfirst( $f['type'] );
        };

        // Helper: type-label string for a FCRM field entry.
        $fcrm_type_label = function ( array $f ) use ( $sync_type_labels ): string {
            return $sync_type_labels[ $f['type'] ] ?? ucfirst( $f['type'] );
        };

        echo '<tr class="fcrm-mapping-row" data-id="' . esc_attr( $row_id ) . '">';

        // --- Column 1: FluentCRM Field ---
        // Compute the initial hint text (JS will keep it live on change).
        $selected_fcrm_uid = $fcrm_src . '__' . $fcrm_key;
        $sel_fcrm_f        = $fcrm_fields[ $selected_fcrm_uid ] ?? null;
        $fcrm_hint_text    = '';
        if ( $sel_fcrm_f ) {
            $src_lbl        = $sel_fcrm_f['source'] === 'custom' ? 'FluentCRM Custom' : 'FluentCRM';
            $fcrm_hint_text = $src_lbl . ': ' . $fcrm_type_label( $sel_fcrm_f );
        }

        echo '<td>';
        echo '<select class="fcrm-fcrm-field" name="mappings[' . esc_attr( $row_id ) . '][fcrm_uid]">';
        echo '<option value="">' . esc_html__( '— Select FluentCRM field —', 'fcrm-wp-sync' ) . '</option>';
        foreach ( $fcrm_fields as $uid => $f ) {
            $selected     = ( $f['key'] === $fcrm_key && $f['source'] === $fcrm_src ) ? ' selected' : '';
            $options_json = wp_json_encode( $f['options'] ?? [] );
            $src_lbl      = $f['source'] === 'custom' ? 'FluentCRM Custom' : 'FluentCRM';
            $t_lbl        = $fcrm_type_label( $f );
            printf(
                '<option value="%s" data-type="%s" data-label="%s" data-options="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( $f['label'] ),
                esc_attr( $options_json ),
                esc_attr( $src_lbl ),
                esc_attr( $t_lbl ),
                $selected,
                esc_html( $f['label'] )
            );
        }
        echo '</select>';
        echo '<p class="fcrm-field-hint fcrm-fcrm-hint">' . esc_html( $fcrm_hint_text ) . '</p>';
        echo '</td>';

        // --- Column 2: WordPress Field ---
        // Find the currently selected WP field for hint text + readonly detection.
        $wp_uid_selected = '';
        foreach ( $wp_fields as $uid => $f ) {
            if ( $f['key'] === $wp_key && $f['source'] === $wp_src ) {
                $wp_uid_selected = $uid;
                break;
            }
        }
        $sel_wp_f     = $wp_fields[ $wp_uid_selected ] ?? null;
        $wp_hint_text = '';
        if ( $sel_wp_f ) {
            $src_lbl      = $wp_source_labels[ $sel_wp_f['source'] ] ?? $sel_wp_f['source'];
            $wp_hint_text = $src_lbl . ': ' . $wp_type_label( $sel_wp_f );
        }

        $dir_is_locked = $sel_wp_f && ! empty( $sel_wp_f['readonly'] );
        if ( $dir_is_locked ) {
            $direction = 'wp_to_fcrm';
        }

        echo '<td>';
        echo '<select class="fcrm-wp-field" name="mappings[' . esc_attr( $row_id ) . '][wp_uid]">';
        echo '<option value="">' . esc_html__( '— Don\'t map —', 'fcrm-wp-sync' ) . '</option>';
        foreach ( $wp_fields as $uid => $f ) {
            $selected      = ( $f['key'] === $wp_key && $f['source'] === $wp_src ) ? ' selected' : '';
            $is_readonly   = ! empty( $f['readonly'] ) ? 1 : 0;
            $options_json  = wp_json_encode( $f['options'] ?? [] );
            $date_fmt_attr = esc_attr( $f['date_format_wp'] ?? '' );
            $src_lbl       = $wp_source_labels[ $f['source'] ] ?? $f['source'];
            $t_lbl         = $wp_type_label( $f );
            printf(
                '<option value="%s" data-type="%s" data-label="%s" data-readonly="%d" data-options="%s" data-date-format="%s" data-source-label="%s" data-type-label="%s"%s>%s</option>',
                esc_attr( $uid ),
                esc_attr( $f['type'] ),
                esc_attr( $f['label'] ),
                $is_readonly,
                esc_attr( $options_json ),
                $date_fmt_attr,
                esc_attr( $src_lbl ),
                esc_attr( $t_lbl ),
                $selected,
                esc_html( $f['label'] )
            );
        }
        echo '</select>';
        echo '<p class="fcrm-field-hint fcrm-wp-hint">' . esc_html( $wp_hint_text ) . '</p>';
        echo '</td>';

        // --- Column 3: Field Type ---
        $types = [
            'text'     => __( 'Text', 'fcrm-wp-sync' ),
            'select'   => __( 'Select / Radio', 'fcrm-wp-sync' ),
            'date'     => __( 'Date', 'fcrm-wp-sync' ),
            'checkbox' => __( 'Checkbox / Multi-select', 'fcrm-wp-sync' ),
            'number'   => __( 'Number', 'fcrm-wp-sync' ),
            'email'    => __( 'Email', 'fcrm-wp-sync' ),
            'textarea' => __( 'Textarea', 'fcrm-wp-sync' ),
        ];
        echo '<td>';
        echo '<select class="fcrm-field-type" name="mappings[' . esc_attr( $row_id ) . '][field_type]">';
        foreach ( $types as $val => $label ) {
            $sel = selected( $field_type, $val, false );
            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }
        echo '</select>';
        // Date format input (shown/hidden via JS when type === 'date')
        echo '<div class="fcrm-date-format-wrap" style="margin-top:4px">';
        echo '<small>' . esc_html__( 'WP date format:', 'fcrm-wp-sync' ) . ' </small>';
        echo '<input type="text" class="fcrm-date-format-wp small-text" value="' . esc_attr( $date_fmt_wp ) . '" placeholder="m/d/Y" name="mappings[' . esc_attr( $row_id ) . '][date_format_wp]">';
        echo '</div>';
        // Hidden input carries the saved value_map JSON for JS to read on page-load
        echo '<input type="hidden" class="fcrm-value-map-json" value="' . esc_attr( wp_json_encode( $value_map ) ) . '">';
        echo '</td>';

        // --- Column 4: Sync Direction ---
        $directions = [
            'both'       => __( '⇄ Both', 'fcrm-wp-sync' ),
            'wp_to_fcrm' => __( '→ WP → FluentCRM', 'fcrm-wp-sync' ),
            'fcrm_to_wp' => __( '← FluentCRM → WP', 'fcrm-wp-sync' ),
        ];
        echo '<td>';
        $dir_disabled = $dir_is_locked ? ' disabled' : '';
        echo '<select class="fcrm-sync-direction" name="mappings[' . esc_attr( $row_id ) . '][sync_direction]"' . $dir_disabled . '>';
        foreach ( $directions as $val => $label ) {
            $sel = selected( $direction, $val, false );
            echo "<option value=\"{$val}\"{$sel}>{$label}</option>";
        }
        echo '</select>';
        if ( $dir_is_locked ) {
            echo '<small style="display:block;color:#888">' . esc_html__( 'Read-only field: WP→FluentCRM only', 'fcrm-wp-sync' ) . '</small>';
        }
        echo '</td>';

        // --- Column 5: Enabled toggle ---
        $chk = $enabled ? ' checked' : '';
        echo '<td style="text-align:center">';
        echo '<input type="checkbox" class="fcrm-enabled" name="mappings[' . esc_attr( $row_id ) . '][enabled]" value="1"' . $chk . '>';
        echo '</td>';

        // --- Column 6: Remove button ---
        echo '<td style="text-align:center">';
        echo '<button type="button" class="button fcrm-remove-row" title="' . esc_attr__( 'Remove', 'fcrm-wp-sync' ) . '">✕</button>';
        echo '</td>';

        echo '</tr>';
    }

    // -----------------------------------------------------------------------
    // Page: Sync & Settings
    // -----------------------------------------------------------------------

    public function render_sync_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'fcrm-wp-sync' ) );
        }

        $settings       = get_option( 'fcrm_wp_sync_settings', [] );
        $last_sync      = get_option( 'fcrm_wp_sync_last_bulk_sync', '' );
        $total_users    = count_users()['total_users'];
        $total_fcrm     = class_exists( '\FluentCrm\App\Models\Subscriber' )
            ? \FluentCrm\App\Models\Subscriber::count()
            : 0;
        $active_mappings = $this->mapper->get_active_mappings();

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'FluentCRM WP Sync – Sync & Settings', 'fcrm-wp-sync' ); ?></h1>

            <!-- Status cards -->
            <div class="fcrm-status-cards">
                <div class="fcrm-card">
                    <span class="fcrm-card-number"><?php echo esc_html( $total_users ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'WordPress Users', 'fcrm-wp-sync' ); ?></span>
                </div>
                <div class="fcrm-card">
                    <span class="fcrm-card-number"><?php echo esc_html( $total_fcrm ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'FluentCRM Contacts', 'fcrm-wp-sync' ); ?></span>
                </div>
                <?php if ( $last_sync ) : ?>
                <div class="fcrm-card">
                    <span class="fcrm-card-number" style="font-size:14px"><?php echo esc_html( $last_sync ); ?></span>
                    <span class="fcrm-card-label"><?php esc_html_e( 'Last Bulk Sync', 'fcrm-wp-sync' ); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Bulk sync controls -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Bulk Sync', 'fcrm-wp-sync' ); ?></h2>
                <p><?php esc_html_e( 'Sync all records in batch. Large sites may take several minutes. The operation runs in pages to avoid timeouts.', 'fcrm-wp-sync' ); ?></p>

                <?php if ( ! empty( $active_mappings ) ) : ?>
                <div class="fcrm-field-selection">
                    <div class="fcrm-field-selection-header">
                        <strong><?php esc_html_e( 'Fields to sync', 'fcrm-wp-sync' ); ?></strong>
                        <span class="fcrm-field-sel-toggle">
                            <a href="#" id="fcrm-field-sel-all"><?php esc_html_e( 'All', 'fcrm-wp-sync' ); ?></a>
                            &nbsp;/&nbsp;
                            <a href="#" id="fcrm-field-sel-none"><?php esc_html_e( 'None', 'fcrm-wp-sync' ); ?></a>
                        </span>
                    </div>
                    <div class="fcrm-field-selection-list">
                        <?php foreach ( $active_mappings as $mapping ) : ?>
                            <?php
                            $map_id    = esc_attr( $mapping['id'] );
                            $wp_label  = esc_html( $mapping['wp_field_label']   ?? $mapping['wp_field_key'] );
                            $crm_label = esc_html( $mapping['fcrm_field_label'] ?? $mapping['fcrm_field_key'] );
                            $dir_map   = [
                                'both'       => '↔',
                                'wp_to_fcrm' => '→',
                                'fcrm_to_wp' => '←',
                            ];
                            $dir_icon = $dir_map[ $mapping['sync_direction'] ?? 'both' ] ?? '↔';
                            ?>
                            <label class="fcrm-field-sel-item">
                                <input type="checkbox"
                                       class="fcrm-field-sel-cb"
                                       name="field_ids[]"
                                       value="<?php echo $map_id; ?>"
                                       checked>
                                <span class="fcrm-field-sel-dir"><?php echo esc_html( $dir_icon ); ?></span>
                                <?php echo $wp_label; ?> &rarr; <?php echo $crm_label; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="fcrm-bulk-controls">
                    <button id="fcrm-bulk-wp-to-fcrm" class="button button-primary">
                        <?php esc_html_e( 'Sync WP → FluentCRM', 'fcrm-wp-sync' ); ?>
                    </button>
                    <button id="fcrm-bulk-fcrm-to-wp" class="button button-secondary">
                        <?php esc_html_e( 'Sync FluentCRM → WP', 'fcrm-wp-sync' ); ?>
                    </button>
                </div>

                <div id="fcrm-bulk-progress" style="display:none; margin-top:16px">
                    <div class="fcrm-progress-bar-wrap">
                        <div id="fcrm-progress-bar" class="fcrm-progress-bar" style="width:0%"></div>
                    </div>
                    <p id="fcrm-bulk-status"></p>
                </div>
            </div>

            <!-- Settings -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Sync Settings', 'fcrm-wp-sync' ); ?></h2>
                <form id="fcrm-settings-form">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'On User Register', 'fcrm-wp-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_user_register" value="1"
                                        <?php checked( ! empty( $settings['sync_on_user_register'] ) ); ?>>
                                    <?php esc_html_e( 'Sync new WP user to FluentCRM', 'fcrm-wp-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On Profile Update', 'fcrm-wp-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_profile_update" value="1"
                                        <?php checked( ! empty( $settings['sync_on_profile_update'] ) ); ?>>
                                    <?php esc_html_e( 'Sync WP user changes to FluentCRM', 'fcrm-wp-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On User Delete', 'fcrm-wp-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_user_delete" value="1"
                                        <?php checked( ! empty( $settings['sync_on_user_delete'] ) ); ?>>
                                    <?php esc_html_e( 'Unlink subscriber when WP user is deleted', 'fcrm-wp-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'On FluentCRM Update', 'fcrm-wp-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_fcrm_update" value="1"
                                        <?php checked( ! empty( $settings['sync_on_fcrm_update'] ) ); ?>>
                                    <?php esc_html_e( 'Sync FluentCRM contact changes to WP user', 'fcrm-wp-sync' ); ?>
                                </label>
                            </td>
                        </tr>
                        <?php if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) : ?>
                        <tr>
                            <th><?php esc_html_e( 'On PMP Membership Change', 'fcrm-wp-sync' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="sync_on_pmp_change" value="1"
                                        <?php checked( ! empty( $settings['sync_on_pmp_change'] ) ); ?>>
                                    <?php esc_html_e( 'Sync WP user to FluentCRM when their PMPro membership level changes', 'fcrm-wp-sync' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Pushes PMP date fields (join date, expiration date) to any mapped FluentCRM fields on every membership change.', 'fcrm-wp-sync' ); ?>
                                </p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>

                    <div id="fcrm-settings-notice" class="fcrm-notice" style="display:none"></div>
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Save Settings', 'fcrm-wp-sync' ); ?>
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // Page: Mismatch Resolver
    // -----------------------------------------------------------------------

    public function render_mismatches_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'fcrm-wp-sync' ) );
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'FluentCRM WP Sync – Mismatch Resolver', 'fcrm-wp-sync' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Records below have at least one field where WP and FluentCRM values differ. Choose which value to keep, or skip.', 'fcrm-wp-sync' ); ?>
            </p>

            <div class="fcrm-mismatch-controls">
                <button id="fcrm-scan-mismatches" class="button button-primary">
                    <?php esc_html_e( 'Scan for Mismatches', 'fcrm-wp-sync' ); ?>
                </button>
                <span id="fcrm-scan-status" style="margin-left:12px"></span>
            </div>

            <div id="fcrm-resolve-notice" class="fcrm-notice" style="display:none; margin-top:12px"></div>

            <div id="fcrm-mismatches-container" style="margin-top:20px">
                <p class="fcrm-placeholder"><?php esc_html_e( 'Click "Scan for Mismatches" to begin.', 'fcrm-wp-sync' ); ?></p>
            </div>

            <div id="fcrm-mismatch-pagination" style="display:none; margin-top:12px">
                <button id="fcrm-prev-page" class="button">&laquo; <?php esc_html_e( 'Previous', 'fcrm-wp-sync' ); ?></button>
                <span id="fcrm-page-info" style="margin:0 8px"></span>
                <button id="fcrm-next-page" class="button"><?php esc_html_e( 'Next', 'fcrm-wp-sync' ); ?> &raquo;</button>
            </div>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX handlers
    // -----------------------------------------------------------------------

    public function ajax_save_mappings(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $raw      = isset( $_POST['mappings'] ) ? (array) $_POST['mappings'] : []; // phpcs:ignore
        $wp_fields   = $this->mapper->get_wp_fields();
        $fcrm_fields = $this->mapper->get_fcrm_fields();

        $clean = [];
        foreach ( $raw as $row_id => $row ) {
            $wp_uid   = sanitize_text_field( $row['wp_uid']   ?? '' );
            $fcrm_uid = sanitize_text_field( $row['fcrm_uid'] ?? '' );

            if ( ! $wp_uid || ! $fcrm_uid ) {
                continue;
            }

            $wp_f   = $wp_fields[ $wp_uid ]   ?? null;
            $fcrm_f = $fcrm_fields[ $fcrm_uid ] ?? null;

            if ( ! $wp_f || ! $fcrm_f ) {
                continue;
            }

            // Readonly fields (e.g. User ID) may only sync WP → FluentCRM.
            $field_type   = sanitize_text_field( $row['field_type'] ?? 'text' );
            $direction    = sanitize_text_field( $row['sync_direction'] ?? 'both' );
            if ( ! empty( $wp_f['readonly'] ) ) {
                $direction = 'wp_to_fcrm';
            }

            // Sanitise and store value_map for select/radio fields.
            $value_map = [];
            if ( $field_type === 'select' && ! empty( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
                foreach ( $row['value_map'] as $wp_val => $fcrm_val ) {
                    $wp_val   = sanitize_text_field( $wp_val );
                    $fcrm_val = sanitize_text_field( $fcrm_val );
                    if ( $wp_val !== '' && $fcrm_val !== '' ) {
                        $value_map[ $wp_val ] = $fcrm_val;
                    }
                }
            }

            $clean[] = [
                'id'               => sanitize_text_field( $row_id ),
                'wp_field_key'     => $wp_f['key'],
                'wp_field_source'  => $wp_f['source'],
                'wp_field_label'   => $wp_f['label'],
                'fcrm_field_key'   => $fcrm_f['key'],
                'fcrm_field_source'=> $fcrm_f['source'],
                'fcrm_field_label' => $fcrm_f['label'],
                'field_type'       => $field_type,
                'sync_direction'   => $direction,
                'enabled'          => ! empty( $row['enabled'] ),
                'date_format_wp'   => sanitize_text_field( $row['date_format_wp'] ?? 'm/d/Y' ),
                'date_format_fcrm' => 'Y-m-d',
                // Carry ACF-specific date format through
                'acf_field_type'   => $wp_f['acf_field_type'] ?? '',
                // Select/radio value translation map
                'value_map'        => $value_map,
            ];
        }

        $this->mapper->save_mappings( $clean );
        wp_send_json_success( [ 'count' => count( $clean ) ] );
    }

    public function ajax_save_settings(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $fields = [
            'sync_on_user_register',
            'sync_on_profile_update',
            'sync_on_user_delete',
            'sync_on_fcrm_update',
            'sync_on_pmp_change',
        ];

        $settings = [];
        foreach ( $fields as $key ) {
            $settings[ $key ] = ! empty( $_POST[ $key ] ); // phpcs:ignore
        }

        update_option( 'fcrm_wp_sync_settings', $settings );
        wp_send_json_success();
    }

    public function ajax_get_fields(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        wp_send_json_success( [
            'wp'   => array_values( $this->mapper->get_wp_fields() ),
            'fcrm' => array_values( $this->mapper->get_fcrm_fields() ),
        ] );
    }

    public function ajax_bulk_sync(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $direction = sanitize_text_field( $_POST['direction'] ?? 'wp_to_fcrm' ); // phpcs:ignore
        $per_page  = max( 1, (int) ( $_POST['per_page'] ?? 50 ) );               // phpcs:ignore
        $offset    = max( 0, (int) ( $_POST['offset']   ?? 0  ) );               // phpcs:ignore

        // Optional field-ID filter: empty array means "sync all fields".
        $raw_ids   = isset( $_POST['field_ids'] ) && is_array( $_POST['field_ids'] ) // phpcs:ignore
            ? $_POST['field_ids']  // phpcs:ignore
            : [];
        $field_ids = array_map( 'sanitize_text_field', $raw_ids );

        $engine    = FCRM_WP_Sync_Engine::get_instance();
        $success   = [];
        $errors    = [];

        if ( $direction === 'wp_to_fcrm' ) {
            $users = get_users( [
                'number'  => $per_page,
                'offset'  => $offset,
                'orderby' => 'ID',
                'order'   => 'ASC',
            ] );
            foreach ( $users as $user ) {
                try {
                    $engine->sync_wp_to_fcrm( $user->ID, $field_ids );
                    $success[] = $user->ID;
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'id' => $user->ID, 'error' => $e->getMessage() ];
                }
            }
        } else {
            // fcrm_to_wp: iterate FluentCRM contacts with a linked WP user
            $contacts = \FluentCrm\App\Models\Subscriber::whereNotNull( 'user_id' )
                ->skip( $offset )
                ->take( $per_page )
                ->get();
            foreach ( $contacts as $contact ) {
                try {
                    $engine->sync_fcrm_to_wp( $contact, $field_ids );
                    $success[] = $contact->user_id;
                } catch ( \Throwable $e ) {
                    $errors[] = [ 'id' => $contact->id, 'error' => $e->getMessage() ];
                }
            }
        }

        $total_users = count_users()['total_users'];
        $has_more    = ( $offset + $per_page ) < $total_users;

        if ( ! $has_more ) {
            update_option( 'fcrm_wp_sync_last_bulk_sync', current_time( 'mysql' ) );
        }

        wp_send_json_success( [
            'success'     => count( $success ),
            'errors'      => $errors,
            'offset'      => $offset,
            'per_page'    => $per_page,
            'total_users' => $total_users,
            'has_more'    => $has_more,
            'next_offset' => $offset + $per_page,
        ] );
    }

    public function ajax_get_mismatches(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $page     = max( 1, (int) ( $_GET['page']     ?? 1  ) );  // phpcs:ignore
        $per_page = max( 1, (int) ( $_GET['per_page'] ?? 20 ) );  // phpcs:ignore

        $result = $this->detector->get_mismatches( $page, $per_page );
        wp_send_json_success( $result );
    }

    public function ajax_resolve_mismatch(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $user_id    = (int) ( $_POST['user_id']    ?? 0 );                              // phpcs:ignore
        $direction  = sanitize_text_field( $_POST['direction']  ?? 'use_wp' );          // phpcs:ignore
        $mapping_id = sanitize_text_field( $_POST['mapping_id'] ?? '' );               // phpcs:ignore
        $scope      = sanitize_text_field( $_POST['scope']      ?? 'field' );           // phpcs:ignore

        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Invalid user ID.' ] );
        }

        try {
            if ( $scope === 'all' ) {
                $ok = $this->detector->resolve_user( $user_id, $direction );
            } elseif ( $scope === 'empty' ) {
                $ok = $this->detector->resolve_user_empty_fields( $user_id );
            } else {
                $ok = $this->detector->resolve_field( $user_id, $mapping_id, $direction );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }

        if ( $ok ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => 'Could not resolve: no linked FluentCRM subscriber found for this user.' ] );
        }
    }

    // -----------------------------------------------------------------------
    // Page: PMP Integration
    // -----------------------------------------------------------------------

    public function render_pmp_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'fcrm-wp-sync' ) );
        }

        $settings     = get_option( 'fcrm_wp_sync_settings', [] );
        $tag_mappings = get_option( 'fcrm_wp_sync_pmp_tag_mappings', [] );
        $pmp_levels   = FCRM_WP_Sync_PMP_Integration::get_all_levels();

        // Collect FluentCRM tags.
        $fcrm_tags = [];
        if ( function_exists( 'FluentCrmApi' ) ) {
            $tags_collection = FluentCrmApi( 'tags' )->all();
            foreach ( $tags_collection as $tag ) {
                $fcrm_tags[] = [ 'id' => (int) $tag->id, 'title' => $tag->title ];
            }
        }

        ?>
        <div class="wrap fcrm-sync-wrap">
            <h1><?php esc_html_e( 'FluentCRM WP Sync – PMP Integration', 'fcrm-wp-sync' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Configure how Paid Memberships Pro membership data syncs with FluentCRM.', 'fcrm-wp-sync' ); ?>
            </p>

            <div id="fcrm-pmp-notice" class="fcrm-notice" style="display:none"></div>

            <!-- ── Field mapping reminder ─────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Date & Level Field Mapping', 'fcrm-wp-sync' ); ?></h2>
                <p>
                    <?php esc_html_e( 'The following PMPro membership fields are available in the Field Mapping screen. These are read-only and sync WP → FluentCRM only:', 'fcrm-wp-sync' ); ?>
                </p>
                <ul style="list-style:disc; margin-left:1.5em; line-height:1.8">
                    <li><strong><?php esc_html_e( 'PMPro Join Date', 'fcrm-wp-sync' ); ?></strong> – <?php esc_html_e( "The date the user's current membership level started.", 'fcrm-wp-sync' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Expiration / Renewal Date', 'fcrm-wp-sync' ); ?></strong> – <?php esc_html_e( 'The date the membership expires or renews. Empty for non-expiring memberships.', 'fcrm-wp-sync' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Level Name', 'fcrm-wp-sync' ); ?></strong> – <?php esc_html_e( 'The name of the active membership level.', 'fcrm-wp-sync' ); ?></li>
                    <li><strong><?php esc_html_e( 'PMPro Level ID', 'fcrm-wp-sync' ); ?></strong> – <?php esc_html_e( 'The numeric ID of the active membership level.', 'fcrm-wp-sync' ); ?></li>
                </ul>
            </div>

            <!-- ── Billing address field mapping ─────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Billing Address Field Mapping', 'fcrm-wp-sync' ); ?></h2>
                <p>
                    <?php esc_html_e( 'PMPro stores billing address information in WordPress user meta. These fields can be mapped bidirectionally to FluentCRM address fields:', 'fcrm-wp-sync' ); ?>
                </p>
                <table class="widefat" style="max-width:700px">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'PMPro Field (WP meta key)', 'fcrm-wp-sync' ); ?></th>
                            <th><?php esc_html_e( 'Suggested FluentCRM Field', 'fcrm-wp-sync' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $addr_suggestions = [
                            'PMPro Billing Address Line 1' => 'Address Line 1',
                            'PMPro Billing Address Line 2' => 'Address Line 2',
                            'PMPro Billing City'           => 'City',
                            'PMPro Billing State'          => 'State',
                            'PMPro Billing Postal Code'    => 'Postal Code',
                            'PMPro Billing Country'        => 'Country',
                            'PMPro Billing Phone'          => 'Phone',
                        ];
                        foreach ( $addr_suggestions as $wp_lbl => $fcrm_lbl ) :
                        ?>
                        <tr>
                            <td><?php echo esc_html( $wp_lbl ); ?></td>
                            <td><?php echo esc_html( $fcrm_lbl ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:8px">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fcrm-wp-sync' ) ); ?>" class="button">
                        <?php esc_html_e( 'Go to Field Mapping', 'fcrm-wp-sync' ); ?>
                    </a>
                </p>
            </div>

            <!-- ── Sync trigger ───────────────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Sync Trigger', 'fcrm-wp-sync' ); ?></h2>
                <p><?php esc_html_e( 'Enable automatic syncing to FluentCRM when a membership level changes.', 'fcrm-wp-sync' ); ?></p>
                <label>
                    <input type="checkbox" id="fcrm-pmp-sync-on-change" value="1"
                        <?php checked( ! empty( $settings['sync_on_pmp_change'] ) ); ?>>
                    <?php esc_html_e( 'Sync WP → FluentCRM on every membership level change', 'fcrm-wp-sync' ); ?>
                </label>
            </div>

            <!-- ── Tag mappings ───────────────────────────────────────────── -->
            <div class="fcrm-section">
                <h2><?php esc_html_e( 'Tag Mappings', 'fcrm-wp-sync' ); ?></h2>
                <p>
                    <?php esc_html_e( 'Select which FluentCRM tags to apply when a user belongs to each membership level. Tags assigned by this mapping will be removed automatically when the user\'s level changes.', 'fcrm-wp-sync' ); ?>
                </p>

                <?php if ( empty( $pmp_levels ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No membership levels found. Create levels in PMPro first.', 'fcrm-wp-sync' ); ?></p>
                <?php elseif ( empty( $fcrm_tags ) ) : ?>
                    <p class="description"><?php esc_html_e( 'No FluentCRM tags found. Create tags in FluentCRM first.', 'fcrm-wp-sync' ); ?></p>
                <?php else : ?>
                    <table class="widefat fcrm-pmp-tag-table" style="max-width:800px">
                        <thead>
                            <tr>
                                <th style="width:30%"><?php esc_html_e( 'Membership Level', 'fcrm-wp-sync' ); ?></th>
                                <th><?php esc_html_e( 'FluentCRM Tags to Apply', 'fcrm-wp-sync' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $pmp_levels as $level ) :
                                $level_id    = (int) ( $level->id ?? $level->ID );
                                $level_name  = esc_html( $level->name );
                                $saved_tags  = isset( $tag_mappings[ $level_id ] ) ? array_map( 'intval', (array) $tag_mappings[ $level_id ] ) : [];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo $level_name; ?></strong>
                                    <br><small><?php echo esc_html( sprintf( __( 'Level ID: %d', 'fcrm-wp-sync' ), $level_id ) ); ?></small>
                                </td>
                                <td>
                                    <select multiple
                                        name="pmp_tag_mappings[<?php echo esc_attr( $level_id ); ?>][]"
                                        class="fcrm-pmp-tag-select"
                                        data-level-id="<?php echo esc_attr( $level_id ); ?>"
                                        style="min-width:300px; min-height:80px">
                                        <?php foreach ( $fcrm_tags as $tag ) : ?>
                                            <option value="<?php echo esc_attr( $tag['id'] ); ?>"
                                                <?php selected( in_array( $tag['id'], $saved_tags, true ) ); ?>>
                                                <?php echo esc_html( $tag['title'] ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <p class="description" style="margin-top:4px">
                                        <?php esc_html_e( 'Hold Ctrl / Cmd to select multiple tags.', 'fcrm-wp-sync' ); ?>
                                    </p>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <button id="fcrm-save-pmp-settings" class="button button-primary">
                <?php esc_html_e( 'Save PMP Settings', 'fcrm-wp-sync' ); ?>
            </button>

        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX: Save PMP settings
    // -----------------------------------------------------------------------

    public function ajax_save_pmp_settings(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        // 1. Update the sync_on_pmp_change toggle within the main settings array.
        $settings                       = get_option( 'fcrm_wp_sync_settings', [] );
        $settings['sync_on_pmp_change'] = ! empty( $_POST['sync_on_pmp_change'] ); // phpcs:ignore
        update_option( 'fcrm_wp_sync_settings', $settings );

        // 2. Build and save tag mappings: [ level_id (int) => [ tag_id (int), ... ] ]
        $raw_mappings  = isset( $_POST['pmp_tag_mappings'] ) ? (array) $_POST['pmp_tag_mappings'] : []; // phpcs:ignore
        $clean_mappings = [];

        foreach ( $raw_mappings as $level_id => $tag_ids ) {
            $lid = (int) $level_id;
            if ( $lid <= 0 ) {
                continue;
            }
            $clean_tags = [];
            foreach ( (array) $tag_ids as $tid ) {
                $t = (int) $tid;
                if ( $t > 0 ) {
                    $clean_tags[] = $t;
                }
            }
            $clean_mappings[ $lid ] = $clean_tags;
        }

        update_option( 'fcrm_wp_sync_pmp_tag_mappings', $clean_mappings );

        wp_send_json_success( [ 'levels' => count( $clean_mappings ) ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: user search autocomplete (for Sample Data Preview)
    // -----------------------------------------------------------------------

    public function ajax_search_users(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $query = sanitize_text_field( $_POST['query'] ?? '' ); // phpcs:ignore
        if ( strlen( $query ) < 2 ) {
            wp_send_json_success( [] );
        }

        $users = get_users( [
            'search'         => '*' . $query . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 10,
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
        ] );

        $result = [];
        foreach ( $users as $u ) {
            $result[] = [
                'id'    => (int) $u->ID,
                'label' => $u->display_name . ' (' . $u->user_email . ')',
                'email' => $u->user_email,
            ];
        }

        wp_send_json_success( $result );
    }

    // -----------------------------------------------------------------------
    // AJAX: sample data preview
    // -----------------------------------------------------------------------

    public function ajax_sample_data(): void {
        check_ajax_referer( 'fcrm_wp_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Insufficient permissions', 403 );
        }

        $user_id = (int) ( $_POST['user_id'] ?? 0 ); // phpcs:ignore
        if ( ! $user_id ) {
            wp_send_json_error( __( 'Invalid user ID.', 'fcrm-wp-sync' ) );
        }

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            wp_send_json_error( __( 'User not found.', 'fcrm-wp-sync' ) );
        }

        $engine = FCRM_WP_Sync_Engine::get_instance();
        $rows   = $engine->get_field_values_for_user( $user_id );

        wp_send_json_success( [
            'user' => [
                'id'           => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
            ],
            'rows' => $rows,
        ] );
    }
}
