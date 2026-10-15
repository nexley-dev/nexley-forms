<?php
declare(strict_types=1);

namespace Nexley_Forms;

use Nexley_Forms\Importers\Importer_Interface;
use Nexley_Forms\Importers\Importer_Ninja_Forms;
use Nexley_Forms\Importers\Importer_Gravity_Forms;
use Nexley_Forms\Importers\Importer_CF7;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Controller for Native .nex Form Envelope import/export, third-party adapters, and shortcode replacement.
 */
final class Importer_Exporter {

    public const ENVELOPE_FORMAT = 'nexley-form';
    public const ENVELOPE_SCHEMA = 1;

    /**
     * @var array<string, Importer_Interface>|null
     */
    private static ?array $importers = null;

    /**
     * Get all registered third-party importer adapters.
     *
     * @return array<string, Importer_Interface>
     */
    public static function get_importers(): array {
        if (self::$importers === null) {
            self::$importers = [
                'ninja-forms'   => new Importer_Ninja_Forms(),
                'gravity-forms' => new Importer_Gravity_Forms(),
                'cf7'           => new Importer_CF7(),
            ];
        }
        return self::$importers;
    }

    /**
     * Get single importer adapter by ID.
     *
     * @param string $id
     * @return Importer_Interface|null
     */
    public static function get_importer(string $id): ?Importer_Interface {
        $importers = self::get_importers();
        return $importers[$id] ?? null;
    }

    /**
     * Export a form to Native .nex JSON Envelope.
     *
     * @param int $form_id
     * @return string
     */
    public static function export_form_nex(int $form_id): string {
        $post = get_post($form_id);
        if (!$post || $post->post_type !== Post_Types::CPT_FORM) {
            return '';
        }

        $schema = Form_Schema::get_form_schema($form_id);

        $envelope = [
            'format'      => self::ENVELOPE_FORMAT,
            'schema'      => self::ENVELOPE_SCHEMA,
            'generator'   => 'Nexley Forms ' . NEXLEY_FORMS_VERSION,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'form'        => $schema,
        ];

        return (string) wp_json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Provider labels map for standardized imported form titles.
     */
    public const PROVIDER_LABELS = [
        'ninja-forms'    => 'Ninja Forms',
        'gravity-forms'  => 'Gravity Forms',
        'cf7'            => 'Contact Form 7',
        'contact-form-7' => 'Contact Form 7',
        'native'         => '',
        'nex'            => '',
    ];

    /**
     * Generate a unique, sanitized, standardized imported form title.
     *
     * Rules:
     * - Third-party format: "<Original name> (<Provider>)"
     * - Empty original name fallback: "Untitled form (<Provider>)"
     * - Native (.nex): "<Original name>" without provider suffix (fallback: "Untitled form").
     * - Truncates original name if needed, never clips the provider suffix.
     * - Collision numbering: appends " 2", " 3", etc. if a form with the title exists in publish, draft, or trash.
     *
     * @param string $original_title Raw original title.
     * @param string $provider_id    Importer provider ID (e.g. 'ninja-forms', 'gravity-forms', 'cf7', 'native').
     * @return string Unique final form title.
     */
    public static function generate_import_title(string $original_title, string $provider_id): string {
        global $wpdb;

        $provider_key   = strtolower(trim($provider_id));
        $provider_label = self::PROVIDER_LABELS[$provider_key] ?? '';

        $clean_original = trim(sanitize_text_field($original_title));
        if ($clean_original === '') {
            $base_name = 'Untitled form';
        } else {
            $base_name = $clean_original;
        }

        $suffix = !empty($provider_label) ? " ({$provider_label})" : '';

        // Max safe length for base + suffix before collision numbering (WP post_title varchar 200)
        $max_total_len = 190;
        $suffix_len    = mb_strlen($suffix, 'UTF-8');
        $max_base_len  = $max_total_len - $suffix_len;

        if (mb_strlen($base_name, 'UTF-8') > $max_base_len) {
            $base_name = mb_substr($base_name, 0, $max_base_len, 'UTF-8');
        }

        $base_candidate = $base_name . $suffix;

        // Check if $base_candidate exists in database across any status (publish, draft, trash, etc.)
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
            Post_Types::CPT_FORM,
            $base_candidate
        ));

        if (!$exists) {
            return $base_candidate;
        }

        // Collision detected: find the next available counter ( 2, 3, etc.)
        $counter = 2;
        while (true) {
            $numbered_candidate = "{$base_candidate} {$counter}";
            $exists_num = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
                Post_Types::CPT_FORM,
                $numbered_candidate
            ));

            if (!$exists_num) {
                return $numbered_candidate;
            }
            $counter++;
        }
    }

    /**
     * Import a form from a Native .nex JSON Envelope.
     *
     * @param string $json_str
     * @return int Form post ID on success, or 0 on failure.
     */
    public static function import_form_nex(string $json_str): int {
        $data = json_decode($json_str, true);
        if (!is_array($data)) {
            return 0;
        }

        if (($data['format'] ?? '') !== self::ENVELOPE_FORMAT) {
            return 0;
        }

        $form_data = $data['form'] ?? null;
        if (!is_array($form_data)) {
            return 0;
        }

        $raw_title   = !empty($form_data['title']) ? (string) $form_data['title'] : '';
        $final_title = self::generate_import_title($raw_title, 'native');

        $post_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => $final_title,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id) || $post_id === 0) {
            return 0;
        }

        $form_data['id']    = $post_id;
        $form_data['title'] = $final_title;
        Form_Schema::save_form_schema($post_id, $form_data);

        update_post_meta($post_id, '_nexley_import_source', 'native');
        update_post_meta($post_id, '_nexley_import_source_name', $raw_title);

        return $post_id;
    }

    /**
     * Preview / Dry-run conversion from a third-party importer.
     *
     * @param string $importer_id
     * @param mixed  $source
     * @return array<string, mixed>
     */
    public static function preview_import(string $importer_id, mixed $source): array {
        $importer = self::get_importer($importer_id);
        if (!$importer) {
            return ['error' => 'Importer not found.'];
        }

        $schema = $importer->convert($source);
        if (!empty($schema)) {
            $raw_title = !empty($schema['title']) ? (string) $schema['title'] : '';
            $schema['title'] = self::generate_import_title($raw_title, $importer_id);
        }

        return $schema;
    }

    /**
     * Import a form from a third-party importer and persist as a new Nexley form.
     *
     * @param string $importer_id
     * @param mixed  $source
     * @return int Created form post ID or 0 on failure.
     */
    public static function import_from_importer(string $importer_id, mixed $source): int {
        $importer = self::get_importer($importer_id);
        if (!$importer) {
            return 0;
        }

        $schema = $importer->convert($source);
        if (empty($schema)) {
            return 0;
        }

        $raw_title   = !empty($schema['title']) ? (string) $schema['title'] : '';
        $final_title = self::generate_import_title($raw_title, $importer_id);

        $post_id = wp_insert_post([
            'post_type'   => Post_Types::CPT_FORM,
            'post_title'  => $final_title,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id) || $post_id === 0) {
            return 0;
        }

        $schema['id']    = $post_id;
        $schema['title'] = $final_title;
        Form_Schema::save_form_schema($post_id, $schema);

        update_post_meta($post_id, '_nexley_import_source', $importer_id);
        update_post_meta($post_id, '_nexley_import_source_name', $raw_title);

        return $post_id;
    }

    /**
     * Find all posts/pages containing third-party form shortcodes.
     *
     * @return list<array{
     *     post_id: int,
     *     post_title: string,
     *     post_type: string,
     *     system: string,
     *     source_id: string,
     *     shortcode: string
     * }>
     */
    public static function find_shortcodes(): array {
        global $wpdb;

        // Query posts containing shortcode prefixes
        $sql = "SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts}
                WHERE post_status IN ('publish', 'draft', 'pending')
                AND post_type NOT IN ('revision', 'nexley_form', 'nexley_entry')
                AND (post_content LIKE '%[ninja_form%' OR post_content LIKE '%[gravityform%' OR post_content LIKE '%[contact-form-7%')
                LIMIT 200";

        $rows = $wpdb->get_results($sql);
        $found = [];

        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $content = (string) $row->post_content;

            // 1. Ninja Forms: [ninja_form id=X] or [ninja_forms id=X]
            if (preg_match_all('/\[ninja_forms?\s+id=["\']?([0-9]+)["\']?[^\]]*\]/i', $content, $nf_matches, PREG_SET_ORDER)) {
                foreach ($nf_matches as $m) {
                    $found[] = [
                        'post_id'    => (int) $row->ID,
                        'post_title' => (string) ($row->post_title ?: "#{$row->ID}"),
                        'post_type'  => (string) $row->post_type,
                        'system'     => 'Ninja Forms',
                        'source_id'  => (string) $m[1],
                        'shortcode'  => (string) $m[0],
                    ];
                }
            }

            // 2. Gravity Forms: [gravityform id="X" ...]
            if (preg_match_all('/\[gravityform\s+id=["\']?([0-9]+)["\']?[^\]]*\]/i', $content, $gf_matches, PREG_SET_ORDER)) {
                foreach ($gf_matches as $m) {
                    $found[] = [
                        'post_id'    => (int) $row->ID,
                        'post_title' => (string) ($row->post_title ?: "#{$row->ID}"),
                        'post_type'  => (string) $row->post_type,
                        'system'     => 'Gravity Forms',
                        'source_id'  => (string) $m[1],
                        'shortcode'  => (string) $m[0],
                    ];
                }
            }

            // 3. Contact Form 7: [contact-form-7 id="X" ...]
            if (preg_match_all('/\[contact-form-7\s+id=["\']?([0-9]+)["\']?[^\]]*\]/i', $content, $cf_matches, PREG_SET_ORDER)) {
                foreach ($cf_matches as $m) {
                    $found[] = [
                        'post_id'    => (int) $row->ID,
                        'post_title' => (string) ($row->post_title ?: "#{$row->ID}"),
                        'post_type'  => (string) $row->post_type,
                        'system'     => 'Contact Form 7',
                        'source_id'  => (string) $m[1],
                        'shortcode'  => (string) $m[0],
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Replace a shortcode in a post with Nexley Forms shortcode, preserving backup.
     *
     * @param int    $post_id
     * @param string $old_shortcode
     * @param int    $new_form_id
     * @return bool
     */
    public static function replace_shortcode(int $post_id, string $old_shortcode, int $new_form_id): bool {
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }

        $new_shortcode = sprintf('[nexley_form id="%d"]', $new_form_id);
        if (strpos($post->post_content, $old_shortcode) === false) {
            return false;
        }

        // Store backup before modifying
        $backups = get_post_meta($post_id, '_nexley_shortcode_replacement_backup', true);
        if (!is_array($backups)) {
            $backups = [];
        }
        $backups[] = [
            'timestamp'     => current_time('mysql'),
            'old_shortcode' => $old_shortcode,
            'new_shortcode' => $new_shortcode,
            'full_content'  => $post->post_content,
        ];
        update_post_meta($post_id, '_nexley_shortcode_replacement_backup', $backups);

        $updated_content = str_replace($old_shortcode, $new_shortcode, $post->post_content);

        $res = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $updated_content,
        ]);

        return !is_wp_error($res) && $res > 0;
    }

    /**
     * Rollback the most recent shortcode replacement for a post.
     *
     * @param int $post_id
     * @return bool
     */
    public static function rollback_shortcode_replacement(int $post_id): bool {
        $backups = get_post_meta($post_id, '_nexley_shortcode_replacement_backup', true);
        if (!is_array($backups) || empty($backups)) {
            return false;
        }

        $last_backup = array_pop($backups);
        if (empty($last_backup['full_content'])) {
            return false;
        }

        $res = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $last_backup['full_content'],
        ]);

        if (!is_wp_error($res) && $res > 0) {
            update_post_meta($post_id, '_nexley_shortcode_replacement_backup', $backups);
            return true;
        }

        return false;
    }
}
