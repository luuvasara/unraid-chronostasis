// compare-shots.js <out dir>: every screen of the comparison on Unraid's stylesheets, black, white and narrow, with made-up
// fixtures and a stand-in for docker, plus axe on each. Start compare-serve.sh first. usage: node compare-shots.js /path/out (PLAYWRIGHT and AXE_JS name the modules if they are not installed here)
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const fs = require('fs'), { execSync } = require('child_process');
const H = require('path').resolve(__dirname, '../..'), W = H + '/tests/.work/compare', OUT = process.argv[2] + '/', PFX = 'chronostasis-compare-';
const AXE = fs.readFileSync(process.env.AXE_JS || require.resolve('axe-core/axe.min.js'), 'utf8');
const UP = 'running|2020-01-01T00:00:00.000Z|dockerman||bridge|0|', axeAll = {};
const cases = [['yours', 'o', 'MY_EXTRA_FLAG'], ['new-default', 'o', 'LOG_FORMAT'], ['new-required', 's', 'Path /downloads'], ['likely', 'o', 'Variable MOVIES_DIR'], ['rename', 's', 'FROM_EMAIL_ADDRESS'], ['moved-path', 'o', 'Path /media'], ['port', 'o', 'Port 8181/tcp'], ['image', 's', 'Image differs'], ['address', 's', 'Template address'], ['default', 'o', 'Variable LOG_LEVEL'], ['required', 'o', 'Variable WORKERS'], ['masked', 'o', 'Variable OLD_API_TOKEN'], ['long', 'o', 'JAVA_EXTRA_OPTIONS']];
const sh = c => execSync(c, { encoding: 'utf8', shell: '/bin/bash' });
(async () => {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
  const ctx = await b.newContext(), page = await ctx.newPage(), errs = [], missed = [];
  page.on('pageerror', e => errs.push(e.message));
  await page.addInitScript(() => { try { localStorage.removeItem('chronostasis-walk'); } catch (e) { /* none */ } }); // every screen starts with no answers
  ctx.on('page', p => { if (p !== page) setTimeout(() => p.close().catch(() => {}), 200); });
  for (const [tag, theme, port, w, h] of [['black', 'black', 18811, 1300, 1000], ['white', 'white', 18812, 1300, 1000], ['narrow', 'black', 18811, 420, 900]]) {
    const R = `${W}/${theme}`, F = `${R}/fake`, D = `${R}/tpl/my-filmshelf.xml`, U = `${R}/run/upgrade/my-filmshelf.xml`;
    await page.setViewportSize({ width: w, height: h });
    const shot = async (id, sel, axeSel) => {
      await page.waitForTimeout(250);
      await page.evaluate(([from, to]) => { [...document.querySelectorAll('body *')].filter(e => e.textContent.includes(from) && ![...e.children].some(c => c.textContent.includes(from))).forEach(e => { e.textContent = e.textContent.split(from).join(to); }); }, [`${R}/config`, '/boot/config/plugins/unraid-chronostasis']);
      await page.locator(sel).first().screenshot({ path: OUT + PFX + tag + '-' + id + '.png' });
      await page.evaluate(AXE);
      const r = await page.evaluate(async s => { const x = await axe.run(document.querySelector(s), { resultTypes: ['violations'], rules: { 'target-size': { enabled: true } } }); return x.violations.map(v => [v.id, v.impact, v.nodes.length, v.nodes[0].target.join(' ')]); }, axeSel || sel);
      r.forEach(v => { const k = v[0] + ' ' + v[1] + ' ' + v[3]; axeAll[k] = (axeAll[k] || 0) + v[2]; });
    };
    const reset = (extra = '') => sh(`rm -rf ${R}/config/* ${R}/run/upgrade ${F}/manifest-error ${F}/create-error ${F}/image-ok ${F}/c/*; cp -p ${H}/tests/fixtures/compare/templates/my-filmshelf.xml ${D}; echo 'aaa|${UP}' >${F}/c/filmshelf; echo 'aaa|${UP}' >${F}/c/oldpad; ${extra}`);
    const go = async (walk, txt) => { await page.goto(`http://127.0.0.1:${port}/`); await page.waitForSelector('.cs-app'); await page.click(walk); for (let i = 0; i < 60; i++) { const t = await page.locator('#cs-walk h3').first().textContent().catch(() => null); if (t && t.includes(txt)) return true; const n = page.locator('#cs-next'); if (!await n.count()) return false; await n.click(); await page.waitForTimeout(40); } return false; };
    reset();
    for (const [id, k, txt] of cases) {
      const walk = k === 's' ? '#cs-walkstart' : '#cs-walkother';
      if (!await go(walk, txt)) { missed.push(tag + ':' + id); continue; }
      await page.waitForTimeout(150);
      await shot(id, '#cs-walk');
      if (id === 'likely') {
        await page.click('#cs-yes'); await shot('likely-yes', '#cs-walk');
        await go(walk, txt); await page.waitForTimeout(150);
        await page.locator('#cs-other').selectOption('MY_EXTRA_FLAG'); await shot('likely-other', '#cs-walk');
      }
    }
    const answer = async () => {
      await go('#cs-walkstart', 'Path /downloads'); await page.click('#cs-include'); await page.waitForTimeout(150);
      for (const [t, sel] of [['Variable MOVIES_DIR', '#cs-yes'], ['Path /media', '#cs-yes'], ['Port 8181/tcp', '#cs-yes'], ['LOG_FORMAT', '#cs-include'], ['MY_EXTRA_FLAG', '#cs-report']]) {
        await page.click('#cs-walkother'); await page.waitForTimeout(150);
        for (let i = 0; i < 30; i++) { const x = await page.locator('#cs-walk h3').first().textContent(); if (x.includes(t)) break; await page.click('#cs-next'); await page.waitForTimeout(40); }
        await page.waitForTimeout(120);
        await page.click(sel); await page.waitForTimeout(150);
      }
      await page.click('#cs-finish'); await page.waitForTimeout(300); await page.click('#cs-go-upgrade'); await page.waitForSelector('.cs-review');
    };
    await answer();
    await shot('finish', '#cs-walk');
    await shot('finish-app', '#cs-finish-apps');
    const review = async () => { await page.locator('.cs-review').first().click(); await page.waitForFunction(() => /taken/.test(document.getElementById('cs-walk').textContent)); };
    await review(); await shot('finish-app-backed', '#cs-finish-apps');
    const press = (line, extra = '') => sh(`cp ${U} ${D}; touch -d '2 seconds ago' ${D}; ${line === null ? `rm -f ${F}/c/filmshelf` : `echo '${line}' >${F}/c/filmshelf`}; ${extra}`);
    const failed = async (id, re) => { await page.waitForFunction(r => new RegExp(r).test(document.getElementById('cs-jobs').textContent), re, { timeout: 15000 }); await shot(id, '#cs-jobs'); };
    press('bbb|created|0001-01-01T00:00:00Z|dockerman||bridge|0|failed to set up container networking: driver failed programming external connectivity on endpoint filmshelf (0834df757d9e2ed7ef608233e639ac8e4853ef2c4e85092e34aee0930517b401): Bind for :::18090 failed: port is already allocated');
    await failed('failed', 'Error from Docker');
    // going back fails too
    const [p1] = await Promise.all([ctx.waitForEvent('page'), page.click('#cs-job-old')]);
    await page.waitForTimeout(900);
    sh(`cp ${R}/config/backups/filmshelf/*.xml ${D}; touch -d '1 seconds ago' ${D}; echo 'ccc|created|0001-01-01T00:00:00Z|dockerman||bridge|0|failed to set up container networking: driver failed programming external connectivity on endpoint filmshelf (ee82d6dffb2b9054a6c7a5dfb777c4695953a0e8ac4bb286816b9baf9ab406f3): Bind for :::18080 failed: port is already allocated' >${F}/c/filmshelf`);
    await failed('failed-restore', 'going back failed too');
    // the three kinds of missing
    for (const [id, setup, re] of [['failed-missing', `echo 'no such manifest: docker.io/library/nginx:no-such-tag-cs' >${F}/manifest-error`, 'the image was checked'], ['failed-param', `touch ${F}/image-ok; printf 'docker: unknown flag: --no-such-flag-cs\\nSee docker create --help\\n' >${F}/create-error`, 'extra parameters were checked'], ['failed-noerror', `touch ${F}/image-ok`, 'keep the error']]) {
      reset(id === 'failed-param' ? `sed -i 's#<Network>bridge</Network>#<Network>bridge</Network><ExtraParams>--no-such-flag-cs</ExtraParams>#' ${D}; touch -d '60 seconds ago' ${D}` : '');
      sh(`CS_HISTORY=${H}/tests/fixtures/compare/history.json CS_TEMPLATES=${R}/tpl CS_FCPFEED=${H}/tests/fixtures/compare/feed.json CS_CACHE=${R}/cache CS_RUN=${R}/run CS_DOCKER=/bin/false CS_NO_URLCHECK=1 php ${H}/src/usr/local/emhttp/plugins/unraid-chronostasis/scripts/check.php`);
      await answer(); await review(); sh(setup); press(null);
      await failed(id, re);
    }
    reset(); await answer(); await review(); press('ddd|' + UP);
    await page.waitForFunction(() => /Upgraded settings applied/.test(document.getElementById('cs-apps').textContent), null, { timeout: 15000 });
    await page.waitForTimeout(800);
    await shot('row', '.cs-app:has-text("Upgraded settings applied")', '#cs-apps');
    reset(`rm -f ${D}; cp -p ${H}/tests/fixtures/compare/templates/my-filmshelf.xml ${D}`);
  }
  await b.close();
  console.log(JSON.stringify({ missed, errs: [...new Set(errs)].slice(0, 5), axe: axeAll }, null, 1));
})();
