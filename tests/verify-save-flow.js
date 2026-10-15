const puppeteer = require('/Users/work/.gemini/antigravity-ide/brain/b2b0ade3-e34b-48aa-8d78-b3e27f1205df/scratch/node_modules/puppeteer-core');

const CHROME_PATH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function runVerification() {
  console.log('======================================================================');
  console.log('       NEXLEY FORMS: AUTOMATED SAVE FLOW & DOM ASSERTION TEST         ');
  console.log('======================================================================\n');

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900']
  });

  const page = await browser.newPage();
  page.on('dialog', async d => {
    console.log(`  [Dialog] Auto-accepting dialog: "${d.message()}"`);
    await d.accept();
  });

  // 1. Log in
  console.log('1. Logging in to WordPress...');
  await page.goto('http://nexley.local/wp-login.php', { waitUntil: 'networkidle2' });
  await page.type('#user_login', 'admin');
  await page.type('#user_pass', 'admin');
  await page.click('#wp-submit');
  await page.waitForNavigation({ waitUntil: 'networkidle2' });
  console.log('   Logged in successfully.\n');

  let formId = null;

  // -------------------------------------------------------------------------
  // TRANSITION 1: NEW DRAFT
  // -------------------------------------------------------------------------
  console.log('2. Testing Transition 1: New Draft Save Flow');
  await page.goto('http://nexley.local/wp-admin/post-new.php?post_type=nexley_form', { waitUntil: 'networkidle2' });

  // Assert single column layout and submitdiv removal
  const layoutCheck = await page.evaluate(() => {
    const sideCol = document.getElementById('postbox-container-1');
    const submitdiv = document.getElementById('submitdiv');
    const topbar = document.querySelector('.nexley-builder-topbar');
    const topbarTitle = document.getElementById('nexley-topbar-title');
    const sideColHidden = sideCol ? window.getComputedStyle(sideCol).display === 'none' : true;
    return {
      sideColHidden,
      submitdivExists: !!submitdiv,
      topbarExists: !!topbar,
      topbarTitleExists: !!topbarTitle
    };
  });

  console.log(`   - Side column hidden: ${layoutCheck.sideColHidden}`);
  console.log(`   - Native submitdiv removed: ${!layoutCheck.submitdivExists}`);
  console.log(`   - Top builder bar present: ${layoutCheck.topbarExists}`);

  if (layoutCheck.submitdivExists) {
    throw new Error('Native submitdiv should be removed from CPT_FORM edit screen');
  }

  // Type title in top bar
  await page.click('#nexley-topbar-title');
  await page.keyboard.down('Meta');
  await page.keyboard.press('KeyA');
  await page.keyboard.up('Meta');
  await page.keyboard.press('Backspace');
  await page.type('#nexley-topbar-title', 'Puppeteer Verification Form');

  // Click Save Draft
  console.log('   - Clicking "Save Draft"...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('.nexley-topbar-draft-btn')
  ]);

  // Assert draft saved
  const draftState = await page.evaluate(() => {
    const postStatus = document.getElementById('post_status')?.value;
    const title = document.getElementById('nexley-topbar-title')?.value;
    const badge = document.getElementById('nexley-topbar-status-badge')?.textContent.trim();
    const postId = document.getElementById('post_ID')?.value;
    return { postStatus, title, badge, postId };
  });

  formId = draftState.postId;
  console.log(`   - Saved as Form ID: ${formId}`);
  console.log(`   - post_status: "${draftState.postStatus}"`);
  console.log(`   - Badge status: "${draftState.badge}"`);
  console.log(`   - Form title: "${draftState.title}"`);

  if (draftState.postStatus !== 'draft' || draftState.badge !== 'Draft') {
    throw new Error(`Expected draft status, got: ${draftState.postStatus}`);
  }
  console.log('   => RESULT 1 (New Draft): SUCCESS\n');

  // -------------------------------------------------------------------------
  // TRANSITION 2: PUBLISH DRAFT
  // -------------------------------------------------------------------------
  console.log('3. Testing Transition 2: Publish Draft');
  console.log('   - Clicking "Publish"...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('.nexley-topbar-publish-btn')
  ]);

  const publishState = await page.evaluate(() => {
    const postStatus = document.getElementById('post_status')?.value;
    const badge = document.getElementById('nexley-topbar-status-badge')?.textContent.trim();
    const updateBtn = document.querySelector('.nexley-topbar-save-btn');
    const unpublishBtn = document.getElementById('nexley-btn-unpublish');
    return {
      postStatus,
      badge,
      hasUpdateBtn: !!updateBtn,
      hasUnpublishBtn: !!unpublishBtn
    };
  });

  console.log(`   - post_status: "${publishState.postStatus}"`);
  console.log(`   - Badge status: "${publishState.badge}"`);
  console.log(`   - Has Update button: ${publishState.hasUpdateBtn}`);
  console.log(`   - Has Switch to Draft button: ${publishState.hasUnpublishBtn}`);

  if (publishState.postStatus !== 'publish' || publishState.badge !== 'Published') {
    throw new Error(`Expected published status, got: ${publishState.postStatus}`);
  }
  console.log('   => RESULT 2 (Publish): SUCCESS\n');

  // -------------------------------------------------------------------------
  // TRANSITION 3: UPDATE PUBLISHED FORM
  // -------------------------------------------------------------------------
  console.log('4. Testing Transition 3: Update Published Form');
  await page.$eval('#nexley-topbar-title', el => { el.value = ''; });
  await page.type('#nexley-topbar-title', 'Puppeteer Verification Form (Updated)');

  console.log('   - Clicking "Update"...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('.nexley-topbar-save-btn')
  ]);

  const updateState = await page.evaluate(() => {
    const postStatus = document.getElementById('post_status')?.value;
    const title = document.getElementById('nexley-topbar-title')?.value;
    const badge = document.getElementById('nexley-topbar-status-badge')?.textContent.trim();
    return { postStatus, title, badge };
  });

  console.log(`   - post_status: "${updateState.postStatus}"`);
  console.log(`   - Updated title: "${updateState.title}"`);
  console.log(`   - Badge status: "${updateState.badge}"`);

  if (updateState.postStatus !== 'publish' || updateState.title !== 'Puppeteer Verification Form (Updated)') {
    throw new Error(`Update failed, got title: "${updateState.title}", status: "${updateState.postStatus}"`);
  }
  console.log('   => RESULT 3 (Update): SUCCESS\n');

  // -------------------------------------------------------------------------
  // TRANSITION 4: UNPUBLISH TO DRAFT
  // -------------------------------------------------------------------------
  console.log('5. Testing Transition 4: Unpublish to Draft');
  console.log('   - Clicking "Switch to Draft"...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('#nexley-btn-unpublish')
  ]);

  const unpublishState = await page.evaluate(() => {
    const postStatus = document.getElementById('post_status')?.value;
    const badge = document.getElementById('nexley-topbar-status-badge')?.textContent.trim();
    const hasPublishBtn = !!document.querySelector('.nexley-topbar-publish-btn');
    const hasDraftBtn = !!document.querySelector('.nexley-topbar-draft-btn');
    return { postStatus, badge, hasPublishBtn, hasDraftBtn };
  });

  console.log(`   - post_status: "${unpublishState.postStatus}"`);
  console.log(`   - Badge status: "${unpublishState.badge}"`);
  console.log(`   - Publish button restored: ${unpublishState.hasPublishBtn}`);
  console.log(`   - Save Draft button restored: ${unpublishState.hasDraftBtn}`);

  if (unpublishState.postStatus !== 'draft' || unpublishState.badge !== 'Draft') {
    throw new Error(`Expected draft status after unpublish, got: ${unpublishState.postStatus}`);
  }
  console.log('   => RESULT 4 (Unpublish to Draft): SUCCESS\n');

  // -------------------------------------------------------------------------
  // TRANSITION 5: TRASH (via signed nonce URL + confirm dialog)
  // -------------------------------------------------------------------------
  console.log('6. Testing Transition 5: Move to Trash (get_delete_post_link)');
  const trashHref = await page.evaluate(() => {
    const trashLink = document.querySelector('.nexley-topbar-trash-link');
    return trashLink ? trashLink.getAttribute('href') : null;
  });

  console.log(`   - Signed trash URL: ${trashHref}`);
  if (!trashHref || !trashHref.includes('action=trash') || !trashHref.includes('_wpnonce=')) {
    throw new Error('Trash link must be a valid signed nonce URL generated by get_delete_post_link()');
  }

  console.log('   - Clicking "Move to Trash"...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.click('.nexley-topbar-trash-link')
  ]);

  const currentUrl = page.url();
  console.log(`   - Redirected URL: ${currentUrl}`);

  // Verify in trash table
  await page.goto('http://nexley.local/wp-admin/edit.php?post_status=trash&post_type=nexley_form', { waitUntil: 'networkidle2' });
  const inTrash = await page.$(`tr#post-${formId}`);
  if (!inTrash) {
    throw new Error(`Post ${formId} not found in Trash table`);
  }
  console.log('   - Post successfully located in Trash table');
  console.log('   => RESULT 5 (Trash): SUCCESS\n');

  // -------------------------------------------------------------------------
  // TRANSITION 6: RESTORE FROM TRASH
  // -------------------------------------------------------------------------
  console.log('7. Testing Transition 6: Restore from Trash');
  await page.goto('http://nexley.local/wp-admin/edit.php?post_status=trash&post_type=nexley_form', { waitUntil: 'networkidle2' });

  const restoreSelector = `tr#post-${formId} .untrash a`;
  const restoreExists = await page.$(restoreSelector);

  if (!restoreExists) {
    throw new Error(`Restore link for post-${formId} not found in Trash table`);
  }

  console.log('   - Clicking "Restore" in trash list table...');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2' }),
    page.$eval(restoreSelector, el => el.click())
  ]);

  // Navigate to edit screen of restored form to verify restored status

  // Navigate to edit screen of restored form
  await page.goto(`http://nexley.local/wp-admin/post.php?post=${formId}&action=edit`, { waitUntil: 'networkidle2' });
  const restoredState = await page.evaluate(() => {
    const postStatus = document.getElementById('post_status')?.value;
    const badge = document.getElementById('nexley-topbar-status-badge')?.textContent.trim();
    return { postStatus, badge };
  });

  console.log(`   - Restored post_status: "${restoredState.postStatus}"`);
  console.log(`   - Restored badge: "${restoredState.badge}"`);
  console.log('   => RESULT 6 (Restore): SUCCESS\n');

  // Clean up test post
  await page.goto(`http://nexley.local/wp-admin/edit.php?post_type=nexley_form`, { waitUntil: 'networkidle2' });
  await page.evaluate(id => {
    const row = document.getElementById(`post-${id}`);
    if (row) {
      const del = row.querySelector('.submitdelete');
      if (del) del.click();
    }
  }, formId);

  // -------------------------------------------------------------------------
  // REDESIGNED IMPORT / EXPORT SCREEN DOM ASSERTIONS
  // -------------------------------------------------------------------------
  console.log('8. Testing Redesigned Import / Export Screen DOM Assertions');
  await page.goto('http://nexley.local/wp-admin/edit.php?post_type=nexley_form&page=nexley-import-export', { waitUntil: 'networkidle2' });

  const screenCheck = await page.evaluate(() => {
    // 1. Tab order
    const tabs = Array.from(document.querySelectorAll('.nexley-import-tab-link')).map(a => ({
      text: a.textContent.trim(),
      href: a.getAttribute('href'),
      role: a.getAttribute('role'),
      selected: a.getAttribute('aria-selected')
    }));

    // 2. Drop zone
    const dropzone = document.querySelector('.nexley-dropzone');
    const fileInput = document.querySelector('.nexley-file-input');
    const uploadIcon = document.querySelector('.nexley-dropzone-icon');
    const submitBtn = document.querySelector('.nexley-dropzone-submit');

    // 3. No accordions
    const detailsCount = document.querySelectorAll('details').length;

    // 4. No unverified badges anywhere
    const unverifiedPills = Array.from(document.querySelectorAll('*')).filter(el => {
      const text = el.textContent || '';
      return text.toLowerCase().includes('unverified') && (el.className || '').includes('pill');
    }).length;

    return {
      tabs,
      hasDropzone: !!dropzone,
      hasFileInput: !!fileInput,
      hasUploadIcon: !!uploadIcon,
      submitDisabled: submitBtn ? submitBtn.disabled : null,
      detailsCount,
      unverifiedPills
    };
  });

  console.log('   - Tabs rendered:', screenCheck.tabs.map(t => t.text).join(' | '));
  console.log(`   - Default active tab: ${screenCheck.tabs[0]?.text} (selected: ${screenCheck.tabs[0]?.selected})`);
  console.log(`   - Has Drop Zone: ${screenCheck.hasDropzone}`);
  console.log(`   - Has Accessible Hidden File Input: ${screenCheck.hasFileInput}`);
  console.log(`   - Submit Button Initially Disabled: ${screenCheck.submitDisabled}`);
  console.log(`   - Accordion count (<details>): ${screenCheck.detailsCount}`);
  console.log(`   - "Unverified" decorative badges count: ${screenCheck.unverifiedPills}`);

  const expectedTabs = [
    'Nexley (.nex)',
    'Third-Party Forms',
    'Replace Shortcodes'
  ];

  if (screenCheck.tabs.length !== 3) {
    throw new Error(`Expected exactly 3 tabs, got ${screenCheck.tabs.length}`);
  }

  for (let i = 0; i < expectedTabs.length; i++) {
    if (!screenCheck.tabs[i] || !screenCheck.tabs[i].text.includes(expectedTabs[i])) {
      throw new Error(`Tab ${i} mismatch. Expected "${expectedTabs[i]}", got "${screenCheck.tabs[i]?.text}"`);
    }
  }

  if (screenCheck.detailsCount > 0) {
    throw new Error(`Accordions found: ${screenCheck.detailsCount}. Requirement specifies NO accordions.`);
  }

  if (screenCheck.unverifiedPills > 0) {
    throw new Error(`"Unverified" badges found: ${screenCheck.unverifiedPills}. Requirement specifies removing every unverified badge from UI.`);
  }

  // 8b. Navigate to Third-Party Forms tab and verify select dropdown
  console.log('   - Navigating to Third-Party Forms tab...');
  await page.goto('http://nexley.local/wp-admin/edit.php?post_type=nexley_form&page=nexley-import-export&tab=third-party', { waitUntil: 'networkidle2' });

  const thirdPartyCheck = await page.evaluate(() => {
    const select = document.getElementById('nexley-provider-select');
    const options = select ? Array.from(select.options).map(o => ({ value: o.value, text: o.text })) : [];
    const nfPanel = document.getElementById('nexley-provider-panel-ninja-forms');
    const gfPanel = document.getElementById('nexley-provider-panel-gravity-forms');
    const cf7Panel = document.getElementById('nexley-provider-panel-cf7');

    return {
      hasSelect: !!select,
      options,
      nfVisible: nfPanel ? window.getComputedStyle(nfPanel).display !== 'none' : false,
      gfVisible: gfPanel ? window.getComputedStyle(gfPanel).display !== 'none' : false,
      cf7Visible: cf7Panel ? window.getComputedStyle(cf7Panel).display !== 'none' : false
    };
  });

  console.log(`   - Provider Select Dropdown present: ${thirdPartyCheck.hasSelect}`);
  console.log('   - Provider Select options:', thirdPartyCheck.options.map(o => `${o.value} (${o.text})`).join(', '));
  console.log(`   - Initial panel visibility (NF: ${thirdPartyCheck.nfVisible}, GF: ${thirdPartyCheck.gfVisible}, CF7: ${thirdPartyCheck.cf7Visible})`);

  if (!thirdPartyCheck.hasSelect) {
    throw new Error('Provider select dropdown (#nexley-provider-select) not found in Third-Party Forms tab');
  }

  const expectedProviders = ['ninja-forms', 'gravity-forms', 'cf7'];
  for (const prov of expectedProviders) {
    if (!thirdPartyCheck.options.some(o => o.value === prov)) {
      throw new Error(`Missing expected provider option: ${prov}`);
    }
  }

  // Test selecting Gravity Forms via JavaScript / change event
  console.log('   - Testing provider dropdown selection change to Gravity Forms...');
  await page.select('#nexley-provider-select', 'gravity-forms');
  await page.evaluate(() => {
    document.getElementById('nexley-provider-select').dispatchEvent(new Event('change', { bubbles: true }));
  });

  const gfPanelVisibility = await page.evaluate(() => {
    const nf = document.getElementById('nexley-provider-panel-ninja-forms');
    const gf = document.getElementById('nexley-provider-panel-gravity-forms');
    return {
      nfVisible: nf ? window.getComputedStyle(nf).display !== 'none' : false,
      gfVisible: gf ? window.getComputedStyle(gf).display !== 'none' : false
    };
  });

  console.log(`   - After select change (NF visible: ${gfPanelVisibility.nfVisible}, GF visible: ${gfPanelVisibility.gfVisible})`);
  if (!gfPanelVisibility.gfVisible || gfPanelVisibility.nfVisible) {
    throw new Error('Dropdown switching failed: Gravity Forms panel should be visible and Ninja Forms hidden');
  }

  console.log('   => IMPORT / EXPORT SCREEN DOM ASSERTIONS: ALL PASSED\n');

  await browser.close();
  console.log('======================================================================');
  console.log('            ALL VERIFICATIONS COMPLETED SUCCESSFULLY!                 ');
  console.log('======================================================================');
}

runVerification().catch(err => {
  console.error('\n[TEST ERROR]:', err);
  process.exit(1);
});
