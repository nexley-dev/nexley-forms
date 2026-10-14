<?php
declare(strict_types=1);

namespace Nexley_Forms\Importers;

use Nexley_Forms\Form_Schema;
use Nexley_Forms\Form_Parser;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Contact Form 7 Importer Adapter.
 * Status: Direct source code inspected.
 * Converts Contact Form 7 posts (wpcf7_contact_form) and mail meta into Nexley Form Schema v1.
 */
class Importer_CF7 implements Importer_Interface {

    public const CPT_CF7 = 'wpcf7_contact_form';

    public function get_id(): string {
        return 'cf7';
    }

    public function get_title(): string {
        return 'Contact Form 7';
    }

    public function get_description(): string {
        return __('Imports forms from Contact Form 7 posts, converting CF7 tags and _mail configurations into structured schemas.', 'nexley-forms');
    }

    public function get_verification_status(): string {
        return 'Direct source code inspected';
    }

    /**
     * Check if Contact Form 7 post type exists or if posts exist.
     */
    public function is_available(): bool {
        return post_type_exists(self::CPT_CF7) || (bool) get_posts([
            'post_type'      => self::CPT_CF7,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
    }

    /**
     * List available CF7 forms.
     */
    public function get_available_forms(): array {
        $posts = get_posts([
            'post_type'      => self::CPT_CF7,
            'posts_per_page' => -1,
            'post_status'    => ['publish', 'draft'],
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $forms = [];
        foreach ($posts as $p) {
            $forms[$p->ID] = $p->post_title ?: "CF7 Form #{$p->ID}";
        }

        return $forms;
    }

    /**
     * Convert CF7 form to Nexley Form Schema.
     *
     * @param mixed $source Post ID or string content.
     * @return array<string, mixed>
     */
    public function convert(mixed $source): array {
        if (is_numeric($source)) {
            $post = get_post((int) $source);
            if (!$post) {
                return Form_Schema::get_default_schema();
            }

            $template = (string) get_post_meta($post->ID, '_form', true);
            if (empty($template)) {
                $template = $post->post_content;
            }

            $mail = get_post_meta($post->ID, '_mail', true);
            return $this->convert_template_and_mail($post->post_title ?: 'Imported CF7 Form', $template, is_array($mail) ? $mail : []);
        }

        if (is_string($source)) {
            return $this->convert_template_and_mail('Imported CF7 Form', $source, []);
        }

        if (is_array($source)) {
            $title    = (string) ($source['title'] ?? 'Imported CF7 Form');
            $template = (string) ($source['template'] ?? $source['content'] ?? '');
            $mail     = is_array($source['mail'] ?? null) ? $source['mail'] : [];
            return $this->convert_template_and_mail($title, $template, $mail);
        }

        return Form_Schema::get_default_schema();
    }

    /**
     * Convert template tags and mail meta.
     *
     * @param string               $title
     * @param string               $template
     * @param array<string, mixed> $mail
     * @return array<string, mixed>
     */
    protected function convert_template_and_mail(string $title, string $template, array $mail): array {
        $schema = [
            'version'       => Form_Schema::SCHEMA_VERSION,
            'title'         => $title,
            'layout'        => [
                'columns'    => Form_Schema::DEFAULT_GRID_COLUMNS,
                'breakpoint' => Form_Schema::DEFAULT_BREAKPOINT,
            ],
            'fields'        => [],
            'rows'          => [],
            'settings'      => [
                'style_mode' => 'preset',
                'preset'     => 'rounded',
            ],
            'notifications' => [],
            'confirmations' => [],
            'webhooks'      => [],
        ];

        // Parse tags from template: [type(*) name (placeholder "...") ("option1" "option2")]
        // Regex matches standard CF7 tags
        $tag_regex = '/\[([a-zA-Z0-9_\*\-]+)(?:\s+([a-zA-Z0-9_\-]+))?((?:\s+[^\]]+)?)\]/';
        preg_match_all($tag_regex, $template, $matches, PREG_SET_ORDER);

        $row_index = 1;
        $has_submit = false;

        foreach ($matches as $match) {
            $raw_type = $match[1];
            $name     = !empty($match[2]) ? sanitize_key($match[2]) : '';
            $args_str = $match[3] ?? '';

            $is_required = (strpos($raw_type, '*') !== false);
            $clean_type  = str_replace('*', '', $raw_type);

            $nexley_type = $this->map_cf7_type($clean_type);
            if ($nexley_type === 'submit') {
                $has_submit = true;
                $btn_label = 'Submit';
                if (preg_match('/"([^"]+)"/', $args_str, $btn_match)) {
                    $btn_label = $btn_match[1];
                } elseif (!empty($name)) {
                    $btn_label = str_replace(['_', '-'], ' ', $name);
                }

                $field_id = 'f_submit';
                $schema['fields'][] = [
                    'id'          => $field_id,
                    'type'        => 'submit',
                    'name'        => 'submit_button',
                    'label'       => '',
                    'button_text' => $btn_label,
                    'span'        => 12,
                ];

                $schema['rows'][] = [
                    'id'      => 'row_' . $row_index,
                    'columns' => [
                        [
                            'id'     => 'col_' . $row_index . '_1',
                            'span'   => 12,
                            'fields' => [$field_id],
                        ],
                    ],
                ];
                $row_index++;
                continue;
            }

            if (empty($name)) {
                $name = uniqid('cf7_');
            }

            $field_id = 'f_' . $name;
            $label = ucwords(str_replace(['_', '-'], ' ', $name));

            $field_def = [
                'id'       => $field_id,
                'type'     => $nexley_type,
                'name'     => $name,
                'label'    => $label,
                'span'     => 12,
                'required' => $is_required,
            ];

            // Parse placeholder
            if (preg_match('/placeholder\s+"([^"]+)"/i', $args_str, $p_match)) {
                $field_def['placeholder'] = $p_match[1];
            }

            // Parse options for select/radio/checkbox
            if (in_array($nexley_type, ['select', 'radio', 'checkbox'], true)) {
                preg_match_all('/"([^"]+)"/', $args_str, $opt_matches);
                if (!empty($opt_matches[1])) {
                    $opts = [];
                    foreach ($opt_matches[1] as $opt_val) {
                        // ignore if it was the placeholder
                        if (isset($field_def['placeholder']) && $opt_val === $field_def['placeholder']) {
                            continue;
                        }
                        $opts[] = ['label' => $opt_val, 'value' => $opt_val];
                    }
                    if (!empty($opts)) {
                        $field_def['options'] = $opts;
                    }
                }
            }

            $schema['fields'][] = $field_def;

            $schema['rows'][] = [
                'id'      => 'row_' . $row_index,
                'columns' => [
                    [
                        'id'     => 'col_' . $row_index . '_1',
                        'span'   => 12,
                        'fields' => [$field_id],
                    ],
                ],
            ];
            $row_index++;
        }

        // Add submit button if CF7 didn't include [submit] tag
        if (!$has_submit) {
            $field_id = 'f_submit';
            $schema['fields'][] = [
                'id'          => $field_id,
                'type'        => 'submit',
                'name'        => 'submit_button',
                'label'       => '',
                'button_text' => 'Submit',
                'span'        => 12,
            ];
            $schema['rows'][] = [
                'id'      => 'row_' . $row_index,
                'columns' => [
                    [
                        'id'     => 'col_' . $row_index . '_1',
                        'span'   => 12,
                        'fields' => [$field_id],
                    ],
                ],
            ];
        }

        // Convert CF7 _mail settings
        if (!empty($mail)) {
            $to      = (string) ($mail['recipient'] ?? get_option('admin_email'));
            $subject = (string) ($mail['subject'] ?? 'New CF7 Submission');
            $body    = (string) ($mail['body'] ?? '{_all_fields}');
            $headers = (string) ($mail['additional_headers'] ?? '');

            // Convert CF7 tags [your-name] to Nexley tags {your-name}
            $body    = preg_replace('/\[([a-zA-Z0-9_\-]+)\]/', '{$1}', $body);
            $to      = preg_replace('/\[([a-zA-Z0-9_\-]+)\]/', '{$1}', $to);
            $subject = preg_replace('/\[([a-zA-Z0-9_\-]+)\]/', '{$1}', $subject);

            // Extract Reply-To from additional headers if present
            $reply_to = '';
            if (preg_match('/Reply-To:\s*([^\r\n]+)/i', $headers, $rh)) {
                $reply_to = preg_replace('/\[([a-zA-Z0-9_\-]+)\]/', '{$1}', trim($rh[1]));
            }

            $schema['notifications'][] = [
                'id'       => 'notif_cf7_admin',
                'name'     => 'Admin Notification',
                'to'       => $to,
                'subject'  => $subject,
                'message'  => $body,
                'reply_to' => $reply_to,
            ];
        }

        return $schema;
    }

    /**
     * Map CF7 tag types to Nexley types.
     */
    protected function map_cf7_type(string $cf7_type): string {
        switch ($cf7_type) {
            case 'text':
                return 'text';
            case 'email':
                return 'email';
            case 'url':
                return 'url';
            case 'tel':
                return 'phone';
            case 'number':
                return 'number';
            case 'date':
                return 'date';
            case 'textarea':
                return 'textarea';
            case 'select':
                return 'select';
            case 'checkbox':
                return 'checkbox';
            case 'radio':
                return 'radio';
            case 'file':
                return 'file';
            case 'submit':
                return 'submit';
            default:
                return 'text';
        }
    }
}
