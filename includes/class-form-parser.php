<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses raw form template containing pseudo-shortcodes
 * into semantic, accessible, standard HTML inputs.
 */
final class Form_Parser {

    /**
     * Regex pattern to identify form pseudo-shortcodes.
     * Matches: [text* client-name "Your Name"], [email* client-email], [select service "A" "B"], [submit "Send"]
     */
    private const TAG_REGEX = '/\[([a-zA-Z0-9_-]+)(\*?)(?:\s+([^\]]+))?\]/';

    /**
     * Parse and render form HTML for a given form ID.
     *
     * @param int $form_id
     * @return string
     */
    public static function render(int $form_id): string {
        $post = get_post($form_id);
        if (!$post || $post->post_type !== Post_Types::CPT_FORM) {
            return '';
        }

        $raw_template = (string) get_post_meta($form_id, '_nexley_form_content', true);
        if (trim($raw_template) === '') {
            $raw_template = self::get_default_template();
        }

        // Parse pseudo-shortcodes.
        $parsed_body = self::parse_template($raw_template, $form_id);

        // Security nonce for REST API fetch.
        $nonce = wp_create_nonce('wp_rest');

        // Honeypot field for invisible zero-bloat bot protection.
        $honeypot = sprintf(
            '<div class="nexley-forms__hp" style="display:none !important;" aria-hidden="true">'
            . '<input type="text" name="_nexley_hp" tabindex="-1" autocomplete="off" value="" />'
            . '</div>'
        );

        // Hidden fields for Form ID and Nonce.
        $hidden_fields = sprintf(
            '<input type="hidden" name="_nexley_form_id" value="%d" />'
            . '<input type="hidden" name="_nexley_nonce" value="%s" />',
            $form_id,
            esc_attr($nonce)
        );

        // Live status message area.
        $status_box = '<div class="nexley-forms__status" aria-live="polite"></div>';

        // Wrap inside outer form tag.
        $form_action = esc_url(rest_url('nexley/v1/submit'));

        return sprintf(
            '<form class="nexley-forms nexley-forms--%1$d" id="nexley-form-%1$d" action="%2$s" method="post" data-nexley-form="%1$d">' . "\n"
            . '%3$s' . "\n"
            . '%4$s' . "\n"
            . '%5$s' . "\n"
            . '%6$s' . "\n"
            . '</form>',
            $form_id,
            $form_action,
            $honeypot,
            $hidden_fields,
            $parsed_body,
            $status_box
        );
    }

    /**
     * Parse pseudo-shortcodes in a raw template string.
     *
     * @param string $template
     * @param int $form_id
     * @return string
     */
    public static function parse_template(string $template, int $form_id = 0): string {
        return (string) preg_replace_callback(self::TAG_REGEX, static function (array $matches) use ($form_id): string {
            $type        = strtolower(trim($matches[1]));
            $is_required = ($matches[2] === '*');
            $raw_args    = isset($matches[3]) ? trim($matches[3]) : '';

            return self::render_tag($type, $is_required, $raw_args, $form_id);
        }, $template);
    }

    /**
     * Render an individual tag into HTML.
     *
     * @param string $type
     * @param bool $is_required
     * @param string $raw_args
     * @param int $form_id
     * @return string
     */
    private static function render_tag(string $type, bool $is_required, string $raw_args, int $form_id): string {
        // Handle submit button tag.
        if ($type === 'submit') {
            $label = __('Send', 'nexley-forms');
            if ($raw_args !== '') {
                $label = trim($raw_args, " \t\n\r\0\x0B\"'");
            }
            return sprintf(
                '<button type="submit" class="nexley-forms__submit">%s</button>',
                esc_html($label)
            );
        }

        // Parse arguments: field name, options, placeholders, classes.
        $parsed = self::parse_arguments($raw_args, $type);
        $name   = $parsed['name'];

        if ($name === '') {
            return ''; // Invalid tag without field name.
        }

        $id          = $parsed['id'] !== '' ? $parsed['id'] : sprintf('nexley-%d-%s', $form_id, $name);
        $classes     = array_merge(['nexley-forms__field', 'nexley-forms__field--' . $type], $parsed['classes']);
        $class_attr  = esc_attr(implode(' ', array_unique($classes)));
        $req_attr    = $is_required ? ' required aria-required="true"' : '';
        $placeholder = $parsed['placeholder'] !== '' ? sprintf(' placeholder="%s"', esc_attr($parsed['placeholder'])) : '';

        switch ($type) {
            case 'text':
            case 'email':
            case 'url':
            case 'tel':
            case 'number':
                $input_type = in_array($type, ['text', 'email', 'url', 'tel', 'number'], true) ? $type : 'text';
                $value_attr = $parsed['default'] !== '' ? sprintf(' value="%s"', esc_attr($parsed['default'])) : '';

                return sprintf(
                    '<input type="%s" name="%s" id="%s" class="%s"%s%s%s />',
                    esc_attr($input_type),
                    esc_attr($name),
                    esc_attr($id),
                    $class_attr,
                    $placeholder,
                    $value_attr,
                    $req_attr
                );

            case 'textarea':
                $default = esc_textarea($parsed['default']);
                return sprintf(
                    '<textarea name="%s" id="%s" class="%s"%s%s>%s</textarea>',
                    esc_attr($name),
                    esc_attr($id),
                    $class_attr,
                    $placeholder,
                    $req_attr,
                    $default
                );

            case 'select':
                $options_html = '';
                if ($parsed['placeholder'] !== '') {
                    $options_html .= sprintf(
                        '<option value="">%s</option>',
                        esc_html($parsed['placeholder'])
                    );
                }

                foreach ($parsed['options'] as $opt) {
                    $opt_val   = $opt['value'];
                    $opt_label = $opt['label'];
                    $selected  = ($opt_val === $parsed['default']) ? ' selected' : '';

                    $options_html .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($opt_val),
                        $selected,
                        esc_html($opt_label)
                    );
                }

                return sprintf(
                    '<select name="%s" id="%s" class="%s"%s>%s</select>',
                    esc_attr($name),
                    esc_attr($id),
                    $class_attr,
                    $req_attr,
                    $options_html
                );

            default:
                // Unknown tag, return untouched.
                return sprintf('[%s%s %s]', $type, $is_required ? '*' : '', $raw_args);
        }
    }

    /**
     * Parses raw tag arguments string into structured attributes.
     * Examples:
     * - "client-name"
     * - 'client-name "Your full name"'
     * - 'client-name placeholder "Your name" class:custom-input'
     * - 'service "Web Development" "SEO" "Marketing"'
     *
     * @param string $raw_args
     * @return array{name: string, placeholder: string, default: string, id: string, classes: list<string>, options: list<array{value: string, label: string}>}
     */
    public static function parse_arguments(string $raw_args, string $type = ''): array {
        $result = [
            'name'        => '',
            'placeholder' => '',
            'default'     => '',
            'id'          => '',
            'classes'     => [],
            'options'     => [],
        ];

        if (trim($raw_args) === '') {
            return $result;
        }

        // Tokenize args preserving quoted strings.
        preg_match_all('/(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))/', $raw_args, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $match) {
            if (isset($match[1]) && $match[1] !== '') {
                $tokens[] = ['val' => $match[1], 'quoted' => true];
            } elseif (isset($match[2]) && $match[2] !== '') {
                $tokens[] = ['val' => $match[2], 'quoted' => true];
            } elseif (isset($match[3]) && $match[3] !== '') {
                $tokens[] = ['val' => $match[3], 'quoted' => false];
            }
        }

        if (empty($tokens)) {
            return $result;
        }

        // First non-quoted token is the field name (or first token).
        $result['name'] = sanitize_key($tokens[0]['val']);
        array_shift($tokens);

        $total_tokens = count($tokens);
        for ($i = 0; $i < $total_tokens; $i++) {
            $token  = $tokens[$i]['val'];
            $quoted = $tokens[$i]['quoted'];

            if (!$quoted && strpos($token, 'class:') === 0) {
                $result['classes'][] = sanitize_html_class(substr($token, 6));
                continue;
            }

            if (!$quoted && strpos($token, 'id:') === 0) {
                $result['id'] = sanitize_html_class(substr($token, 3));
                continue;
            }

            if (!$quoted && strtolower($token) === 'placeholder' && isset($tokens[$i + 1])) {
                $result['placeholder'] = $tokens[$i + 1]['val'];
                $i++;
                continue;
            }

            if (!$quoted && strpos($token, 'placeholder:') === 0) {
                $result['placeholder'] = substr($token, 12);
                continue;
            }

            if ($quoted) {
                if ($type === 'select') {
                    // For select elements, quoted strings are option items.
                    $parts     = explode('|', $token, 2);
                    $opt_val   = $parts[0];
                    $opt_label = $parts[1] ?? $parts[0];
                    $result['options'][] = [
                        'value' => $opt_val,
                        'label' => $opt_label,
                    ];
                } else {
                    // For input elements, the first quoted string is the placeholder / default.
                    if ($result['placeholder'] === '') {
                        $result['placeholder'] = $token;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extracts all field names and required status from a form template.
     * Useful for dynamic tags and server-side validation.
     *
     * @param string $template
     * @return array<string, array{type: string, required: bool, name: string}>
     */
    public static function extract_form_fields(string $template): array {
        $fields = [];
        if (trim($template) === '') {
            return $fields;
        }

        preg_match_all(self::TAG_REGEX, $template, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $type        = strtolower(trim($match[1]));
            $is_required = ($match[2] === '*');
            $raw_args    = isset($match[3]) ? trim($match[3]) : '';

            if ($type === 'submit') {
                continue;
            }

            $parsed = self::parse_arguments($raw_args, $type);
            $name   = $parsed['name'];

            if ($name !== '') {
                $fields[$name] = [
                    'name'     => $name,
                    'type'     => $type,
                    'required' => $is_required,
                ];
            }
        }

        return $fields;
    }

    /**
     * Returns default form template when a new form is created.
     *
     * @return string
     */
    public static function get_default_template(): string {
        return "<label>Your Name (required)\n[text* client-name placeholder \"John Doe\"]\n</label>\n\n"
             . "<label>Your Email (required)\n[email* client-email placeholder \"john@example.com\"]\n</label>\n\n"
             . "<label>Subject\n[text subject placeholder \"How can we help?\"]\n</label>\n\n"
             . "<label>Your Message\n[textarea your-message placeholder \"Write your message here...\"]\n</label>\n\n"
             . "[submit \"Send Message\"]";
    }
}
