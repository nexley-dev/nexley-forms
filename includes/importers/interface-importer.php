<?php
declare(strict_types=1);

namespace Nexley_Forms\Importers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Interface for third-party form migration adapters.
 */
interface Importer_Interface {

    /**
     * Unique identifier for the importer (e.g. 'ninja-forms', 'gravity-forms', 'cf7').
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * Human-readable title of the source system.
     *
     * @return string
     */
    public function get_title(): string;

    /**
     * Short description of how data is parsed from this source.
     *
     * @return string
     */
    public function get_description(): string;

    /**
     * Verification status label according to task guidelines:
     * Either "Direct source code inspected" or "Documented, unverified".
     *
     * @return string
     */
    public function get_verification_status(): string;

    /**
     * Whether the source plugin or its database tables are present in the current WordPress environment.
     *
     * @return bool
     */
    public function is_available(): bool;

    /**
     * Detect and list existing forms available to import from this source.
     *
     * @return array<int|string, string> Key: form ID/slug, Value: form title
     */
    public function get_available_forms(): array;

    /**
     * Convert source form data (either form ID or parsed array/export object) into a Nexley Form Schema.
     *
     * @param mixed $source Form ID or data structure.
     * @return array<string, mixed> Converted Nexley Form Schema v1.
     */
    public function convert(mixed $source): array;
}
