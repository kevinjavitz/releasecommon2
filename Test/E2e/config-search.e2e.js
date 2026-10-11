// Settings search (Stores > Configuration, the Sales Igniter sections), end to end in headless Chromium:
//  1. the box is on top of the first Sales Igniter section, labelled, with an empty aria-live count, and the page
//     does not move when the script starts; the index lists exactly the settings the page shows (this scope);
//  2. typing "license key" keeps only the matching settings, every one of them matching every word, opens their
//     groups, hides the rest, marks the words, and counts them (the live region says the same a moment later);
//     "Also in:" lists the other Sales Igniter sections that match, with counts, linked with the term;
//  3. a setting path ("license/key") finds the setting and shows the path under its label;
//  4. a setting hidden by a dependency is found through the setting it depends on ("Change this to show ...");
//  5. Enter goes to the first match; "Use system value" still works on a filtered setting;
//  6. Save Config while searching would save the groups' open state as it was before the search;
//  7. Esc puts the page back exactly as it was (the form's HTML is byte for byte the same, groups closed again);
//     the clear button does the same and gives the focus back to the box; a search with no match says so;
//  8. an "Also in" link opens that section with the box pre-filled and filtered (the parameter is dropped from
//     the address), and Esc there restores that page;
//  9. website scope: the index still matches the page and the links keep the scope;
// 10. no box on a section that is not Sales Igniter's; no browser errors on any of these pages.
// Nothing is saved. It makes one throwaway admin user (random password, never printed) and deletes it.
// Run it on the database clone (from the Magento root, inside the web container):
//   php -d memory_limit=512M <releaserental2>/Test/Integration/run-on-clone.php --exec -- env PLAYWRIGHT_DIR=/var/www/html/.claude-e2e \
//     PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.claude-e2e/ms-playwright NODE_OPTIONS=--max-old-space-size=2048 node <this file>
// Or against a dev site's own URL with MAGE_BASE_URL. SHOT_DIR=<dir> keeps screenshots.
'use strict';
const path = require('path');
const crypto = require('crypto');
const fs = require('fs');
const { execFileSync } = require('child_process');

function loadPlaywright() {
  const candidates = [process.env.PLAYWRIGHT_DIR, '/var/www/html/.claude-e2e', '/var/www/html/.claude-wt/e2e'].filter(Boolean);
  for (const dir of candidates) { try { return require(dir + '/node_modules/playwright'); } catch (e) { /* next */ } }
  return require('playwright');
}
const { chromium } = loadPlaywright();

const ROOT = process.env.MAGE_ROOT || '/var/www/html';
const BASE = (process.env.MAGE_BASE_URL || 'http://127.0.0.1:8899').replace(/\/$/, '');
const ADMIN = BASE + '/' + (process.env.ADMIN_PATH || 'admin');
const SHOT_DIR = process.env.SHOT_DIR || '';
const TIMEOUT = parseInt(process.env.E2E_TIMEOUT, 10) || 120000;
const USER = 'claude_e2e_cfgsearch';
const PASS = 'Cs' + crypto.randomBytes(10).toString('hex') + '7q';

let failures = 0;
const ok = (stage, detail) => console.log(JSON.stringify({ stage, ok: true, detail }));
const bad = (stage, detail) => { failures++; console.log(JSON.stringify({ stage, ok: false, detail })); };
const check = (cond, stage, detail) => (cond ? ok(stage, detail) : bad(stage, detail));
const squash = t => String(t || '').replace(/\s+/g, ' ').trim();

function fx(action, env) {
  const out = execFileSync('php', ['-d', 'memory_limit=1G', path.join(__dirname, 'config-search-fixtures.php')], {
    cwd: ROOT,
    env: Object.assign({}, process.env, { CS_ACTION: action, CS_ADMIN_USER: USER, MAGE_ROOT: ROOT }, env || {}),
    maxBuffer: 16 * 1024 * 1024,
  }).toString();
  const line = out.split('\n').filter(l => l.trim().startsWith('{')).pop();
  if (!line) throw new Error(`config-search-fixtures ${action} printed no JSON: ${out.slice(-400)}`);
  const data = JSON.parse(line);
  if (data.error) throw new Error('config-search-fixtures: ' + data.error);
  return data;
}

async function shot(page, name) {
  if (!SHOT_DIR) return;
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  await page.waitForTimeout(300);
  await page.screenshot({ path: path.join(SHOT_DIR, name + '.png'), fullPage: false });
}

/** a config section page, fully set up (the page's own scripts run on load) */
async function openSection(page, section, extra) {
  await page.goto(`${ADMIN}/admin/system_config/edit/section/${section}/${extra || ''}`, { waitUntil: 'load' });
  await page.waitForLoadState('networkidle').catch(() => {});
  await page.waitForTimeout(500);
}

/** type like a person and wait for the filter (the count) and the live region */
async function search(page, term) {
  const box = page.locator('#si-cfg-search-input');
  await box.click();
  await box.fill('');
  await box.pressSequentially(term, { delay: 30 });
  await page.waitForFunction(() => squash(document.querySelector('.si-cfg-search__count').textContent) !== ''
    && squash(document.querySelector('#si-cfg-search-status').textContent) !== '', null, { timeout: 15000 })
    .catch(() => {});
  await page.waitForTimeout(200);
}

/**
 * The settings form as the search may change it, one line per element: tag, id, every attribute but `style` (other
 * scripts size things when a group opens, e.g. the Search Styler's preview), whether a group or a setting is
 * shown or hidden, and its own text. The same lines before a search and after Esc = the page is back as it was.
 */
async function fingerprint(page) {
  return page.evaluate(() => {
    const form = document.getElementById('config-edit-form');
    const lines = [];
    const walk = (el, depth) => {
      const attrs = Array.from(el.attributes).filter(a => a.name !== 'style').map(a => `${a.name}=${a.value}`).join(' ');
      // shown or hidden ('' and 'table-row' are the same thing for a row; some settings' own scripts set one or
      // the other when their group opens)
      const shown = el.matches('tr, fieldset, .section-config') ? (el.style.display === 'none' ? ' hidden' : ' shown') : '';
      const text = Array.from(el.childNodes).filter(n => n.nodeType === 3).map(n => n.nodeValue).join('').replace(/\s+/g, ' ').trim();
      lines.push(`${depth} <${el.tagName.toLowerCase()} ${attrs}>${shown} ${text}`);
      if (el.tagName !== 'SCRIPT' && el.tagName !== 'IFRAME') {
        Array.from(el.children).forEach(c => walk(c, depth + 1));
      }
    };
    walk(form, 0);
    return lines;
  });
}

/** everything the checks below need to know about the page, read in the page */
async function audit(page, term) {
  return page.evaluate((term) => {
    const fold = s => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const words = fold(term).split(/\s+/).filter(Boolean);
    const form = document.getElementById('config-edit-form');
    const index = JSON.parse(document.getElementById('si-cfg-search-index').textContent);
    const section = new URL(location.href).pathname.match(/\/section\/([^/]+)/)[1];
    const mine = (index.sections.find(s => s.id === section) || { fields: [] }).fields;
    const byId = {};
    mine.forEach(f => { byId[f.path.replace(/\//g, '_')] = f; });
    const shown = el => {
      for (let e = el; e && e !== form; e = e.parentElement) {
        if (getComputedStyle(e).display === 'none') return false;
      }
      return true;
    };
    const groups = Array.from(form.querySelectorAll('fieldset.config[id]')).map(fs => {
      const head = document.getElementById(fs.id + '-head');
      const wrapper = head ? head.closest('.section-config') : fs;
      return { id: fs.id, label: head ? head.textContent : '', wrapperShown: getComputedStyle(wrapper).display !== 'none',
        open: getComputedStyle(fs).display !== 'none', state: (document.getElementById(fs.id + '-state') || {}).value,
        stateDefault: (document.getElementById(fs.id + '-state') || {}).defaultValue };
    });
    const groupLabel = {};
    groups.forEach(g => { groupLabel[g.id] = g.label; });
    const rows = Array.from(form.querySelectorAll('tr[id^="row_"]')).filter(tr => {
      const t = tr.parentElement.closest('table');
      return t && t.parentElement.matches('fieldset.config');
    });
    const visible = rows.filter(shown).map(tr => {
      const id = tr.id.substring(4);
      const f = byId[id] || {};
      const fs = tr.closest('fieldset.config');
      const hay = fold([tr.querySelector('.label label, td.label') && tr.querySelector('.label label, td.label').textContent,
        (tr.querySelector('.note') || {}).textContent, (tr.querySelector('.tooltip-content') || {}).textContent,
        groupLabel[fs.id], f.path, f.config_path].join(' '));
      return { id, group: fs.id, match: tr.classList.contains('si-cfg-search-match'), context: tr.classList.contains('si-cfg-search-context'),
        everyWord: words.every(w => hay.includes(w)), marks: tr.querySelectorAll('mark.si-cfg-search-mark').length,
        hint: squash((tr.querySelector('.si-cfg-search-hint') || {}).textContent), path: squash((tr.querySelector('.si-cfg-search-path') || {}).textContent),
        groupOpen: getComputedStyle(fs).display !== 'none' };
      function squash(t) { return String(t || '').replace(/\s+/g, ' ').trim(); }
    });
    const missingRows = mine.filter(f => !document.getElementById('row_' + f.path.replace(/\//g, '_'))).map(f => f.path);
    const extraRows = rows.map(tr => tr.id.substring(4)).filter(id => !byId[id]);
    const sq = t => String(t || '').replace(/\s+/g, ' ').trim();
    return {
      section,
      count: sq(document.querySelector('.si-cfg-search__count').textContent),
      live: sq(document.getElementById('si-cfg-search-status').textContent),
      resultsHidden: document.querySelector('.si-cfg-search__results').hidden,
      clearHidden: document.querySelector('.si-cfg-search__clear').hidden,
      also: Array.from(document.querySelectorAll('.si-cfg-search__also-links a')).map(a => ({ text: sq(a.textContent), href: a.getAttribute('href'), section: a.dataset.section })),
      marks: form.querySelectorAll('mark.si-cfg-search-mark').length,
      ours: form.querySelectorAll('.si-cfg-search-hide, .si-cfg-search-match, .si-cfg-search-context, .si-cfg-search-tip, .si-cfg-search-hint, .si-cfg-search-path').length,
      visible,
      groups,
      rows: rows.length,
      indexFields: mine.length,
      missingRows,
      extraRows,
      value: document.getElementById('si-cfg-search-input').value,
    };
  }, term || '');
}

(async () => {
  const state = fx('setup', { CS_ADMIN_PASS: PASS });
  const sections = state.sections.map(s => s.id);
  ok('setup', { user: state.user_id, sections });
  if (!sections.length) {
    bad('sections', 'no section in the salesigniter tab: nothing to search');
  }
  const first = sections.includes('salesigniter_rental') ? 'salesigniter_rental' : sections[0];

  let browser = null;
  const errors = [];
  try {
    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true });
    ctx.setDefaultTimeout(TIMEOUT);
    ctx.setDefaultNavigationTimeout(TIMEOUT);
    // where the settings form starts as soon as the HTML is in, before any script has run on it
    await ctx.addInitScript(() => {
      window.squash = t => String(t || '').replace(/\s+/g, ' ').trim();
      document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('config-edit-form');
        const box = document.getElementById('si-cfg-search');
        window.__siEarly = {
          form: form ? form.getBoundingClientRect().top + window.scrollY : null,
          boxTop: box ? box.getBoundingClientRect().top + window.scrollY : null,
          box: box ? box.getBoundingClientRect().height : null,
        };
      });
    });
    const page = await ctx.newPage();
    const where = () => page.url().replace(BASE, '').replace(/\/key\/[0-9a-f]+\/?/, '/');
    page.on('console', m => { if (m.type() === 'error') errors.push(where() + ' :: ' + m.text().slice(0, 300)); });
    page.on('pageerror', e => errors.push(where() + ' :: pageerror: ' + String(e.message).slice(0, 300)));

    // ------------------------------------------------------------------------------------------------- login
    await page.goto(ADMIN + '/', { waitUntil: 'domcontentloaded' });
    await page.fill('#username', USER);
    await page.fill('#login', PASS);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), page.click('.action-login')]);
    check(!(await page.locator('#login').count()), 'admin-login', where());

    // ---------------------------------------------------------------------------------------- 1. the box
    await openSection(page, first);
    const box = page.locator('#si-cfg-search');
    check(await box.count() === 1, 'box-on-the-section', where());
    const label = squash(await page.locator('label[for="si-cfg-search-input"]').textContent().catch(() => ''));
    const live = page.locator('#si-cfg-search-status');
    check(label === 'Find a setting' && await live.getAttribute('aria-live') === 'polite' && await live.getAttribute('role') === 'status'
      && squash(await live.textContent()) === '' && !!(await page.locator('#si-cfg-search-input').getAttribute('aria-describedby')),
    'box-labelled-with-an-empty-live-count', { label });
    const order = await page.evaluate(() => {
      const box = document.getElementById('si-cfg-search');
      const form = document.getElementById('config-edit-form');
      return !!(box && form && (box.compareDocumentPosition(form) & Node.DOCUMENT_POSITION_FOLLOWING));
    });
    check(order, 'box-above-the-settings');
    const late = await page.evaluate(() => {
      const form = document.getElementById('config-edit-form');
      const box = document.getElementById('si-cfg-search');
      return { early: window.__siEarly, form: form.getBoundingClientRect().top + window.scrollY,
        boxTop: box.getBoundingClientRect().top + window.scrollY, box: box.getBoundingClientRect().height };
    });
    // the box and what follows it do not move against each other, and the box keeps its height (whatever the
    // admin's own header and messages above it do while the page loads)
    check(late.early && Math.abs((late.early.form - late.early.boxTop) - (late.form - late.boxTop)) < 0.5 && Math.abs(late.early.box - late.box) < 0.5,
      'no-layout-shift-when-the-script-starts', late);
    let a = await audit(page, '');
    check(a.missingRows.length === 0 && a.extraRows.length === 0 && a.indexFields > 0,
      'index-lists-exactly-the-settings-on-the-page', { fields: a.indexFields, rows: a.rows, missing: a.missingRows.slice(0, 5), extra: a.extraRows.slice(0, 5) });
    check(a.resultsHidden && a.clearHidden && a.count === '' && a.marks === 0 && a.ours === 0, 'nothing-shown-while-empty', { count: a.count });
    await shot(page, '1-empty');

    const before = await fingerprint(page);
    const groupsBefore = a.groups.map(g => [g.id, g.open, g.state].join(':'));

    // ----------------------------------------------------------------------------------- 2. license key
    await search(page, 'license key');
    a = await audit(page, 'license key');
    const matched = a.visible.filter(r => r.match);
    const hintTotal = a.visible.reduce((n, r) => n + (parseInt((r.hint.match(/\d+/) || ['0'])[0], 10) || 0), 0);
    const licenseRow = a.visible.find(r => /_license_key$/.test(r.id));
    check(matched.length > 0 && a.visible.every(r => r.match ? r.everyWord : r.context), 'only-matching-settings-shown',
      { matched: matched.map(r => r.id), other: a.visible.filter(r => !r.match && !r.context).map(r => r.id) });
    check(!!licenseRow && licenseRow.marks >= 2, 'license-key-found-and-marked', licenseRow);
    check(a.visible.every(r => r.groupOpen) && a.groups.every(g => g.wrapperShown === a.visible.some(r => r.group === g.id)),
      'groups-with-a-match-open-the-rest-hidden', a.groups.filter(g => g.wrapperShown).map(g => g.id));
    const want = matched.length + hintTotal;
    const sentence = want === 1 ? '1 setting matches' : `${want} settings match`;
    check(a.count === sentence, 'count-of-matches', { count: a.count, want: sentence });
    check(a.live.startsWith(sentence), 'live-region-says-the-count', a.live);
    const others = sections.filter(s => s !== first);
    check(others.length === 0 || (a.also.length > 0 && a.also.every(l => /\(\d+\)$/.test(l.text) && others.includes(l.section)
      && l.href.includes(`/section/${l.section}/`) && new URL(l.href).searchParams.get('config_search') === 'license key')),
    'also-in-lists-the-other-sections-with-the-term', a.also);
    await shot(page, '2-license-key');

    // ------------------------------------------------------------------------------------ 3. a setting path
    await search(page, 'license/key');
    a = await audit(page, 'license/key');
    const byPath = a.visible.find(r => /_license_key$/.test(r.id));
    check(!!byPath && byPath.match && /license\/key/.test(byPath.path), 'setting-path-finds-the-setting-and-shows-the-path', byPath);
    await shot(page, '3-path');

    // ---------------------------------------------------------------------- 4. hidden by a dependency
    const dependent = await page.evaluate(() => {
      const index = JSON.parse(document.getElementById('si-cfg-search-index').textContent);
      const section = new URL(location.href).pathname.match(/\/section\/([^/]+)/)[1];
      const fields = (index.sections.find(s => s.id === section) || { fields: [] }).fields;
      const labels = {};
      Array.from(document.querySelectorAll('#config-edit-form tr[id^="row_"]')).forEach(tr => {
        const l = tr.querySelector('.label label, td.label');
        labels[tr.id.substring(4)] = l ? l.textContent.replace(/\s+/g, ' ').trim() : '';
      });
      const counts = {};
      Object.values(labels).forEach(l => { counts[l] = (counts[l] || 0) + 1; });
      for (const f of fields) {
        const id = f.path.replace(/\//g, '_');
        const tr = document.getElementById('row_' + id);
        if (!f.depends || !tr || tr.style.display !== 'none' || !labels[id] || counts[labels[id]] !== 1 || labels[id].length < 8) continue;
        const master = document.getElementById('row_' + f.depends[0]);
        if (master && master.style.display !== 'none') return { id, label: labels[id], master: f.depends[0] };
      }
      return null;
    });
    if (dependent) {
      await search(page, dependent.label);
      a = await audit(page, dependent.label);
      const master = a.visible.find(r => r.id === dependent.master);
      const hidden = await page.evaluate(id => getComputedStyle(document.getElementById('row_' + id)).display === 'none', dependent.id);
      check(hidden && !!master && /^Change this to show \d+ more matching settings?\.$/.test(master.hint),
        'dependency-hidden-match-found-through-its-master', { dependent, master });
      await shot(page, '4-dependency');
    } else {
      ok('dependency-hidden-match-found-through-its-master', 'skipped: no field hidden by a dependency on this page');
    }

    // ------------------------------------------------------------------------------------------- 5. Enter
    await search(page, 'license key');
    await page.locator('#si-cfg-search-input').press('Enter');
    const focused = await page.evaluate(() => {
      const el = document.activeElement;
      const tr = el && el.closest('tr[id^="row_"]');
      return tr ? { row: tr.id, match: tr.classList.contains('si-cfg-search-match'), tag: el.tagName } : null;
    });
    check(!!focused && focused.match, 'enter-goes-to-the-first-match', focused);

    // ----------------------------------------------------------------------------- 6. Save Config guard
    await search(page, 'license key');
    const saveGuard = await page.evaluate(() => {
      window.require('jquery')('#config-edit-form').trigger('afterValidate');
      const states = Array.from(document.querySelectorAll('#config-edit-form input[id$="-state"]'));
      return { changed: states.filter(i => i.value !== i.defaultValue).map(i => i.id),
        stillOpen: Array.from(document.querySelectorAll('#config-edit-form .si-cfg-search-match')).every(tr => getComputedStyle(tr.closest('fieldset')).display !== 'none') };
    });
    check(saveGuard.changed.length === 0 && saveGuard.stillOpen, 'saving-while-searching-keeps-the-groups-saved-state', saveGuard);

    // ------------------------------------------------------------------------------------ 7. Esc, clear
    await page.locator('#si-cfg-search-input').focus();
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    a = await audit(page, '');
    const after = await fingerprint(page);
    let firstDiff = -1;
    for (let i = 0; i < Math.max(before.length, after.length); i++) { if (before[i] !== after[i]) { firstDiff = i; break; } }
    check(a.value === '' && a.count === '' && a.live === '' && a.resultsHidden && a.clearHidden && a.marks === 0 && a.ours === 0,
      'esc-clears-the-search', { value: a.value, count: a.count, marks: a.marks, ours: a.ours });
    check(after.join('\n') === before.join('\n'), 'esc-restores-the-form-exactly', firstDiff < 0 ? { lines: before.length }
      : { at: firstDiff, before: before.slice(Math.max(0, firstDiff - 2), firstDiff + 3), after: after.slice(Math.max(0, firstDiff - 2), firstDiff + 3) });
    check(JSON.stringify(a.groups.map(g => [g.id, g.open, g.state].join(':'))) === JSON.stringify(groupsBefore), 'esc-restores-the-open-groups');

    await search(page, 'late fee');
    await shot(page, '7-late-fee');
    await page.locator('.si-cfg-search__clear').click();
    await page.waitForTimeout(500);
    a = await audit(page, '');
    const after2 = await fingerprint(page);
    const focusBack = await page.evaluate(() => document.activeElement && document.activeElement.id);
    check(after2.join('\n') === before.join('\n') && a.marks === 0 && a.value === '' && focusBack === 'si-cfg-search-input', 'clear-button-restores-and-refocuses', { focusBack });

    const nothing = 'zzqx' + crypto.randomBytes(3).toString('hex');
    await search(page, nothing);
    a = await audit(page, nothing);
    check(a.count === `No settings match "${nothing}".` && a.visible.length === 0 && a.groups.every(g => !g.wrapperShown) && a.resultsHidden,
      'no-match-says-so', { count: a.count, live: a.live });
    await page.locator('#si-cfg-search-input').focus();
    await page.keyboard.press('Tab');
    const tabbed = await page.evaluate(() => document.activeElement && document.activeElement.className);
    check(/si-cfg-search__clear/.test(tabbed || ''), 'tab-reaches-the-clear-button', tabbed);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    check((await fingerprint(page)).join('\n') === before.join('\n'), 'esc-on-the-clear-button-restores');

    // --------------------------------------------------------------- 7b. Use system value on a filtered setting
    const inherit = await page.evaluate(() => {
      for (const tr of document.querySelectorAll('#config-edit-form tr[id^="row_"]')) {
        const cb = tr.querySelector('.use-default input[type="checkbox"]');
        const l = tr.querySelector('.label label, td.label');
        const value = tr.querySelector('.value select, .value input:not([type="hidden"]), .value textarea');
        if (cb && l && value && getComputedStyle(tr).display !== 'none' && l.textContent.trim().length > 6) {
          return { row: tr.id, label: l.textContent.replace(/\s+/g, ' ').trim(), cb: cb.id, value: value.id };
        }
      }
      return null;
    });
    if (inherit) {
      await search(page, inherit.label);
      const disabled = () => page.evaluate(id => document.getElementById(id).disabled, inherit.value);
      const d0 = await disabled();
      await page.locator('#' + inherit.cb).click();
      await page.waitForTimeout(300);
      const d1 = await disabled();
      const stillShown = await page.evaluate(id => getComputedStyle(document.getElementById(id)).display !== 'none', inherit.row);
      await page.locator('#' + inherit.cb).click();
      await page.waitForTimeout(300);
      const d2 = await disabled();
      check(d1 === !d0 && d2 === d0 && stillShown, 'use-system-value-works-on-a-filtered-setting', { inherit, d0, d1, d2, stillShown });
      await page.locator('#si-cfg-search-input').press('Escape');
      await page.waitForTimeout(400);
    } else {
      ok('use-system-value-works-on-a-filtered-setting', 'skipped: no "Use system value" setting on this page');
    }


    // -------------------------------------------------------------------------------- 8. Also in: the link
    if (others.length) {
      await search(page, 'license key');
      a = await audit(page, 'license key');
      const link = a.also[0];
      if (link) {
        await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.locator(`.si-cfg-search__also-links a[data-section="${link.section}"]`).click()]);
        await page.waitForLoadState('networkidle').catch(() => {});
        await page.waitForFunction(() => squash(document.querySelector('.si-cfg-search__count').textContent) !== '', null, { timeout: 15000 }).catch(() => {});
        await page.waitForTimeout(600);
        a = await audit(page, 'license key');
        const n = parseInt(link.text.match(/\((\d+)\)$/)[1], 10);
        const landed = a.visible.filter(r => r.match);
        check(a.section === link.section && a.value === 'license key' && landed.length + 0 >= 1 && a.count === (n === 1 ? '1 setting matches' : `${n} settings match`)
          && landed.every(r => r.everyWord && r.marks > 0),
        'also-in-link-opens-the-section-pre-filled-and-filtered', { section: a.section, value: a.value, count: a.count, link: link.text, landed: landed.map(r => r.id) });
        check(!new URL(page.url()).searchParams.has('config_search'), 'the-term-leaves-the-address', where());
        check(await page.evaluate(() => document.activeElement && document.activeElement.id) === 'si-cfg-search-input', 'focus-in-the-box-on-arrival');
        check(a.also.some(l => l.section === first), 'the-way-back-is-listed', a.also);
        await shot(page, '8-landed');
        await page.locator('#si-cfg-search-input').press('Escape');
        await page.waitForTimeout(400);
        a = await audit(page, '');
        check(a.marks === 0 && a.ours === 0 && a.groups.every(g => g.wrapperShown && g.state === g.stateDefault && g.open === (g.state === '1')),
          'esc-restores-the-landed-page', a.groups.filter(g => !(g.wrapperShown && g.state === g.stateDefault)).map(g => g.id));
      } else {
        bad('also-in-link-opens-the-section-pre-filled-and-filtered', 'no link for "license key"');
      }
    }

    // ----------------------------------------------------------------------------------- 9. website scope
    await openSection(page, first, 'website/1/');
    a = await audit(page, '');
    check(a.missingRows.length === 0 && a.extraRows.length === 0, 'website-scope-index-lists-exactly-its-settings',
      { fields: a.indexFields, rows: a.rows, missing: a.missingRows.slice(0, 5), extra: a.extraRows.slice(0, 5) });
    await search(page, 'email');
    a = await audit(page, 'email');
    check(a.visible.filter(r => r.match).every(r => r.everyWord) && a.also.every(l => l.href.includes('/website/1/')),
      'website-scope-search-and-links-keep-the-scope', { count: a.count, also: a.also.map(l => l.href.replace(BASE, '')) });
    await shot(page, '9-website-scope');

    // ---------------------------------------------------------------------------- 10. other sections, errors
    await openSection(page, 'general');
    check(await page.locator('#si-cfg-search').count() === 0, 'no-box-on-other-sections', where());
    check(errors.length === 0, 'no-browser-errors', errors.slice(0, 10));
  } catch (e) {
    bad('exception', String(e && e.stack || e).slice(0, 1200));
  } finally {
    if (browser) await browser.close();
    try {
      ok('teardown', fx('teardown'));
    } catch (e) {
      bad('teardown', String(e.message).slice(0, 400));
    }
  }
  console.log(JSON.stringify({ stage: 'summary', ok: failures === 0, failures }));
  process.exit(failures ? 1 : 0);
})();
