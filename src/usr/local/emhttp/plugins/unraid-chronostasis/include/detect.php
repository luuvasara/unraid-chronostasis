<?php
/* unraid-chronostasis: compares saved Docker templates with the Community Applications feed. Pure functions, no I/O
 * except reading one template file. Values are shown only on your own server; they are never in reports or in anything you copy or paste.
 * This file takes only attributes from a <Config>, and a saved Default only as a hash, never for a Mask="true" field;
 * include/value.php gives the page one field's value on request. */

function cs_url_norm(string $u): string {
  $u = trim($u);
  $u = preg_replace('~^http://~i', 'https://', $u);
  $u = preg_replace('~^https://raw\.github\.com/~i', 'https://raw.githubusercontent.com/', $u);
  $u = preg_replace('~^https://github\.com/([^/]+)/([^/]+)/(?:raw|blob)/~i', 'https://raw.githubusercontent.com/$1/$2/', $u);
  return str_replace('/refs/heads/', '/', $u);
}
/* owner/repo/path of a raw GitHub address, without the branch: master and main serve the same file after a rename */
function cs_url_key(string $u): string {
  $u = cs_url_norm($u);
  if (preg_match('~^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/[^/]+/(.+)$~i', $u, $m)) {
    return strtolower("$m[1]/$m[2]/$m[3]");
  }
  return strtolower($u);
}
function cs_repo_full(string $r): string {
  $r = strtolower(trim($r));
  $r = preg_replace('~@.*$~', '', $r);
  $r = preg_replace('~:[^/]+$~', '', $r);
  $r = preg_replace('~^(docker\.io|index\.docker\.io|registry\.hub\.docker\.com|registry-1\.docker\.io)/~', '', $r);
  return preg_replace('~^library/~', '', $r);
}
/* the image without its registry, to notice a move between registries */
function cs_repo_base(string $r): string {
  return preg_replace('~^(ghcr\.io|lscr\.io|quay\.io|gcr\.io|public\.ecr\.aws)/~', '', cs_repo_full($r));
}
/* Docker Template Fields turns a field off by saving Type="Disabled:Port". Unraid skips such a field when it builds
 * the docker command. It is the same field, kept on purpose, so it keys as its real type. A template can also say so
 * in the Name ("Disabled: X"). */
function cs_is_disabled(string $type, string $name = ''): bool {
  return stripos(trim($type), 'disabled:') === 0 || stripos(trim($name), 'disabled:') === 0;
}
function cs_real_type(string $type): string { return cs_is_disabled($type) ? substr(trim($type), 9) : $type; }
function cs_field_key(string $type, string $target, string $name, string $mode): string {
  $t = strtolower(cs_real_type($type));
  $tg = trim($target);
  if ($t === 'path') $tg = strlen($tg) > 1 ? rtrim($tg, '/') : $tg;
  if ($t === 'port') return 'port:' . $tg . '/' . (strtolower(trim($mode)) ?: 'tcp');
  if ($tg === '') return "$t:name:" . trim($name);
  return "$t:$tg";
}
function cs_field_label(string $type, string $target, string $name, string $mode): string {
  $tg = trim($target) !== '' ? trim($target) : trim($name);
  return strtolower(cs_real_type($type)) === 'port' ? $tg . '/' . (strtolower(trim($mode)) ?: 'tcp') : $tg;
}
function cs_is_container_net(string $network, string $extraParams): bool {
  return stripos($network, 'container:') === 0
      || preg_match('/--(?:net|network)(?:=|\s+)[\'"]?container:/', $extraParams) === 1;
}

/* A file's size and time, to notice a change. Not a hash: a report never holds anything derived from a value. */
function cs_stamp(string $file): string { clearstatcache(true, $file); return is_file($file) ? filemtime($file) . '-' . filesize($file) : ''; }
/* The tag or digest of an image, '' when none. */
function cs_image_tag(string $r): string {
  $r = trim($r);
  if (preg_match('/@(sha256:[0-9a-f]+)$/i', $r, $m)) return $m[1];
  return preg_match('~:([^/:]+)$~', $r, $m) ? $m[1] : '';
}
/* The saved image stays on a version of its own when it has a tag the template does not ask for. The comparison is then
 * with the current template, which may describe a newer version: the feed has no templates of earlier versions. */
function cs_pinned(string $saved, string $feed): string {
  $tag = cs_image_tag($saved);
  return $tag === '' || strtolower($tag) === 'latest' || $tag === cs_image_tag($feed) ? '' : $tag;
}
/* One saved template, identity only. Returns null when the file is not a template. */
function cs_read_template(string $file): ?array {
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;
  $prev = libxml_use_internal_errors(true);
  $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  if (!$x || !isset($x->Name)) return null;
  $fields = [];
  foreach ($x->Config as $c) {
    $a = $c->attributes();
    $type = (string)($a['Type'] ?? ''); $target = (string)($a['Target'] ?? ''); $name = (string)($a['Name'] ?? ''); $mode = (string)($a['Mode'] ?? '');
    $mask = strtolower((string)($a['Mask'] ?? '')) === 'true';
    $fields[cs_field_key($type, $target, $name, $mode)] = [
      'type' => cs_real_type($type), 'label' => cs_field_label($type, $target, $name, $mode), 'name' => $name,
      'required' => strtolower((string)($a['Required'] ?? '')) === 'true', 'mask' => $mask,
      'default' => $mask ? '' : sha1((string)($a['Default'] ?? '')), 'disabled' => cs_is_disabled($type, $name),
    ];
  }
  return [
    'name' => (string)$x->Name, 'file' => basename($file), 'stamp' => cs_stamp($file), 'repository' => (string)$x->Repository, 'url' => trim((string)$x->TemplateURL),
    'support' => trim((string)$x->Support), 'project' => trim((string)$x->Project),
    'containerNet' => cs_is_container_net((string)$x->Network, (string)$x->ExtraParams),
    'legacy' => (string)($x->attributes()['version'] ?? '') !== '2' && !isset($x->Config), 'fields' => $fields,
  ];
}

function cs_feed_fields(array $app): array {
  $c = $app['Config'] ?? [];
  if (!is_array($c)) return [];
  if (isset($c['@attributes'])) $c = [$c];
  $out = [];
  foreach ($c as $i) {
    $a = is_array($i) ? ($i['@attributes'] ?? null) : null;
    if (!is_array($a)) continue;
    $type = (string)($a['Type'] ?? ''); $target = (string)($a['Target'] ?? ''); $name = (string)($a['Name'] ?? ''); $mode = (string)($a['Mode'] ?? '');
    $mask = strtolower((string)($a['Mask'] ?? '')) === 'true';
    $out[cs_field_key($type, $target, $name, $mode)] = [
      'type' => $type, 'label' => cs_field_label($type, $target, $name, $mode), 'name' => $name,
      'required' => strtolower((string)($a['Required'] ?? '')) === 'true', 'mask' => $mask,
      'default' => $mask ? '' : sha1((string)($a['Default'] ?? '')), 'shownDefault' => $mask ? '' : (string)($a['Default'] ?? ''),
      'noDefault' => trim((string)($a['Default'] ?? '')) === '', 'disabled' => cs_is_disabled($type, $name),
    ];
  }
  return $out;
}

/* One app's template in the feed as the upgraded settings need it, field by field: public data, no value of yours. */
function cs_template_data(array $app): array {
  $c = $app['Config'] ?? [];
  if (!is_array($c)) $c = [];
  if (isset($c['@attributes'])) $c = [$c];
  $f = [];
  foreach ($c as $i) {
    $a = is_array($i) ? ($i['@attributes'] ?? null) : null;
    if (!is_array($a)) continue;
    $r = [];
    foreach (['Name', 'Target', 'Default', 'Mode', 'Description', 'Type', 'Display', 'Required', 'Mask'] as $k) $r[$k] = is_scalar($a[$k] ?? null) ? (string)$a[$k] : '';
    $f[cs_field_key($r['Type'], $r['Target'], $r['Name'], $r['Mode'])] = $r;
  }
  return ['webui' => is_string($app['WebUI'] ?? null) ? $app['WebUI'] : '', 'fields' => $f];
}

function cs_index(array $feed): array {
  $idx = ['apps' => [], 'url' => [], 'full' => [], 'base' => []];
  foreach (($feed['applist'] ?? []) as $a) {
    if (!is_array($a) || !empty($a['Plugin']) || !is_string($a['Repository'] ?? null)) continue;
    $i = count($idx['apps']);
    $idx['apps'][] = $a;
    foreach (['TemplateURL', 'caTemplateURL'] as $k) {
      if (is_string($a[$k] ?? null) && $a[$k] !== '') $idx['url'][cs_url_key($a[$k])][] = $i;
    }
    $idx['full'][cs_repo_full($a['Repository'])][] = $i;
    $idx['base'][cs_repo_base($a['Repository'])][] = $i;
  }
  $idx['deprecated'] = $feed['deprecated'] ?? [];
  $idx['blacklisted'] = $feed['blacklisted'] ?? [];
  return $idx;
}

function cs_prefer(array $cands, array $idx, array $t): array {
  if (count($cands) < 2) return $cands;
  $s = array_values(array_filter($cands, function ($i) use ($idx, $t) {
    $a = $idx['apps'][$i];
    return ($t['support'] !== '' && ($a['Support'] ?? '') === $t['support']) || ($t['project'] !== '' && ($a['Project'] ?? '') === $t['project']);
  }));
  return $s ?: $cands;
}

/* Match order: template address, then the image, then the image under another registry. Several templates for one
 * image and nothing to tell them apart: no match, never the first one. */
function cs_match(array $t, array $idx): array {
  $url = strtolower($t['url']) === 'false' ? '' : $t['url'];
  if ($url !== '') {
    $c = $idx['url'][cs_url_key($url)] ?? [];
    if ($c) {
      $same = array_values(array_filter($c, fn($i) => cs_repo_base($idx['apps'][$i]['Repository']) === cs_repo_base($t['repository'])));
      return ['entry' => $idx['apps'][($same ?: $c)[0]], 'how' => 'address', 'confidence' => 'high', 'candidates' => count($c)];
    }
  }
  foreach ([['full', 'image', 'medium'], ['base', 'image on another registry', 'low']] as [$tbl, $how, $conf]) {
    $key = $tbl === 'full' ? cs_repo_full($t['repository']) : cs_repo_base($t['repository']);
    $c = cs_prefer($idx[$tbl][$key] ?? [], $idx, $t);
    if (count($c) === 1) return ['entry' => $idx['apps'][$c[0]], 'how' => $how, 'confidence' => $conf, 'candidates' => 1];
    if (count($c) > 1) return ['entry' => null, 'how' => 'several templates', 'confidence' => 'none', 'candidates' => count($c)];
  }
  return ['entry' => null, 'how' => 'not found', 'confidence' => 'none', 'candidates' => 0];
}

function cs_finding(string $kind, string $key, string $label, string $fp, array $extra = []): array {
  return ['kind' => $kind, 'key' => $key, 'label' => $label, 'fp' => substr(sha1($fp), 0, 12)] + $extra;
}

function cs_url_state(array $t, ?array $app, ?int $http): string {
  $u = strtolower($t['url']);
  if ($u === 'false') return 'false';
  if ($u === '') return 'missing';
  if ($http === 404 || $http === 410) return 'dead';
  if ($app !== null) {
    $k = cs_url_key($t['url']);
    foreach (['TemplateURL', 'caTemplateURL'] as $f) {
      if (is_string($app[$f] ?? null) && cs_url_key($app[$f]) === $k) return 'same';
    }
    return 'other';
  }
  return 'unknown';
}

/* The template field keeps its Name and Type but sits at another Target: template key => saved key. Each saved field
 * pairs once, first come first served. */
function cs_moved_fields(array $R, array $L): array {
  $out = [];
  foreach ($R as $k => $f) {
    if (isset($L[$k]) || !empty($f['disabled']) || trim($f['name']) === '') continue;
    foreach ($L as $lk => $l) {
      if (isset($R[$lk]) || !empty($l['disabled']) || in_array($lk, $out, true)) continue;
      if (strtolower($l['type']) === strtolower($f['type']) && strtolower(trim($l['name'])) === strtolower(trim($f['name']))) { $out[$k] = $lk; break; }
    }
  }
  return $out;
}
const CS_GUESS_MIN = 60;
function cs_name_part(string $s): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper(preg_replace('~/(tcp|udp)$~i', '', $s))); }
/* How much a saved field looks like a template field of the same Type, 0 to 100. Spelling of the target first; the same
 * display Name or the same non-empty default count as evidence. similar_text is not symmetric, so the better way round. */
function cs_pair_score(array $new, array $saved): float {
  $a = cs_name_part($new['label']); $b = cs_name_part($saved['label']);
  similar_text($a, $b, $x); similar_text($b, $a, $y);
  $score = max($x, $y);
  if (trim($new['name']) !== '' && strtolower(trim($new['name'])) === strtolower(trim($saved['name']))) $score += 25;
  if (($new['default'] ?? '') !== '' && $new['default'] !== sha1('') && $new['default'] === ($saved['default'] ?? '')) $score += 15;
  return min(100.0, $score);
}
/* Why a pair was suggested, in one line. */
function cs_pair_reason(array $new, array $saved, ?array $same): string {
  if ($same !== null) return $saved['label'] . ' was in this template until ' . $same['date'] . ' and was replaced by ' . $new['label'] . ' in the same change.';
  $why = [];
  if (cs_pair_score(['label' => $new['label'], 'name' => '', 'default' => ''], ['label' => $saved['label'], 'name' => '', 'default' => '']) >= CS_GUESS_MIN) $why[] = 'similar name';
  if (trim($new['name']) !== '' && strtolower(trim($new['name'])) === strtolower(trim($saved['name']))) $why[] = 'same display name';
  if (($new['default'] ?? '') !== '' && $new['default'] !== sha1('') && $new['default'] === ($saved['default'] ?? '')) $why[] = 'same default';
  return 'Suggested because: ' . implode(', ', $why ?: ['the same type']) . '.';
}
/* Fills in, on the new and only-yours findings: cands, the other side's fields of the same Type, best first; guess, the
 * one suggested, or '' under CS_GUESS_MIN; reason, why. A field is suggested for one partner only, the best pairs first.
 * History, when there is any for this template, outranks everything: $hist = ['keys' => [field key => last seen],
 * 'pairs' => [['from' => field key, 'to' => field key, 'date' => when]]]. A pair replaced in one change is a rename; a
 * saved key the template once had is no longer the user's own (past). These are guesses: the user decides. */
function cs_pair_guesses(array $findings, array $R, array $L, array $moved, array $hist = []): array {
  $news = $yours = $pairs = [];
  foreach ($findings as $i => $f) {
    if ($f['kind'] === 'new') $news[$f['key']] = $i;
    if ($f['kind'] === 'yours') $yours[$f['key']] = $i;
  }
  $same = [];
  foreach (($hist['pairs'] ?? []) as $p) if (is_array($p)) $same[(string)($p['to'] ?? '') . '|' . (string)($p['from'] ?? '')] = ['date' => (string)($p['date'] ?? '')];
  $past = is_array($hist['keys'] ?? null) ? $hist['keys'] : [];
  foreach ($news as $nk => $i) foreach ($yours as $yk => $j) {
    if (strtolower($R[$nk]['type']) !== strtolower($L[$yk]['type'])) continue;
    $sure = ($moved[$nk] ?? null) === $yk;
    $base = cs_pair_score($R[$nk], $L[$yk]);
    $s = $same["$nk|$yk"] ?? null;
    $rank = ($s !== null ? 300 : 0) + (isset($past[$yk]) ? 200 : 0) + ($sure ? 101 : $base);
    $pairs[] = [$rank, $nk, $yk, $s !== null || $sure || $base >= CS_GUESS_MIN, $s];
  }
  usort($pairs, fn($p, $q) => $q[0] <=> $p[0]);
  foreach (array_merge($news, $yours) as $i) { $findings[$i]['cands'] = []; $findings[$i]['guess'] = ''; $findings[$i]['reason'] = ''; }
  foreach ($yours as $yk => $j) $findings[$j]['past'] = isset($past[$yk]) ? $findings[$j]['label'] . ' was in this template until ' . $past[$yk] . '.' : '';
  foreach ($pairs as [$rank, $nk, $yk, $ok, $s]) {
    $n = $news[$nk]; $y = $yours[$yk];
    if (($moved[$nk] ?? null) === $yk) { $findings[$n]['moved'] = $findings[$y]['label']; $findings[$y]['moved'] = $findings[$n]['label']; }
    $findings[$n]['cands'][] = $findings[$y]['label'];
    $findings[$y]['cands'][] = $findings[$n]['label'];
    if ($ok && $findings[$n]['guess'] === '' && $findings[$y]['guess'] === '') {
      $findings[$n]['guess'] = $findings[$y]['label'];
      $findings[$y]['guess'] = $findings[$n]['label'];
      $findings[$n]['reason'] = $findings[$y]['reason'] = cs_pair_reason($R[$nk], $L[$yk], $s);
    }
  }
  return $findings;
}
/* A field the template requires and fills in nothing for. Called for a field the saved copy does not have. */
function cs_required_gap(array $f): bool {
  return empty($f['disabled']) && $f['required'] && $f['noDefault'] && in_array(strtolower($f['type']), ['path', 'port', 'variable'], true);
}
/* What the summary and the walkthrough count. Field differences only with the warn mark (a required field with no
 * default, cs_required_gap) and the renamed variable; image, address and moderation findings always. */
function cs_to_check(array $f): bool {
  return !in_array($f['kind'], ['new', 'yours', 'required', 'default'], true) || !empty($f['warn']);
}

/* One application: everything that differs from its current template. $http: status of the saved address, or null.
 * $history: what is known about earlier versions, by template address key; see cs_pair_guesses. Optional. */
function cs_app(array $t, array $idx, ?int $http, array $history = []): array {
  $m = cs_match($t, $idx);
  $app = $m['entry'];
  $r = ['name' => $t['name'], 'file' => $t['file'] ?? '', 'stamp' => $t['stamp'] ?? '', 'pinned' => $app ? cs_pinned($t['repository'], (string)$app['Repository']) : '', 'repository' => $t['repository'], 'how' => $m['how'], 'confidence' => $m['confidence'],
        'candidates' => $m['candidates'], 'support' => $t['support'], 'project' => $t['project'], 'containerNet' => $t['containerNet'],
        'source' => $app ? (string)($app['Repo'] ?? '') : '', 'findings' => [], 'disabledFields' => [], 'notApplicable' => [], 'moved' => [],
        'feedName' => $app ? (string)($app['Name'] ?? '') : '', 'feedImage' => $app ? (string)$app['Repository'] : '',
        'savedUrl' => $t['url'], 'feedUrl' => $app ? (string)($app['TemplateURL'] ?? $app['caTemplateURL'] ?? '') : ''];
  if ($app) {
    if ($r['support'] === '') $r['support'] = is_string($app['Support'] ?? null) ? $app['Support'] : '';
    if ($r['project'] === '') $r['project'] = is_string($app['Project'] ?? null) ? $app['Project'] : '';
  }
  $state = cs_url_state($t, $app, $http);
  $r['address'] = $state;
  $feedUrl = $app ? (string)($app['TemplateURL'] ?? $app['caTemplateURL'] ?? '') : '';
  if (in_array($state, ['dead', 'other', 'missing'], true) && ($app || $state === 'dead')) {
    $r['findings'][] = cs_finding('address', $state, $state, $state . $feedUrl, ['saved' => $t['url'], 'feed' => $feedUrl]);
  }
  $dep = $idx['deprecated'][$t['repository']] ?? $idx['deprecated'][cs_repo_full($t['repository'])] ?? null;
  $blk = $idx['blacklisted'][$t['repository']] ?? $idx['blacklisted'][cs_repo_full($t['repository'])] ?? null;
  if ($dep !== null || !empty($app['Deprecated'])) {
    $r['findings'][] = cs_finding('moderation', 'deprecated', 'Deprecated', 'deprecated', ['text' => is_string($dep) ? $dep : 'Marked deprecated in Community Applications']);
  }
  if ($blk !== null || !empty($app['Blacklist'])) {
    $r['findings'][] = cs_finding('moderation', 'blacklisted', 'Blacklisted', 'blacklisted', ['text' => is_string($blk) ? $blk : 'Blacklisted in Community Applications']);
  }
  foreach (['ModeratorComment', 'CAComment'] as $f) {
    if (is_string($app[$f] ?? null) && trim($app[$f]) !== '') {
      $txt = trim(html_entity_decode(strip_tags($app[$f]), ENT_QUOTES));
      $r['findings'][] = cs_finding('moderation', strtolower($f), 'Moderator comment', $txt, ['text' => $txt]);
    }
  }
  if ($app === null) return $r;
  if (cs_repo_full((string)$app['Repository']) !== cs_repo_full($t['repository'])) {
    $r['findings'][] = cs_finding('image', 'repository', (string)$app['Repository'], (string)$app['Repository'], ['saved' => $t['repository']]);
  }
  $R = cs_feed_fields($app);
  if (!$R) { $r['how'] .= ', the feed has no field list'; return $r; }
  if ($t['legacy']) { $r['how'] .= ', old template format'; return $r; }
  $L = $t['fields'];
  $na = fn($f) => $t['containerNet'] && strtolower($f['type']) === 'port';
  /* Same Name and Type at another Target: the template author moved the field. That is not an update of old settings,
     so it is no finding and nothing changes it; the app lists it once, for reference. */
  $moved = cs_moved_fields($R, $L);
  $r['moved'] = [];
  /* a Device with no Target in the template is one the user fills in: nothing moved, so no line either */
  foreach ($moved as $rk => $lk) if (strpos($rk, 'device:name:') !== 0 && $R[$rk]['label'] !== $L[$lk]['label'])
    $r['moved'][] = ['name' => $R[$rk]['name'], 'type' => $R[$rk]['type'], 'saved' => $L[$lk]['label'], 'template' => $R[$rk]['label']];
  /* a dismissed field returns when the template's field list changes */
  $state = array_keys($R); sort($state);
  $renamed = array_values($moved);
  foreach ($R as $k => $f) {
    if (isset($L[$k]) || isset($moved[$k])) continue;
    if ($na($f)) { $r['notApplicable'][] = $f['label']; continue; }
    $r['findings'][] = cs_finding('new', $k, $f['label'], $f['default'] . (int)$f['required'],
      ['type' => $f['type'], 'name' => $f['name'], 'required' => $f['required'], 'default' => $f['shownDefault'],
       'warn' => cs_required_gap($f), 'mask' => $f['mask']]);
  }
  foreach ($L as $k => $f) {
    if (!empty($f['disabled'])) { $r['disabledFields'][] = $f['label']; continue; }
    if (isset($R[$k]) || in_array($k, $renamed, true)) continue;
    if ($na($f)) { $r['notApplicable'][] = $f['label']; continue; }
    $r['findings'][] = cs_finding('yours', $k, $f['label'], 'yours|' . implode(',', $state), ['type' => $f['type'], 'name' => $f['name'], 'mask' => $f['mask']]);
  }
  $h = $feedUrl !== '' ? ($history[cs_url_key($feedUrl)] ?? []) : [];
  $r['findings'] = cs_pair_guesses($r['findings'], $R, $L, [], is_array($h) ? $h : []);
  foreach ($R as $k => $f) {
    if (!isset($L[$k]) || $na($f)) continue;
    $l = $L[$k];
    if (!empty($l['disabled'])) continue;
    /* Paths are left out: Unraid rewrites their default to the local appdata location.
       A saved copy with no default says nothing about what it was based on, so it is not compared. */
    $otherDefault = strtolower($f['type']) !== 'path' && !$f['mask'] && !$l['mask'] && $l['default'] !== sha1('') && $f['default'] !== $l['default'];
    /* one field is one row: a default that changed along with the required flag goes in the same finding */
    if ($f['required'] !== $l['required']) {
      $r['findings'][] = cs_finding('required', $k, $f['label'], (int)$f['required'] . ($otherDefault ? $f['default'] : ''), ['type' => $f['type'], 'required' => $f['required'], 'mask' => $f['mask'] || $l['mask']]
        + ($otherDefault ? ['default' => $f['shownDefault'], 'defaultChanged' => true] : []));
    } elseif ($otherDefault) {
      $r['findings'][] = cs_finding('default', $k, $f['label'], $f['default'], ['type' => $f['type'], 'default' => $f['shownDefault'], 'mask' => false]);
    }
  }
  return $r;
}

/* The settings of CA Auto Update Applications, when it is installed. It recreates a container from its saved template,
 * so the image moves on while the template stays. No schedule set means nothing runs, whatever the rest says. */
function cs_auto_updated(array $s, string $name): bool {
  $freq = (string)($s['cron']['dockerCronFrequency'] ?? 'disabled');
  if ($freq === '' || $freq === 'disabled') return false;
  return ($s['global']['dockerUpdateAll'] ?? '') === 'yes' || !empty($s['containers'][$name]['name']);
}

/* The commit history of a template on GitHub: when and how it changed, for correlation. */
function cs_history_url(string $u): string {
  $u = cs_url_norm($u);
  if (preg_match('~^https://raw\.githubusercontent\.com/([^/]+)/([^/]+)/([^/]+)/(.+)$~i', $u, $m)) {
    return "https://github.com/$m[1]/$m[2]/commits/$m[3]/$m[4]";
  }
  return '';
}

/* Why one finding is shown. Says what differs and what could explain it; never that the user's copy is wrong. */
function cs_diagnosis(array $a, array $f): string {
  $count = function (string $k) use ($a) { return count(array_filter($a['findings'], fn($x) => $x['kind'] === $k)); };
  switch ($f['kind']) {
    case 'new':
      $t = 'The template has this field and old settings do not. It may be a new option, or a new name for a field you already have.';
      if ($count('yours')) $t .= ' This app also has ' . $count('yours') . ' field(s) only in old settings: compare them.';
      if (!empty($f['warn'])) $t .= ' The template marks it as required and gives no default, so it may be waiting for a path or value from you. You may also have set this up another way.';
      elseif (!empty($f['required'])) $t .= ' The template marks it as required.';
      return $t;
    case 'renamed':
      return 'The template has this field under the same name, with a new target. Old settings still use ' . $f['from'] . '. It may be a rename by the app, and old settings can be the newer side: the template history shows when it changed.';
    case 'yours':
      return 'Old settings have this field and the template does not. You may have added it, the template may have renamed or dropped it, or old settings may be newer than the template.';
    case 'required':
      return 'The field exists on both sides, but the template now treats it differently. Unraid only uses this flag in the edit form.';
    case 'default':
      return "The template default differs from the default in old settings. Your value is not affected.";
    case 'image':
      return 'Old settings and the template name different images. It can be the same app on another registry, or another build: check the Project link before changing anything.';
    case 'address':
      if ($f['key'] === 'dead') return 'The address in old settings answers 404. Unraid does not use this address at run time, so nothing breaks, but it no longer shows where the template lives.';
      if ($f['key'] === 'other') return 'Community Applications lists another address for this app. The template may have moved, or this may be a different author\'s template for the same image.';
      return 'Old settings have no template address, so it can only be matched by its image.';
    case 'moderation':
      return 'This comes from the Community Applications moderators.';
  }
  return '';
}

/* Hide what the user dismissed, until the template changes that item (the fingerprint no longer matches). */
function cs_apply_dismissed(array $report, array $dismissed): array {
  $counts = ['apps' => 0, 'differ' => 0, 'unmatched' => 0, 'dismissed' => 0, 'issues' => 0, 'issueApps' => 0, 'others' => 0, 'otherApps' => 0];
  foreach (($report['apps'] ?? []) as $i => $a) {
    $keep = []; $hid = [];
    foreach ($a['findings'] as $f) {
      $id = substr(sha1($a['name'] . '|' . $f['kind'] . '|' . $f['key']), 0, 16);
      $f['id'] = $id;
      $f['why'] = cs_diagnosis($a, $f);
      $f['check'] = cs_to_check($f);
      if (($dismissed[$id]['fp'] ?? null) === $f['fp']) $hid[] = $f; else $keep[] = $f;
    }
    $report['apps'][$i]['findings'] = $keep;
    $report['apps'][$i]['dismissed'] = $hid;
    $report['apps'][$i]['history'] = cs_history_url((string)($a['feedUrl'] ?: $a['savedUrl'] ?? ''));
    $counts['apps']++;
    $counts['dismissed'] += count($hid);
    if ($keep) $counts['differ']++;
    $issues = count(array_filter($keep, fn($f) => $f['check']));
    $counts['issues'] += $issues;
    $counts['others'] += count($keep) - $issues;
    if ($issues) $counts['issueApps']++;
    if (count($keep) > $issues) $counts['otherApps']++;
    if ($a['confidence'] === 'none') $counts['unmatched']++;
  }
  /* What is to check first, and in there what could change with nobody watching; the sort keeps the name order inside
     each group. Apps with only other differences come after them. */
  $apps = $report['apps'] ?? [];
  $top = fn($a) => array_filter($a['findings'], fn($f) => $f['check']) || $a['confidence'] === 'none';
  $auto = fn($a) => !empty($a['autoUpdated']) && $top($a);
  $counts['autoDiffer'] = count(array_filter($apps, $auto));
  $rank = fn($a) => ((int)$top($a)) + (int)$auto($a);
  usort($apps, fn($a, $b) => $rank($b) <=> $rank($a));
  $report['apps'] = $apps;
  $report['counts'] = $counts;
  return $report;
}
