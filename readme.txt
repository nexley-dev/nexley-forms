=== Nexley Forms ===
Contributors: nexley
Donate link: https://nexley.dev
Tags: forms, contact form, performance, lightweight, form builder, gutenberg, drag and drop
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultra-lightweight, high-performance drag-and-drop form builder with zero frontend CSS bloat, dedicated database table, and sub-2KB runtime.

== Description ==

Nexley Forms is an elite WordPress form engine built for modern performance-first engineering and bespoke design systems. It rivals Gravity Forms and Ninja Forms in features, while keeping frontend assets radically lighter and SEO-optimized.

Most WordPress form plugins output heavy, opinionated CSS frameworks, redundant assets, and bloated JavaScript bundles on every page. Nexley Forms takes the opposite approach: **zero frontend CSS stylesheets in Raw Mode**, tiny scoped base CSS (< 3KB gz) in Preset Modes, and an ultra-lightweight vanilla JavaScript submission runtime (< 2KB gz) that is enqueued strictly on pages containing a form.

### Key Features

* **Strict Performance Budgets**: Core JS < 2KB gzipped (measured at 1,619 B), conditional logic module < 1.5KB gzipped (1,165 B), multi-step module < 2KB gzipped (1,221 B), and base stylesheet < 3KB gzipped (2,053 B).
* **Flat Sibling Grid & Max 3 DOM Elements**: Zero row/column wrapper divs. Form layout flattens into direct siblings with `--span: N` and max 3 DOM elements per field.
* **Single Source of Truth**: Standardized JSON Schema v1 specification for all forms.
* **High-Performance Dedicated Database**: Submissions stored in `{$wpdb->prefix}nexley_entries` with composite index `(form_id, status, created_at)`.
* **Formula-Safe CSV Export**: Full CWE-1236 defense escaping `=`, `+`, `-`, `@`, `\t`, `\r` with single quotes and streaming UTF-8 BOM for Microsoft Excel.
* **Gutenberg Dynamic Block**: Server-rendered `nexley-forms/form-selector` with live preview and zero frontend JavaScript.
* **Third-Party Migration Wizard**: Seamlessly import from Ninja Forms 3, Gravity Forms, and Contact Form 7, plus automated shortcode replacement with instant rollback.
* **Cache-Safe Anti-Spam**: Invisible honeypot, client-stamped time-trap (skips when JS is off), and transient IP rate-limiting.

== Installation ==

1. Upload the `nexley-forms` folder to the `/wp-content/plugins/` directory, or install the .zip directly via WordPress Admin (**Plugins > Add New > Upload Plugin**).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Nexley Forms > Add New Form** to launch the Drag-and-Drop Builder.
4. Copy the generated shortcode (e.g. `[nexley_form id="12"]`) or insert the **Nexley Form** Gutenberg Block into any post or page.

== Frequently Asked Questions ==

= Does Nexley Forms load CSS stylesheets on the front-end? =
In Raw Mode, zero stylesheets are loaded. In Preset or Base modes, a tiny stylesheet (< 2.1KB gzipped) is enqueued inside `@layer nexley` with zero specificity (`:where()`), ensuring theme styles always take precedence without `!important`.

= When are JavaScript files loaded? =
JavaScript runtime modules are enqueued in the footer strictly on pages where a Nexley Form is actively rendered. Pages without forms load zero plugin JS and zero CSS.

= How does Nexley Forms prevent CSV Formula Injection? =
All exported submission values starting with `=`, `+`, `-`, `@`, tab, or carriage return are safely escaped with a single quote `'` prior to CSV generation.

== Changelog ==

= 2.0.0 =
* Complete evolution into a drag-and-drop form builder with 12-column canvas and palette.
* Replaced post meta storage with dedicated `{$wpdb->prefix}nexley_entries` table.
* Added formula-safe CSV export with UTF-8 BOM streaming.
* Added native `.nex` envelope format import and export.
* Added third-party migration adapters for Ninja Forms, Gravity Forms, and Contact Form 7.
* Added automated shortcode replacer with full rollback capability.
* Added server-rendered Gutenberg block `nexley-forms/form-selector`.
* Enforced strict frontend asset performance budgets.
