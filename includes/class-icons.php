<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Authentic Lucide SVG Icon System for Nexley Forms Admin UI.
 * Sourced directly from lucide-static v1.57.0 (ISC License).
 *
 * Rules:
 * - Native Lucide geometry: viewBox="0 0 24 24", stroke-width="2", round caps/joins, fill="none", stroke="currentColor".
 * - Bare, unboxed stroke icons only. No custom vector-effect or custom stroke-width.
 * - Sized via CSS or width/height attributes: 16px (tabs, palette, inline), 20px (field bar), 24px (toolbar, controls).
 * - Sourced directly from npm lucide-static package (see assets/icons/LICENSE).
 */
final class Icons {

    /**
     * Map of raw SVG child elements for all plugin glyphs.
     * All paths from lucide-static v1.57.0 on native 24x24 grid.
     *
     * @var array<string, string>
     */
    private static array $glyphs = [
        'acceptance' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /> <path d="m9 12 2 2 4-4" />',
        'actions' => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" /> <rect x="2" y="4" width="20" height="16" rx="2" />',
        'address' => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" /> <circle cx="12" cy="10" r="3" />',
        'arrow-right-left' => '<path d="m16 3 4 4-4 4" /> <path d="M20 7H4" /> <path d="m8 21-4-4 4-4" /> <path d="M4 17h16" />',
        'build' => '<rect width="7" height="9" x="3" y="3" rx="1" /> <rect width="7" height="5" x="14" y="3" rx="1" /> <rect width="7" height="9" x="14" y="12" rx="1" /> <rect width="7" height="5" x="3" y="16" rx="1" />',
        'check' => '<path d="M20 6 9 17l-5-5" />',
        'checkbox' => '<rect width="18" height="18" x="3" y="3" rx="2" /> <path d="m16 9-5.5 5.5L8 12" />',
        'checkbox-group' => '<path d="M13 5h8" /> <path d="M13 12h8" /> <path d="M13 19h8" /> <path d="m3 17 2 2 4-4" /> <path d="m3 7 2 2 4-4" />',
        'chevrons-up-down' => '<path d="m7 15 5 5 5-5" /> <path d="m7 9 5-5 5 5" />',
        'circle-dot' => '<circle cx="12" cy="12" r="1" /> <circle cx="12" cy="12" r="10" />',
        'clipboard-copy' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1" /> <path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /> <path d="M16 4h2a2 2 0 0 1 2 2v4" /> <path d="M21 14H11" /> <path d="m15 10-4 4 4 4" />',
        'close' => '<path d="M18 6 6 18" /> <path d="m6 6 12 12" />',
        'consent' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /> <path d="m9 12 2 2 4-4" />',
        'copy' => '<rect width="8" height="4" x="8" y="2" rx="1" ry="1" /> <path d="M8 4H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /> <path d="M16 4h2a2 2 0 0 1 2 2v4" /> <path d="M21 14H11" /> <path d="m15 10-4 4 4 4" />',
        'date' => '<path d="M8 2v3" /> <path d="M16 2v3" /> <rect x="3" y="3" width="18" height="18" rx="2" /> <path d="M3 9h18" />',
        'desktop' => '<rect width="20" height="14" x="2" y="3" rx="2" /> <line x1="8" x2="16" y1="21" y2="21" /> <line x1="12" x2="12" y1="17" y2="21" />',
        'divider' => '<path d="m16 16-4 4-4-4" /> <path d="M3 12h18" /> <path d="m8 8 4-4 4 4" />',
        'download' => '<path d="M12 15V3" /> <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" /> <path d="m7 10 5 5 5-5" />',
        'drag' => '<circle cx="9" cy="12" r="1" /> <circle cx="9" cy="5" r="1" /> <circle cx="9" cy="19" r="1" /> <circle cx="15" cy="12" r="1" /> <circle cx="15" cy="5" r="1" /> <circle cx="15" cy="19" r="1" />',
        'duplicate' => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2" /> <path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2" />',
        'email' => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7" /> <rect x="2" y="4" width="20" height="16" rx="2" />',
        'external' => '<path d="M15 3h6v6" /> <path d="M10 14 21 3" /> <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />',
        'external-link' => '<path d="M15 3h6v6" /> <path d="M10 14 21 3" /> <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6" />',
        'file' => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z" /> <path d="M14 2v5a1 1 0 0 0 1 1h5" /> <path d="M12 12v6" /> <path d="m15 15-3-3-3 3" />',
        'file-up' => '<path d="M6 22a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8a2.4 2.4 0 0 1 1.704.706l3.588 3.588A2.4 2.4 0 0 1 20 8v12a2 2 0 0 1-2 2z" /> <path d="M14 2v5a1 1 0 0 0 1 1h5" /> <path d="M12 12v6" /> <path d="m15 15-3-3-3 3" />',
        'grip-vertical' => '<circle cx="9" cy="12" r="1" /> <circle cx="9" cy="5" r="1" /> <circle cx="9" cy="19" r="1" /> <circle cx="15" cy="12" r="1" /> <circle cx="15" cy="5" r="1" /> <circle cx="15" cy="19" r="1" />',
        'heading' => '<path d="M6 12h12" /> <path d="M6 20V4" /> <path d="M18 20V4" />',
        'hidden' => '<path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49" /> <path d="M14.084 14.158a3 3 0 0 1-4.242-4.242" /> <path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143" /> <path d="m2 2 20 20" />',
        'html' => '<path d="m16 18 6-6-6-6" /> <path d="m8 6-6 6 6 6" />',
        'info' => '<circle cx="12" cy="12" r="10" /> <path d="M12 16v-4" /> <path d="M12 8h.01" />',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /> <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />',
        'list-checks' => '<path d="M13 5h8" /> <path d="M13 12h8" /> <path d="M13 19h8" /> <path d="m3 17 2 2 4-4" /> <path d="m3 7 2 2 4-4" />',
        'list-plus' => '<path d="M16 5H3" /> <path d="M11 12H3" /> <path d="M16 19H3" /> <path d="M18 9v6" /> <path d="M21 12h-6" />',
        'migrate' => '<path d="m16 3 4 4-4 4" /> <path d="M20 7H4" /> <path d="m8 21-4-4 4-4" /> <path d="M4 17h16" />',
        'mobile' => '<rect width="14" height="20" x="5" y="2" rx="2" ry="2" /> <path d="M12 18h.01" />',
        'move-vertical' => '<path d="M12 2v20" /> <path d="m8 18 4 4 4-4" /> <path d="m8 6 4-4 4 4" />',
        'name' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /> <circle cx="12" cy="7" r="4" />',
        'number' => '<line x1="4" x2="20" y1="9" y2="9" /> <line x1="4" x2="20" y1="15" y2="15" /> <line x1="10" x2="8" y1="3" y2="21" /> <line x1="16" x2="14" y1="3" y2="21" />',
        'paragraph' => '<path d="M13 4v16" /> <path d="M17 4v16" /> <path d="M19 4H9.5a4.5 4.5 0 0 0 0 9H13" />',
        'plus' => '<path d="M5 12h14" /> <path d="M12 5v14" />',
        'preview' => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0" /> <circle cx="12" cy="12" r="3" />',
        'radio' => '<circle cx="12" cy="12" r="1" /> <circle cx="12" cy="12" r="10" />',
        'range' => '<path d="M10 5H3" /> <path d="M12 19H3" /> <path d="M14 3v4" /> <path d="M16 17v4" /> <path d="M21 12h-9" /> <path d="M21 19h-5" /> <path d="M21 5h-7" /> <path d="M8 10v4" /> <path d="M8 12H3" />',
        'rating' => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z" />',
        'redo' => '<path d="m15 14 5-5-5-5" /> <path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13" />',
        'repeater' => '<path d="M16 5H3" /> <path d="M11 12H3" /> <path d="M16 19H3" /> <path d="M18 9v6" /> <path d="M21 12h-6" />',
        'select' => '<path d="m7 15 5 5 5-5" /> <path d="m7 9 5-5 5 5" />',
        'separator-horizontal' => '<path d="m16 16-4 4-4-4" /> <path d="M3 12h18" /> <path d="m8 8 4-4 4 4" />',
        'settings' => '<path d="M9.671 4.136a2.34 2.34 0 0 1 4.659 0 2.34 2.34 0 0 0 3.319 1.915 2.34 2.34 0 0 1 2.33 4.033 2.34 2.34 0 0 0 0 3.831 2.34 2.34 0 0 1-2.33 4.033 2.34 2.34 0 0 0-3.319 1.915 2.34 2.34 0 0 1-4.659 0 2.34 2.34 0 0 0-3.32-1.915 2.34 2.34 0 0 1-2.33-4.033 2.34 2.34 0 0 0 0-3.831A2.34 2.34 0 0 1 6.35 6.051a2.34 2.34 0 0 0 3.319-1.915" /> <circle cx="12" cy="12" r="3" />',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" /> <path d="m9 12 2 2 4-4" />',
        'signature' => '<path d="M13 21h8" /> <path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z" />',
        'sliders-horizontal' => '<path d="M10 5H3" /> <path d="M12 19H3" /> <path d="M14 3v4" /> <path d="M16 17v4" /> <path d="M21 12h-9" /> <path d="M21 19h-5" /> <path d="M21 5h-7" /> <path d="M8 10v4" /> <path d="M8 12H3" />',
        'spacer' => '<path d="M12 2v20" /> <path d="m8 18 4 4 4-4" /> <path d="m8 6 4-4 4 4" />',
        'square-check' => '<rect width="18" height="18" x="3" y="3" rx="2" /> <path d="m16 9-5.5 5.5L8 12" />',
        'submit' => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z" /> <path d="m21.854 2.147-10.94 10.939" />',
        'tablet' => '<rect width="16" height="20" x="4" y="2" rx="2" ry="2" /> <line x1="12" x2="12.01" y1="18" y2="18" />',
        'tel' => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384" />',
        'text' => '<path d="M12 4v16" /> <path d="M4 7V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v2" /> <path d="M9 20h6" />',
        'textarea' => '<path d="M21 5H3" /> <path d="M15 12H3" /> <path d="M17 19H3" />',
        'trash' => '<path d="M10 11v6" /> <path d="M14 11v6" /> <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /> <path d="M3 6h18" /> <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />',
        'trash-2' => '<path d="M10 11v6" /> <path d="M14 11v6" /> <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" /> <path d="M3 6h18" /> <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />',
        'undo' => '<path d="M9 14 4 9l5-5" /> <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11" />',
        'upload' => '<path d="M12 3v12" /> <path d="m17 8-5-5-5 5" /> <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />',
        'url' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" /> <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />',
    ];

    /**
     * Get raw SVG element string for an icon.
     *
     * @param string $name        Icon glyph name.
     * @param int    $size        Display size in px (width and height). Default 16.
     * @param string $extra_class Additional CSS classes.
     * @return string
     */
    public static function get(string $name, int $size = 16, string $extra_class = ''): string {
        $key = sanitize_key($name);
        $path = self::$glyphs[$key] ?? (self::$glyphs['build'] ?? '');
        $class = 'nexley-icon nexley-icon--' . $key;
        if (!empty($extra_class)) {
            $class .= ' ' . esc_attr($extra_class);
        }

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            $class,
            $size,
            $size,
            $path
        );
    }

    /**
     * Echo icon directly.
     *
     * @param string $name
     * @param int    $size
     * @param string $extra_class
     */
    public static function render(string $name, int $size = 16, string $extra_class = ''): void {
        echo self::get($name, $size, $extra_class); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Output inline hidden SVG sprite containing all glyphs for client-side JS reuse.
     */
    public static function render_sprite(): void {
        echo '<svg xmlns="http://www.w3.org/2000/svg" style="display:none;" id="nexley-icons-sprite">' . "
";
        foreach (self::$glyphs as $name => $content) {
            printf(
                '  <symbol id="nexley-icon-%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%s</symbol>' . "
",
                esc_attr($name),
                $content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            );
        }
        echo '</svg>' . "
";
    }
}
