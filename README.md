<p align="center">
  <img src="assets/images/icon-256x256.png" width="128" height="128" alt="Nexley Forms Thumbnail" />
</p>

# Nexley Forms

Ultra-lightweight, high-performance WordPress form builder with a raw HTML code editing workflow, engineered for zero front-end bloat.

## Highlights
- **Zero Frontend CSS Bloat**: Loads zero stylesheets. The output consists of clean, semantic, accessible HTML inputs wrapped in predictable BEM classes (`nexley-forms`, `nexley-forms__field`, `nexley-forms__submit`, `nexley-forms__status`). Easily style forms with Tailwind CSS or any design system without fighting specificity.
- **Conditional Script Loading**: The ultra-lightweight vanilla JS runtime (< 2KB) is enqueued in the footer **only** when a `[nexley_form]` shortcode is actively rendered on the page.
- **Raw HTML Code Editor**: Combine raw HTML (`<div>`, `<label>`, `<fieldset>`) with flexible pseudo-shortcodes (`[text* client-name]`, `[email* client-email]`, `[submit "Send"]`).
- **One-Click Tag Generator Toolbar**: Generates clean pseudo-shortcodes with interactive options and inserts them directly at the cursor position.
- **REST API Submissions**: Asynchronous submissions sent via `fetch()` to `/wp-json/nexley/v1/submit`.
- **Database Entry Logging**: Submissions are automatically saved into a private `nexley_entry` Custom Post Type with complete metadata, submitter information, IP address, and timestamp.
- **Dynamic Email Routing & WP Mail SMTP Support**: Dynamic `{field}` tags in Admin Notifications and Client Auto-Responders, fully compatible with WP Mail SMTP.
- **Zero-Bloat Spam Protection**: Built-in honeypot field seamlessly absorbs bot submissions without heavy third-party captchas.

---

## Shortcode Usage

Embed any form in pages, posts, or templates:
```text
[nexley_form id="12"]
```

Or render directly via PHP:
```php
echo do_shortcode('[nexley_form id="12"]');
```

---

## Pseudo-Shortcode Syntax

| Tag | Example | Output |
| :--- | :--- | :--- |
| **Text** | `[text* client-name placeholder "Your Name"]` | `<input type="text" name="client-name" placeholder="Your Name" required ...>` |
| **Email** | `[email* client-email placeholder "john@example.com"]` | `<input type="email" name="client-email" placeholder="john@example.com" required ...>` |
| **URL** | `[url client-website placeholder "https://example.com"]` | `<input type="url" name="client-website" ...>` |
| **Textarea** | `[textarea* your-message placeholder "Write here..."]` | `<textarea name="your-message" required ...></textarea>` |
| **Select** | `[select* service "Web Design" "SEO" "Consulting"]` | `<select name="service" required><option value="Web Design">Web Design</option>...</select>` |
| **Submit** | `[submit "Send Inquiry"]` | `<button type="submit" class="nexley-forms__submit">Send Inquiry</button>` |

*An asterisk (`*`) right after the tag name marks the field as required.*

---

## Dynamic Mail Tags

In **Admin Notification** and **Client Auto-Responder** settings, you can use:
- `{field_name}` — Value submitted by the user for that input (e.g. `{client-name}`, `{client-email}`).
- `{_all_fields}` — Automatically formats a clean HTML table of all submitted form fields.
- `{site_title}` — Site name.
- `{site_url}` — Site URL.
- `{date}` — Submission date (`Y-m-d`).
- `{time}` — Submission time (`H:i:s`).
- `{ip}` — Submitter IP address.

---

## JavaScript Events

Listen for submission lifecycle events in your theme for analytics (e.g. GTM, Meta Pixel):

```js
document.addEventListener('nexley:form:success', (e) => {
  console.log('Form submitted successfully:', e.detail);
});

document.addEventListener('nexley:form:error', (e) => {
  console.warn('Form submission error:', e.detail);
});
```

---

## Architecture & File Structure

```
nexley-forms/
├── nexley-forms.php                 # Main plugin bootstrap & constants
├── README.md                        # Documentation
├── includes/
│   ├── class-plugin.php             # Core orchestrator singleton
│   ├── class-post-types.php         # 'nexley_form' & 'nexley_entry' CPTs & admin list columns
│   ├── class-meta-boxes.php         # Form Editor, Admin Notification, Auto-Responder & Entry meta boxes
│   ├── class-form-parser.php        # Regex pseudo-shortcode engine
│   ├── class-shortcode.php          # [nexley_form] handler with conditional script enqueueing
│   ├── class-mailer.php             # Dynamic tag replacement & WP Mail SMTP email dispatch
│   ├── class-rest-controller.php    # /nexley/v1/submit REST API controller
│   └── class-admin-docs.php         # Dedicated Documentation dashboard page
└── assets/
    ├── css/
    │   └── admin-nexley.css         # Scoped brutalist Light Mode admin dashboard styling
    └── js/
        ├── admin.js                 # Admin tag generator modal & clipboard interactions
        └── frontend.js              # Ultra-lightweight vanilla JS AJAX handler (< 2KB)
```
