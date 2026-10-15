<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Extensible Field Registration API.
 * Manages all registered field types (Core, Structural, HTML, Compound, Advanced).
 */
final class Field_Registry {

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $field_types = [];

    private static bool $initialized = false;

    /**
     * Initialize built-in field types and trigger registration hook.
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::register_core_fields();
        self::register_structural_fields();
        self::register_html_field();
        self::register_compound_fields();
        self::register_advanced_fields();

        /**
         * Fires when field types are being registered.
         * Third-party add-ons can hook here to register custom field types via Field_Registry::register_type().
         */
        do_action('nexley_forms_register_fields');
    }

    /**
     * Register a new field type.
     *
     * @param string $type Field type identifier (e.g. 'text', 'email').
     * @param array{
     *     title: string,
     *     category: string,
     *     icon?: string,
     *     description?: string,
     *     is_input?: bool,
     *     defaults?: array<string, mixed>,
     *     render_callback?: callable,
     *     sanitize_callback?: callable,
     *     validate_callback?: callable
     * } $args
     */
    public static function register_type(string $type, array $args): void {
        $type = sanitize_key($type);
        $defaults = [
            'type'              => $type,
            'title'             => ucwords(str_replace(['_', '-'], ' ', $type)),
            'category'          => 'core', // 'core', 'structural', 'compound', 'advanced'
            'icon'              => 'text',
            'description'       => '',
            'is_input'          => true,
            'defaults'          => [
                'label'              => '',
                'name'               => $type,
                'placeholder'        => '',
                'default'            => '',
                'help_text'          => '',
                'required'           => false,
                'span'               => 12,
                'css_class'          => '',
                'custom_attributes'  => [],
                'validation_message' => '',
                'logic'              => null, // Conditional logic config
            ],
            'render_callback'   => null,
            'sanitize_callback' => null,
            'validate_callback' => null,
        ];

        self::$field_types[$type] = array_merge($defaults, $args);
    }

    /**
     * Get all registered field types.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function get_field_types(): array {
        self::init();
        return self::$field_types;
    }

    /**
     * Get a single field type definition.
     *
     * @param string $type
     * @return array<string, mixed>|null
     */
    public static function get_field_type(string $type): ?array {
        self::init();
        return self::$field_types[$type] ?? null;
    }

    /**
     * Check if a field type is an actual user input (vs structural/html).
     *
     * @param string $type
     * @return bool
     */
    public static function is_input_field(string $type): bool {
        $def = self::get_field_type($type);
        return $def ? ($def['is_input'] ?? true) : false;
    }

    /* --------------------------------------------------------------------------
     * 1. Core Fields Registration
     * -------------------------------------------------------------------------- */

    private static function register_core_fields(): void {
        // Text
        self::register_type('text', [
            'title'       => __('Single Line Text', 'nexley-forms'),
            'category'    => 'core',
            'icon'        => 'text',
            'description' => __('Standard single-line text input.', 'nexley-forms'),
        ]);

        // Email
        self::register_type('email', [
            'title'             => __('Email Address', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'email',
            'description'       => __('Validated email input field.', 'nexley-forms'),
            'sanitize_callback' => static fn($val) => sanitize_email((string) $val),
            'validate_callback' => static function ($val, array $field): ?string {
                if ($val !== '' && !is_email((string) $val)) {
                    return __('Please enter a valid email address.', 'nexley-forms');
                }
                return null;
            },
        ]);

        // URL
        self::register_type('url', [
            'title'             => __('Website / URL', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'url',
            'description'       => __('Standard website URL input.', 'nexley-forms'),
            'sanitize_callback' => static fn($val) => esc_url_raw((string) $val),
            'validate_callback' => static function ($val, array $field): ?string {
                if ($val !== '' && !filter_var($val, FILTER_VALIDATE_URL)) {
                    return __('Please enter a valid web URL.', 'nexley-forms');
                }
                return null;
            },
        ]);

        // Phone
        self::register_type('phone', [
            'title'             => __('Phone Number', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'tel',
            'description'       => __('Telephone number input.', 'nexley-forms'),
            'sanitize_callback' => static fn($val) => sanitize_text_field((string) $val),
        ]);

        // Number
        self::register_type('number', [
            'title'             => __('Number', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'number',
            'description'       => __('Numeric input with min, max, and step.', 'nexley-forms'),
            'defaults'          => [
                'min'  => '',
                'max'  => '',
                'step' => 'any',
            ],
            'sanitize_callback' => static fn($val) => ($val === '' ? '' : (is_numeric($val) ? $val + 0 : sanitize_text_field((string) $val))),
        ]);

        // Textarea
        self::register_type('textarea', [
            'title'             => __('Multi-line Textarea', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'textarea',
            'description'       => __('Multi-line text area for messages and comments.', 'nexley-forms'),
            'defaults'          => [
                'rows' => 4,
            ],
            'sanitize_callback' => static fn($val) => sanitize_textarea_field((string) $val),
        ]);

        // Select
        self::register_type('select', [
            'title'       => __('Dropdown Select', 'nexley-forms'),
            'category'    => 'core',
            'icon'        => 'select',
            'description' => __('Dropdown select list.', 'nexley-forms'),
            'defaults'    => [
                'options' => [
                    ['value' => 'Option 1', 'label' => 'Option 1'],
                    ['value' => 'Option 2', 'label' => 'Option 2'],
                ],
            ],
        ]);

        // Radio Group
        self::register_type('radio', [
            'title'       => __('Radio Buttons', 'nexley-forms'),
            'category'    => 'core',
            'icon'        => 'radio',
            'description' => __('Radio button group allowing single selection.', 'nexley-forms'),
            'defaults'    => [
                'options' => [
                    ['value' => 'Choice 1', 'label' => 'Choice 1'],
                    ['value' => 'Choice 2', 'label' => 'Choice 2'],
                ],
            ],
        ]);

        // Checkbox Group
        self::register_type('checkbox', [
            'title'             => __('Checkbox Group', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'checkbox',
            'description'       => __('Checkboxes allowing multiple selections.', 'nexley-forms'),
            'defaults'          => [
                'options' => [
                    ['value' => 'Option 1', 'label' => 'Option 1'],
                    ['value' => 'Option 2', 'label' => 'Option 2'],
                ],
            ],
            'sanitize_callback' => static function ($val) {
                if (is_array($val)) {
                    return array_values(array_map('sanitize_text_field', $val));
                }
                return sanitize_text_field((string) $val);
            },
        ]);

        // Consent (Single Checkbox / GDPR)
        self::register_type('consent', [
            'title'             => __('Consent / Terms Checkbox', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'consent',
            'description'       => __('GDPR agreement or terms acceptance checkbox.', 'nexley-forms'),
            'defaults'          => [
                'label'      => __('I agree to the Terms of Service and Privacy Policy.', 'nexley-forms'),
                'required'   => true,
                'checkbox_val' => 'yes',
            ],
            'sanitize_callback' => static fn($val) => (!empty($val) ? 'yes' : ''),
            'validate_callback' => static function ($val, array $field): ?string {
                if (!empty($field['required']) && empty($val)) {
                    return __('You must agree to continue.', 'nexley-forms');
                }
                return null;
            },
        ]);

        // Date
        self::register_type('date', [
            'title'             => __('Date Picker', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'date',
            'description'       => __('HTML5 native date input.', 'nexley-forms'),
            'defaults'          => [
                'min' => '',
                'max' => '',
            ],
            'sanitize_callback' => static fn($val) => sanitize_text_field((string) $val),
        ]);

        // Hidden
        self::register_type('hidden', [
            'title'             => __('Hidden Field', 'nexley-forms'),
            'category'          => 'core',
            'icon'              => 'hidden',
            'description'       => __('Hidden input value for passing tracking or query parameters.', 'nexley-forms'),
            'defaults'          => [
                'default' => '',
            ],
            'sanitize_callback' => static fn($val) => sanitize_text_field((string) $val),
        ]);

        // Submit Button
        self::register_type('submit', [
            'title'       => __('Submit Button', 'nexley-forms'),
            'category'    => 'core',
            'icon'        => 'submit',
            'description' => __('Form submission trigger button.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'label'     => __('Submit', 'nexley-forms'),
                'span'      => 12,
                'css_class' => '',
            ],
        ]);
    }

    /* --------------------------------------------------------------------------
     * 2. Structural Fields Registration
     * -------------------------------------------------------------------------- */

    private static function register_structural_fields(): void {
        // Heading
        self::register_type('heading', [
            'title'       => __('Heading', 'nexley-forms'),
            'category'    => 'structural',
            'icon'        => 'heading',
            'description' => __('Section header within the form.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'label'     => __('Section Title', 'nexley-forms'),
                'tag'       => 'h3',
                'span'      => 12,
                'css_class' => '',
            ],
        ]);

        // Paragraph
        self::register_type('paragraph', [
            'title'       => __('Paragraph Text', 'nexley-forms'),
            'category'    => 'structural',
            'icon'        => 'paragraph',
            'description' => __('Explanatory text block.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'content'   => __('Enter your instructions or guidance here.', 'nexley-forms'),
                'span'      => 12,
                'css_class' => '',
            ],
        ]);

        // Divider
        self::register_type('divider', [
            'title'       => __('Divider Rule', 'nexley-forms'),
            'category'    => 'structural',
            'icon'        => 'divider',
            'description' => __('Horizontal rule separating form sections.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'span'      => 12,
                'css_class' => '',
            ],
        ]);

        // Spacer
        self::register_type('spacer', [
            'title'       => __('Spacer', 'nexley-forms'),
            'category'    => 'structural',
            'icon'        => 'spacer',
            'description' => __('Empty space to balance multi-column grids.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'height'    => 20,
                'span'      => 12,
                'css_class' => '',
            ],
        ]);
    }

    /* --------------------------------------------------------------------------
     * 3. HTML Field (Strict Sanitization, No PHP/Shortcodes)
     * -------------------------------------------------------------------------- */

    private static function register_html_field(): void {
        self::register_type('html', [
            'title'       => __('Custom HTML', 'nexley-forms'),
            'category'    => 'structural',
            'icon'        => 'html',
            'description' => __('Sanitized custom HTML markup respecting grid span.', 'nexley-forms'),
            'is_input'    => false,
            'defaults'    => [
                'content'   => '<div>' . esc_html__('Custom content', 'nexley-forms') . '</div>',
                'span'      => 12,
                'css_class' => '',
            ],
            'sanitize_callback' => static function ($val) {
                // Content is sanitized with wp_kses (post-allowed tags) on save and on import,
                // unless user has unfiltered_html capability. Never execute shortcodes or PHP.
                if (current_user_can('unfiltered_html')) {
                    return (string) $val;
                }
                return wp_kses_post((string) $val);
            },
        ]);
    }

    /* --------------------------------------------------------------------------
     * 4. Compound Fields Registration
     * -------------------------------------------------------------------------- */

    private static function register_compound_fields(): void {
        // Name (First / Last)
        self::register_type('name', [
            'title'             => __('Name (First & Last)', 'nexley-forms'),
            'category'          => 'compound',
            'icon'              => 'name',
            'description'       => __('Compound field capturing first and last names.', 'nexley-forms'),
            'defaults'          => [
                'label'              => __('Full Name', 'nexley-forms'),
                'first_placeholder'  => __('First Name', 'nexley-forms'),
                'last_placeholder'   => __('Last Name', 'nexley-forms'),
                'required'           => false,
                'span'               => 12,
            ],
            'sanitize_callback' => static function ($val) {
                if (is_array($val)) {
                    return [
                        'first' => sanitize_text_field((string) ($val['first'] ?? '')),
                        'last'  => sanitize_text_field((string) ($val['last'] ?? '')),
                    ];
                }
                return sanitize_text_field((string) $val);
            },
        ]);

        // Address
        self::register_type('address', [
            'title'             => __('Physical Address', 'nexley-forms'),
            'category'          => 'compound',
            'icon'              => 'address',
            'description'       => __('Structured multi-part street address.', 'nexley-forms'),
            'defaults'          => [
                'label'    => __('Address', 'nexley-forms'),
                'span'     => 12,
                'required' => false,
            ],
            'sanitize_callback' => static function ($val) {
                if (is_array($val)) {
                    return [
                        'street'  => sanitize_text_field((string) ($val['street'] ?? '')),
                        'street2' => sanitize_text_field((string) ($val['street2'] ?? '')),
                        'city'    => sanitize_text_field((string) ($val['city'] ?? '')),
                        'state'   => sanitize_text_field((string) ($val['state'] ?? '')),
                        'zip'     => sanitize_text_field((string) ($val['zip'] ?? '')),
                        'country' => sanitize_text_field((string) ($val['country'] ?? '')),
                    ];
                }
                return sanitize_text_field((string) $val);
            },
        ]);
    }

    /* --------------------------------------------------------------------------
     * 5. Advanced Fields Registration
     * -------------------------------------------------------------------------- */

    private static function register_advanced_fields(): void {
        // File Upload
        self::register_type('file', [
            'title'             => __('Secure File Upload', 'nexley-forms'),
            'category'          => 'advanced',
            'icon'              => 'file',
            'description'       => __('Encrypted/protected direct file uploads with MIME validation.', 'nexley-forms'),
            'defaults'          => [
                'label'      => __('Attach File', 'nexley-forms'),
                'allowed_ext'=> 'pdf,doc,docx,jpg,jpeg,png,webp,zip',
                'max_size_mb'=> 5,
                'multiple'   => false,
                'span'       => 12,
            ],
            'sanitize_callback' => static function ($val) {
                // If uploaded, holds array or URL of sanitized file paths
                if (is_array($val)) {
                    return array_map('esc_url_raw', $val);
                }
                return esc_url_raw((string) $val);
            },
        ]);

        // Range Slider
        self::register_type('range', [
            'title'             => __('Range Slider', 'nexley-forms'),
            'category'          => 'advanced',
            'icon'              => 'range',
            'description'       => __('Slider bar selecting values within a range.', 'nexley-forms'),
            'defaults'          => [
                'min'   => 0,
                'max'   => 100,
                'step'  => 1,
                'default' => 50,
                'span'  => 12,
            ],
            'sanitize_callback' => static fn($val) => (is_numeric($val) ? (float) $val : 0),
        ]);

        // Star / Numeric Rating
        self::register_type('rating', [
            'title'             => __('Rating', 'nexley-forms'),
            'category'          => 'advanced',
            'icon'              => 'rating',
            'description'       => __('Star or score rating selector.', 'nexley-forms'),
            'defaults'          => [
                'max_rating' => 5,
                'span'       => 12,
            ],
            'sanitize_callback' => static fn($val) => absint($val),
        ]);

        // Repeater
        self::register_type('repeater', [
            'title'             => __('Repeater Field', 'nexley-forms'),
            'category'          => 'advanced',
            'icon'              => 'repeater',
            'description'       => __('Repeatable sub-fields rows (e.g. guest list, items).', 'nexley-forms'),
            'defaults'          => [
                'label'      => __('Repeatable Items', 'nexley-forms'),
                'sub_fields' => [
                    ['type' => 'text', 'name' => 'item_name', 'label' => 'Item Name'],
                ],
                'max_rows'   => 10,
                'span'       => 12,
            ],
            'sanitize_callback' => static function ($val) {
                if (is_array($val)) {
                    $cleaned = [];
                    foreach ($val as $row) {
                        if (is_array($row)) {
                            $cleaned_row = [];
                            foreach ($row as $k => $v) {
                                $cleaned_row[sanitize_key($k)] = sanitize_text_field((string) $v);
                            }
                            $cleaned[] = $cleaned_row;
                        }
                    }
                    return $cleaned;
                }
                return [];
            },
        ]);
    }
}
