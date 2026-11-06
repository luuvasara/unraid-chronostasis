// tips-check.js: walk both lists on the fixture page and list every visible control with no tooltip. Start compare-serve.sh first.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] }), p = await b.newPage(), miss = new Set(), seen = new Set();
  const scan = async () => { for (const r of await p.$$eval('button,select,a[download],a[href*="Browse"],#cs-pick', e => e.filter(x => x.offsetParent && !x.closest('#menu,#header,#footer,.nav')).map(x => [(x.textContent || x.id).trim().slice(0, 40), !!(x.title || (x.closest('label') && x.closest('label').title))]))) { seen.add(r[0]); if (!r[1]) miss.add(r[0]); } };
  for (const w of ['#cs-walkstart', '#cs-walkother']) {
    await p.goto('http://127.0.0.1:18811/'); await p.waitForSelector('.cs-app'); await scan();
    await p.click(w); await p.waitForTimeout(150);
    for (let i = 0; i < 40; i++) { await scan(); if (!(await p.$('#cs-next'))) break; await p.click('#cs-next'); await p.waitForTimeout(80); }
    await p.click('#cs-finish'); await p.waitForTimeout(400); await scan(); if (await p.isEnabled('#cs-go-upgrade')) { await p.click('#cs-go-upgrade'); await p.waitForTimeout(300); await scan(); await p.click('#cs-end-hub'); await p.waitForTimeout(300); }
  }
  await p.setViewportSize({ width: 1300, height: 900 }); await p.goto('http://127.0.0.1:18811/'); await p.waitForSelector('.cs-app'); await p.waitForTimeout(300);
  await p.locator('.cs').first().screenshot({ path: process.argv[2] + '/chronostasis-compare-black-top.png' });
  await p.click('#cs-walkstart'); await p.waitForTimeout(200);
  for (let i = 0; i < 40; i++) { if (/Template address/.test(await p.locator('#cs-walk').innerText())) break; if (!(await p.$('#cs-next'))) break; await p.click('#cs-next'); await p.waitForTimeout(80); }
  await p.locator('#cs-walk').screenshot({ path: process.argv[2] + '/chronostasis-compare-black-address-labels.png' });
  await p.goto('http://127.0.0.1:18811/'); await p.waitForSelector('.cs-app'); await p.click('#cs-walkstart'); await p.waitForTimeout(200);
  await p.check('#cs-pick'); await p.click('#cs-next'); await p.waitForTimeout(120); await p.check('#cs-pick'); await p.click('#cs-finish'); await p.waitForTimeout(600);
  await p.locator('#cs-walk').screenshot({ path: process.argv[2] + '/chronostasis-compare-black-finish-hub.png' });
  await p.click('#cs-go-report'); await p.waitForTimeout(500); await scan();
  await p.locator('#cs-walk').screenshot({ path: process.argv[2] + '/chronostasis-compare-black-finish-report.png' });
  console.log('report rows', await p.locator('#cs-ticked li').count(), 'in text', ((await p.inputValue('#cs-share')).match(/^- /gm) || []).length, await p.locator('#cs-walk .cs-muted').first().innerText());
  console.log('controls seen', seen.size, 'without tooltip', miss.size, [...miss].join(' | ')); await b.close();
})().catch(e => { console.log('ERR', String(e).slice(0, 300)); process.exit(1); });
