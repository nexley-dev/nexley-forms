<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Drag-and-Drop Form Builder UI & Controller.
 * Provides a Fluent Forms / Ninja Forms-style workflow with:
 * - Top tabs: Build / Emails & Actions / Settings / Preview
 * - Searchable categorized field palette
 * - 12-column visual grid canvas with drop indicators, column presets & row remaining width calculation
 * - Field settings drawer with live validation and conditional logic builder
 * - Scoped admin assets loaded strictly on form edit screens
 */
final class Builder {

    public const NONCE_ACTION = 'nexley_forms_builder_save';
    public const NONCE_NAME   = 'nexley_forms_builder_nonce';

    /**
     * Hook builder save and assets.
     */
    public static function init(): void {
        add_action('save_post_' . Post_Types::CPT_FORM, [self::class, 'save_form_builder_data'], 10, 2);
        add_action('wp_ajax_nexley_autosave_draft', [self::class, 'handle_autosave_draft']);
    }

    /**
     * Render the complete drag-and-drop builder interface.
     *
     * @param \WP_Post $post
     */
    public static function render(\WP_Post $post): void {
        Field_Registry::init();

        $form_id = $post->ID;
        $schema  = Form_Schema::get_form_schema($form_id);
        $types   = Field_Registry::get_field_types();

        // Categorize field types
        $categories = [
            'core'       => ['title' => __('Core Fields', 'nexley-forms'), 'fields' => []],
            'structural' => ['title' => __('Structural Elements', 'nexley-forms'), 'fields' => []],
            'compound'   => ['title' => __('Compound Fields', 'nexley-forms'), 'fields' => []],
            'advanced'   => ['title' => __('Advanced Fields', 'nexley-forms'), 'fields' => []],
        ];

        foreach ($types as $type_key => $type_def) {
            $cat = $type_def['category'] ?? 'core';
            if (isset($categories[$cat])) {
                $categories[$cat]['fields'][$type_key] = $type_def;
            } else {
                $categories['core']['fields'][$type_key] = $type_def;
            }
        }

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $shortcode = sprintf('[nexley_form id="%d"]', $form_id);
        ?>
        <div id="nexley-builder-root" class="nexley-builder" data-form-id="<?php echo esc_attr((string) $form_id); ?>" data-autosave-nonce="<?php echo esc_attr(wp_create_nonce('nexley_builder_autosave')); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <!-- Hidden JSON input holding single source of truth schema -->
            <input type="hidden" name="nexley_form_schema_json" id="nexley_form_schema_json" value="<?php echo esc_attr(wp_json_encode($schema)); ?>" />

            <!-- Top Builder Navigation Bar -->
            <div class="nexley-builder-header">
                <div class="nexley-builder-tabs" role="tablist">
                    <button type="button" class="nexley-tab-btn is-active" data-tab="build" role="tab" aria-selected="true">
                        <span class="dashicons dashicons-layout"></span> <?php esc_html_e('Build', 'nexley-forms'); ?>
                    </button>
                    <button type="button" class="nexley-tab-btn" data-tab="actions" role="tab" aria-selected="false">
                        <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Emails & Actions', 'nexley-forms'); ?>
                    </button>
                    <button type="button" class="nexley-tab-btn" data-tab="settings" role="tab" aria-selected="false">
                        <span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Settings', 'nexley-forms'); ?>
                    </button>
                    <button type="button" class="nexley-tab-btn" data-tab="preview" role="tab" aria-selected="false">
                        <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview', 'nexley-forms'); ?>
                    </button>
                </div>

                <div class="nexley-builder-actions">
                    <div class="nexley-history-controls" role="group" aria-label="<?php esc_attr_e('History', 'nexley-forms'); ?>">
                        <button type="button" class="button button-secondary button-small nexley-undo-btn" title="<?php esc_attr_e('Undo (Ctrl+Z)', 'nexley-forms'); ?>" disabled>
                            <span class="dashicons dashicons-undo"></span>
                        </button>
                        <button type="button" class="button button-secondary button-small nexley-redo-btn" title="<?php esc_attr_e('Redo (Ctrl+Y)', 'nexley-forms'); ?>" disabled>
                            <span class="dashicons dashicons-redo"></span>
                        </button>
                    </div>

                    <span id="nexley-autosave-indicator" style="font-size:11px;color:#64748b;margin-left:8px;margin-right:auto;display:none;align-items:center;gap:4px;">
                        <span class="dashicons dashicons-saved" style="font-size:14px;width:14px;height:14px;color:#10b981;"></span>
                        <span class="nexley-autosave-text"><?php esc_html_e('Draft autosaved', 'nexley-forms'); ?></span>
                    </span>

                    <button type="button" class="button button-secondary nexley-copy-shortcode-btn" data-shortcode="<?php echo esc_attr($shortcode); ?>">
                        <span class="dashicons dashicons-admin-page"></span>
                        <span><?php esc_html_e('Copy Shortcode', 'nexley-forms'); ?></span>
                    </button>

                    <button type="button" class="button button-primary nexley-save-builder-btn">
                        <span class="dashicons dashicons-saved"></span>
                        <span><?php esc_html_e('Save Form', 'nexley-forms'); ?></span>
                    </button>
                </div>
            </div>

            <!-- ARIA Live region for screen reader announcements -->
            <div id="nexley-aria-announcer" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>

            <!-- TAB 1: BUILD -->
            <div class="nexley-tab-pane is-active" id="nexley-pane-build" role="tabpanel">
                <div class="nexley-builder-grid">
                    <!-- Left: Field Palette -->
                    <aside class="nexley-palette">
                        <div class="nexley-palette-search-wrap">
                            <input type="search" id="nexley-palette-search" placeholder="<?php esc_attr_e('Search fields...', 'nexley-forms'); ?>" class="widefat" />
                        </div>
                        <div class="nexley-palette-list">
                            <?php foreach ($categories as $cat_key => $cat_data) : ?>
                                <div class="nexley-palette-group" data-category="<?php echo esc_attr($cat_key); ?>">
                                    <h4 class="nexley-palette-group-title"><?php echo esc_html($cat_data['title']); ?></h4>
                                    <div class="nexley-palette-items">
                                        <?php foreach ($cat_data['fields'] as $type_slug => $def) : ?>
                                            <div class="nexley-palette-item" 
                                                 draggable="true" 
                                                 tabindex="0"
                                                 data-type="<?php echo esc_attr($type_slug); ?>"
                                                 title="<?php echo esc_attr($def['description'] ?? ''); ?>">
                                                <span class="dashicons <?php echo esc_attr($def['icon'] ?? 'dashicons-admin-generic'); ?>"></span>
                                                <span class="nexley-palette-item-title"><?php echo esc_html($def['title']); ?></span>
                                                <button type="button" class="nexley-add-field-btn" title="<?php esc_attr_e('Click to add to canvas', 'nexley-forms'); ?>">+</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </aside>

                    <!-- Center: 12-Column Grid Canvas -->
                    <main class="nexley-canvas-area">
                        <div class="nexley-canvas-toolbar">
                            <span class="nexley-canvas-heading"><?php esc_html_e('Form Layout Grid (12 Columns)', 'nexley-forms'); ?></span>
                            <span class="description"><?php esc_html_e('Drag to arrange, resize handles to adjust spans, or click fields to edit.', 'nexley-forms'); ?></span>
                        </div>

                        <!-- Drop indicator line -->
                        <div id="nexley-drop-indicator" class="nexley-drop-indicator" style="display:none;"></div>

                        <div id="nexley-canvas-rows" class="nexley-canvas-rows" role="region" aria-label="<?php esc_attr_e('Form Canvas', 'nexley-forms'); ?>">
                            <!-- Rows and fields rendered dynamically via admin-builder.js -->
                        </div>

                        <div class="nexley-canvas-footer">
                            <button type="button" class="button button-dashed button-large" id="nexley-add-row-btn">
                                + <?php esc_html_e('Add Row', 'nexley-forms'); ?>
                            </button>
                        </div>
                    </main>

                    <!-- Right: Field Settings Panel (Drawer) -->
                    <aside class="nexley-settings-drawer" id="nexley-field-drawer" style="display:none;" aria-label="<?php esc_attr_e('Field Settings', 'nexley-forms'); ?>">
                        <div class="nexley-drawer-header">
                            <h3 id="nexley-drawer-title"><?php esc_html_e('Field Settings', 'nexley-forms'); ?></h3>
                            <button type="button" class="nexley-drawer-close" aria-label="<?php esc_attr_e('Close panel', 'nexley-forms'); ?>">&times;</button>
                        </div>
                        <div class="nexley-drawer-body" id="nexley-drawer-fields">
                            <!-- Populated dynamically on field selection -->
                        </div>
                    </aside>
                </div>
            </div>

            <!-- TAB 2: EMAILS & ACTIONS -->
            <div class="nexley-tab-pane" id="nexley-pane-actions" role="tabpanel" style="display:none;">
                <div class="nexley-actions-view">
                    <div class="nexley-actions-header">
                        <h2><?php esc_html_e('Notification Emails & Post-Submit Actions', 'nexley-forms'); ?></h2>
                        <button type="button" class="button button-primary" id="nexley-add-notif-btn">
                            + <?php esc_html_e('Add Notification Email', 'nexley-forms'); ?>
                        </button>
                    </div>

                    <div id="nexley-notifications-list" class="nexley-notifications-list">
                        <!-- Populated dynamically -->
                    </div>

                    <hr style="margin:30px 0; border:0; border-top:1px solid #e2e8f0;" />

                    <h3><?php esc_html_e('Post-Submit Actions', 'nexley-forms'); ?></h3>
                    <div class="nexley-post-actions-panel">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="nexley_success_msg"><?php esc_html_e('Success Message', 'nexley-forms'); ?></label></th>
                                <td>
                                    <textarea id="nexley_success_msg" rows="3" class="large-text"><?php echo esc_textarea($schema['settings']['success_message'] ?? 'Thank you! Your message has been sent successfully.'); ?></textarea>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="nexley_redirect_url"><?php esc_html_e('Redirect URL (Optional)', 'nexley-forms'); ?></label></th>
                                <td>
                                    <input type="url" id="nexley_redirect_url" class="large-text" placeholder="https://example.com/thank-you" value="<?php echo esc_attr($schema['settings']['redirect_url'] ?? ''); ?>" />
                                    <p class="description"><?php esc_html_e('If specified, users will be redirected to this URL after submitting.', 'nexley-forms'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: SETTINGS -->
            <div class="nexley-tab-pane" id="nexley-pane-settings" role="tabpanel" style="display:none;">
                <div class="nexley-settings-view">
                    <h2><?php esc_html_e('Form Settings', 'nexley-forms'); ?></h2>
                    
                    <h3><?php esc_html_e('Styling Mode & Preset', 'nexley-forms'); ?></h3>
                    <div class="nexley-styling-modes-selector">
                        <label class="nexley-style-card">
                            <input type="radio" name="nexley_setting_style_mode" value="raw" <?php checked(($schema['settings']['style_mode'] ?? 'base'), 'raw'); ?> />
                            <div class="nexley-style-card-body">
                                <strong><?php esc_html_e('Raw Mode', 'nexley-forms'); ?></strong>
                                <p><?php esc_html_e('0 CSS loaded. Pure semantic HTML. Theme controls all styling via grid-column: span var(--span).', 'nexley-forms'); ?></p>
                            </div>
                        </label>

                        <label class="nexley-style-card">
                            <input type="radio" name="nexley_setting_style_mode" value="base" <?php checked(($schema['settings']['style_mode'] ?? 'base'), 'base'); ?> />
                            <div class="nexley-style-card-body">
                                <strong><?php esc_html_e('Base Mode', 'nexley-forms'); ?></strong>
                                <p><?php esc_html_e('Tiny micro-stylesheet (< 3 KB) with zero specificity. Driven by CSS variables.', 'nexley-forms'); ?></p>
                            </div>
                        </label>

                        <label class="nexley-style-card">
                            <input type="radio" name="nexley_setting_style_mode" value="preset" <?php checked(($schema['settings']['style_mode'] ?? 'base'), 'preset'); ?> />
                            <div class="nexley-style-card-body">
                                <strong><?php esc_html_e('Preset Mode', 'nexley-forms'); ?></strong>
                                <p><?php esc_html_e('Curated styling presets without extra stylesheets.', 'nexley-forms'); ?></p>
                                <select id="nexley_setting_preset" class="regular-text">
                                    <option value="minimal" <?php selected(($schema['settings']['preset'] ?? 'minimal'), 'minimal'); ?>><?php esc_html_e('Minimal Clean', 'nexley-forms'); ?></option>
                                    <option value="rounded" <?php selected(($schema['settings']['preset'] ?? 'minimal'), 'rounded'); ?>><?php esc_html_e('Modern Rounded', 'nexley-forms'); ?></option>
                                    <option value="outlined" <?php selected(($schema['settings']['preset'] ?? 'minimal'), 'outlined'); ?>><?php esc_html_e('Brutalist Outlined', 'nexley-forms'); ?></option>
                                    <option value="dark" <?php selected(($schema['settings']['preset'] ?? 'minimal'), 'dark'); ?>><?php esc_html_e('Sleek Dark Mode', 'nexley-forms'); ?></option>
                                </select>
                            </div>
                        </label>
                    </div>

                    <table class="form-table" style="margin-top:16px;">
                        <tr>
                            <th scope="row"><label for="nexley_setting_breakpoint"><?php esc_html_e('Responsive Breakpoint', 'nexley-forms'); ?></label></th>
                            <td>
                                <input type="number" id="nexley_setting_breakpoint" value="<?php echo esc_attr((string) ($schema['layout']['breakpoint'] ?? 640)); ?>" min="320" max="1400" step="10" style="width:100px;" /> px
                                <p class="description"><?php esc_html_e('Screen width below which multi-column layouts collapse to 100% width on mobile viewports (default: 640px).', 'nexley-forms'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <hr style="margin:24px 0; border:0; border-top:1px solid #e2e8f0;" />

                    <h3><?php esc_html_e('Security & Anti-Spam (Cache-Safe)', 'nexley-forms'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Honeypot Trap', 'nexley-forms'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="nexley_setting_honeypot" value="1" <?php checked(!empty($schema['settings']['honeypot'])); ?> />
                                    <?php esc_html_e('Enable invisible honeypot trap for bots', 'nexley-forms'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Time-Trap', 'nexley-forms'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="nexley_setting_time_trap" value="1" <?php checked(!empty($schema['settings']['time_trap'])); ?> />
                                    <?php esc_html_e('Enable client-stamped interaction time-trap (skips when JS is off)', 'nexley-forms'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexley_setting_rate_limit"><?php esc_html_e('IP Rate Limit', 'nexley-forms'); ?></label></th>
                            <td>
                                <input type="number" id="nexley_setting_rate_limit" value="<?php echo esc_attr((string) ($schema['settings']['rate_limit'] ?? 10)); ?>" min="0" max="100" />
                                <span class="description"><?php esc_html_e('Max submissions allowed per IP per hour (0 to disable).', 'nexley-forms'); ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexley_setting_captcha_type"><?php esc_html_e('Third-Party Captcha', 'nexley-forms'); ?></label></th>
                            <td>
                                <select id="nexley_setting_captcha_type">
                                    <option value="none" <?php selected(($schema['settings']['captcha_type'] ?? 'none'), 'none'); ?>><?php esc_html_e('None (Zero External Requests)', 'nexley-forms'); ?></option>
                                    <option value="turnstile" <?php selected(($schema['settings']['captcha_type'] ?? 'none'), 'turnstile'); ?>><?php esc_html_e('Cloudflare Turnstile', 'nexley-forms'); ?></option>
                                    <option value="hcaptcha" <?php selected(($schema['settings']['captcha_type'] ?? 'none'), 'hcaptcha'); ?>><?php esc_html_e('hCaptcha', 'nexley-forms'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Loaded strictly on first user interaction to guarantee zero render-blocking requests.', 'nexley-forms'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <hr style="margin:24px 0; border:0; border-top:1px solid #e2e8f0;" />

                    <h3><?php esc_html_e('Privacy & GDPR', 'nexley-forms'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('IP Anonymization', 'nexley-forms'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="nexley_setting_privacy_ip" value="1" <?php checked(!empty($schema['settings']['privacy_anonymize_ip'])); ?> />
                                    <?php esc_html_e('Anonymize submitter IP addresses before saving to the database', 'nexley-forms'); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="nexley_setting_retention"><?php esc_html_e('Retention Period', 'nexley-forms'); ?></label></th>
                            <td>
                                <input type="number" id="nexley_setting_retention" value="<?php echo esc_attr((string) ($schema['settings']['retention_days'] ?? 0)); ?>" min="0" />
                                <span class="description"><?php esc_html_e('Number of days to retain entries before auto-deletion (0 to keep indefinitely).', 'nexley-forms'); ?></span>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- TAB 4: PREVIEW -->
            <div class="nexley-tab-pane" id="nexley-pane-preview" role="tabpanel" style="display:none;">
                <div class="nexley-preview-wrap">
                    <div class="nexley-preview-toolbar">
                        <div class="nexley-device-switcher" role="group" aria-label="<?php esc_attr_e('Device Viewport', 'nexley-forms'); ?>">
                            <button type="button" class="button is-active" data-device="desktop">
                                <span class="dashicons dashicons-desktop"></span> <?php esc_html_e('Desktop', 'nexley-forms'); ?>
                            </button>
                            <button type="button" class="button" data-device="tablet">
                                <span class="dashicons dashicons-tablet"></span> <?php esc_html_e('Tablet (768px)', 'nexley-forms'); ?>
                            </button>
                            <button type="button" class="button" data-device="mobile">
                                <span class="dashicons dashicons-smartphone"></span> <?php esc_html_e('Mobile (375px)', 'nexley-forms'); ?>
                            </button>
                        </div>
                    </div>

                    <div class="nexley-preview-viewport" id="nexley-preview-viewport">
                        <div class="nexley-preview-container" id="nexley-preview-container">
                            <!-- Live form rendered dynamically via JS Form_Renderer -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save builder schema data from POST request.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public static function save_form_builder_data(int $post_id, \WP_Post $post): void {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (empty($_POST['nexley_form_schema_json'])) {
            return;
        }

        $raw_json = wp_unslash($_POST['nexley_form_schema_json']);
        $schema   = json_decode((string) $raw_json, true);

        if (is_array($schema)) {
            $schema['id']    = $post_id;
            $schema['title'] = $post->post_title;
            Form_Schema::save_form_schema($post_id, $schema);
            // Clear any outstanding autosave draft once the live schema is explicitly saved
            delete_post_meta($post_id, '_nexley_form_schema_draft');
        }
    }

    /**
     * AJAX handler for builder autosave.
     * Writes strictly to '_nexley_form_schema_draft' post meta, NEVER to the live schema.
     */
    public static function handle_autosave_draft(): void {
        check_ajax_referer('nexley_builder_autosave', 'nonce');

        $form_id = isset($_POST['form_id']) ? absint($_POST['form_id']) : 0;
        if (!$form_id || !current_user_can('edit_post', $form_id)) {
            wp_send_json_error(['message' => __('Unauthorized permission to edit this form.', 'nexley-forms')], 403);
        }

        $raw_schema = isset($_POST['schema']) ? wp_unslash($_POST['schema']) : '';
        $schema = json_decode((string) $raw_schema, true);
        if (!is_array($schema)) {
            wp_send_json_error(['message' => __('Invalid schema payload.', 'nexley-forms')], 400);
        }

        $validated = Form_Schema::validate_and_normalize($schema);
        if (!$validated['valid']) {
            wp_send_json_error(['message' => __('Schema validation failed.', 'nexley-forms'), 'errors' => $validated['errors']], 422);
        }

        // Store draft strictly in '_nexley_form_schema_draft' meta key
        $draft_payload = [
            'schema'       => $validated['schema'],
            'autosaved_at' => current_time('mysql'),
            'user_id'      => get_current_user_id(),
        ];

        update_post_meta($form_id, '_nexley_form_schema_draft', $draft_payload);

        wp_send_json_success([
            'message'      => __('Draft autosaved', 'nexley-forms'),
            'autosaved_at' => current_time('g:i:s A'),
            'storage_key'  => '_nexley_form_schema_draft',
        ]);
    }
}
