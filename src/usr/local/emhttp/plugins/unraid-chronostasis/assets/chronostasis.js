/* unraid-chronostasis page. All text goes into the DOM through textContent. */
(function () {
  'use strict';
  var root = document.getElementById('cs-root');
  if (!root || root.dataset.started) return;
  root.dataset.started = '1';
  var base = root.dataset.base, csrf = root.dataset.csrf || (typeof csrf_token !== 'undefined' ? csrf_token : '');
  var statusEl = document.getElementById('cs-status'), btn = document.getElementById('cs-check');
  var summary = document.getElementById('cs-summary'), list = document.getElementById('cs-apps');
  var filter = document.getElementById('cs-filter'), all = document.getElementById('cs-all');
  var last = null, open = {}, openOthers = {}, timer = null;
  var walk = document.getElementById('cs-walk'), walkBtn = document.getElementById('cs-walkstart'), otherBtn = document.getElementById('cs-walkother');
  var pos = -1, walkOthers = false, picked = {}, mappings = [], chosen = {}, previewRun = 0, walkList = [], answers = {}, rejected = {}, away = {}, showPair = {}, seenRenames = {}, doneHere = {}, gone = [], resumed = false, restored = false;
  var jobsEl = document.getElementById('cs-jobs'), jobs = {}, planApps = [], jobTimer = null, polling = false, endView = 'hub', endPos = 0;

  /* what pressing a control does, keyed by its label; the unit test checks that every button label is here */
  var APPLY_ONLY = ' Nothing changes until you press Apply in Unraid\'s form.';
  var TIPS = {
    'Previous difference': 'Back to the previous difference. Your answers are kept.',
    'Next difference': 'On to the next difference. You can skip one without answering.',
    'Finish': 'Goes to your two next steps: upgrade with your answers, or report what you added. Nothing changes here, and you can come back.',
    'Upgrade': 'Shows old settings against upgraded settings, one app at a time.',
    'Report': 'Shows the text to copy, one app at a time.',
    'Previous app': 'Back to the previous app.',
    'Next app': 'On to the next app.',
    'Back to the two choices': 'Back to Upgrade or Report. Nothing is lost.',
    'Dismiss': 'Hides this difference from now on. Bring back, in the app\'s Dismissed list, shows it again.',
    'Bring back': 'This difference is hidden. Shows it again and removes your answer.',
    'It\'s my own': 'Marks this as yours: it stays as it is and is not asked about again. Press again to undo.',
    'Report it': 'Adds a question about this key to the report at Finish. The value is never in it. Press again to undo.',
    'Include it': 'Adds this key to the upgraded settings.' + APPLY_ONLY,
    'Leave it out': 'Keeps this key out of the upgraded settings and stops asking. Press again to undo.',
    'Leave it as it is': 'Keeps this key as it is and stops asking. Press again to undo.',
    'Yes, same field': 'Treats them as one field: your value goes under the template\'s key in the upgraded settings.' + APPLY_ONLY,
    'No, different, not a rename': 'Treats them as two separate fields and asks about each one on its own.',
    'Compare with another field': 'Shows a list of fields to compare this one with.',
    'Add mapping': 'Adds this rename to the report at Finish.',
    'Copy': 'Copies the text. The plugin sends nothing; you post it where you choose.',
    'Back to the differences': 'Back to the last difference. Your answers are kept.',
    'Forget my answers': 'Clears your answers from this browser and closes this. What you dismissed stays dismissed. Nothing else changes.',
    'Open in Unraid\'s form': 'Opens this backup in Unraid\'s form. Nothing happens until you press Apply there.',
    'Review in Unraid\'s form': 'Saves the old settings first, then opens Unraid\'s form in a new tab with the upgraded settings.' + APPLY_ONLY,
    'I did not press Apply': 'Stops waiting for Apply and removes the temporary settings. The container is not touched.',
    'Try again with the upgraded settings': 'Opens Unraid\'s form with the upgraded settings, where you can tweak them.' + APPLY_ONLY,
    'Go back to the old settings': 'Opens Unraid\'s form with the old settings from the backup.' + APPLY_ONLY,
    'Open folder': 'Opens this folder in Unraid\'s file browser in a new tab.',
    'Download': 'Saves a copy of this backup on your computer.',
    'Open Unraid\'s form anyway': 'Opens the form as it is. Nothing changes until you press Apply there.',
    'Cancel': 'Closes this and opens nothing.'
  };
  var SELECT_TIPS = {
    'cs-other': 'The field to compare with. The comparison above follows your choice.',
    'cs-why': 'Why you think this is a rename. It goes in the report at Finish.',
    'cs-src': 'Where that can be checked. It goes in the report at Finish.'
  };
  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = String(text);
    if (TIPS[text]) e.title = TIPS[text];
    return e;
  }
  function safeUrl(u) { return /^https?:\/\//i.test(u || '') ? u : ''; }
  function link(u, text) {
    var s = safeUrl(u);
    if (!s) return el('span', '', text);
    var a = el('a', '', text); a.href = s; a.target = '_blank'; a.rel = 'noopener noreferrer';
    return a;
  }
  function post(data) {
    data.csrf_token = csrf;
    var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
    return fetch(base + '/include/action.php', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; }); });
  }

  var kinds = {
    new: 'New in the template', yours: 'Only in old settings', required: 'Required changed', default: 'Default differs',
    renamed: 'Variable with a new target', image: 'Image differs', address: 'Template address', moderation: 'Community Applications'
  };
  function kindName(f) { return f.kind === 'new' && f.warn ? 'Required, no default' : kinds[f.kind]; }
  var addressText = {
    dead: 'The template address in use no longer answers (404).',
    other: 'The template address in use is not the current one Community Applications lists.',
    missing: 'This template has no address.'
  };
  function findingText(f) {
    if (f.kind === 'address') return addressText[f.key] || f.key;
    if (f.kind === 'image') return 'Old settings: ' + f.saved + '. Template: ' + f.label;
    if (f.kind === 'moderation') return f.label + ': ' + f.text;
    if (f.kind === 'renamed') return 'Variable ' + f.from + ' in old settings, ' + f.label + ' in the template (' + f.name + ')';
    var t = f.type ? f.type + ' ' + f.label : f.label;
    if (f.kind === 'new' && f.required) t += ' (required)';
    if (f.kind === 'required') t += f.required ? ': now required' : ': no longer required';
    if ((f.kind === 'default' || f.kind === 'new' || f.defaultChanged) && f.default) t += ', template default ' + f.default;
    return t;
  }
  function countOf(a, k) { return a.findings.filter(function (f) { return f.check && f.kind === k; }).length; }
  function others(a) { return a.findings.filter(function (f) { return !f.check; }); }

  var NOT_A_FAULT = 'A difference is only a difference: old settings may be the newer ones, and many have no effect on the app.';
  var LOOK = 'Open the current template and the template in use below, and read the support thread, to find out why it changed.';
  var STAY = 'If you stay with what you have, the app keeps running as it is. Press Dismiss to stop seeing this.';
  var REINSTALL = 'To move to the current template, treat it as a reinstall: remove the container, install the app from the Apps tab and carry your settings over yourself. Removing a container does not remove its appdata folder.';
  var GUIDE = {
    other: ['The maintainer may have changed too much for this plugin to judge, so it does not upgrade this. A move can mean another author, image or way of storing data.', [LOOK, STAY, REINSTALL]],
    dead: ['The template address in use no longer answers. This plugin cannot tell why, so it has nothing to upgrade from.', ['Read the support thread, or look for the app in the Apps tab, to find out where it went.', STAY, REINSTALL]],
    missing: ['The template in use has no address, so this plugin cannot follow it. The app runs without one.', [STAY, 'A reinstall from the Apps tab gives it an address: remove the container, install the app and carry your settings over yourself. Removing a container does not remove its appdata folder.']],
    image: ['Old settings and the template use different images. It can be the same app at a new registry, or too big a change by the maintainer for this plugin to judge, so it does not change the image.', [LOOK, 'If it is the same app at a new registry, you can change Repository yourself: Docker tab, Edit, then Apply.', STAY, REINSTALL]]
  };
  function guide(key) {
    var g = GUIDE[key], d = el('div', 'cs-guide');
    if (!g) return d;
    d.appendChild(el('p', '', g[0])); d.appendChild(el('h4', '', 'What you can do'));
    var ul = el('ul'); g[1].forEach(function (t) { ul.appendChild(el('li', '', t)); }); d.appendChild(ul);
    return d;
  }
  var CHOICE_CHECK = 'Start here. These can need something from you: a required field with no default that old settings do not have, or a change of image, template address or moderation note.';
  var CHOICE_OTHER = 'Go through these when you want old settings to match the template: new optional fields, fields only you have, and changed defaults.';
  var AUTO_NOTE = 'CA Auto Update Applications updates this image on a schedule and recreates the container from old settings, which stay as they are. A setting renamed by the app could arrive with nobody watching.';

  function pinNote(tag) { return 'This app stays on ' + tag + '. The comparison is with the current template, which may describe a newer version: Chronostasis has no template for ' + tag + ' itself.'; }
  function row(a) {
    var box = el('div', 'cs-app');
    var head = el('button', 'cs-head'); head.type = 'button'; head.title = 'Shows or hides the differences of this app.';
    head.setAttribute('aria-expanded', open[a.name] ? 'true' : 'false');
    head.appendChild(el('span', 'cs-name', a.name));
    var tags = el('span', 'cs-tags');
    if (a.autoUpdated) tags.appendChild(el('span', 'cs-tag cs-strong', 'auto-updated'));
    var job = jobs[a.name];
    if (a.pinned) tags.appendChild(el('span', 'cs-tag', 'stays on ' + a.pinned));
    function tag(n, label, cls) { if (n) tags.appendChild(el('span', 'cs-tag ' + (cls || ''), n + ' ' + label)); }
    tag(countOf(a, 'new'), 'required, no default', 'cs-strong'); tag(countOf(a, 'renamed'), 'new target', 'cs-strong');
    tag(countOf(a, 'image'), 'image', 'cs-strong'); tag(countOf(a, 'address'), 'address');
    tag(countOf(a, 'moderation'), 'moderation', 'cs-strong');
    tag(others(a).length, 'other', 'cs-other');
    if (a.confidence === 'none') tags.appendChild(el('span', 'cs-tag', a.how === 'several templates' ? 'several templates' : 'no template found'));
    if (!a.findings.length && a.confidence !== 'none') tags.appendChild(el('span', 'cs-tag cs-same', 'same'));
    if (job && (job.phase === 'applied' || job.phase === 'restored')) tags.appendChild(el('span', 'cs-tag', 'backup kept'));
    head.appendChild(tags);
    head.appendChild(el('span', 'cs-match cs-conf-' + a.confidence, a.confidence === 'none' ? 'not matched' : 'matched by ' + a.how));
    box.appendChild(head);
    if (open[a.name]) box.appendChild(detail(a));
    head.addEventListener('click', function () { open[a.name] = !open[a.name]; draw(); });
    return box;
  }
  function detail(a) {
    var d = el('div', 'cs-detail');
    if (jobs[a.name]) rowJob(d, jobs[a.name]);
    var meta = el('p', 'cs-meta');
    meta.appendChild(el('span', '', a.repository));
    if (a.source) meta.appendChild(el('span', '', ' · template from ' + a.source));
    if (a.support) { meta.appendChild(el('span', '', ' · ')); meta.appendChild(link(a.support, 'Support')); }
    if (a.project) { meta.appendChild(el('span', '', ' · ')); meta.appendChild(link(a.project, 'Project')); }
    d.appendChild(meta);
    if (a.autoUpdated) d.appendChild(el('p', '', AUTO_NOTE));
    if (a.pinned) d.appendChild(el('p', '', pinNote(a.pinned)));
    if (a.confidence === 'none') {
      d.appendChild(el('p', '', a.how === 'several templates'
        ? a.candidates + ' templates use this image and nothing tells them apart, so this app is not compared.'
        : 'No template for this image was found in Community Applications.'));
    }
    if (a.confidence === 'low') d.appendChild(el('p', '', 'Matched by the image name on another registry: check that this is the same app.'));
    function section(parent, items) {
      Object.keys(kinds).forEach(function (k) {
        var fs = items.filter(function (f) { return f.kind === k; });
        if (!fs.length) return;
        parent.appendChild(el('h4', '', kindName(fs[0]) + ' (' + fs.length + ')'));
        var ul = el('ul');
        fs.forEach(function (f) {
          var li = el('li', '', findingText(f));
          if (f.kind === 'address' && f.feed) { li.appendChild(el('span', '', ' ')); li.appendChild(link(f.feed, 'Current address')); }
          var b = el('button', 'cs-mini', 'Dismiss'); b.type = 'button';
          b.addEventListener('click', function () { post({ action: 'dismiss', id: f.id, fp: f.fp }).then(load); });
          li.appendChild(b); ul.appendChild(li);
        });
        parent.appendChild(ul);
      });
    }
    section(d, a.findings.filter(function (f) { return f.check; }));
    var rest = others(a);
    if (rest.length) {
      var more = el('details', 'cs-others');
      more.open = !!openOthers[a.name];
      more.appendChild(el('summary', '', 'Other differences (' + rest.length + ')'));
      section(more, rest);
      more.addEventListener('toggle', function () { openOthers[a.name] = more.open; });
      d.appendChild(more);
    }
    (a.moved || []).forEach(function (m) {
      d.appendChild(el('p', 'cs-muted', 'Template now maps ' + (m.name || m.type) + ' to ' + m.template + ' (yours: ' + m.saved + '); your setting is kept.'));
    });
    if ((a.disabledFields || []).length) {
      d.appendChild(el('p', 'cs-muted', 'Turned off in old settings, so not compared: ' + a.disabledFields.join(', ')));
    }
    if (a.notApplicable.length) {
      d.appendChild(el('h4', '', 'Ports that do not apply'));
      d.appendChild(el('p', '', 'This app shares the network of another container, so Unraid ignores its port entries: ' + a.notApplicable.join(', ') + '. Ports are published on the container that owns the network.'));
    }
    if (a.dismissed && a.dismissed.length) {
      d.appendChild(el('h4', '', 'Dismissed (' + a.dismissed.length + ')'));
      var ul = el('ul');
      a.dismissed.forEach(function (f) {
        var li = el('li', 'cs-muted', findingText(f));
        var b = el('button', 'cs-mini', 'Bring back'); b.type = 'button';
        b.addEventListener('click', function () { post({ action: 'restore', id: f.id, fp: f.fp }).then(load); });
        li.appendChild(b); ul.appendChild(li);
      });
      d.appendChild(ul);
    }
    return d;
  }
  function draw() {
    summary.textContent = ''; list.textContent = '';
    var r = last && last.report;
    filter.hidden = !r;
    if (!r) { summary.appendChild(el('p', 'cs-muted', 'No check has run yet. Press Check now.')); return; }
    var c = r.counts;
    summary.appendChild(el('p', 'cs-headline', c.issues
      ? c.issues + ' to check on ' + c.issueApps + ' of ' + c.apps + ' apps.'
      : c.differ ? 'Nothing to check on your ' + c.apps + ' apps.' : 'None of your ' + c.apps + ' apps differ from their template.'));
    if (c.differ) summary.appendChild(el('p', 'cs-muted', NOT_A_FAULT));
    var extra = [];
    if (c.autoDiffer) extra.push(c.autoDiffer + ' update automatically: listed first');
    if (c.unmatched) extra.push(c.unmatched + ' could not be matched to a template');
    if (c.dismissed) extra.push(c.dismissed + ' dismissed');
    if (!r.docker) extra.push('Docker is not running, so every saved template is listed');
    if (extra.length) summary.appendChild(el('p', 'cs-muted', extra.join(' · ')));
    if (r.noContainer) {
      var nc = el('p', 'cs-muted', r.noContainer + ' saved template(s) have no container and are not listed. ');
      nc.appendChild(link('https://github.com/alexphillips-dev/Appdata-Cleanup-Plus', 'Appdata Cleanup Plus'));
      nc.appendChild(document.createTextNode(' looks after those.'));
      summary.appendChild(nc);
    }
    r.apps.filter(function (a) { return all.checked || a.findings.length || a.confidence === 'none'; }).forEach(function (a) { list.appendChild(row(a)); });
  }
  /* Work through the issues one at a time; the last step is the text to copy. */
  function issues() {
    var out = [];
    ((last && last.report && last.report.apps) || []).forEach(function (a) {
      a.findings.forEach(function (f) { if (!f.check === walkOthers) out.push({ a: a, f: f }); });
    });
    return out;
  }
  var reasons = ['field-rename', 'field-removed', 'field-split', 'rename', 'registry-move', 'template-repo-move', 'author-handover', 'fork-remake', 'deprecation-replacement'];
  function select(id, items, label) {
    var s = el('select'); s.id = id; s.setAttribute('aria-label', label); if (SELECT_TIPS[id]) s.title = SELECT_TIPS[id];
    items.forEach(function (i) { var o = el('option', '', i); o.value = i; s.appendChild(o); });
    return s;
  }
  function links(a) {
    var p = el('p', 'cs-links');
    [[a.support, 'Support'], [a.project, 'Project'], [a.feedUrl, 'Current template'], [a.history, 'Template history'], [a.savedUrl, 'Template in use']].forEach(function (l) {
      if (safeUrl(l[0])) { p.appendChild(link(l[0], l[1])); p.appendChild(el('span', '', ' ')); }
    });
    return p;
  }
  /* Each field issue is a comparison, your copy above and the template below, and one question. The value is asked for
     when the issue is drawn and stays in that box only: answers, mappings and the text to copy hold key names. */
  var fieldKinds = { new: 1, yours: 1, renamed: 1, default: 1, required: 1 };
  var NOT_PAIRED = 'not paired';
  var PREVIEW_NOTE = 'Nothing changes here. You make the change in Unraid\'s form.';
  var QUESTIONS = {
    yours: 'Here is a key and value the template does not include. Is it your own, or do you want to report it and ask what it means?',
    new: 'Here is a new key and default value from the template that old settings do not have. Do you want to include it?',
    newEmpty: 'Here is a new key from the template that old settings do not have. It has no default, so you fill in the value. Do you want to include it?',
    match: 'Here is a key missing from old settings, and a key in old settings that looks like it. This is a guess. Are they a match?',
    moved: 'Here is a field with the same display name that points somewhere new in the container. It looks moved. Is it the same field?',
    past: 'Here is a key and value that this template used to have and no longer does. Do you want to report it and ask what it means?'
  };
  var ANSWERS = {
    own: { text: 'It\'s my own', dismiss: true }, report: { text: 'Report it' },
    include: { text: 'Include it' }, leave: { text: 'Leave it out', dismiss: true }, keep: { text: 'Leave it as it is', dismiss: true },
    same: { text: 'Yes, same field' }, dismissed: { text: 'Dismissed', dismiss: true }
  };
  function walkItems() { return walkList.filter(function (it) { return !away[it.f.id]; }); }
  function rejectKey(x, y) { return x.id < y.id ? x.id + '|' + y.id : y.id + '|' + x.id; }
  function cmpHead(type) {
    var t = String(type || '').toLowerCase();
    return t === 'path' ? ['Path in the container', 'Host path'] : t === 'port' ? ['Port in the container', 'Host port'] : t === 'device' ? ['Device', 'Host device'] : ['Key', 'Value'];
  }
  /* text with the part that differs from other marked: what is left after the common start and end */
  function marked(cls, text, other) {
    var c = el('span', cls);
    if (other === null || text === other) { c.textContent = text; return c; }
    var n = Math.min(text.length, other.length), a = 0, b = 0;
    while (a < n && text[a] === other[a]) a++;
    while (b < n - a && text[text.length - 1 - b] === other[other.length - 1 - b]) b++;
    c.appendChild(document.createTextNode(text.slice(0, a)));
    c.appendChild(el('span', 'cs-diff', text.slice(a, text.length - b)));
    c.appendChild(document.createTextNode(text.slice(text.length - b)));
    return c;
  }
  /* rows: [side, key, value, value is a note rather than a value]. Two rows: your copy above, the template below. */
  function cmp(box, head, rows) {
    var g = el('div', 'cs-cmp');
    g.appendChild(el('span', 'cs-cmp-head cs-cmp-corner', ''));
    head.forEach(function (h) { g.appendChild(el('span', 'cs-cmp-head', h)); });
    rows.forEach(function (r, i) {
      var o = rows[1 - i], real = !r[3] && !o[3];
      g.appendChild(el('span', 'cs-pv-side', r[0]));
      g.appendChild(marked('cs-pv-key', r[1], r[1] && o[1] ? o[1] : null));
      g.appendChild(marked('cs-pv-value' + (r[3] ? ' cs-pv-note' : ''), r[2], real ? o[2] : null));
    });
    box.appendChild(g);
  }
  /* p: the field compared with f, or null */
  function preview(box, a, f, p, question, evidence) {
    var run = ++previewRun;
    var saved = f.kind === 'renamed' ? { type: f.type, label: f.from, key: f.fromKey } : f.kind === 'new' ? p : f;
    var tpl = f.kind === 'yours' ? p : f;
    var hidden = !!(f.mask || (p && p.mask));
    function paint(text) {
      box.textContent = '';
      if (question) box.appendChild(el('p', 'cs-question', question));
      var was = '', now = '';
      if (f.kind === 'required') { was = f.required ? ' (not required)' : ' (required)'; now = f.required ? ' (required)' : ' (not required)'; }
      var one = saved || tpl, top, below;
      var note = text === 'hidden' || text === '...' || text === 'could not be read';
      if (saved) top = ['Old settings', saved.label, text + was, note]; else top = ['Old settings', '', 'not in old settings', true];
      if (!tpl) below = ['Template', '', 'not in the template', true];
      else if (saved) below = ['Template', tpl.label, text + now, note];
      else below = ['Template', tpl.label, f.mask ? 'hidden' : f.default || (f.required ? 'you fill this in' : 'empty'), f.mask || !f.default];
      cmp(box, cmpHead(one.type), [top, below]);
      if (evidence) box.appendChild(el('p', 'cs-evidence', evidence));
      if (f.kind === 'default' || f.defaultChanged) box.appendChild(el('p', 'cs-muted', 'The template default is now ' + (f.default || 'empty') + '. The value in old settings stays.'));
    }
    if (!saved) return paint();
    if (hidden) return paint('hidden');
    paint('...');
    post({ action: 'value', app: a.name, key: saved.key }).then(function (r) {
      if (run !== previewRun) return;
      paint(!r.ok ? 'could not be read' : r.state === 'hidden' ? 'hidden' : r.state === 'absent' ? 'no longer in old settings' : (r.value === '' ? 'empty' : r.value));
    });
  }
  function otherSide(a, f) {
    return (f.cands || []).map(function (l) {
      return a.findings.filter(function (x) { return x.label === l && x.kind === (f.kind === 'new' ? 'yours' : 'new'); })[0];
    }).filter(Boolean);
  }
  function setAnswer(f, it, type) {
    var cur = answers[f.id], next = cur && cur.type === type ? null : type;
    var was = cur && ANSWERS[cur.type].dismiss, now = next && ANSWERS[next].dismiss;
    if (cur && cur.type === 'same') unmatch(f);
    if (next) answers[f.id] = { type: next, app: it.a.name, label: f.kind === 'address' || f.kind === 'moderation' ? kindName(f) : f.label }; else delete answers[f.id];
    if (was && !now) post({ action: 'restore', id: f.id, fp: f.fp }).then(load);
    if (now && !was) post({ action: 'dismiss', id: f.id, fp: f.fp }).then(load);
    walkDraw();
  }
  function unmatch(f) {
    var cur = answers[f.id];
    delete away[cur.other];
    mappings = mappings.filter(function (m) { return m.forId !== f.id; });
    delete answers[f.id];
  }
  function match(it, p, why, src) {
    var f = it.f, n = f.kind === 'new' ? f : p, y = f.kind === 'new' ? p : f;
    if (answers[f.id] && answers[f.id].type === 'same') { unmatch(f); return walkDraw(); }
    if (answers[f.id]) { setAnswerQuiet(f); }
    if (answers[p.id]) { setAnswerQuiet(p); }
    answers[f.id] = { type: 'same', app: it.a.name, label: y.label + ' is ' + n.label, other: p.id };
    away[p.id] = true;
    mappings.push({ forId: f.id, type: 'field', app: it.a.name, from: y.label, to: n.label, reason: why, source: src, date: new Date().toISOString().slice(0, 10) });
    pos = Math.max(0, walkItems().map(function (x) { return x.f.id; }).indexOf(f.id));
    walkDraw();
  }
  function setAnswerQuiet(x) {
    var c = answers[x.id];
    if (c && ANSWERS[c.type].dismiss) post({ action: 'restore', id: x.id, fp: x.fp }).then(load);
    delete answers[x.id];
  }
  function different(it, p) {
    var f = it.f;
    if (answers[f.id] && answers[f.id].type === 'same') unmatch(f);
    rejected[rejectKey(f, p)] = { app: it.a.name, label: (f.kind === 'new' ? p : f).label + ' and ' + (f.kind === 'new' ? f : p).label };
    delete chosen[f.id]; delete chosen[p.id];
    walkDraw();
  }
  function walkDraw() {
    previewRun++;
    walk.textContent = '';
    saveWalk();
    var list2 = walkItems();
    if (pos < 0) { walk.hidden = true; return; }
    walk.hidden = false;
    if (pos >= list2.length) return walkEnd(list2);
    var it = list2[pos], a = it.a, f = it.f, ans = answers[f.id];
    if (f.kind === 'renamed') { seenRenames[f.id] = a.name + ': ' + f.from + ' to ' + f.label + ': rename the template confirms'; saveWalk(); }
    if (resumed) walk.appendChild(el('p', 'cs-muted', 'Picked up where you left off. Your answers are kept in this browser, and they hold names only, no values.'));
    walk.appendChild(el('p', 'cs-muted', (walkOthers ? 'Other difference ' : 'Issue ') + (pos + 1) + ' of ' + list2.length));
    /* a field issue says it in the comparison and the question; the rest keep their text */
    var asks = f.kind === 'new' || f.kind === 'yours';
    walk.appendChild(el('h3', '', a.name + ': ' + (fieldKinds[f.kind] ? (f.type || 'Field') + ' ' + (f.kind === 'renamed' ? f.from + ' to ' + f.label : f.label) : kindName(f))));
    if (!fieldKinds[f.kind]) walk.appendChild(el('p', 'cs-issue', findingText(f)));
    if (!asks) walk.appendChild(el('p', '', f.why));
    if (a.autoUpdated) walk.appendChild(el('p', '', AUTO_NOTE));
    if (a.pinned) walk.appendChild(el('p', '', pinNote(a.pinned)));

    /* the comparison */
    var box = el('div', 'cs-box'), pv = el('div', ''); pv.id = 'cs-preview';
    var answerRow = el('div', 'cs-answers'), pairFinding = null, below = [];
    box.appendChild(pv); box.appendChild(answerRow);
    function answerBtn(id, text, on, fn) {
      var x = el('button', 'cs-answer' + (on ? ' cs-on' : ''), text); x.type = 'button'; x.id = id;
      x.setAttribute('aria-pressed', on ? 'true' : 'false');
      x.addEventListener('click', fn); answerRow.appendChild(x);
    }
    var srcs = [a.feedUrl, a.history, a.support, a.project].filter(safeUrl);
    var why = select('cs-why', reasons, 'Reason'), src = select('cs-src', srcs, 'Source');
    if (f.kind === 'new' || f.kind === 'yours') {
      var cands = otherSide(a, f);
      var want = ans && ans.type === 'same' ? (cands.filter(function (x) { return x.id === ans.other; })[0] || {}).label : chosen[f.id];
      if (want === undefined) {
        var g = cands.filter(function (x) { return x.label === f.guess; })[0];
        want = g && !rejected[rejectKey(f, g)] ? g.label : NOT_PAIRED;
      }
      pairFinding = cands.filter(function (x) { return x.label === want; })[0] || null;
      walk.appendChild(box);
      if (cands.length && !pairFinding && !showPair[f.id]) {
        var mp = el('p', 'cs-map'), more = el('button', 'cs-mini', 'Compare with another field'); more.type = 'button'; more.id = 'cs-pairshow';
        more.addEventListener('click', function () { showPair[f.id] = true; walkDraw(); });
        mp.appendChild(more); below.push(mp);
      } else if (cands.length) {
        var m = el('p', 'cs-map');
        m.appendChild(el('span', '', f.kind === 'new' ? 'Rename of: ' : 'Renamed to: '));
        var other = select('cs-other', [NOT_PAIRED].concat(cands.map(function (x) { return x.label; })), 'Compared field');
        other.value = pairFinding ? pairFinding.label : NOT_PAIRED;
        other.addEventListener('change', function () {
          if (ans && ans.type === 'same') unmatch(f);
          chosen[f.id] = other.value;
          walkDraw();
          var again = document.getElementById('cs-other'); if (again) again.focus();
        });
        m.appendChild(other); m.appendChild(el('span', '', ' '));
        if (pairFinding) [why, src].forEach(function (n) { m.appendChild(n); m.appendChild(el('span', '', ' ')); });
        below.push(m);
      }
      var evidence = pairFinding ? (pairFinding.label === f.guess ? f.reason : '') : f.past;
      var ask = pairFinding ? (pairFinding.label === f.moved ? 'moved' : 'match') : f.past ? 'past' : f.kind === 'new' && !f.default && !f.mask ? 'newEmpty' : f.kind;
      preview(pv, a, f, pairFinding, QUESTIONS[ask], evidence);
      if (pairFinding) {
        var same = ans && ans.type === 'same';
        answerBtn('cs-yes', ANSWERS.same.text, same, function () { match(it, pairFinding, why.value, src.value); });
        answerBtn('cs-no', 'No, different, not a rename', false, function () { different(it, pairFinding); });
      } else if (f.past) {
        answerBtn('cs-report', ANSWERS.report.text, ans && ans.type === 'report', function () { setAnswer(f, it, 'report'); });
        answerBtn('cs-keep', ANSWERS.keep.text, ans && ans.type === 'keep', function () { setAnswer(f, it, 'keep'); });
      } else if (f.kind === 'yours') {
        answerBtn('cs-own', ANSWERS.own.text, ans && ans.type === 'own', function () { setAnswer(f, it, 'own'); });
        answerBtn('cs-report', ANSWERS.report.text, ans && ans.type === 'report', function () { setAnswer(f, it, 'report'); });
      } else {
        answerBtn('cs-include', ANSWERS.include.text, ans && ans.type === 'include', function () { setAnswer(f, it, 'include'); });
        answerBtn('cs-leave', ANSWERS.leave.text, ans && ans.type === 'leave', function () { setAnswer(f, it, 'leave'); });
      }
    } else if (fieldKinds[f.kind]) {
      walk.appendChild(box);
      preview(pv, a, f, null);
      if (f.kind === 'renamed') {
        var mr = el('p', 'cs-map');
        var add = el('button', 'cs-mini', 'Add mapping'); add.type = 'button';
        add.addEventListener('click', function () {
          mappings.push({ type: 'field', app: a.name, from: f.from, to: f.label, reason: why.value, source: src.value, date: new Date().toISOString().slice(0, 10) });
          add.textContent = 'Added'; add.disabled = true;
        });
        [why, src, add].forEach(function (n) { mr.appendChild(n); mr.appendChild(el('span', '', ' ')); });
        below.push(mr);
      }
    } else if (f.kind === 'image') {
      walk.appendChild(box); cmp(pv, ['', 'Value'], [['Old settings', 'Image', f.saved], ['Template', 'Image', f.label]]); below.push(guide('image'));
    } else if (f.kind === 'address') {
      walk.appendChild(box); cmp(pv, ['', 'Address'], [['In use', 'Template address', f.saved || 'none', !f.saved], ['Current', 'Template address', f.feed || 'not listed', !f.feed]]); below.push(guide(f.key));
    }
    if (ans) answerRow.appendChild(el('span', 'cs-your', 'Your answer: ' + ANSWERS[ans.type].text));
    if (!answerRow.childNodes.length) box.removeChild(answerRow);
    if (fieldKinds[f.kind]) box.appendChild(el('p', 'cs-muted', PREVIEW_NOTE));
    below.forEach(function (n) { walk.appendChild(n); });
    walk.appendChild(links(a));

    if (f.kind !== 'yours') {
      var share = el('label', 'cs-sharepick'), tick = el('input'); tick.type = 'checkbox'; tick.id = 'cs-pick'; tick.checked = !!picked[f.id];
      share.title = 'A tick puts this difference in the report you can copy at Finish. It changes nothing in the container.';
      tick.addEventListener('change', function () { picked[f.id] = tick.checked ? it : false; saveWalk(); });
      share.appendChild(tick); share.appendChild(document.createTextNode(' Add to the report'));
      walk.appendChild(share);
    }
    var bar = el('p', 'cs-walkbar');
    function b(t, fn, dis, id) { var x = el('button', '', t); x.type = 'button'; x.disabled = !!dis; if (id) x.id = id; x.addEventListener('click', fn); bar.appendChild(x); return x; }
    b('Previous difference', function () { pos--; walkDraw(); }, pos === 0, 'cs-prev');
    if (f.kind !== 'new' && f.kind !== 'yours') b(ans ? 'Bring back' : 'Dismiss', function () { setAnswer(f, it, 'dismissed'); });
    if (pos < list2.length - 1) b('Next difference', function () { pos++; walkDraw(); }, false, 'cs-next');
    b('Finish', function () { pos = list2.length; endView = 'hub'; endPos = 0; walkDraw(); }, false, 'cs-finish');
    walk.appendChild(bar);
  }
  /* Finish: two ways on, each one app at a time. Upgrades come from answers, reports only from what was ticked or reported. */
  function endBar(items) {
    var bar = el('p', 'cs-walkbar');
    items.forEach(function (i) {
      if (!i) return;
      var x = el('button', '', i[0]); x.type = 'button'; if (i[2]) x.id = i[2]; x.disabled = !!i[3];
      x.addEventListener('click', i[1]); bar.appendChild(x);
    });
    return bar;
  }
  function toHub() { endView = 'hub'; endPos = 0; walkDraw(); }
  function stepBar(n, list2) {
    return endBar([['Previous app', function () { endPos--; walkDraw(); }, 'cs-end-prev', endPos === 0],
      endPos < n - 1 ? ['Next app', function () { endPos++; walkDraw(); }, 'cs-end-next'] : null,
      ['Back to the two choices', toHub, 'cs-end-hub']]);
  }
  function reportApps() {
    var by = {}, order = [];
    function app(n) { if (!by[n]) { by[n] = { name: n, ids: [], asks: [], maps: [], rows: [] }; order.push(by[n]); } return by[n]; }
    Object.keys(picked).forEach(function (k) {
      if (!picked[k]) return;
      var g = app(picked[k].a.name); g.ids.push(k); g.rows.push(kindName(picked[k].f) + ': ' + findingText(picked[k].f));
    });
    Object.keys(answers).forEach(function (k) {
      if (answers[k].type !== 'report') return;
      var g = app(answers[k].app); g.asks.push(k); g.rows.push('Question: ' + answers[k].label);
    });
    mappings.forEach(function (m) { var g = app(m.app); g.maps.push(m); g.rows.push('Rename: ' + m.from + ' to ' + m.to); });
    return order;
  }
  function walkEnd(list2) {
    if (endView === 'upgrade') {
      var f0 = el('div'); f0.id = 'cs-finish-apps'; walk.appendChild(f0); drawFinish(list2);
      return;
    }
    if (endView === 'report') return drawReport(list2);
    walk.appendChild(el('h3', '', 'Finish: two things you can do next'));
    walk.appendChild(el('p', '', 'Nothing has changed so far. You can do either one, both or neither.'));
    var rows = Object.keys(answers).map(function (id) { return answers[id].app + ': ' + answers[id].label + ': ' + ANSWERS[answers[id].type].text; })
      .concat(Object.keys(rejected).map(function (k) { return rejected[k].app + ': ' + rejected[k].label + ': No, different, not a rename'; }))
      .concat(Object.keys(seenRenames).filter(function (id) { return !answers[id]; }).map(function (id) { return seenRenames[id]; }));
    var d = el('details'), sum = el('summary', '', 'Your answers and confirmed renames (' + rows.length + ')'); d.appendChild(sum);
    if (rows.length) {
      var ul = el('ul'); ul.id = 'cs-answerlist';
      rows.forEach(function (r) { ul.appendChild(el('li', '', r)); });
      d.appendChild(ul);
    } else d.appendChild(el('p', 'cs-muted', 'No answers yet.'));
    walk.appendChild(d);
    var box = el('div', 'cs-choices'), reps = reportApps();
    function choice(id, label, count, text) {
      var row = el('div', 'cs-choice'), x = el('button', '', label); x.type = 'button'; x.id = id;
      row.appendChild(x); row.appendChild(el('span', '', text)); box.appendChild(row);
      return x;
    }
    var up = choice('cs-go-upgrade', 'Upgrade', 0, 'Uses your answers and the renames the template confirms. Shows old settings against upgraded settings for one app at a time, then opens Unraid\'s form.');
    var rep = choice('cs-go-report', 'Report', 0, 'Uses what you added to the report, your Report it answers and the renames you confirmed. Shows the text to copy for one app at a time, to post for example in its support thread. The plugin sends nothing.');
    up.addEventListener('click', function () { endView = 'upgrade'; endPos = 0; walkDraw(); });
    rep.addEventListener('click', function () { endView = 'report'; endPos = 0; walkDraw(); });
    rep.textContent = 'Report ' + reps.length + (reps.length === 1 ? ' app' : ' apps'); rep.disabled = !reps.length;
    walk.appendChild(box);
    walk.appendChild(endBar([['Back to the differences', function () { pos = Math.max(0, list2.length - 1); walkDraw(); }], ['Forget my answers', forgetWalk]]));
    post({ action: 'plan', answers: answerList() }).then(function (r) {
      planApps = r.ok ? r.apps : [];
      var n = upgrades().length;
      if (!r.ok) walk.appendChild(el('p', 'cs-muted', 'Could not work out the upgraded settings: ' + (r.error || '')));
      if (endView === 'upgrade') return drawFinish(list2);
      up.textContent = 'Upgrade ' + n + (n === 1 ? ' app' : ' apps'); up.disabled = !n;
    });
  }
  function drawReport(list2) {
    var reps = reportApps(); endPos = Math.min(endPos, Math.max(0, reps.length - 1));
    if (!reps.length) return toHub();
    var g = reps[endPos], report = last && last.report, app = report ? report.apps.filter(function (x) { return x.name === g.name; })[0] : null;
    walk.appendChild(el('p', 'cs-muted', 'Report: app ' + (endPos + 1) + ' of ' + reps.length));
    walk.appendChild(el('h3', '', g.name));
    var ul = el('ul'); ul.id = 'cs-ticked'; g.rows.forEach(function (r) { ul.appendChild(el('li', '', r)); }); walk.appendChild(ul);
    var hint = el('p', '', 'Copy this and post it wherever you choose');
    if (app && safeUrl(app.support)) { hint.appendChild(document.createTextNode(', for example the ')); hint.appendChild(link(app.support, 'support thread')); }
    hint.appendChild(document.createTextNode('. The plugin sends nothing. It holds field names, app, image, template address and versions only.'));
    walk.appendChild(hint);
    var t = el('textarea', 'cs-share'); t.readOnly = true; t.rows = 12; t.id = 'cs-share'; t.setAttribute('aria-label', 'Findings to copy');
    walk.appendChild(t);
    var c = el('button', '', 'Copy'); c.type = 'button';
    c.addEventListener('click', function () {
      t.select();
      var done = function () { c.textContent = 'Copied'; };
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(t.value).then(done); else { document.execCommand('copy'); done(); }
    });
    var cp = el('p', 'cs-walkbar'); cp.appendChild(c); walk.appendChild(cp);
    walk.appendChild(stepBar(reps.length, list2));
    post({ action: 'share', ids: g.ids.join(',') || 'none', asks: g.asks.join(','), mappings: JSON.stringify(g.maps) })
      .then(function (r) { t.value = r.ok ? r.text : ('Could not build the text: ' + (r.error || '')); });
  }
  function answerList() {
    var out = Object.keys(answers).map(function (id) { return { id: id, type: answers[id].type, other: answers[id].other || '' }; });
    Object.keys(seenRenames).forEach(function (id) { if (!answers[id]) out.push({ id: id, type: 'renamed', other: '' }); });
    return JSON.stringify(out);
  }
  function when(ts) {
    var d = new Date(ts * 1000), p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  function pathCode(p) {
    var c = el('code', 'cs-path');
    p.split('/').forEach(function (s, i, l) { c.appendChild(document.createTextNode(s + (i < l.length - 1 ? '/' : ''))); if (i < l.length - 1) c.appendChild(document.createElement('wbr')); });
    return c;
  }
  function pathLinks(file) {
    var i = file.lastIndexOf('/'), box = el('div', 'cs-pathlinks');
    var o = el('a', '', 'Open folder'); o.href = '/Main/Browse?dir=' + encodeURIComponent(file.slice(0, i)); o.target = '_blank'; o.rel = 'noopener';
    var d = el('a', '', 'Download'); d.href = encodeURI(file); d.setAttribute('download', file.slice(i + 1));
    box.appendChild(o); box.appendChild(d);
    return box;
  }
  function backupInto(parent, job) { parent.appendChild(pathCode(job.backup)); parent.appendChild(pathLinks(job.backup)); }
  /* What Apply in this Unraid's form would drop. A second click opens the form, so the new tab still counts as a click. */
  function dropDialog(list, go) {
    var d = el('dialog', 'cs-dialog');
    d.appendChild(el('h3', '', 'Apply would remove part of these settings'));
    var ul = el('ul', 'cs-warns'); list.forEach(function (t) { ul.appendChild(el('li', '', t)); }); d.appendChild(ul);
    var bar = el('div', 'cs-answers');
    var ok = el('button', 'cs-answer', 'Open Unraid\'s form anyway'); ok.type = 'button';
    var no = el('button', 'cs-answer', 'Cancel'); no.type = 'button';
    ok.addEventListener('click', function () { d.close(); go(); });
    no.addEventListener('click', function () { d.close(); });
    d.addEventListener('close', function () { d.remove(); });
    bar.appendChild(ok); bar.appendChild(no); d.appendChild(bar);
    var root = document.querySelector('.cs') || document.body;
    /* 6.12 has no theme variables: take the page's own colours, so the dialog reads in every theme */
    var bg = [document.body, document.documentElement].map(function (x) { return getComputedStyle(x).backgroundColor; }).filter(function (c) { return c && c !== 'transparent' && c !== 'rgba(0, 0, 0, 0)'; })[0];
    if (bg) d.style.backgroundColor = bg;
    d.style.color = getComputedStyle(root).color;
    root.appendChild(d); d.showModal();
  }
  /* Open a tab now, while the click counts, then send it to the address: a tab opened after an answer is blocked. */
  function toForm(data, onError) {
    var w = window.open('', '_blank');
    return post(data).then(function (r) {
      if (r.warn && r.warn.length) { if (w) w.close(); dropDialog(r.warn, function () { toForm(Object.assign({}, data, { confirmed: '1' }), onError); }); return; }
      if (!r.ok || !r.url) { if (w) w.close(); onError(r.error || 'Could not open Unraid\'s form.'); return; }
      if (w) w.location.href = r.url; else window.location.href = r.url;
      pollJobs();
    });
  }
  var APPLY_FAILS = 'If Apply fails, Unraid will already have removed the container. That is how Unraid has implemented Apply.';
  var COME_BACK = 'Come back here. The error is shown here if it can be found, so note it from the Apply window too. You can try again and tweak the upgraded settings according to what it says, or go back to the old settings. Both open Unraid\'s form.';
  var FOLDERS = 'Your app\'s folders on the server are not touched.';
  var STAYS = 'Old settings stay on this server. They are never in reports or in anything you copy or paste.';
  var BOTH_OPEN = 'Both open Unraid\'s form, where you can check or change them. Nothing happens until you press Apply.';
  var NO_ERROR = 'Unraid didn\'t keep the error. It was shown in the Apply window.';
  function upgrades() { return planApps.filter(function (p) { return p.rows.length || p.blocks.length; }); }
  function drawFinish(list2) {
    var fin = document.getElementById('cs-finish-apps');
    if (!fin) return;
    fin.textContent = '';
    var ups = upgrades();
    if (!ups.length) { fin.appendChild(el('p', 'cs-muted', 'Working out the upgraded settings...')); fin.appendChild(endBar([['Back to the two choices', toHub, 'cs-end-hub']])); return; }
    endPos = Math.min(endPos, ups.length - 1);
    fin.appendChild(el('p', 'cs-muted', 'Upgrade: app ' + (endPos + 1) + ' of ' + ups.length));
    [ups[endPos]].forEach(function (p) {
      var job = jobs[p.name];
      if (job && job.phase !== 'form' && (doneHere[p.name] || (job.phase !== 'applied' && job.phase !== 'restored'))) { fin.appendChild(el('p', '', p.name + ': see the result at the top of this page.')); return; }
      var box = el('div', 'cs-box'); fin.appendChild(box);
      box.appendChild(el('p', 'cs-question', p.name));
      if (p.pinned) box.appendChild(el('p', '', pinNote(p.pinned)));
      p.rows.forEach(function (r) {
        box.appendChild(el('h4', '', r.head));
        cmp(box, cmpHead(r.type), [['Old settings', r.old[0], r.old[1], r.old[2]], ['Upgraded settings', r.new[0], r.new[1], r.new[2]]]);
      });
      box.appendChild(el('p', '', 'Unraid recreates this container when you press Apply: it is stopped, removed and started again with the upgraded settings.'));
      var note = [];
      if (p.own.length) note.push('Your own field' + (p.own.length > 1 ? 's ' : ' ') + p.own.join(', ') + (p.own.length > 1 ? ' stay.' : ' stays.'));
      if (p.left.length) note.push('Left out: ' + p.left.join(', ') + '.');
      note.push('Nothing has changed so far.');
      box.appendChild(el('p', 'cs-muted', note.join(' ')));
      if (p.warnings.length) { var wl = el('ul', 'cs-warns'); p.warnings.forEach(function (w) { wl.appendChild(el('li', '', w)); }); box.appendChild(wl); }
      if (p.blocks.length) { p.blocks.forEach(function (w) { box.appendChild(el('p', 'cs-issue', w)); }); return; }
      var safe = el('div', 'cs-safety'); safe.appendChild(el('h4', '', 'Before you press Apply'));
      var ul = el('ul'), li = el('li');
      if (job) { li.appendChild(document.createTextNode('Old settings are saved here, taken ' + when(job.taken) + ':')); backupInto(li, job); }
      else { li.appendChild(document.createTextNode('Old settings are saved in this folder first, when you press the button below:')); li.appendChild(pathCode(p.backupDir)); }
      ul.appendChild(li);
      [APPLY_FAILS, COME_BACK, FOLDERS, STAYS].forEach(function (t) { ul.appendChild(el('li', '', t)); });
      safe.appendChild(ul); box.appendChild(safe);
      var ar = el('div', 'cs-answers'), go = el('button', 'cs-answer', 'Review in Unraid\'s form'); go.type = 'button'; go.className += ' cs-review';
      var err = el('p', 'cs-issue');
      go.addEventListener('click', function () {
        err.textContent = '';
        toForm({ action: 'open', app: p.name, answers: answerList() }, function (t) { err.textContent = t; });
      });
      ar.appendChild(go); box.appendChild(ar); box.appendChild(err);
      if (job) {
        box.appendChild(el('p', 'cs-muted', 'Waiting for Apply in the other tab. This page follows what happens.'));
        var cn = el('button', 'cs-mini', 'I did not press Apply'); cn.type = 'button';
        cn.addEventListener('click', function () { post({ action: 'cancel', app: p.name }).then(pollJobs); });
        box.appendChild(cn);
      }
    });
    fin.appendChild(stepBar(ups.length, list2));
  }
  function jobButtons(parent, job, err) {
    var two = el('div', 'cs-two');
    [['upgraded', 'Try again with the upgraded settings'], ['old', 'Go back to the old settings']].filter(function (b) { return !job.putback || b[0] === 'old'; }).forEach(function (b) {
      var x = el('button', 'cs-answer', b[1]); x.type = 'button'; x.id = 'cs-job-' + b[0];
      x.addEventListener('click', function () { err.textContent = ''; toForm({ action: 'retry', app: job.app, which: b[0] }, function (t) { err.textContent = t; }); });
      two.appendChild(x);
    });
    parent.appendChild(two); parent.appendChild(err);
    if (!job.putback) parent.appendChild(el('p', '', BOTH_OPEN));
  }
  function failedBox(job) {
    var w = el('div', 'cs-walk'), box = el('div', 'cs-box'), old = job.attempt === 'old';
    var holds = old ? 'the old settings' : 'the upgraded settings';
    w.appendChild(el('h3', '', job.app + (old && !job.putback ? ': going back failed too' : ': Apply failed')));
    var q = { created: job.putback ? 'The container was created with the old settings but did not start.' : old ? 'Apply failed with the old settings as well. The container was created but did not start.' : 'The container was created with the upgraded settings but did not start.',
      missing: job.putback ? 'There is still no container.' : old ? 'Apply failed with the old settings as well. The container is missing.' : 'The container is missing. Unraid now holds the upgraded settings.',
      stopped: 'The container started and then stopped. Unraid now holds ' + holds + '.',
      unchanged: 'The container was not changed. Unraid now holds ' + holds + '.' }[job.container];
    box.appendChild(el('p', 'cs-question', q));
    var g = el('div', 'cs-state');
    g.appendChild(el('span', '', 'Container'));
    var st = el('span'); st.appendChild(el('strong', '', { created: 'created, not running', missing: 'missing', stopped: 'stopped', unchanged: 'as it was' }[job.container])); g.appendChild(st);
    g.appendChild(el('span', '', 'Unraid now holds')); g.appendChild(el('span', '', holds));
    g.appendChild(el('span', '', 'Backup'));
    var bk = el('span'); bk.appendChild(el('strong', '', 'old settings, taken ' + when(job.taken))); backupInto(bk, job); g.appendChild(bk);
    box.appendChild(g);
    if (job.error) {
      var from = { docker: 'Error from Docker', image: 'Unraid does not keep this error, so the image was checked again just now', params: 'Unraid does not keep this error, so the extra parameters were checked again just now' }[job.errorFrom] || 'Error from Docker';
      var p = el('p'); p.appendChild(el('strong', '', from)); box.appendChild(p);
      box.appendChild(el('div', 'cs-err', job.error));
      if (old && /port is already allocated|address already in use/.test(job.error)) box.appendChild(el('p', '', 'Something else is using this port. Stop it, or change the port in Unraid\'s form.'));
    } else if (job.container === 'stopped') box.appendChild(el('p', '', 'Its log on the Docker tab shows why.'));
    else { var n = el('p'); n.appendChild(el('strong', '', NO_ERROR)); box.appendChild(n); }
    jobButtons(box, job, el('p', 'cs-issue'));
    box.appendChild(el('p', '', FOLDERS));
    w.appendChild(box);
    return w;
  }
  function plainBox(job, text, cancel) {
    var w = el('div', 'cs-walk');
    w.appendChild(el('h3', '', job.app)); w.appendChild(el('p', '', text));
    if (cancel) {
      var cn = el('button', 'cs-mini', 'I did not press Apply'); cn.type = 'button';
      cn.addEventListener('click', function () { post({ action: 'cancel', app: job.app }).then(pollJobs); });
      w.appendChild(cn);
    }
    return w;
  }
  function rowJob(d, job) {
    if (job.phase !== 'applied' && job.phase !== 'restored') return;
    var r = el('div', 'cs-rowbtn'), t = el('span', 'cs-rowtext');
    t.appendChild(document.createTextNode((job.phase === 'applied' ? 'Upgraded settings applied ' : 'Old settings back in place ') + when(job.when) + '. Old settings saved here, taken ' + when(job.taken) + ':'));
    backupInto(t, job);
    r.appendChild(t);
    if (job.phase === 'applied') {
      var err = el('p', 'cs-issue'), b = el('button', 'cs-mini', 'Go back to the old settings'); b.type = 'button';
      b.addEventListener('click', function () { toForm({ action: 'retry', app: job.app, which: 'old' }, function (x) { err.textContent = x; }); });
      r.appendChild(b); r.appendChild(err);
    }
    d.appendChild(r);
    if ((job.differs || []).length) d.appendChild(el('p', 'cs-muted', 'Unraid saved something else than the upgraded settings for: ' + job.differs.join(', ') + '. That can be your own change in the form.'));
  }
  function drawJobs() {
    jobsEl.textContent = '';
    Object.keys(jobs).forEach(function (k) {
      var j = jobs[k], onFinish = planApps.some(function (p) { return p.name === k; }) && pos >= 0;
      if (j.phase === 'failed') jobsEl.appendChild(failedBox(j));
      else if (j.phase === 'working') jobsEl.appendChild(plainBox(j, 'Apply was pressed. Waiting for Unraid to finish.', false));
      else if (j.phase === 'form' && !onFinish) jobsEl.appendChild(plainBox(j, 'Unraid\'s form is open with these settings. Nothing has happened yet.', true));
    });
    drawGone();
  }
  function drawGone() {
    if (!gone.length) return;
    var box = el('div', 'cs-gone'); box.id = 'cs-gone';
    box.appendChild(el('h4', '', 'Backups of apps no longer on this server'));
    box.appendChild(el('p', 'cs-muted', 'Old settings saved before an upgrade. The container and its saved settings are gone. Opening one shows it in Unraid\'s form, and nothing happens until you press Apply there.'));
    var ul = el('ul');
    gone.forEach(function (g) {
      g.files.forEach(function (x) {
        var li = el('li', '', g.app + ': ' + new Date(x.taken * 1000).toLocaleString() + ' '), b = el('button', 'cs-mini', 'Open in Unraid\'s form'), err = el('span', 'cs-err');
        b.type = 'button'; b.title = TIPS['Open in Unraid\'s form'];
        b.addEventListener('click', function () { toForm({ action: 'putback', app: g.app, file: x.file }, function (t) { err.textContent = t; }); });
        li.appendChild(b); li.appendChild(err); li.appendChild(el('div', 'cs-muted', x.path)); ul.appendChild(li);
      });
    });
    box.appendChild(ul); jobsEl.appendChild(box);
  }
  /* Answers stay in this browser across a reload. They hold key names and finding ids, never a value. */
  var WALK_KEY = 'chronostasis-walk';
  function saveWalk() {
    if (!restored) return;
    try {
      localStorage.setItem(WALK_KEY, JSON.stringify({ answers: answers, mappings: mappings, rejected: rejected, away: away, chosen: chosen, seenRenames: seenRenames,
        picked: Object.keys(picked).filter(function (k) { return picked[k]; }), walkOthers: walkOthers, pos: pos }));
    } catch (e) { /* no storage here: answers last until the page closes */ }
  }
  function restoreWalk() {
    restored = true;
    var s = null;
    try { s = JSON.parse(localStorage.getItem(WALK_KEY) || 'null'); } catch (e) { s = null; }
    var apps = last && last.report && last.report.apps;
    if (!s || !apps) return;
    var known = {}, items = {};
    apps.forEach(function (a) { a.findings.concat(a.dismissed || []).forEach(function (f) { known[f.id] = true; items[f.id] = { a: a, f: f }; }); });
    function keep(o) { var out = {}; Object.keys(o || {}).forEach(function (k) { if (known[k]) out[k] = o[k]; }); return out; }
    answers = keep(s.answers); away = keep(s.away); chosen = keep(s.chosen); seenRenames = keep(s.seenRenames); rejected = s.rejected || {};
    mappings = (s.mappings || []).filter(function (m) { return answers[m.forId]; });
    (s.picked || []).forEach(function (k) { if (items[k]) picked[k] = items[k]; });
    if (!(s.pos >= 0)) return;
    walkOthers = !!s.walkOthers; walkList = issues();
    if (!walkList.length && !Object.keys(answers).length) return;
    pos = Math.min(s.pos, walkItems().length); resumed = true;
    walkDraw();
  }
  function forgetWalk() {
    answers = {}; mappings = []; rejected = {}; away = {}; chosen = {}; picked = {}; seenRenames = {}; pos = -1; endView = 'hub'; resumed = false;
    try { localStorage.removeItem(WALK_KEY); } catch (e) { /* nothing was kept */ }
    walkDraw();
  }
  function pollJobs() {
    if (polling) return Promise.resolve();
    polling = true;
    return post({ action: 'jobs' }).then(function (r) {
      polling = false;
      if (!r.ok) return;
      var before = jobs, now = {}, changed = false;
      r.jobs.forEach(function (j) { now[j.app] = j; });
      jobs = now;
      var goneNow = r.gone || [];
      if (JSON.stringify(goneNow) !== JSON.stringify(gone)) { gone = goneNow; changed = true; }
      Object.keys(now).forEach(function (k) {
        var was = before[k] || {}, is = now[k];
        if (was.phase !== is.phase || was.error !== is.error || was.container !== is.container) changed = true;
        if (was.phase && was.phase !== is.phase && (is.phase === 'applied' || is.phase === 'restored')) { open[k] = true; doneHere[k] = true; post({ action: 'check' }).then(function () { setTimeout(load, 1500); }); }
      });
      if (Object.keys(before).length !== Object.keys(now).length) changed = true;
      if (changed) { drawJobs(); draw(); if (document.getElementById('cs-finish-apps') && endView === 'upgrade') drawFinish(); }
      clearTimeout(jobTimer);
      var busy = r.jobs.some(function (j) { return j.phase === 'form' || j.phase === 'working' || j.phase === 'failed'; });
      if (busy) jobTimer = setTimeout(pollJobs, 4000);
    }, function () { polling = false; });
  }
  function apply(s) {
    last = s;
    btn.disabled = !!s.running;
    if (s.running) statusEl.textContent = (s.step || 'Checking') + '...';
    else if (s.error) statusEl.textContent = 'Last check failed: ' + s.error;
    else if (s.report) statusEl.textContent = 'Last check: ' + new Date(s.report.generated * 1000).toLocaleString();
    else statusEl.textContent = 'Ready';
    draw();
    var c = (s.report && s.report.counts) || {};
    var issueRow = document.getElementById('cs-choice-issues'), otherRow = document.getElementById('cs-choice-others');
    issueRow.hidden = !c.issues; otherRow.hidden = !c.others;
    walkBtn.textContent = 'Go through the ' + c.issues + ' to check';
    otherBtn.textContent = 'Go through the ' + c.others + ' other differences';
    document.getElementById('cs-choice-issues-text').textContent = CHOICE_CHECK + ' On ' + c.issueApps + (c.issueApps === 1 ? ' app.' : ' apps.');
    document.getElementById('cs-choice-others-text').textContent = CHOICE_OTHER + ' On ' + c.otherApps + (c.otherApps === 1 ? ' app.' : ' apps.');
    if (pos >= 0) { pos = Math.min(pos, walkItems().length); walkDraw(); }
    clearTimeout(timer);
    if (s.running) timer = setTimeout(load, 2000);
  }
  function load() {
    return fetch(base + '/include/status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(apply)
      .catch(function () { statusEl.textContent = 'Could not read the status.'; });
  }
  btn.addEventListener('click', function () {
    btn.disabled = true; statusEl.textContent = 'Starting...';
    post({ action: 'check' }).then(function (r) {
      if (!r.ok) statusEl.textContent = 'Could not start: ' + (r.error || 'unknown error');
      setTimeout(load, 800);
    });
  });
  function startWalk(rest) {
    walkOthers = rest; walkList = issues(); pos = 0; doneHere = {}; resumed = false;
    walkDraw(); walk.scrollIntoView();
  }
  walkBtn.addEventListener('click', function () { startWalk(false); });
  otherBtn.addEventListener('click', function () { startWalk(true); });
  all.addEventListener('change', draw);
  window.addEventListener('pagehide', saveWalk);
  load().then(function () { restoreWalk(); return pollJobs(); });
})();
