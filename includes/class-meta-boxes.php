<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles custom meta boxes for:
 * 1. Nexley Form edit screen:
 *    - Meta Box 1: Form Editor (with tag generator buttons and code textarea)
 *    - Meta Box 2: Admin Notification
 *    - Meta Box 3: Client Auto-Responder
 * 2. Nexley Entry view screen:
 *    - Meta Box: Entry Submission Details
 */
final class Meta_Boxes {

    private const NONCE_ACTION = 'nexley_forms_save_meta';
    private const NONCE_NAME   = 'nexley_forms_meta_nonce';

    /**
     * Hook into WordPress.
     */
    public static function init(): void {
        add_action('add_meta_boxes', [self::class, 'register_meta_boxes']);
        add_action('save_post_' . Post_Types::CPT_FORM, [self::class, 'save_form_meta']);
        add_filter('screen_layout_columns', [self::class, 'filter_screen_layout_columns'], 10, 2);
        add_filter('get_user_option_screen_layout_' . Post_Types::CPT_FORM, [self::class, 'filter_user_screen_layout']);
        Builder::init();
    }

    /**
     * Force 1-column layout for Nexley Form edit screen.
     *
     * @param array<string, int> $columns
     * @param string             $screen
     * @return array<string, int>
     */
    public static function filter_screen_layout_columns(array $columns, string $screen): array {
        if ($screen === Post_Types::CPT_FORM) {
            $columns[Post_Types::CPT_FORM] = 1;
        }
        return $columns;
    }

    /**
     * Force user option for screen layout to 1 column.
     *
     * @return int
     */
    public static function filter_user_screen_layout(): int {
        return 1;
    }

    /**
     * Register meta boxes.
     */
    public static function register_meta_boxes(): void {
        // Remove native submitdiv meta box from CPT_FORM
        remove_meta_box('submitdiv', Post_Types::CPT_FORM, 'side');

        // Form post type meta box: Full Drag-and-Drop Builder
        add_meta_box(
            'nexley_meta_form_editor',
            __('Nexley Form Builder', 'nexley-forms'),
            [self::class, 'render_editor_box'],
            Post_Types::CPT_FORM,
            'normal',
            'high'
        );

        // Entry post type meta box.
        add_meta_box(
            'nexley_meta_entry_details',
            __('Submission Details', 'nexley-forms'),
            [self::class, 'render_entry_details_box'],
            Post_Types::CPT_ENTRY,
            'normal',
            'high'
        );
    }

    /**
     * Meta Box 1: Form Editor.
     *
     * @param \WP_Post $post
     */
    public static function render_editor_box(\WP_Post $post): void {
        Builder::render($post);
    }

    /**
     * Meta Box 2: Admin Notification.
     *
     * @param \WP_Post $post
     */
    public static function render_admin_notification_box(\WP_Post $post): void {
        $mail_to       = (string) get_post_meta($post->ID, '_nexley_mail_to', true);
        $mail_subject  = (string) get_post_meta($post->ID, '_nexley_mail_subject', true);
        $mail_body     = (string) get_post_meta($post->ID, '_nexley_mail_body', true);
        $mail_reply_to = (string) get_post_meta($post->ID, '_nexley_mail_reply_to', true);

        if ($mail_to === '') {
            $mail_to = (string) get_option('admin_email');
        }
        if ($mail_subject === '') {
            $mail_subject = sprintf('[%s] New submission: {form_title}', get_bloginfo('name'));
        }
        if ($mail_body === '') {
            $mail_body = "You have received a new submission from {site_title}:\n\n{_all_fields}\n\n--\nSent from {site_url}";
        }

        self::render_tag_pills($post->ID);
        ?>
        <table class="form-table nexley-meta-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="nexley_mail_to"><?php esc_html_e('To Email', 'nexley-forms'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="nexley_mail_to" id="nexley_mail_to" value="<?php echo esc_attr($mail_to); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('Destination email. Separate multiple emails with commas. Dynamic tags like {department_email} are supported.', 'nexley-forms'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nexley_mail_reply_to"><?php esc_html_e('Reply-To', 'nexley-forms'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="nexley_mail_reply_to" id="nexley_mail_reply_to" value="<?php echo esc_attr($mail_reply_to); ?>" class="regular-text" placeholder="{client-email}" />
                        <p class="description"><?php esc_html_e('Tag for customer email, e.g. {client-email}. Replies will go directly to the client.', 'nexley-forms'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nexley_mail_subject"><?php esc_html_e('Subject', 'nexley-forms'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="nexley_mail_subject" id="nexley_mail_subject" value="<?php echo esc_attr($mail_subject); ?>" class="large-text" />
                        <p class="description"><?php esc_html_e('Supports dynamic tags like {client-name}, {form_title}, {date}.', 'nexley-forms'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="nexley_mail_body"><?php esc_html_e('Message Body', 'nexley-forms'); ?></label>
                    </th>
                    <td>
                        <textarea name="nexley_mail_body" id="nexley_mail_body" rows="8" class="large-text code"><?php echo esc_textarea($mail_body); ?></textarea>
                        <p class="description"><?php esc_html_e('Supports dynamic tags and {_all_fields} to automatically list all submitted form inputs.', 'nexley-forms'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Meta Box 3: Client Auto-Responder.
     *
     * @param \WP_Post $post
     */
    public static function render_client_autoresponder_box(\WP_Post $post): void {
        $enabled  = (bool) get_post_meta($post->ID, '_nexley_autoresponder_enable', true);
        $to_field = (string) get_post_meta($post->ID, '_nexley_autoresponder_to', true);
        $subject  = (string) get_post_meta($post->ID, '_nexley_autoresponder_subject', true);
        $body     = (string) get_post_meta($post->ID, '_nexley_autoresponder_body', true);

        if ($to_field === '') {
            $to_field = '{client-email}';
        }
        if ($subject === '') {
            $subject = sprintf('Thank you for contacting %s', get_bloginfo('name'));
        }
        if ($body === '') {
            $body = "Hi {client-name},\n\nThank you for reaching out to us. We have received your message and will respond shortly.\n\nBest regards,\n{site_title}";
        }

        self::render_tag_pills($post->ID);
        ?>
        <p>
            <label>
                <input type="checkbox" name="nexley_autoresponder_enable" id="nexley_autoresponder_enable" value="1" <?php checked($enabled, true); ?> />
                <strong><?php esc_html_e('Enable Client Auto-Responder', 'nexley-forms'); ?></strong>
            </label>
        </p>

        <div id="nexley-autoresponder-fields" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
            <table class="form-table nexley-meta-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="nexley_autoresponder_to"><?php esc_html_e('Recipient Field Tag', 'nexley-forms'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="nexley_autoresponder_to" id="nexley_autoresponder_to" value="<?php echo esc_attr($to_field); ?>" class="regular-text" placeholder="{client-email}" />
                            <p class="description"><?php esc_html_e('The dynamic tag containing the client email address, e.g. {client-email}.', 'nexley-forms'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="nexley_autoresponder_subject"><?php esc_html_e('Subject', 'nexley-forms'); ?></label>
                        </th>
                        <td>
                            <input type="text" name="nexley_autoresponder_subject" id="nexley_autoresponder_subject" value="<?php echo esc_attr($subject); ?>" class="large-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="nexley_autoresponder_body"><?php esc_html_e('Message Body', 'nexley-forms'); ?></label>
                        </th>
                        <td>
                            <textarea name="nexley_autoresponder_body" id="nexley_autoresponder_body" rows="8" class="large-text code"><?php echo esc_textarea($body); ?></textarea>
                            <p class="description"><?php esc_html_e('Dynamic tags like {client-name}, {site_title} are supported.', 'nexley-forms'); ?></p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render tag pills helper banner.
     *
     * @param int $post_id
     */
    private static function render_tag_pills(int $post_id): void {
        $content = (string) get_post_meta($post_id, '_nexley_form_content', true);
        $fields  = Form_Parser::extract_form_fields($content);

        ?>
        <div class="nexley-tag-pills-bar">
            <span class="nexley-tag-pills-title"><?php esc_html_e('Available Tags (click to copy):', 'nexley-forms'); ?></span>
            <div class="nexley-tag-pills-container">
                <?php if (!empty($fields)) : ?>
                    <?php foreach ($fields as $f) : ?>
                        <button type="button" class="nexley-tag-pill" data-tag="{<?php echo esc_attr($f['name']); ?>}">
                            {<?php echo esc_html($f['name']); ?>}
                        </button>
                    <?php endforeach; ?>
                <?php endif; ?>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{_all_fields}">{_all_fields}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{admin_email}">{admin_email}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{page_url}">{page_url}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{post_title}">{post_title}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{site_title}">{site_title}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{site_url}">{site_url}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{date}">{date}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{time}">{time}</button>
                <button type="button" class="nexley-tag-pill nexley-tag-pill--system" data-tag="{ip}">{ip}</button>
            </div>
        </div>
        <?php
    }

    /**
     * Meta Box for nexley_entry CPT: details view.
     *
     * @param \WP_Post $post
     */
    public static function render_entry_details_box(\WP_Post $post): void {
        $form_id     = (int) get_post_meta($post->ID, '_nexley_form_id', true);
        $form_title  = (string) get_post_meta($post->ID, '_nexley_form_title', true);
        $user_ip     = (string) get_post_meta($post->ID, '_nexley_user_ip', true);
        $user_agent  = (string) get_post_meta($post->ID, '_nexley_user_agent', true);
        $time        = (string) get_post_meta($post->ID, '_nexley_submission_time', true);
        $data        = get_post_meta($post->ID, '_nexley_submitted_fields', true);

        ?>
        <div class="nexley-entry-meta-header">
            <div class="nexley-entry-meta-item">
                <strong><?php esc_html_e('Form:', 'nexley-forms'); ?></strong>
                <?php if ($form_id > 0) : ?>
                    <a href="<?php echo esc_url(get_edit_post_link($form_id) ?: '#'); ?>">
                        <?php echo esc_html($form_title ?: "#{$form_id}"); ?>
                    </a>
                <?php else : ?>
                    <span><?php esc_html_e('Unknown', 'nexley-forms'); ?></span>
                <?php endif; ?>
            </div>
            <div class="nexley-entry-meta-item">
                <strong><?php esc_html_e('Submitted on:', 'nexley-forms'); ?></strong>
                <span><?php echo esc_html($time ?: get_the_date('Y-m-d H:i:s', $post)); ?></span>
            </div>
            <div class="nexley-entry-meta-item">
                <strong><?php esc_html_e('IP Address:', 'nexley-forms'); ?></strong>
                <code><?php echo esc_html($user_ip ?: 'N/A'); ?></code>
            </div>
            <?php if ($user_agent !== '') : ?>
                <div class="nexley-entry-meta-item nexley-entry-meta-item--wide">
                    <strong><?php esc_html_e('User Agent:', 'nexley-forms'); ?></strong>
                    <span class="description"><?php echo esc_html($user_agent); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <hr style="margin:20px 0; border:0; border-top:1px solid #e2e8f0;" />

        <h3><?php esc_html_e('Submitted Form Data', 'nexley-forms'); ?></h3>
        <?php if (is_array($data) && !empty($data)) : ?>
            <table class="widefat striped nexley-entry-fields-table">
                <thead>
                    <tr>
                        <th style="width: 30%;"><?php esc_html_e('Field', 'nexley-forms'); ?></th>
                        <th><?php esc_html_e('Submitted Value', 'nexley-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($data as $key => $val) : ?>
                        <?php if (strpos((string) $key, '_nexley_') === 0) continue; ?>
                        <tr>
                            <td><strong><?php echo esc_html(ucwords(str_replace(['-', '_'], ' ', (string) $key))); ?></strong><br><code><?php echo esc_html((string) $key); ?></code></td>
                            <td>
                                <?php
                                if (is_array($val)) {
                                    echo esc_html(implode(', ', $val));
                                } else {
                                    echo nl2br(esc_html((string) $val));
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p><?php esc_html_e('No submitted fields recorded for this entry.', 'nexley-forms'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Save Form Meta fields.
     *
     * @param int $post_id
     */
    public static function save_form_meta(int $post_id): void {
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // 1. Form Content / Template.
        if (isset($_POST['nexley_form_content'])) {
            // Raw HTML with pseudo-shortcodes. We allow standard HTML for forms (input tags, divs, labels, etc.).
            $content = wp_kses_post(wp_unslash($_POST['nexley_form_content']));
            update_post_meta($post_id, '_nexley_form_content', $content);
        }

        // 2. Admin Mail fields.
        if (isset($_POST['nexley_mail_to'])) {
            update_post_meta($post_id, '_nexley_mail_to', sanitize_text_field(wp_unslash($_POST['nexley_mail_to'])));
        }
        if (isset($_POST['nexley_mail_reply_to'])) {
            update_post_meta($post_id, '_nexley_mail_reply_to', sanitize_text_field(wp_unslash($_POST['nexley_mail_reply_to'])));
        }
        if (isset($_POST['nexley_mail_subject'])) {
            update_post_meta($post_id, '_nexley_mail_subject', sanitize_text_field(wp_unslash($_POST['nexley_mail_subject'])));
        }
        if (isset($_POST['nexley_mail_body'])) {
            update_post_meta($post_id, '_nexley_mail_body', wp_kses_post(wp_unslash($_POST['nexley_mail_body'])));
        }

        // 3. Auto-responder fields.
        $autoresponder_enable = !empty($_POST['nexley_autoresponder_enable']) ? 1 : 0;
        update_post_meta($post_id, '_nexley_autoresponder_enable', $autoresponder_enable);

        if (isset($_POST['nexley_autoresponder_to'])) {
            update_post_meta($post_id, '_nexley_autoresponder_to', sanitize_text_field(wp_unslash($_POST['nexley_autoresponder_to'])));
        }
        if (isset($_POST['nexley_autoresponder_subject'])) {
            update_post_meta($post_id, '_nexley_autoresponder_subject', sanitize_text_field(wp_unslash($_POST['nexley_autoresponder_subject'])));
        }
        if (isset($_POST['nexley_autoresponder_body'])) {
            update_post_meta($post_id, '_nexley_autoresponder_body', wp_kses_post(wp_unslash($_POST['nexley_autoresponder_body'])));
        }
    }
}
