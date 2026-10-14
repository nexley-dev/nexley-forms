=== Nexley Forms ===
Contributors: nexley
Donate link: https://nexley.dev
Tags: forms, contact form, performance, lightweight, rest api
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ultra-lightweight, zero-bloat form builder with raw HTML editing, zero frontend CSS stylesheets, and asynchronous REST API submissions.

== Description ==

Nexley Forms is an elite WordPress form engine built for modern performance-first engineering and bespoke design systems.

Most WordPress form plugins output heavy, opinionated CSS frameworks, redundant assets, and bloated JavaScript bundles on every page. Nexley Forms takes the opposite approach: **zero frontend CSS stylesheets** and an ultra-lightweight vanilla JavaScript submission runtime (< 2KB) that is enqueued strictly on pages containing the shortcode.

Write your forms using a raw HTML code editor with intuitive pseudo-shortcode tag generators, receive asynchronous AJAX/REST submissions, save records into a dedicated WordPress Custom Post Type, and dynamically route email notifications.

### Key Features

* **Zero Frontend CSS Bloat**: The plugin loads zero stylesheets on the frontend. Output consists of semantic, accessible HTML inputs wrapped in predictable BEM classes (`nexley-forms`, `nexley-forms__field`, `nexley-forms__submit`, `nexley-forms__status`).
* **Conditional JavaScript Enqueueing**: The vanilla JS runtime (< 2KB) is enqueued in the footer only when a `[nexley_form id="X"]` shortcode is actively rendered on the page.
* **Raw HTML Code Editor**: Full structural freedom to wrap standard HTML markup (`<div>`, `<label>`, `<fieldset>`, Tailwind classes) around simple tag-generator inputs (`[text* client-name]`, `[email* client-email]`, `[submit "Send"]`).
* **Interactive Tag Generator Toolbar**: Click-to-insert buttons for Text, Email, URL, Select, Textarea, and Submit fields with live syntax previews.
* **REST API Dispatch**: Asynchronous form submission via modern `fetch()` to `/wp-json/nexley/v1/submit`.
* **Database Entry Management**: Submissions are automatically saved to a private `nexley_entry` post type with complete submitter metadata, timestamps, and IP logging.
* **Dynamic Tag Routing**: Route notifications dynamically using `{field-name}` tags (e.g. `{client-name}`, `{client-email}`, `{_all_fields}`, `{site_title}`, `{date}`).
* **WP Mail SMTP Compatible**: Dispatches emails via standard `wp_mail()` with clean headers and `Reply-To` routing.
* **Zero-Bloat Anti-Spam**: Built-in honeypot bot mitigation without heavy third-party captcha scripts.
* **Developer Friendly**: Emits custom JavaScript DOM events (`nexley:form:success`, `nexley:form:error`) for seamless Google Tag Manager and analytics tracking.

== Installation ==

1. Upload the `nexley-forms` folder to the `/wp-content/plugins/` directory, or install the .zip directly via WordPress Admin (**Plugins > Add New > Upload Plugin**).
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Nexley Forms > Add New Form** to build your first form.
4. Copy the generated shortcode (e.g. `[nexley_form id="12"]`) and paste it into any post, page, block, or theme template.

== Frequently Asked Questions ==

= Does Nexley Forms load any CSS stylesheets on the front-end? =
No. Nexley Forms strictly outputs clean semantic HTML with BEM classes. This allows themes (such as Tailwind CSS or bespoke themes) to style forms without specificity conflicts or CSS bloat.

= When is the JavaScript file loaded? =
The vanilla JS script is registered but only enqueued in the footer when the `[nexley_form]` shortcode is actively rendered during page execution.

= Is Nexley Forms compatible with WP Mail SMTP? =
Yes. All notifications and auto-responders are dispatched through standard `wp_mail()` calls, making them 100% compatible with WP Mail SMTP, SendGrid, Amazon SES, Postmark, and Mailgun.

= Does this plugin support file uploads or payments? =
Nexley Forms is engineered as an ultra-fast, zero-bloat contact and inquiry engine. File uploads and payment gateways are intentionally omitted to preserve zero-overhead performance.

== Screenshots ==

1. Form Editor with Tag Generator buttons and custom HTML template.
2. Admin Notification and Client Auto-Responder dynamic routing settings.
3. Submissions Entry log view with captured inputs.
4. Built-in developer documentation reference dashboard.

== Changelog ==

= 1.0.0 =
* Initial public release.
* Added `nexley_form` and `nexley_entry` Custom Post Types.
* Implemented regex pseudo-shortcode parser and HTML5 generator.
* Implemented `/nexley/v1/submit` REST API endpoint with nonce verification.
* Implemented dynamic `{field}` tag mailer with WP Mail SMTP support.
* Implemented scoped brutalist Light Mode admin UI and developer documentation page.
