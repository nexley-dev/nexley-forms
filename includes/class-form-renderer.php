<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * High-performance, zero-bloat Server-Side Form Renderer.
 *
 * Architecture & Constraints:
 * - 12-column CSS Grid without row or column wrapper divs.
 * - Flat sibling list of fields, each declaring inline --span: N.
 * - Strict DOM budget: max 3 elements per field (wrapper, label, control) + optional aria hint.
 * - Semantic <fieldset> for compound and choice groups.
 * - 100% usable with JavaScript disabled (Progressive Enhancement).
 * - Cache-safe anti-spam: honeypot + client-stamped time-trap token.
 */
final class Form_Renderer {

    /**
     * Render complete form markup from a form ID or schema array.
     *
     * @param int|array<string, mixed> $form_or_id Form post ID or pre-loaded schema array.
     * @return string Rendered HTML markup.
     */
    public static function render($form_or_id): string {
        Field_Registry::init();

        if (is_numeric($form_or_id)) {
            $form_id = absint($form_or_id);
            $schema  = Form_Schema::get_form_schema($form_id);
        } elseif (is_array($form_or_id)) {
            $schema  = $form_or_id;
            $form_id = absint($schema['id'] ?? 0);
        } else {
            return '';
        }

        $validated = Form_Schema::validate_and_normalize($schema);
        $schema    = $validated['schema'];

        $settings   = $schema['settings'] ?? [];
        $style_mode = $settings['style_mode'] ?? 'base';
        $preset     = $settings['preset'] ?? 'minimal';

        // Conditionally enqueue assets only when a form is actually rendered
        self::enqueue_form_assets($schema);

        // Build outer form classes
        $classes = [
            'nexley-forms',
            'nexley-forms--' . $form_id,
        ];

        if ($style_mode === 'preset') {
            $classes[] = 'nexley-forms--preset-' . sanitize_html_class($preset);
        } elseif ($style_mode === 'base') {
            $classes[] = 'nexley-forms--base';
        } else {
            $classes[] = 'nexley-forms--raw';
        }

        /**
         * Filters the CSS classes applied to the outer form tag.
         *
         * @param list<string>         $classes List of class names.
         * @param int                  $form_id Form post ID.
         * @param array<string, mixed> $schema  Full form schema.
         */
        $classes = apply_filters('nexley_forms_form_classes', $classes, $form_id, $schema);
        $class_attr = esc_attr(implode(' ', array_unique($classes)));

        // Action URLs for Progressive Enhancement:
        // Direct POST fallback to admin-post.php; AJAX endpoint via REST API data-attribute
        $post_action   = esc_url(admin_url('admin-post.php'));
        $rest_endpoint = esc_url_raw(rest_url('nexley/v1/submit'));

        // Grid inline custom property for total columns & configurable mobile breakpoint
        $grid_cols  = (int) ($schema['layout']['columns'] ?? 12);
        $breakpoint = (int) ($schema['layout']['breakpoint'] ?? 640);
        $grid_style = sprintf('--nexley-cols:%d;--nexley-breakpoint:%dpx;', $grid_cols, $breakpoint);

        $should_inline = apply_filters('nexley_forms_inline_base_css', !empty($settings['inline_css']), $form_id, $schema);
        $inlined_base_css = '';
        if ($should_inline && $style_mode !== 'raw') {
            $inlined_base_css = '<style id="nexley-forms-base-inline">' . self::get_inlined_base_css() . '</style>' . "\n";
        }

        $custom_breakpoint_css = '';
        if ($breakpoint !== 640 && $form_id > 0) {
            $custom_breakpoint_css = sprintf(
                '<style>@media(max-width:%dpx){.nexley-forms--%d :where(.nexley-forms__group,.nexley-forms__submit){grid-column:span var(--nexley-cols,%d) !important;}}</style>' . "\n",
                $breakpoint,
                $form_id,
                $grid_cols
            );
        }

        // Security tokens & anti-spam:
        // 1. Honeypot field (hidden from view and assistive tech)
        $honeypot_html = '';
        if (!empty($settings['honeypot'])) {
            $honeypot_html = '<div class="nexley-forms__hp" style="display:none !important;" aria-hidden="true">'
                . '<input type="text" name="_nexley_hp" tabindex="-1" autocomplete="off" value="" />'
                . '</div>' . "\n";
        }

        // 2. Client-stamped time-trap token (avoids server cache staleness)
        $time_trap_html = '';
        if (!empty($settings['time_trap'])) {
            $time_trap_html = '<input type="hidden" name="_nexley_tt" value="" class="nexley-forms__tt" />' . "\n";
        }

        // 3. Hidden form ID and admin-post action tag for fallback
        $hidden_meta = sprintf(
            '<input type="hidden" name="action" value="nexley_form_submit" />' . "\n"
            . '<input type="hidden" name="_nexley_form_id" value="%d" />' . "\n",
            $form_id
        );

        // 4. Render Flat Sibling Fields (No row or column wrapper divs!)
        $fields_html = self::render_fields($schema, $form_id);

        // 5. Accessible Status Container
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $status_param = isset($_GET['nexley_status']) ? sanitize_key($_GET['nexley_status']) : '';
        if ($status_param === 'success') {
            $msg = !empty($settings['success_message']) ? (string) $settings['success_message'] : __('Thank you! Your message has been sent successfully.', 'nexley-forms');
            $status_box = '<div class="nexley-forms__status is-success" aria-live="polite">' . esc_html($msg) . '</div>' . "\n";
        } elseif ($status_param === 'error') {
            $status_box = '<div class="nexley-forms__status is-error" aria-live="polite">' . esc_html__('An error occurred while submitting the form. Please try again.', 'nexley-forms') . '</div>' . "\n";
        } else {
            $status_box = '<div class="nexley-forms__status" aria-live="polite"></div>' . "\n";
        }

        // Assemble full form
        $output = $inlined_base_css . $custom_breakpoint_css . sprintf(
            '<form class="%1$s" id="nexley-form-%2$d" action="%3$s" method="post" data-nexley-form="%2$d" data-endpoint="%4$s" style="%5$s" novalidate>' . "\n"
            . '%6$s'
            . '%7$s'
            . '%8$s'
            . '%9$s'
            . '%10$s'
            . '</form>',
            $class_attr,
            $form_id,
            $post_action,
            $rest_endpoint,
            esc_attr($grid_style),
            $honeypot_html,
            $time_trap_html,
            $hidden_meta,
            $fields_html,
            $status_box
        );

        /**
         * Filters the fully rendered form HTML before returning.
         *
         * @param string               $output  Complete HTML markup.
         * @param int                  $form_id Form post ID.
         * @param array<string, mixed> $schema  Full form schema.
         */
        return apply_filters('nexley_forms_render_html', $output, $form_id, $schema);
    }

    /**
     * Render the flat list of sibling fields.
     *
     * @param array<string, mixed> $schema
     * @param int                  $form_id
     * @return string
     */
    private static function render_fields(array $schema, int $form_id): string {
        $fields = $schema['fields'] ?? [];
        if (empty($fields)) {
            return '';
        }

        $steps = $schema['steps'] ?? [];
        if (!empty($steps) && count($steps) > 1) {
            $step_fields = [];
            foreach ($steps as $st) {
                $st_id = $st['id'] ?? '';
                $step_fields[$st_id] = [];
            }
            $default_step = $steps[0]['id'] ?? 'step_1';
            foreach ($fields as $field) {
                $f_step = $field['step'] ?? $default_step;
                if (!isset($step_fields[$f_step])) {
                    $step_fields[$default_step][] = $field;
                } else {
                    $step_fields[$f_step][] = $field;
                }
            }

            $html = '';
            $step_idx = 0;
            foreach ($steps as $st) {
                $st_id = $st['id'] ?? '';
                $f_list = $step_fields[$st_id] ?? [];
                $is_first = ($step_idx === 0);
                $step_idx++;

                $html .= sprintf(
                    '<section class="nexley-forms__step" data-step="%s"%s>' . "\n",
                    esc_attr($st_id),
                    $is_first ? '' : ' hidden aria-hidden="true"'
                );
                foreach ($f_list as $field) {
                    $html .= self::render_single_field($field, $form_id) . "\n";
                }
                $html .= '</section>' . "\n";
            }
            return $html;
        }

        $html = '';
        foreach ($fields as $field) {
            $html .= self::render_single_field($field, $form_id) . "\n";
        }

        return $html;
    }

    /**
     * Render a single field adhering to the strict 3-element DOM budget.
     *
     * @param array<string, mixed> $field
     * @param int                  $form_id
     * @return string
     */
    public static function render_single_field(array $field, int $form_id): string {
        $type = sanitize_key($field['type'] ?? 'text');
        $span = max(1, min(12, (int) ($field['span'] ?? 12)));
        $name = sanitize_key($field['name'] ?? 'field');
        $id   = sanitize_key($field['id'] ?? ('nexley_' . $form_id . '_' . $name));
        $req  = !empty($field['required']);

        $wrapper_classes = [
            'nexley-forms__group',
            'nexley-forms__group--' . $type,
        ];
        if (!empty($field['css_class'])) {
            $wrapper_classes[] = sanitize_html_class($field['css_class']);
        }

        // Style attribute sets --span directly for CSS grid
        $span_style = sprintf('--span:%d;', $span);

        // Data attributes for conditional logic if defined
        $logic_attr = '';
        if (!empty($field['logic']) && is_array($field['logic'])) {
            $logic_attr = ' data-logic="' . esc_attr(wp_json_encode($field['logic'])) . '"';
        }

        // Element 1: Wrapper
        // Element 2: Label
        // Element 3: Control
        // Optional: Hint / Error

        $hint_html = '';
        $aria_describedby = '';
        if (!empty($field['help_text'])) {
            $hint_id = $id . '_hint';
            $aria_describedby = ' aria-describedby="' . esc_attr($hint_id) . '"';
            $hint_html = sprintf('<span id="%s" class="nexley-forms__hint">%s</span>', esc_attr($hint_id), esc_html($field['help_text']));
        }

        // 1. Submit Button
        if ($type === 'submit') {
            $label = !empty($field['label']) ? $field['label'] : __('Submit', 'nexley-forms');
            return sprintf(
                '<button type="submit" class="nexley-forms__submit %s" style="%s">%s</button>',
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style),
                esc_html($label)
            );
        }

        // 2. Structural Elements (Heading, Paragraph, Divider, Spacer, HTML)
        if ($type === 'heading') {
            $tag = in_array($field['tag'] ?? 'h3', ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $field['tag'] : 'h3';
            return sprintf(
                '<%1$s class="nexley-forms__group nexley-forms__heading %2$s" style="%3$s">%4$s</%1$s>',
                $tag,
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style),
                esc_html($field['label'] ?? '')
            );
        }

        if ($type === 'paragraph') {
            return sprintf(
                '<p class="nexley-forms__group nexley-forms__paragraph %s" style="%s">%s</p>',
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style),
                esc_html($field['content'] ?? '')
            );
        }

        if ($type === 'divider') {
            return sprintf(
                '<hr class="nexley-forms__group nexley-forms__divider %s" style="%s" />',
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style)
            );
        }

        if ($type === 'spacer') {
            $height = absint($field['height'] ?? 20);
            return sprintf(
                '<div class="nexley-forms__group nexley-forms__spacer %s" style="%s;height:%dpx;" aria-hidden="true"></div>',
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style),
                $height
            );
        }

        if ($type === 'html') {
            $content = (string) ($field['content'] ?? '');
            return sprintf(
                '<div class="nexley-forms__group nexley-forms__group--html %s" style="%s">%s</div>',
                esc_attr(!empty($field['css_class']) ? $field['css_class'] : ''),
                esc_attr($span_style),
                $content
            );
        }

        // 3. Hidden Field
        if ($type === 'hidden') {
            return sprintf(
                '<input type="hidden" name="%s" id="%s" value="%s" />',
                esc_attr($name),
                esc_attr($id),
                esc_attr((string) ($field['default'] ?? ''))
            );
        }

        // 4. Consent / GDPR single checkbox
        if ($type === 'consent') {
            $label_text = $field['label'] ?? __('I agree to terms.', 'nexley-forms');
            $req_attr   = $req ? ' required aria-required="true"' : '';
            return sprintf(
                '<div class="%s" style="%s"%s>' . "\n"
                . '<label class="nexley-forms__choice-item" for="%s">'
                . '<input type="checkbox" name="%s" id="%s" value="yes" class="nexley-forms__field nexley-forms__control"%s%s />'
                . '<span>%s</span>'
                . '</label>' . "\n"
                . '%s'
                . '</div>',
                esc_attr(implode(' ', $wrapper_classes)),
                esc_attr($span_style),
                $logic_attr,
                esc_attr($id),
                esc_attr($name),
                esc_attr($id),
                $req_attr,
                $aria_describedby,
                wp_kses_post($label_text),
                $hint_html
            );
        }

        // 5. Choice Groups (Radio & Checkbox Group) via semantic <fieldset>
        if ($type === 'radio' || $type === 'checkbox') {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $input_type = $type === 'checkbox' ? 'checkbox' : 'radio';
            $input_name = $type === 'checkbox' ? $name . '[]' : $name;

            $choices_html = '';
            foreach ($options as $opt_idx => $opt) {
                $val      = (string) ($opt['value'] ?? '');
                $opt_lbl  = (string) ($opt['label'] ?? $val);
                $opt_id   = sprintf('%s_%d', $id, $opt_idx);
                $selected = ($val === ($field['default'] ?? '')) ? ' checked' : '';

                $choices_html .= sprintf(
                    '<label class="nexley-forms__choice-item" for="%s">'
                    . '<input type="%s" name="%s" id="%s" value="%s" class="nexley-forms__field"%s />'
                    . '<span>%s</span>'
                    . '</label>',
                    esc_attr($opt_id),
                    $input_type,
                    esc_attr($input_name),
                    esc_attr($opt_id),
                    esc_attr($val),
                    $selected,
                    esc_html($opt_lbl)
                );
            }

            return sprintf(
                '<fieldset class="%s" style="%s"%s>' . "\n"
                . '<legend class="nexley-forms__legend">%s%s</legend>' . "\n"
                . '<div class="nexley-forms__choice-group">' . "\n"
                . '%s' . "\n"
                . '</div>' . "\n"
                . '%s'
                . '</fieldset>',
                esc_attr(implode(' ', $wrapper_classes)),
                esc_attr($span_style),
                $logic_attr,
                esc_html($field['label'] ?? ''),
                $req ? ' <span class="nexley-forms__req" aria-hidden="true">*</span>' : '',
                $choices_html,
                $hint_html
            );
        }

        // 6. Compound Field: Name (First / Last) via semantic <fieldset>
        if ($type === 'name') {
            return sprintf(
                '<fieldset class="%s" style="%s"%s>' . "\n"
                . '<legend class="nexley-forms__legend">%s%s</legend>' . "\n"
                . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">'
                . '<input type="text" name="%s[first]" id="%s_first" placeholder="%s" class="nexley-forms__field nexley-forms__control"%s />'
                . '<input type="text" name="%s[last]" id="%s_last" placeholder="%s" class="nexley-forms__field nexley-forms__control"%s />'
                . '</div>' . "\n"
                . '%s'
                . '</fieldset>',
                esc_attr(implode(' ', $wrapper_classes)),
                esc_attr($span_style),
                $logic_attr,
                esc_html($field['label'] ?? __('Name', 'nexley-forms')),
                $req ? ' <span class="nexley-forms__req" aria-hidden="true">*</span>' : '',
                esc_attr($name),
                esc_attr($id),
                esc_attr($field['first_placeholder'] ?? __('First Name', 'nexley-forms')),
                $req ? ' required' : '',
                esc_attr($name),
                esc_attr($id),
                esc_attr($field['last_placeholder'] ?? __('Last Name', 'nexley-forms')),
                $req ? ' required' : '',
                $hint_html
            );
        }

        // 7. Compound Field: Address via semantic <fieldset>
        if ($type === 'address') {
            return sprintf(
                '<fieldset class="%s" style="%s"%s>' . "\n"
                . '<legend class="nexley-forms__legend">%s%s</legend>' . "\n"
                . '<div style="display:flex;flex-direction:column;gap:8px;">'
                . '<input type="text" name="%s[street]" placeholder="%s" class="nexley-forms__field nexley-forms__control" />'
                . '<input type="text" name="%s[street2]" placeholder="%s" class="nexley-forms__field nexley-forms__control" />'
                . '<div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:8px;">'
                . '<input type="text" name="%s[city]" placeholder="%s" class="nexley-forms__field nexley-forms__control" />'
                . '<input type="text" name="%s[state]" placeholder="%s" class="nexley-forms__field nexley-forms__control" />'
                . '<input type="text" name="%s[zip]" placeholder="%s" class="nexley-forms__field nexley-forms__control" />'
                . '</div>'
                . '</div>' . "\n"
                . '%s'
                . '</fieldset>',
                esc_attr(implode(' ', $wrapper_classes)),
                esc_attr($span_style),
                $logic_attr,
                esc_html($field['label'] ?? __('Address', 'nexley-forms')),
                $req ? ' <span class="nexley-forms__req" aria-hidden="true">*</span>' : '',
                esc_attr($name),
                esc_attr(__('Street Address', 'nexley-forms')),
                esc_attr($name),
                esc_attr(__('Apartment, suite, unit (optional)', 'nexley-forms')),
                esc_attr($name),
                esc_attr(__('City', 'nexley-forms')),
                esc_attr($name),
                esc_attr(__('State / Province', 'nexley-forms')),
                esc_attr($name),
                esc_attr(__('ZIP / Postal Code', 'nexley-forms')),
                $hint_html
            );
        }

        // 8. Standard Inputs (Text, Email, URL, Phone, Number, Date, File, Range, Rating)
        $label_html = sprintf(
            '<label for="%s" class="nexley-forms__label">%s%s</label>',
            esc_attr($id),
            esc_html($field['label'] ?? ''),
            $req ? ' <span class="nexley-forms__req" aria-hidden="true">*</span>' : ''
        );

        $req_attr    = $req ? ' required aria-required="true"' : '';
        $placeholder = !empty($field['placeholder']) ? sprintf(' placeholder="%s"', esc_attr($field['placeholder'])) : '';
        $default_val = esc_attr((string) ($field['default'] ?? ''));

        $control_html = '';

        if ($type === 'textarea') {
            $control_html = sprintf(
                '<textarea name="%s" id="%s" class="nexley-forms__field nexley-forms__control"%s%s%s>%s</textarea>',
                esc_attr($name),
                esc_attr($id),
                $placeholder,
                $req_attr,
                $aria_describedby,
                $default_val
            );
        } elseif ($type === 'select') {
            $options_html = '';
            if (!empty($field['placeholder'])) {
                $options_html .= sprintf('<option value="">%s</option>', esc_html($field['placeholder']));
            }
            if (isset($field['options']) && is_array($field['options'])) {
                foreach ($field['options'] as $opt) {
                    $val = (string) ($opt['value'] ?? '');
                    $lbl = (string) ($opt['label'] ?? $val);
                    $selected = ($val === ($field['default'] ?? '')) ? ' selected' : '';
                    $options_html .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($val),
                        $selected,
                        esc_html($lbl)
                    );
                }
            }
            $control_html = sprintf(
                '<select name="%s" id="%s" class="nexley-forms__field nexley-forms__control"%s%s>%s</select>',
                esc_attr($name),
                esc_attr($id),
                $req_attr,
                $aria_describedby,
                $options_html
            );
        } elseif ($type === 'file') {
            $allowed = !empty($field['allowed_ext']) ? esc_attr($field['allowed_ext']) : 'pdf,doc,docx,jpg,png,zip';
            $accept = '.' . str_replace(',', ',.', str_replace(' ', '', $allowed));
            $control_html = sprintf(
                '<input type="file" name="%s" id="%s" accept="%s" class="nexley-forms__field nexley-forms__control"%s%s />',
                esc_attr($name),
                esc_attr($id),
                esc_attr($accept),
                $req_attr,
                $aria_describedby
            );
        } elseif ($type === 'range') {
            $min  = isset($field['min']) ? (float) $field['min'] : 0;
            $max  = isset($field['max']) ? (float) $field['max'] : 100;
            $step = isset($field['step']) ? (float) $field['step'] : 1;
            $val  = !empty($field['default']) ? (float) $field['default'] : 50;
            $control_html = sprintf(
                '<input type="range" name="%s" id="%s" min="%s" max="%s" step="%s" value="%s" class="nexley-forms__field nexley-forms__control"%s />',
                esc_attr($name),
                esc_attr($id),
                esc_attr((string) $min),
                esc_attr((string) $max),
                esc_attr((string) $step),
                esc_attr((string) $val),
                $aria_describedby
            );
        } else {
            // text, email, url, phone, number, date
            $input_type = in_array($type, ['text', 'email', 'url', 'number', 'date'], true) ? $type : ($type === 'phone' ? 'tel' : 'text');
            $value_attr = $default_val !== '' ? sprintf(' value="%s"', $default_val) : '';

            $control_html = sprintf(
                '<input type="%s" name="%s" id="%s" class="nexley-forms__field nexley-forms__control"%s%s%s%s />',
                esc_attr($input_type),
                esc_attr($name),
                esc_attr($id),
                $value_attr,
                $placeholder,
                $req_attr,
                $aria_describedby
            );
        }

        // Assemble strict 3 DOM elements: Wrapper -> Label -> Control (+ optional hint)
        return sprintf(
            '<div class="%s" style="%s"%s>' . "\n"
            . '%s' . "\n"
            . '%s' . "\n"
            . '%s'
            . '</div>',
            esc_attr(implode(' ', $wrapper_classes)),
            esc_attr($span_style),
            $logic_attr,
            $label_html,
            $control_html,
            $hint_html
        );
    }

    /**
     * Conditionally enqueue assets only when a form is actively rendered on the current page.
     * Guarantees ZERO frontend CSS/JS requests on pages without a form.
     *
     * @param array<string, mixed> $schema
     */
    public static function enqueue_form_assets(array $schema): void {
        $settings   = $schema['settings'] ?? [];
        $style_mode = $settings['style_mode'] ?? 'base';
        $form_id    = (int) ($schema['id'] ?? 0);

        $should_inline = apply_filters('nexley_forms_inline_base_css', !empty($settings['inline_css']), $form_id, $schema);

        // 1. Stylesheet: enqueued ONLY if mode is 'base' or 'preset' (never for 'raw'), and NOT inlined
        if ($style_mode !== 'raw' && !$should_inline) {
            wp_enqueue_style('nexley-forms-base');
        }

        // 2. Core Frontend Runtime JS
        wp_enqueue_script('nexley-forms-runtime');

        // 3. Conditional Logic Module: enqueued ONLY if any field in the schema defines conditional logic
        $has_logic = false;
        foreach ($schema['fields'] ?? [] as $f) {
            if (!empty($f['logic'])) {
                $has_logic = true;
                break;
            }
        }
        if ($has_logic) {
            wp_enqueue_script('nexley-forms-conditional');
        }

        // 4. Multi-step Module: enqueued ONLY if steps are defined
        if (!empty($schema['steps']) && count($schema['steps']) > 1) {
            wp_enqueue_script('nexley-forms-multistep');
        }
    }

    /**
     * Read and cache minified Base CSS for inline embedding.
     *
     * @return string
     */
    private static function get_inlined_base_css(): string {
        static $cached_css = null;
        if ($cached_css !== null) {
            return $cached_css;
        }

        $min_path = NEXLEY_FORMS_PATH . 'assets/css/frontend-base.min.css';
        $raw_path = NEXLEY_FORMS_PATH . 'assets/css/frontend-base.css';
        $path = file_exists($min_path) ? $min_path : $raw_path;

        if (file_exists($path)) {
            $cached_css = (string) file_get_contents($path);
        } else {
            $cached_css = '';
        }

        return $cached_css;
    }
}
