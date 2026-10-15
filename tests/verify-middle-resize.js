const puppeteer = require('/Users/work/.gemini/antigravity-ide/brain/b2b0ade3-e34b-48aa-8d78-b3e27f1205df/scratch/node_modules/puppeteer-core');

const CHROME_PATH = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

async function runMiddleResizeVerification() {
  console.log('======================================================================');
  console.log('       NEXLEY FORMS: MIDDLE / DUAL FIELD RESIZE VERIFICATION         ');
  console.log('======================================================================\n');

  const browser = await puppeteer.launch({
    executablePath: CHROME_PATH,
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1440,900']
  });

  try {
    const page = await browser.newPage();
    page.on('dialog', async d => {
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

    // 2. Go to New Form
    console.log('2. Opening Form Builder...');
    await page.goto('http://nexley.local/wp-admin/post-new.php?post_type=nexley_form', { waitUntil: 'networkidle2' });

    // Wait for builder canvas
    await page.waitForSelector('#nexley-canvas-rows', { timeout: 10000 });
    console.log('   Builder canvas mounted.\n');

    // 3. Set up two fields side-by-side in row 0
    console.log('3. Setting up 2 side-by-side fields (span 6 + 6) in the builder...');
    await page.evaluate(() => {
      const input = document.getElementById('nexley_form_schema_json');
      const s = {
        version: '1.0.0',
        fields: [
          { id: 'f1', type: 'text', name: 'field_1', label: 'Field 1', span: 6 },
          { id: 'f2', type: 'text', name: 'field_2', label: 'Field 2', span: 6 }
        ],
        rows: [
          {
            id: 'row_1',
            columns: [
              { id: 'col_1', span: 6, fields: ['f1'] },
              { id: 'col_2', span: 6, fields: ['f2'] }
            ]
          }
        ]
      };
      input.value = JSON.stringify(s);
    });

    // Save draft so the post reloads with our two side-by-side fields
    await page.click('#nexley-topbar-title');
    await page.type('#nexley-topbar-title', 'Test Dual Resize Form');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle2' }),
      page.click('.nexley-topbar-draft-btn')
    ]);
    console.log('   Saved form with 2 side-by-side fields.\n');

    // 4. Verify DOM handles
    console.log('4. Verifying dual resize handle in DOM...');
    const handleInfo = await page.evaluate(() => {
      const fields = document.querySelectorAll('.nexley-canvas-field');
      if (fields.length < 2) return { error: `Expected at least 2 fields, found ${fields.length}` };

      const fieldA = fields[0];
      const fieldB = fields[1];

      const handleA = fieldA.querySelector('.nexley-field-resize-handle');
      const handleB = fieldB.querySelector('.nexley-field-resize-handle');

      const spanA = fieldA.style.getPropertyValue('--span');
      const spanB = fieldB.style.getPropertyValue('--span');

      return {
        fieldCount: fields.length,
        hasHandleA: !!handleA,
        isDualA: handleA ? handleA.classList.contains('is-dual') : false,
        hasHandleB: !!handleB,
        isDualB: handleB ? handleB.classList.contains('is-dual') : false,
        spanA,
        spanB,
        titleA: handleA ? handleA.getAttribute('title') : null,
        ariaA: handleA ? handleA.getAttribute('aria-label') : null
      };
    });

    console.log('   Handle info:', JSON.stringify(handleInfo, null, 2));

    if (!handleInfo.isDualA) {
      throw new Error(`FAIL: Field A handle was expected to have 'is-dual' class!`);
    }
    if (handleInfo.isDualB) {
      throw new Error(`FAIL: Field B (last field) handle was NOT expected to have 'is-dual' class!`);
    }
    console.log('   PASS: Field A has .is-dual; Field B does not (single resize).\n');

    // 5. Test Dragging from Middle to Left (Shrink A, Enlarge B)
    console.log('5. Testing Dual Drag: Moving left (shrinks Left card, enlarges Right card)...');
    const dragResultLeft = await page.evaluate(async () => {
      const fieldA = document.querySelectorAll('.nexley-canvas-field')[0];
      const fieldB = document.querySelectorAll('.nexley-canvas-field')[1];
      const handle = fieldA.querySelector('.nexley-field-resize-handle.is-dual');
      const grid = fieldA.closest('.nexley-row-grid');

      const rect = handle.getBoundingClientRect();
      const startX = rect.left + rect.width / 2;
      const startY = rect.top + rect.height / 2;

      // Calculate grid pitch
      const colPitch = grid.getBoundingClientRect().width / 12;

      // Mouse down on dual handle
      handle.dispatchEvent(new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        clientX: startX,
        clientY: startY,
        button: 0
      }));

      // Drag left by 2 column widths (-2 * colPitch)
      const targetX = startX - (2 * colPitch);
      document.dispatchEvent(new MouseEvent('mousemove', {
        bubbles: true,
        cancelable: true,
        clientX: targetX,
        clientY: startY
      }));

      // Check interim DOM state
      const midSpanA = fieldA.style.getPropertyValue('--span');
      const midSpanB = fieldB.style.getPropertyValue('--span');

      // Mouse up
      document.dispatchEvent(new MouseEvent('mouseup', {
        bubbles: true,
        cancelable: true,
        clientX: targetX,
        clientY: startY
      }));

      // Return spans
      const finalFields = document.querySelectorAll('.nexley-canvas-field');
      const schema = JSON.parse(document.getElementById('nexley_form_schema_json').value);

      return {
        colPitch,
        midSpanA,
        midSpanB,
        afterSpanA: finalFields[0].style.getPropertyValue('--span'),
        afterSpanB: finalFields[1].style.getPropertyValue('--span'),
        schemaSpanA: schema.fields[0].span,
        schemaSpanB: schema.fields[1].span,
        colSpanA: schema.rows[0].columns[0].span,
        colSpanB: schema.rows[0].columns[1].span
      };
    });

    console.log('   Drag Left result:', JSON.stringify(dragResultLeft, null, 2));

    if (parseInt(dragResultLeft.afterSpanA, 10) >= 6 || parseInt(dragResultLeft.afterSpanB, 10) <= 6) {
      throw new Error(`FAIL: Dragging left should shrink card A (< 6) and enlarge card B (> 6). Got A=${dragResultLeft.afterSpanA}, B=${dragResultLeft.afterSpanB}`);
    }
    if (parseInt(dragResultLeft.afterSpanA, 10) + parseInt(dragResultLeft.afterSpanB, 10) !== 12) {
      throw new Error(`FAIL: Sum of spans must equal 12. Got ${dragResultLeft.afterSpanA} + ${dragResultLeft.afterSpanB}`);
    }
    console.log(`   PASS: Left card shrunk to ${dragResultLeft.afterSpanA}, Right card enlarged to ${dragResultLeft.afterSpanB} (Sum = 12)\n`);

    // 6. Test Dragging from Middle to Right (Enlarge A, Shrink B)
    console.log('6. Testing Dual Drag: Moving right (enlarges Left card, shrinks Right card)...');
    const dragResultRight = await page.evaluate(async () => {
      const fieldA = document.querySelectorAll('.nexley-canvas-field')[0];
      const handle = fieldA.querySelector('.nexley-field-resize-handle.is-dual');
      const grid = fieldA.closest('.nexley-row-grid');

      const rect = handle.getBoundingClientRect();
      const startX = rect.left + rect.width / 2;
      const startY = rect.top + rect.height / 2;
      const colPitch = grid.getBoundingClientRect().width / 12;

      // Mouse down on dual handle
      handle.dispatchEvent(new MouseEvent('mousedown', {
        bubbles: true,
        cancelable: true,
        clientX: startX,
        clientY: startY,
        button: 0
      }));

      // Drag right by 4 column widths (+4 * colPitch)
      const targetX = startX + (4 * colPitch);
      document.dispatchEvent(new MouseEvent('mousemove', {
        bubbles: true,
        cancelable: true,
        clientX: targetX,
        clientY: startY
      }));

      // Mouse up
      document.dispatchEvent(new MouseEvent('mouseup', {
        bubbles: true,
        cancelable: true,
        clientX: targetX,
        clientY: startY
      }));

      const finalFields = document.querySelectorAll('.nexley-canvas-field');
      const schema = JSON.parse(document.getElementById('nexley_form_schema_json').value);

      return {
        afterSpanA: finalFields[0].style.getPropertyValue('--span'),
        afterSpanB: finalFields[1].style.getPropertyValue('--span'),
        schemaSpanA: schema.fields[0].span,
        schemaSpanB: schema.fields[1].span
      };
    });

    console.log('   Drag Right result:', JSON.stringify(dragResultRight, null, 2));

    if (parseInt(dragResultRight.afterSpanA, 10) <= 6 || parseInt(dragResultRight.afterSpanB, 10) >= 6) {
      throw new Error(`FAIL: Dragging right should enlarge card A (> 6) and shrink card B (< 6). Got A=${dragResultRight.afterSpanA}, B=${dragResultRight.afterSpanB}`);
    }
    if (parseInt(dragResultRight.afterSpanA, 10) + parseInt(dragResultRight.afterSpanB, 10) !== 12) {
      throw new Error(`FAIL: Sum of spans must equal 12. Got ${dragResultRight.afterSpanA} + ${dragResultRight.afterSpanB}`);
    }
    console.log(`   PASS: Left card enlarged to ${dragResultRight.afterSpanA}, Right card shrunk to ${dragResultRight.afterSpanB} (Sum = 12)\n`);

    // 7. Test Keyboard Arrow navigation on the dual handle
    console.log('7. Testing Keyboard Dual Resize (ArrowLeft / ArrowRight)...');
    const keyResult = await page.evaluate(() => {
      const fieldA = document.querySelectorAll('.nexley-canvas-field')[0];
      const handle = fieldA.querySelector('.nexley-field-resize-handle.is-dual');

      const spanBeforeA = parseInt(fieldA.style.getPropertyValue('--span'), 10);

      // Press ArrowLeft
      handle.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'ArrowLeft',
        bubbles: true,
        cancelable: true
      }));

      const finalFields = document.querySelectorAll('.nexley-canvas-field');
      const spanAfterA = parseInt(finalFields[0].style.getPropertyValue('--span'), 10);
      const spanAfterB = parseInt(finalFields[1].style.getPropertyValue('--span'), 10);

      return {
        spanBeforeA,
        spanAfterA,
        spanAfterB
      };
    });

    console.log('   Keyboard result:', JSON.stringify(keyResult, null, 2));
    if (keyResult.spanAfterA !== keyResult.spanBeforeA - 1) {
      throw new Error(`FAIL: ArrowLeft should decrement spanA by 1. Expected ${keyResult.spanBeforeA - 1}, got ${keyResult.spanAfterA}`);
    }
    if (keyResult.spanAfterA + keyResult.spanAfterB !== 12) {
      throw new Error(`FAIL: Sum of spans must equal 12.`);
    }
    console.log('   PASS: Keyboard dual resize properly decremented left card and incremented right card.\n');

    console.log('======================================================================');
    console.log('   ALL DUAL/MIDDLE RESIZE VERIFICATION CHECKS PASSED (100% SUCCESS)   ');
    console.log('======================================================================\n');
  } finally {
    await browser.close();
  }
}

runMiddleResizeVerification().catch(err => {
  console.error('\nTest failed with error:', err);
  process.exit(1);
});
