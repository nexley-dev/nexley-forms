<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Native, Unboxed SVG Icon System for Nexley Forms Admin UI.
 * 
 * Rules:
 * - Bare, unboxed stroke icons only. No badges, no circles, no pill containers, no gradients.
 * - Single stroke weight (1.5px at 20x20 viewbox), round joins/caps, currentColor only.
 * - Sized via CSS (default 16x16 or 20x20).
 * - Self-contained inline SVG sprite or direct inline markup.
 */
final class Icons {

    /**
     * Map of raw SVG paths for all plugin admin glyphs.
     * All paths designed on 20x20 grid with stroke="currentColor", stroke-width="1.5", fill="none".
     *
     * @var array<string, string>
     */
    private static array $glyphs = [
        // Navigation / Tabs
        'build'        => '<rect x="2.5" y="2.5" width="15" height="15" rx="2"/><line x1="2.5" y1="7.5" x2="17.5" y2="7.5"/><line x1="8.5" y1="7.5" x2="8.5" y2="17.5"/>',
        'actions'      => '<rect x="2.5" y="4.5" width="15" height="11" rx="2"/><polyline points="3 5 10 11 17 5"/>',
        'settings'     => '<circle cx="10" cy="10" r="3"/><path d="M10 2.5a1.2 1.2 0 0 1 1.2 1.2v.3a6.8 6.8 0 0 0 1.6.65l.25-.2a1.2 1.2 0 0 1 1.7.35l.8 1.4a1.2 1.2 0 0 1-.35 1.7l-.25.18c.3.5.55 1.05.7 1.62h.3a1.2 1.2 0 0 1 1.2 1.2v1.6a1.2 1.2 0 0 1-1.2 1.2h-.3a6.8 6.8 0 0 0-.7 1.62l.25.18a1.2 1.2 0 0 1 .35 1.7l-.8 1.4a1.2 1.2 0 0 1-1.7.35l-.25-.2a6.8 6.8 0 0 0-1.6.65v.3a1.2 1.2 0 0 1-1.2 1.2h-1.6a1.2 1.2 0 0 1-1.2-1.2v-.3a6.8 6.8 0 0 0-1.6-.65l-.25.2a1.2 1.2 0 0 1-1.7-.35l-.8-1.4a1.2 1.2 0 0 1 .35-1.7l.25-.18a6.8 6.8 0 0 0-.7-1.62h-.3a1.2 1.2 0 0 1-1.2-1.2v-1.6a1.2 1.2 0 0 1 1.2-1.2h.3c.15-.57.4-1.12.7-1.62l-.25-.18a1.2 1.2 0 0 1-.35-1.7l.8-1.4a1.2 1.2 0 0 1 1.7-.35l.25.2c.5-.3 1.05-.52 1.6-.65v-.3a1.2 1.2 0 0 1 1.2-1.2h1.6z"/>',
        'preview'      => '<path d="M2 10s3-5.5 8-5.5 8 5.5 8 5.5-3 5.5-8 5.5S2 10 2 10z"/><circle cx="10" cy="10" r="2.5"/>',

        // History & Utilities
        'undo'         => '<path d="M4 7h8a5 5 0 0 1 5 5v0a5 5 0 0 1-5 5H6"/><polyline points="7 4 4 7 7 10"/>',
        'redo'         => '<path d="M16 7H8a5 5 0 0 0-5 5v0a5 5 0 0 0 5 5h6"/><polyline points="13 4 16 7 13 10"/>',
        'copy'         => '<rect x="6.5" y="6.5" width="10" height="10" rx="1.5"/><path d="M4 13.5H3.5a1.5 1.5 0 0 1-1.5-1.5V3.5A1.5 1.5 0 0 1 3.5 2H12a1.5 1.5 0 0 1 1.5 1.5V4"/>',
        'check'        => '<polyline points="3.5 10.5 7.5 14.5 16.5 5.5"/>',
        'close'        => '<line x1="4.5" y1="4.5" x2="15.5" y2="15.5"/><line x1="15.5" y1="4.5" x2="4.5" y2="15.5"/>',
        'trash'        => '<line x1="3" y1="5.5" x2="17" y2="5.5"/><line x1="7.5" y1="2.5" x2="12.5" y2="2.5"/><path d="M5 5.5l1 12a1.5 1.5 0 0 0 1.5 1.5h5a1.5 1.5 0 0 0 1.5-1.5l1-12"/><line x1="8.5" y1="9" x2="8.5" y2="15"/><line x1="11.5" y1="9" x2="11.5" y2="15"/>',
        'duplicate'    => '<rect x="6" y="6" width="10" height="10" rx="1.5"/><rect x="4" y="4" width="10" height="10" rx="1.5"/>',
        'drag'         => '<circle cx="7" cy="5" r="1" fill="currentColor"/><circle cx="13" cy="5" r="1" fill="currentColor"/><circle cx="7" cy="10" r="1" fill="currentColor"/><circle cx="13" cy="10" r="1" fill="currentColor"/><circle cx="7" cy="15" r="1" fill="currentColor"/><circle cx="13" cy="15" r="1" fill="currentColor"/>',
        'chevron-down' => '<polyline points="5 7.5 10 12.5 15 7.5"/>',
        'chevron-right'=> '<polyline points="7.5 5 12.5 10 7.5 15"/>',
        'plus'         => '<line x1="10" y1="3.5" x2="10" y2="16.5"/><line x1="3.5" y1="10" x2="16.5" y2="10"/>',
        'download'     => '<path d="M10 3.5v9M6 8.5l4 4 4-4"/><line x1="3.5" y1="16.5" x2="16.5" y2="16.5"/>',
        'upload'       => '<path d="M10 12.5v-9M6 7.5l4-4 4 4"/><line x1="3.5" y1="16.5" x2="16.5" y2="16.5"/>',
        'external'     => '<path d="M10 4H4a1.5 1.5 0 0 0-1.5 1.5v10.5A1.5 1.5 0 0 0 4 17.5h10.5A1.5 1.5 0 0 0 16 16v-6"/><polyline points="11 3 17 3 17 9"/><line x1="9" y1="11" x2="16.5" y2="3.5"/>',
        'search'       => '<circle cx="9" cy="9" r="5.5"/><line x1="13.2" y1="13.2" x2="17.5" y2="17.5"/>',
        'info'         => '<circle cx="10" cy="10" r="7.5"/><line x1="10" y1="9" x2="10" y2="14.5"/><circle cx="10" cy="6" r="0.75" fill="currentColor"/>',
        'filter'       => '<polygon points="3 4 17 4 11.5 11 11.5 16 8.5 18 8.5 11 3 4"/>',

        // Device viewports
        'desktop'      => '<rect x="2.5" y="3.5" width="15" height="10.5" rx="1.5"/><line x1="7" y1="16.5" x2="13" y2="16.5"/><line x1="10" y1="14" x2="10" y2="16.5"/>',
        'tablet'       => '<rect x="4" y="2.5" width="12" height="15" rx="2"/><circle cx="10" cy="15" r="0.75" fill="currentColor"/>',
        'mobile'       => '<rect x="5.5" y="2.5" width="9" height="15" rx="2"/><line x1="9" y1="4.5" x2="11" y2="4.5"/><circle cx="10" cy="15" r="0.75" fill="currentColor"/>',

        // Field Types (Unboxed palette icons)
        'text'         => '<line x1="4" y1="5.5" x2="16" y2="5.5"/><line x1="10" y1="5.5" x2="10" y2="16.5"/><line x1="7.5" y1="16.5" x2="12.5" y2="16.5"/>',
        'email'        => '<rect x="2.5" y="5" width="15" height="10" rx="1.5"/><polyline points="3 5.5 10 11 17 5.5"/>',
        'url'          => '<path d="M8.5 11.5a3.5 3.5 0 0 0 5 0l2-2a3.5 3.5 0 0 0-5-5l-1 1"/><path d="M11.5 8.5a3.5 3.5 0 0 0-5 0l-2 2a3.5 3.5 0 0 0 5 5l1-1"/>',
        'tel'          => '<path d="M4.5 3.5h3l1.5 3.5-2 1.5a9.5 9.5 0 0 0 4.5 4.5l1.5-2 3.5 1.5v3c0 .8-.7 1.5-1.5 1.5A13 13 0 0 1 3 4.5c0-.8.7-1.5 1.5-1.5z"/>',
        'number'       => '<line x1="3.5" y1="8" x2="16.5" y2="8"/><line x1="3.5" y1="13" x2="16.5" y2="13"/><line x1="7.5" y1="3.5" x2="5.5" y2="16.5"/><line x1="14.5" y1="3.5" x2="12.5" y2="16.5"/>',
        'textarea'     => '<rect x="2.5" y="3.5" width="15" height="13" rx="2"/><line x1="5.5" y1="7" x2="14.5" y2="7"/><line x1="5.5" y1="10" x2="14.5" y2="10"/><line x1="5.5" y1="13" x2="10.5" y2="13"/>',
        'select'       => '<rect x="2.5" y="4.5" width="15" height="11" rx="1.5"/><polyline points="12.5 8.5 14.5 10.5 16.5 8.5"/>',
        'radio'        => '<circle cx="10" cy="10" r="7"/><circle cx="10" cy="10" r="3" fill="currentColor"/>',
        'checkbox'     => '<rect x="3.5" y="3.5" width="13" height="13" rx="2"/><polyline points="6.5 10 9 12.5 13.5 7.5"/>',
        'date'         => '<rect x="3" y="4.5" width="14" height="12.5" rx="1.5"/><line x1="3" y1="8.5" x2="17" y2="8.5"/><line x1="6.5" y1="2.5" x2="6.5" y2="5"/><line x1="13.5" y1="2.5" x2="13.5" y2="5"/>',
        'hidden'       => '<path d="M2.5 10s3-5 7.5-5 7.5 5 7.5 5-3 5-7.5 5-7.5-5-7.5-5z"/><line x1="3" y1="3" x2="17" y2="17"/>',
        'submit'       => '<polyline points="4 10 16 10"/><polyline points="11 5 16 10 11 15"/>',
        'heading'      => '<line x1="4.5" y1="4.5" x2="4.5" y2="15.5"/><line x1="15.5" y1="4.5" x2="15.5" y2="15.5"/><line x1="4.5" y1="10" x2="15.5" y2="10"/>',
        'paragraph'    => '<path d="M12 4H6.5a3.5 3.5 0 0 0 0 7H12M12 4v12M15 4v12"/>',
        'divider'      => '<line x1="2.5" y1="10" x2="17.5" y2="10"/><circle cx="10" cy="10" r="1.5" fill="currentColor"/>',
        'spacer'       => '<line x1="4" y1="4" x2="16" y2="4"/><line x1="4" y1="16" x2="16" y2="16"/><polyline points="7.5 7.5 10 5 12.5 7.5"/><polyline points="7.5 12.5 10 15 12.5 12.5"/>',
        'html'         => '<polyline points="6.5 6.5 3 10 6.5 13.5"/><polyline points="13.5 6.5 17 10 13.5 13.5"/><line x1="11.5" y1="4" x2="8.5" y2="16"/>',
        'name'         => '<circle cx="10" cy="6.5" r="3"/><path d="M4 16.5c0-3 2.5-4.5 6-4.5s6 1.5 6 4.5"/>',
        'address'      => '<path d="M10 2.5a5.5 5.5 0 0 0-5.5 5.5c0 4 5.5 9.5 5.5 9.5s5.5-5.5 5.5-9.5a5.5 5.5 0 0 0-5.5-5.5z"/><circle cx="10" cy="8" r="2"/>',
        'file'         => '<path d="M4 3.5h7.5L16 8v8.5a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 4 16.5v-13z"/><polyline points="11 3.5 11 8.5 16 8.5"/><polyline points="10 11.5 10 15.5"/><polyline points="8 13.5 10 11.5 12 13.5"/>',
        'range'        => '<line x1="2.5" y1="10" x2="17.5" y2="10"/><circle cx="12" cy="10" r="3"/>',
        'rating'       => '<polygon points="10 2.5 12.4 7.4 17.8 8.1 13.9 12 14.8 17.4 10 14.8 5.2 17.4 6.1 12 2.2 8.1 7.6 7.4 10 2.5"/>',
        'signature'    => '<path d="M3.5 15.5c2-2 3.5-5 5.5-4s-1 5 1 5 3-5 5-5 1 3 2.5 3.5"/>',
        'migrate'      => '<path d="M14 6l3.5 3.5L14 13"/><line x1="2.5" y1="9.5" x2="17.5" y2="9.5"/><path d="M6 14l-3.5-3.5L6 7"/>',
    ];

    /**
     * Get raw SVG element string for an icon.
     *
     * @param string $name       Icon glyph name.
     * @param int    $size       Display size in px (width and height).
     * @param string $extra_class Additional CSS classes.
     * @return string
     */
    public static function get(string $name, int $size = 16, string $extra_class = ''): string {
        $key = sanitize_key($name);
        $path = self::$glyphs[$key] ?? self::$glyphs['build'];
        $class = 'nexley-icon nexley-icon--' . $key;
        if (!empty($extra_class)) {
            $class .= ' ' . esc_attr($extra_class);
        }

        return sprintf(
            '<svg class="%s" width="%d" height="%d" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
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
        echo '<svg xmlns="http://www.w3.org/2000/svg" style="display:none;" id="nexley-icons-sprite">' . "\n";
        foreach (self::$glyphs as $name => $content) {
            printf(
                '  <symbol id="nexley-icon-%s" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">%s</symbol>' . "\n",
                esc_attr($name),
                $content // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            );
        }
        echo '</svg>' . "\n";
    }
}
