<?php
declare(strict_types=1);

namespace Nexley_Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles the Documentation submenu page under Nexley Forms.
 */
final class Admin_Docs {

    public const PAGE_SLUG = 'nexley-forms-docs';

    /**
     * Hook into WordPress.
     */
    public static function init(): void {
        add_action('admin_menu', [self::class, 'register_menu']);
    }

    /**
     * Register Documentation submenu page.
     */
    public static function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=' . Post_Types::CPT_FORM,
            __('Nexley Forms Documentation', 'nexley-forms'),
            __('Documentation', 'nexley-forms'),
            'manage_options',
            self::PAGE_SLUG,
            [self::class, 'render_docs_page']
        );
    }

    /**
     * Render the Documentation dashboard page.
     */
    public static function render_docs_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'nexley-forms'));
        }

        ?>
        <div class="wrap nexley-docs-wrap">
            <!-- Header Banner -->
            <header class="nexley-docs-header">
                <div class="nexley-docs-header__layout">
                    <div class="nexley-docs-header__main">
                        <div class="nexley-docs-header__badge">
                            <span class="nexley-badge-indicator"></span>
                            <span class="nexley-badge-text"><?php esc_html_e('NEXLEY ARCHITECTURE // SYSTEM MANUAL', 'nexley-forms'); ?></span>
                        </div>
                        <h1 class="nexley-docs-header__title"><?php esc_html_e('NEXLEY FORMS DEVELOPER REFERENCE', 'nexley-forms'); ?></h1>
                        <p class="nexley-docs-header__desc">
                            <?php esc_html_e('Ultra-lightweight, zero-bloat WordPress form engine. Engineered with raw HTML templating, dynamic mail tag routing, asynchronous REST API dispatch, and zero frontend stylesheet overhead.', 'nexley-forms'); ?>
                        </p>
                        <div class="nexley-docs-header__meta">
                            <span class="nexley-meta-pill"><strong><?php esc_html_e('VERSION:', 'nexley-forms'); ?></strong> <?php echo esc_html(NEXLEY_FORMS_VERSION); ?></span>
                            <span class="nexley-meta-pill"><strong><?php esc_html_e('FRONTEND CSS:', 'nexley-forms'); ?></strong> <?php esc_html_e('0KB (ZERO STYLESHEETS)', 'nexley-forms'); ?></span>
                            <span class="nexley-meta-pill"><strong><?php esc_html_e('API ROUTE:', 'nexley-forms'); ?></strong> <code>/wp-json/nexley/v1/submit</code></span>
                        </div>
                    </div>
                    <div class="nexley-docs-header__thumbnail">
                        <img src="<?php echo esc_url(NEXLEY_FORMS_URL . 'assets/images/icon-256x256.png'); ?>" alt="Nexley Forms Thumbnail" width="90" height="90" />
                    </div>
                </div>
            </header>

            <!-- Quick Jump Navigation -->
            <nav class="nexley-docs-nav" aria-label="<?php esc_attr_e('Documentation Navigation', 'nexley-forms'); ?>">
                <a href="#syntax" class="nexley-docs-nav__link">
                    <span class="nexley-nav-num">01.</span> <?php esc_html_e('THE BUILDER SYNTAX', 'nexley-forms'); ?>
                </a>
                <a href="#routing" class="nexley-docs-nav__link">
                    <span class="nexley-nav-num">02.</span> <?php esc_html_e('DYNAMIC ROUTING', 'nexley-forms'); ?>
                </a>
                <a href="#rendering" class="nexley-docs-nav__link">
                    <span class="nexley-nav-num">03.</span> <?php esc_html_e('FRONT-END RENDERING', 'nexley-forms'); ?>
                </a>
            </nav>

            <main class="nexley-docs-content">
                <!-- SECTION 1: BUILDER SYNTAX -->
                <section id="syntax" class="nexley-docs-section">
                    <div class="nexley-docs-section__header">
                        <div class="nexley-docs-section__index">01</div>
                        <div>
                            <h2 class="nexley-docs-section__title"><?php esc_html_e('The Builder Syntax', 'nexley-forms'); ?></h2>
                            <p class="nexley-docs-section__subtitle"><?php esc_html_e('How to generate pseudo-shortcodes and wrap them in custom semantic HTML markup.', 'nexley-forms'); ?></p>
                        </div>
                    </div>

                    <div class="nexley-docs-card">
                        <h3 class="nexley-card-title"><?php esc_html_e('Pseudo-Shortcode Tag Directory', 'nexley-forms'); ?></h3>
                        <p class="nexley-card-desc">
                            <?php esc_html_e('Use the Tag Generator toolbar above the Form Editor or type tags directly into the textarea. Adding an asterisk (*) directly after the tag name marks the field as strictly required during client-side and server-side validation.', 'nexley-forms'); ?>
                        </p>

                        <div class="nexley-table-wrapper">
                            <table class="nexley-docs-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Field Type', 'nexley-forms'); ?></th>
                                        <th><?php esc_html_e('Tag Syntax', 'nexley-forms'); ?></th>
                                        <th><?php esc_html_e('Compiled HTML5 Output', 'nexley-forms'); ?></th>
                                        <th><?php esc_html_e('Attributes & Modifiers', 'nexley-forms'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong><?php esc_html_e('Text', 'nexley-forms'); ?></strong></td>
                                        <td><code>[text* client-name placeholder "Your Name"]</code></td>
                                        <td><code>&lt;input type="text" name="client-name" required ...&gt;</code></td>
                                        <td><code>*</code>, <code>placeholder "..."</code>, <code>class:...</code>, <code>id:...</code></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e('Email', 'nexley-forms'); ?></strong></td>
                                        <td><code>[email* client-email placeholder "user@nexley.com"]</code></td>
                                        <td><code>&lt;input type="email" name="client-email" required ...&gt;</code></td>
                                        <td>Validates RFC 5322 format automatically</td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e('URL', 'nexley-forms'); ?></strong></td>
                                        <td><code>[url client-website placeholder "https://..."]</code></td>
                                        <td><code>&lt;input type="url" name="client-website" ...&gt;</code></td>
                                        <td>Sanitized with <code>esc_url_raw()</code></td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e('Textarea', 'nexley-forms'); ?></strong></td>
                                        <td><code>[textarea* message placeholder "Describe project..."]</code></td>
                                        <td><code>&lt;textarea name="message" required ...&gt;&lt;/textarea&gt;</code></td>
                                        <td>Multi-line sanitization</td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e('Select', 'nexley-forms'); ?></strong></td>
                                        <td><code>[select* service "Branding" "Web Development"]</code></td>
                                        <td><code>&lt;select name="service"&gt;&lt;option&gt;...&lt;/select&gt;</code></td>
                                        <td>Use <code>placeholder "Choose..."</code> for blank option</td>
                                    </tr>
                                    <tr>
                                        <td><strong><?php esc_html_e('Submit', 'nexley-forms'); ?></strong></td>
                                        <td><code>[submit "Send Inquiry"]</code></td>
                                        <td><code>&lt;button type="submit" class="nexley-forms__submit"&gt;Send Inquiry&lt;/button&gt;</code></td>
                                        <td>Accessible button element with submitting state</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="nexley-code-block-wrap">
                            <div class="nexley-code-block-header">
                                <span class="nexley-code-lang"><?php esc_html_e('HTML TEMPLATE EXAMPLE', 'nexley-forms'); ?></span>
                                <span class="nexley-code-hint"><?php esc_html_e('Paste this directly into Meta Box 1: Form Editor', 'nexley-forms'); ?></span>
                            </div>
                            <pre class="nexley-code-block"><code>&lt;div class="grid grid-cols-1 md:grid-cols-2 gap-6"&gt;
  &lt;div class="nexley-forms__group"&gt;
    &lt;label class="nexley-forms__label"&gt;
      Full Name (Required)
      [text* client-name placeholder "John Doe"]
    &lt;/label&gt;
  &lt;/div&gt;

  &lt;div class="nexley-forms__group"&gt;
    &lt;label class="nexley-forms__label"&gt;
      Email Address (Required)
      [email* client-email placeholder "john@example.com"]
    &lt;/label&gt;
  &lt;/div&gt;
&lt;/div&gt;

&lt;div class="nexley-forms__group"&gt;
  &lt;label class="nexley-forms__label"&gt;
    Service Required
    [select* service placeholder "Select a capability" "Brand Strategy" "Web Application" "Performance Audit"]
  &lt;/label&gt;
&lt;/div&gt;

&lt;div class="nexley-forms__group"&gt;
  &lt;label class="nexley-forms__label"&gt;
    Project Details
    [textarea* message placeholder "Tell us about your timeline and objectives..."]
  &lt;/label&gt;
&lt;/div&gt;

&lt;div class="nexley-forms__submit-wrapper"&gt;
  [submit "INITIATE TRANSMISSION"]
&lt;/div&gt;</code></pre>
                        </div>
                    </div>
                </section>

                <!-- SECTION 2: DYNAMIC ROUTING -->
                <section id="routing" class="nexley-docs-section">
                    <div class="nexley-docs-section__header">
                        <div class="nexley-docs-section__index">02</div>
                        <div>
                            <h2 class="nexley-docs-section__title"><?php esc_html_e('Dynamic Routing & Email Tags', 'nexley-forms'); ?></h2>
                            <p class="nexley-docs-section__subtitle"><?php esc_html_e('Routing submissions dynamically to admins and clients via WP Mail SMTP.', 'nexley-forms'); ?></p>
                        </div>
                    </div>

                    <div class="nexley-docs-card">
                        <h3 class="nexley-card-title"><?php esc_html_e('Dynamic Tag Injection', 'nexley-forms'); ?></h3>
                        <p class="nexley-card-desc">
                            <?php esc_html_e('Any field name defined in your form template can be wrapped in curly braces {field-name} and used anywhere inside the Admin Notification or Client Auto-Responder fields.', 'nexley-forms'); ?>
                        </p>

                        <div class="nexley-grid-2">
                            <div class="nexley-info-box">
                                <h4 class="nexley-info-box__title"><?php esc_html_e('Field Tags', 'nexley-forms'); ?></h4>
                                <ul class="nexley-info-list">
                                    <li><code>{client-name}</code> &mdash; <?php esc_html_e('Value from [text* client-name]', 'nexley-forms'); ?></li>
                                    <li><code>{client-email}</code> &mdash; <?php esc_html_e('Value from [email* client-email]', 'nexley-forms'); ?></li>
                                    <li><code>{service}</code> &mdash; <?php esc_html_e('Selected option from [select* service]', 'nexley-forms'); ?></li>
                                    <li><code>{message}</code> &mdash; <?php esc_html_e('Text from [textarea message]', 'nexley-forms'); ?></li>
                                </ul>
                            </div>

                            <div class="nexley-info-box">
                                <h4 class="nexley-info-box__title"><?php esc_html_e('System & Global Tags', 'nexley-forms'); ?></h4>
                                <ul class="nexley-info-list">
                                    <li><code>{_all_fields}</code> &mdash; <?php esc_html_e('Renders formatted HTML table of all submitted inputs', 'nexley-forms'); ?></li>
                                    <li><code>{site_title}</code> &mdash; <?php esc_html_e('Current WordPress site name', 'nexley-forms'); ?></li>
                                    <li><code>{site_url}</code> &mdash; <?php esc_html_e('Website home URL', 'nexley-forms'); ?></li>
                                    <li><code>{date}</code> / <code>{time}</code> &mdash; <?php esc_html_e('Exact submission date and timestamp', 'nexley-forms'); ?></li>
                                    <li><code>{ip}</code> &mdash; <?php esc_html_e('Sanitized client IP address', 'nexley-forms'); ?></li>
                                </ul>
                            </div>
                        </div>

                        <div class="nexley-callout-box">
                            <div class="nexley-callout-box__badge"><?php esc_html_e('WP MAIL SMTP COMPLIANCE', 'nexley-forms'); ?></div>
                            <p class="nexley-callout-box__text">
                                <?php esc_html_e('All emails are dispatched through standard WordPress wp_mail() hooks, ensuring full compatibility with WP Mail SMTP, Amazon SES, SendGrid, Postmark, and Mailgun. In the Admin Notification, set the Reply-To field to {client-email} so hitting "Reply" in your email client responds directly to the lead.', 'nexley-forms'); ?>
                            </p>
                        </div>
                    </div>
                </section>

                <!-- SECTION 3: FRONT-END RENDERING -->
                <section id="rendering" class="nexley-docs-section">
                    <div class="nexley-docs-section__header">
                        <div class="nexley-docs-section__index">03</div>
                        <div>
                            <h2 class="nexley-docs-section__title"><?php esc_html_e('Front-End Rendering & BEM Classes', 'nexley-forms'); ?></h2>
                            <p class="nexley-docs-section__subtitle"><?php esc_html_e('Embed shortcodes and target predictable, semantic BEM classes in your theme stylesheets.', 'nexley-forms'); ?></p>
                        </div>
                    </div>

                    <div class="nexley-docs-card">
                        <h3 class="nexley-card-title"><?php esc_html_e('Shortcode Implementation', 'nexley-forms'); ?></h3>
                        <p class="nexley-card-desc">
                            <?php esc_html_e('Embed forms anywhere in block editor content, classic editor content, page templates, or template parts using standard shortcodes:', 'nexley-forms'); ?>
                        </p>

                        <div class="nexley-code-block-wrap">
                            <pre class="nexley-code-block"><code>&lt;!-- In WordPress Block or Post Content --&gt;
[nexley_form id="12"]

&lt;!-- In PHP Theme Templates --&gt;
&lt;?php echo do_shortcode('[nexley_form id="12"]'); ?&gt;</code></pre>
                        </div>

                        <h3 class="nexley-card-title" style="margin-top: 28px;"><?php esc_html_e('BEM Class Architecture', 'nexley-forms'); ?></h3>
                        <p class="nexley-card-desc">
                            <?php esc_html_e('Because Nexley Forms enqueues ZERO frontend CSS stylesheets, your theme has complete visual sovereignty. The theme already imports custom/components/form.css containing styling matching these exact classes:', 'nexley-forms'); ?>
                        </p>

                        <div class="nexley-table-wrapper">
                            <table class="nexley-docs-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('BEM Class Name', 'nexley-forms'); ?></th>
                                        <th><?php esc_html_e('Element Type', 'nexley-forms'); ?></th>
                                        <th><?php esc_html_e('Description / Purpose', 'nexley-forms'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><code>.nexley-forms</code></td>
                                        <td><code>&lt;form&gt;</code></td>
                                        <td><?php esc_html_e('Main form container element.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms--{form_id}</code></td>
                                        <td><code>&lt;form&gt;</code></td>
                                        <td><?php esc_html_e('Modifier class with the specific form ID (e.g. .nexley-forms--12).', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__field</code></td>
                                        <td><code>&lt;input&gt;</code> / <code>&lt;select&gt;</code></td>
                                        <td><?php esc_html_e('Base input element styling hook.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__field--{type}</code></td>
                                        <td><code>&lt;input&gt;</code></td>
                                        <td><?php esc_html_e('Specific type modifiers: --text, --email, --url, --select, --textarea.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__submit</code></td>
                                        <td><code>&lt;button&gt;</code></td>
                                        <td><?php esc_html_e('The primary submit button. Automatically toggles loading state.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__status</code></td>
                                        <td><code>&lt;div&gt;</code></td>
                                        <td><?php esc_html_e('Accessible live status container with aria-live="polite".', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__status.is-loading</code></td>
                                        <td><code>&lt;div&gt;</code></td>
                                        <td><?php esc_html_e('Active while fetch() request is transmitting to REST API.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__status.is-success</code></td>
                                        <td><code>&lt;div&gt;</code></td>
                                        <td><?php esc_html_e('Applied when server returns HTTP 200 with success confirmation.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms__status.is-error</code></td>
                                        <td><code>&lt;div&gt;</code></td>
                                        <td><?php esc_html_e('Applied when validation fails or network error occurs.', 'nexley-forms'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><code>.nexley-forms.is-submitting</code></td>
                                        <td><code>&lt;form&gt;</code></td>
                                        <td><?php esc_html_e('Form-level submitting state class to prevent double-submits.', 'nexley-forms'); ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="nexley-code-block-wrap">
                            <div class="nexley-code-block-header">
                                <span class="nexley-code-lang"><?php esc_html_e('ANALYTICS & DOM EVENTS (JS)', 'nexley-forms'); ?></span>
                                <span class="nexley-code-hint"><?php esc_html_e('Listen in your theme JavaScript for Google Tag Manager or Meta Pixel tracking', 'nexley-forms'); ?></span>
                            </div>
                            <pre class="nexley-code-block"><code>// Dispatched immediately on successful submission
document.addEventListener('nexley:form:success', (event) => {
  const payload = event.detail;
  console.log('Submission recorded:', payload.entry_id);

  // Trigger Google Tag Manager dataLayer event
  if (window.dataLayer) {
    window.dataLayer.push({
      event: 'nexley_form_submit',
      form_id: event.target.dataset.nexleyForm,
      entry_id: payload.entry_id
    });
  }
});

// Dispatched when form encounters validation or server errors
document.addEventListener('nexley:form:error', (event) => {
  console.warn('Submission error:', event.detail);
});</code></pre>
                        </div>
                    </div>
                </section>
            </main>
        </div>
        <?php
    }
}
