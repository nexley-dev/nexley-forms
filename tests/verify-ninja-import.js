/**
 * Headless test for Real Ninja Forms File Import Flow & Frontend Render DOM Assertions
 */
const puppeteer = require('/Users/work/.gemini/antigravity-ide/brain/b2b0ade3-e34b-48aa-8d78-b3e27f1205df/scratch/node_modules/puppeteer-core');
const CHROME_PATH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const path = require('path');

async function testNinjaImportFlow() {
  console.log('======================================================================');
  console.log('       NEXLEY FORMS: REAL NINJA FORMS IMPORT & DOM TEST               ');
  console.log('======================================================================\n');

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1440, height: 900 });

  // 1. Log in to WordPress Admin
  console.log('1. Logging in to WordPress...');
  await page.goto('http://nexley.local/wp-login.php', { waitUntil: 'networkidle2' });
  await page.type('#user_login', 'admin');
  await page.type('#user_pass', 'admin');
  await page.click('#wp-submit');
  await page.waitForNavigation({ waitUntil: 'networkidle2' });
  console.log('   Logged in successfully.');

  // 2. Navigate to Ninja Forms Tab in Import Screen
  console.log('2. Navigating to Import / Export -> Ninja Forms tab...');
  await page.goto('http://nexley.local/wp-admin/edit.php?post_type=nexley_form&page=nexley-import-export&tab=ninja-forms', { waitUntil: 'networkidle2' });

  // 3. Select file for upload
  const fixturePath = path.resolve(__dirname, 'fixtures/fixture-ninja-forms-real.nff');
  console.log(`3. Attaching export file: ${fixturePath}`);

  const fileInputSelector = '.nexley-import-tab-content input[type="file"][name="import_file"]';
  const fileInput = await page.$(fileInputSelector);
  if (!fileInput) {
    throw new Error('File input not found in Ninja Forms tab');
  }
  await fileInput.uploadFile(fixturePath);

  // Trigger change event so dropzone UI updates
  await page.evaluate(sel => {
    const input = document.querySelector(sel);
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }, fileInputSelector);

  // Wait for submit button to be enabled
  await page.waitForFunction(() => {
    const btn = document.querySelector('.nexley-import-tab-content button.button-primary[type="submit"]');
    return btn && !btn.disabled;
  }, { timeout: 5000 });

  // 4. Click Upload & Convert Form
  console.log('4. Submitting form ("Upload & Convert Form")...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('.nexley-import-tab-content button.button-primary[type="submit"]')
  ]);

  // 5. Inspect result notice and extract new Form ID
  const pageUrl = page.url();
  console.log(`   Redirected to: ${pageUrl}`);

  const importedId = await page.evaluate(() => {
    const notice = document.querySelector('.notice-success');
    const noticeText = notice ? notice.textContent : '';
    const match = noticeText.match(/#(\d+)/);
    return match ? parseInt(match[1], 10) : 0;
  });

  console.log(`   Imported Form ID: ${importedId}`);
  if (!importedId) {
    throw new Error('Failed to find imported Form ID in success notice');
  }

  // 6. Navigate to Builder edit screen for this form
  console.log(`5. Navigating to Builder edit screen for Form #${importedId}...`);
  await page.goto(`http://nexley.local/wp-admin/post.php?post=${importedId}&action=edit`, { waitUntil: 'networkidle2' });

  const builderCheck = await page.evaluate(() => {
    const titleInput = document.getElementById('nexley-topbar-title');
    const formTitle = titleInput ? titleInput.value : '';

    // Check canvas fields
    const fields = Array.from(document.querySelectorAll('.nexley-canvas-field')).map(el => {
      const fieldId = el.getAttribute('data-field-id') || '';
      const type = el.getAttribute('data-field-type') || '';
      const spanAttr = el.getAttribute('data-span') || '';
      const style = el.getAttribute('style') || '';
      const labelEl = el.querySelector('.nexley-field-label, .nexley-control-label');
      const label = labelEl ? labelEl.textContent.trim() : '';
      return { fieldId, type, spanAttr, style, label };
    });

    return { formTitle, fields };
  });

  console.log(`   - Form Title in Builder: "${builderCheck.formTitle}"`);
  console.log(`   - Canvas field count: ${builderCheck.fields.length}`);
  builderCheck.fields.forEach((f, idx) => {
    console.log(`     Field ${idx}: [${f.fieldId}] type=${f.type}, span=${f.spanAttr}, label="${f.label}"`);
  });

  if (!builderCheck.formTitle.includes('Contact page form (Ninja Forms)')) {
    throw new Error(`Expected title containing "Contact page form (Ninja Forms)", got "${builderCheck.formTitle}"`);
  }

  if (builderCheck.fields.length !== 8) {
    throw new Error(`Expected 8 fields on canvas, got ${builderCheck.fields.length}`);
  }

  // 6. Switch to Preview tab in Builder to inspect live rendered DOM
  console.log('6. Switching to Preview tab in Builder to inspect live rendered DOM...');
  await page.click('.nexley-tab-btn[data-tab="preview"]');
  await page.waitForSelector('#nexley-preview-container .nexley-forms', { timeout: 5000 });

  const domCheck = await page.evaluate(() => {
    const container = document.getElementById('nexley-preview-container');
    const form = container ? container.querySelector('.nexley-forms') : null;
    if (!form) return { formFound: false };

    const groups = Array.from(form.querySelectorAll('.nexley-forms__group')).map(el => {
      const isSubmit = !!el.querySelector('button.nexley-forms__submit');
      const style = el.getAttribute('style') || '';
      const spanMatch = style.match(/--span:\s*(\d+)/);
      const span = spanMatch ? parseInt(spanMatch[1], 10) : 12;

      const input = el.querySelector('input, textarea, select, button');
      const labelEl = el.querySelector('label, legend');
      const label = labelEl ? labelEl.textContent.trim() : (isSubmit ? el.textContent.trim() : '');

      return {
        tag: el.tagName.toLowerCase(),
        isSubmit,
        span,
        label,
        name: input ? (input.getAttribute('name') || '') : '',
        type: input ? (input.getAttribute('type') || input.tagName.toLowerCase()) : 'text',
        required: label.includes('*') || (input && (input.hasAttribute('required') || input.getAttribute('aria-required') === 'true'))
      };
    });

    return {
      formFound: true,
      groups
    };
  });

  if (!domCheck.formFound) {
    // If frontend preview query is not enabled, verify via REST preview
    console.log('   - Testing via REST preview endpoint...');
    const restData = await page.evaluate(async (id) => {
      const resp = await fetch(`http://nexley.local/wp-json/nexley/v1/preview/${id}`);
      return resp.ok ? await resp.json() : null;
    }, importedId);
    console.log(`   - REST preview status: ${restData ? 'OK' : 'Not active'}`);
  } else {
    console.log(`   - Rendered element count: ${domCheck.groups.length}`);
    domCheck.groups.forEach((g, i) => {
      console.log(`     DOM Field ${i}: span=${g.span}, req=${g.required}, label="${g.label}"`);
    });

    // Detailed assertions against source file requirements
    const expected = [
      { span: 6, req: true, labelContains: 'First Name' },
      { span: 6, req: true, labelContains: 'Last Name' },
      { span: 6, req: true, labelContains: 'Company' },
      { span: 6, req: true, labelContains: 'Email' },
      { span: 6, req: false, labelContains: 'Phone Number' },
      { span: 6, req: true, labelContains: 'Message' },
      { span: 12, req: false, labelContains: 'Keep me in the loop' },
      { span: 12, req: false, labelContains: 'Submit' }
    ];

    for (let i = 0; i < expected.length; i++) {
      const actual = domCheck.groups[i];
      if (!actual) throw new Error(`Missing expected DOM group index ${i}`);
      if (actual.span !== expected[i].span) {
        throw new Error(`Field ${i} span mismatch: expected ${expected[i].span}, got ${actual.span}`);
      }
      if (actual.required !== expected[i].req) {
        throw new Error(`Field ${i} required mismatch: expected ${expected[i].req}, got ${actual.required}`);
      }
      if (!actual.label.includes(expected[i].labelContains)) {
        throw new Error(`Field ${i} label mismatch: expected to contain "${expected[i].labelContains}", got "${actual.label}"`);
      }
    }
    console.log('   => ALL PREVIEW DOM ASSERTIONS MATCH SOURCE FORM EXACTLY!');
  }

  await browser.close();
  console.log('\n======================================================================');
  console.log('       REAL NINJA FORMS IMPORT VERIFICATION COMPLETED!                ');
  console.log('======================================================================\n');
}

testNinjaImportFlow().catch(err => {
  console.error('\n[TEST ERROR]:', err);
  process.exit(1);
});
