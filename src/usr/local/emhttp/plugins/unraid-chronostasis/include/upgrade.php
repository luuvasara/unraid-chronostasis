<?php
/* unraid-chronostasis: the upgraded settings. Old settings plus your answers, written to one file that opens in Unraid's
 * form. Nothing here touches a container or the saved template: Unraid does that when you press Apply. The file holds
 * values, masked ones included, as the saved template does, so it stays in RAM with mode 0600 and goes when it is done
 * with. The backup holds the same and stays on the flash drive beside the plugin's settings. No value leaves this file
 * except for a field that is not masked, for the page, on request. Commands run without a shell. */

const CS_SETTLE = 25;   // seconds Unraid gets, after writing the template, before a missing container counts as a failed Apply
const CS_UP = 8;        // seconds a container must have been up before Apply counts as done

function cs_settle(string $name, int $d): int { $v = getenv($name); return $v === false ? $d : (int)$v; }
function cs_safe_name(string $n): bool { return preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*$/', $n) === 1 && strlen($n) <= 128; }
function cs_upgrade_file(string $app): string { return rtrim(cs_path('run'), '/') . "/upgrade/my-$app.xml"; }
/* Docker's error can repeat what was typed in the extra parameters, so it stays in RAM with the upgraded settings. */
function cs_error_file(string $app): string { return rtrim(cs_path('run'), '/') . "/upgrade/$app.error"; }
function cs_jobs_file(): string { return rtrim(cs_path('config'), '/') . '/upgrades.json'; }
function cs_saved_file(array $a): ?string {
  $f = (string)($a['file'] ?? '');
  if ($f !== basename($f) || !preg_match('/^my-.+\.xml$/', $f)) return null;
  $p = rtrim(cs_path('templates'), '/') . "/$f";
  return is_file($p) && !is_link($p) ? $p : null;
}

/* [exit code, stdout, stderr]. rc 127 when the command cannot run. */
function cs_run(array $cmd, int $timeout = 20): array {
  $p = @proc_open(array_merge(['timeout', (string)$timeout], $cmd), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($p)) return [127, '', ''];
  $o = (string)stream_get_contents($pipes[1]); $e = (string)stream_get_contents($pipes[2]);
  fclose($pipes[1]); fclose($pipes[2]);
  return [proc_close($p), $o, $e];
}
function cs_docker(): string { return getenv('CS_DOCKER') ?: 'docker'; }
/* The container, read field by field: a full inspect would hold every value. null: no such container. */
function cs_container(string $name): ?array {
  $f = '{{.Id}}|{{.State.Status}}|{{.State.StartedAt}}|{{index .Config.Labels "net.unraid.docker.managed"}}|{{index .Config.Labels "com.docker.compose.project"}}|{{.HostConfig.NetworkMode}}|{{.State.ExitCode}}|{{.State.Error}}';
  [$rc, $o] = cs_run([cs_docker(), 'inspect', '--type', 'container', '--format', $f, $name]);
  if ($rc !== 0) return null;
  $p = explode('|', trim($o), 8);
  if (count($p) < 8) return null;
  $p = array_map(fn($v) => $v === '<no value>' ? '' : $v, $p);
  return ['id' => $p[0], 'status' => $p[1], 'started' => $p[2], 'managed' => $p[3], 'project' => $p[4], 'network' => $p[5], 'exit' => $p[6], 'error' => $p[7]];
}
/* Containers that use this one's network: they lose it when Apply recreates it. */
function cs_dependents(string $name, string $id): array {
  [$rc, $o] = cs_run([cs_docker(), 'ps', '-a', '--format', '{{.ID}}']);
  $ids = $rc === 0 ? array_values(array_filter(explode("\n", trim($o)))) : [];
  if (!$ids) return [];
  [$rc, $o] = cs_run(array_merge([cs_docker(), 'inspect', '--type', 'container', '--format', '{{.Name}}|{{.HostConfig.NetworkMode}}'], $ids));
  $out = [];
  foreach (explode("\n", $rc === 0 ? trim($o) : '') as $l) {
    [$n, $m] = array_pad(explode('|', $l, 2), 2, '');
    if ($m === "container:$name" || ($id !== '' && $m === "container:$id")) $out[] = ltrim($n, '/');
  }
  return $out;
}
/* What stops the form from arriving as it should. Texts for the page. */
function cs_network_problem(string $network): string {
  if ($network === '') return '';
  if (stripos($network, 'container:') === 0) {
    $main = substr($network, 10);
    return cs_container($main) ? '' : "This container uses the network of $main, and there is no such container now. Unraid's form would save it without one.";
  }
  [$rc, $o] = cs_run([cs_docker(), 'network', 'ls', '--format', '{{.Name}}']);
  if ($rc !== 0) return '';
  return in_array($network, explode("\n", trim($o)), true) ? '' : "The network $network does not exist now. Unraid's form would set this container to no network, and its ports would go.";
}

function cs_xml(string $file): ?DOMDocument {
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;
  $d = new DOMDocument();
  $prev = libxml_use_internal_errors(true);
  $ok = $d->loadXML($raw, LIBXML_NONET);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  return $ok && $d->documentElement ? $d : null;
}
function cs_configs(DOMDocument $d): array {
  $out = [];
  foreach (iterator_to_array($d->documentElement->childNodes) as $n) if ($n instanceof DOMElement && $n->tagName === 'Config') $out[] = $n;
  return $out;
}
function cs_config_key(DOMElement $c): string { return cs_field_key($c->getAttribute('Type'), $c->getAttribute('Target'), $c->getAttribute('Name'), $c->getAttribute('Mode')); }
function cs_config_label(DOMElement $c): string { return cs_field_label($c->getAttribute('Type'), $c->getAttribute('Target'), $c->getAttribute('Name'), $c->getAttribute('Mode')); }
function cs_first_option(string $default): string { return explode('|', $default)[0]; }
function cs_is_masked(DOMElement $c, array $t = []): bool { return strtolower($c->getAttribute('Mask')) === 'true' || strtolower($t['Mask'] ?? '') === 'true'; }

/* What the answers ask for: renames [saved key => template key], includes [template key], and the lists for the page.
 * $answers: [['id' => finding id, 'type' => answer, 'other' => id of the other side]]. A renamed finding counts when it is
 * in the answers as type renamed: you have been through it and did not dismiss it. */
function cs_upgrade_ops(array $a, array $answers): array {
  $by = [];
  foreach (array_merge($a['findings'] ?? [], $a['dismissed'] ?? []) as $f) $by[$f['id'] ?? ''] = $f;
  $ops = ['renames' => [], 'includes' => [], 'heads' => [], 'own' => [], 'left' => []];
  foreach ($answers as $x) {
    $f = $by[$x['id'] ?? ''] ?? null;
    if ($f === null) continue;
    $type = $x['type'] ?? '';
    if ($type === 'renamed' && $f['kind'] === 'renamed') {
      $ops['renames'][$f['fromKey']] = $f['key'];
      $ops['heads'][$f['key']] = 'Same name in the template, new key';
    } elseif ($type === 'same' && in_array($f['kind'], ['new', 'yours'], true)) {
      $p = $by[$x['other'] ?? ''] ?? null;
      if ($p === null || !in_array($p['kind'], ['new', 'yours'], true) || $p['kind'] === $f['kind']) continue;
      [$n, $y] = $f['kind'] === 'new' ? [$f, $p] : [$p, $f];
      $ops['renames'][$y['key']] = $n['key'];
      $t = strtolower((string)($n['type'] ?? ''));
      $ops['heads'][$n['key']] = empty($n['moved']) ? 'You answered Yes, same field' : ($t === 'path' ? 'Path moved' : ($t === 'port' ? 'Port changed' : 'Device moved'));
    } elseif ($type === 'include' && $f['kind'] === 'new') {
      $ops['includes'][] = $f['key'];
      $ops['heads'][$f['key']] = !empty($f['warn']) ? 'New, required, no default' : 'You answered Include it';
    } elseif (in_array($type, ['own', 'report'], true) && $f['kind'] === 'yours') {
      $ops['own'][] = (string)$f['label'];
    } elseif ($type === 'leave' && $f['kind'] === 'new') {
      $ops['left'][] = (string)$f['label'];
    }
  }
  return $ops;
}
/* Old settings with the answers in. $tpl: cs_template_data() of the app. Returns the XML and, for the page, one entry per
 * change. Every other field keeps its value and takes the template's name, description, default and flags. A required
 * field left empty goes first, so the form shows it at the top. */
function cs_upgrade_build(string $savedFile, array $tpl, array $ops): ?array {
  $d = cs_xml($savedFile);
  if ($d === null) return null;
  $R = $tpl['fields'] ?? [];
  $rows = []; $webui = null; $top = [];
  foreach ($d->documentElement->childNodes as $n) if ($n instanceof DOMElement && $n->tagName === 'WebUI') $webui = $n;
  $take = function (DOMElement $c, array $t, bool $identity) {
    foreach (['Name', 'Description', 'Required', 'Display'] as $k) if (isset($t[$k]) && $t[$k] !== '') $c->setAttribute($k, $t[$k]);
    if (strtolower($c->getAttribute('Type')) !== 'path') $c->setAttribute('Default', $t['Default'] ?? '');
    if (strtolower($t['Mask'] ?? '') === 'true') $c->setAttribute('Mask', 'true');
    if ($identity) { $c->setAttribute('Target', $t['Target']); $c->setAttribute('Mode', $t['Mode']); $c->setAttribute('Default', $t['Default'] ?? ''); }
  };
  $shown = fn(DOMElement $c, array $t) => cs_is_masked($c, $t) ? ['hidden', true] : ($c->textContent === '' ? ['empty', true] : [$c->textContent, false]);
  foreach (cs_configs($d) as $c) {
    if (cs_is_disabled($c->getAttribute('Type'), $c->getAttribute('Name'))) continue;
    $k = cs_config_key($c);
    $to = $ops['renames'][$k] ?? null;
    if ($to !== null && isset($R[$to])) {
      $t = $R[$to]; $was = cs_config_label($c); $wasTarget = $c->getAttribute('Target');
      [$v, $note] = $shown($c, $t);
      $take($c, $t, true);
      $rows[] = ['type' => $t['Type'], 'head' => $ops['heads'][$to] ?? 'You answered Yes, same field', 'old' => [$was, $v, $note], 'new' => [cs_config_label($c), $v, $note]];
      if ($webui && strtolower($t['Type']) === 'port') $webui->textContent = str_replace("[PORT:$wasTarget]", '[PORT:' . $t['Target'] . ']', $webui->textContent);
    } elseif (isset($R[$k])) {
      $take($c, $R[$k], false);
    }
  }
  $last = null;
  foreach (cs_configs($d) as $c) $last = $c;
  foreach ($ops['includes'] as $k) {
    if (!isset($R[$k])) continue;
    $t = $R[$k];
    $c = $d->createElement('Config');
    foreach (['Name', 'Target', 'Default', 'Mode', 'Description', 'Type', 'Display', 'Required', 'Mask'] as $a) $c->setAttribute($a, $t[$a]);
    $value = cs_first_option($t['Default']);
    $c->appendChild($d->createTextNode($value));
    $empty = trim($value) === '';
    $req = strtolower($t['Required']) === 'true';
    if ($empty && $req) { $c->setAttribute('Display', 'always'); $top[] = $c; }
    if ($last) $last->parentNode->insertBefore($c, $last->nextSibling); else $d->documentElement->appendChild($c);
    $last = $c;
    $v = strtolower($t['Mask']) === 'true' ? 'hidden' : ($empty ? ($req ? "you fill this in, in Unraid's form" : 'empty') : $value);
    $row = ['type' => $t['Type'], 'head' => $ops['heads'][$k] ?? 'You answered Include it', 'old' => ['', 'not in old settings', true],
            'new' => [cs_config_label($c), $v, $empty || $v === 'hidden'], 'first' => $empty && $req];
    $rows[] = $row;
  }
  foreach (cs_configs($d) as $c) {
    if (!in_array($c, $top, true) && strtolower($c->getAttribute('Required')) === 'true' && trim($c->textContent) === '' && $c->getAttribute('Default') === '') { $c->setAttribute('Display', 'always'); $top[] = $c; }
  }
  $first = cs_configs($d)[0] ?? null;
  foreach (array_reverse($top) as $c) { if ($c !== $first) { $first->parentNode->insertBefore($c, $first); $first = $c; } }
  usort($rows, fn($a, $b) => (int)!empty($b['first']) <=> (int)!empty($a['first']));
  return ['xml' => $d->saveXML(), 'rows' => $rows];
}
/* Which of the newer elements this Unraid's form keeps. postToXML() in the form writes only the elements it knows.
 * <Memory> came with webgui PR #2673 (commit 4d18192fb) and <ExtraNetworks> with PR #2687 (commit 38e559efc), both
 * first in 7.4.0-beta.0.1; 7.3.4 and 6.12 have neither. Read from the form's own code, so a backport counts too. */
function cs_form_keeps(): array {
  $src = (string)@file_get_contents(getenv('CS_DOCKERHELPERS') ?: '/usr/local/emhttp/plugins/dynamix.docker.manager/include/Helpers.php');
  if ($src === '') return ['Memory' => true, 'ExtraNetworks' => true];
  return ['Memory' => strpos($src, '$xml->Memory') !== false, 'ExtraNetworks' => strpos($src, '$xml->ExtraNetworks') !== false];
}
/* What Apply in the form would drop from these settings, with the reason and what to do instead. */
function cs_drop_warnings(string $xml): array {
  $d = new DOMDocument();
  if (!@$d->loadXML($xml, LIBXML_NONET)) return [];
  $have = [];
  foreach ($d->documentElement->childNodes as $n) if ($n instanceof DOMElement && trim($n->textContent) !== '') $have[$n->tagName] = trim($n->textContent);
  $keeps = cs_form_keeps(); $w = [];
  $why = fn($what) => "Unraid's form saves only the fields it knows, so pressing Apply on this Unraid version removes $what from the settings.";
  if (isset($have['Memory']) && !$keeps['Memory'])
    $w[] = 'These settings have a memory limit (' . $have['Memory'] . '), and the form in this Unraid version has no field for it. ' . $why('it')
         . ' The container then runs with no limit. To keep the limit, add --memory=' . $have['Memory'] . ' to Extra Parameters in the form before you press Apply. Unraid 7.4 and newer have the field.';
  if (isset($have['ExtraNetworks']) && !$keeps['ExtraNetworks'])
    $w[] = 'These settings connect the container to more networks (' . $have['ExtraNetworks'] . '), and the form in this Unraid version has no field for them. ' . $why('them')
         . ' The container then has only its main network. After Apply you can connect it again with docker network connect, once per network; that lasts until the container is next recreated. Unraid 7.4 and newer have the field.';
  return $w;
}
/* What the form would change on its own, by field name only. */
function cs_upgrade_warnings(string $xml): array {
  $d = new DOMDocument();
  if (!@$d->loadXML($xml, LIBXML_NONET)) return [];
  $tags = []; $empty = []; $list = [];
  foreach (cs_configs($d) as $c) {
    if (cs_is_disabled($c->getAttribute('Type'), $c->getAttribute('Name'))) continue;
    $v = $c->textContent; $def = $c->getAttribute('Default'); $l = cs_config_label($c);
    if (strpbrk($v, '<>') !== false) $tags[] = $l;
    if ($v === '' && $def !== '' && strtolower($c->getAttribute('Required')) !== 'true') $empty[] = $l;
    if ($v !== '' && strpos($def, '|') !== false && !in_array($v, explode('|', $def), true)) $list[] = $l;
  }
  $w = cs_drop_warnings($xml);
  if ($tags) $w[] = 'Unraid removes < and > from a value when the form loads: ' . implode(', ', $tags) . '. Check these in the form.';
  if ($empty) $w[] = 'Left empty, so Unraid fills in the template default: ' . implode(', ', $empty) . '.';
  if ($list) $w[] = "Unraid's form shows a list here and the value is not on it, so it would pick the first option: " . implode(', ', $list) . '.';
  return $w;
}
function cs_plan_live(array $a, string $savedFile): array {
  $block = []; $warn = [];
  $c = cs_container($a['name']);
  if ($c === null) { $block[] = 'There is no container with this name now, or Docker is not running.'; return [$block, $warn, null]; }
  if ($c['project'] !== '' || ($c['managed'] !== '' && $c['managed'] !== 'dockerman')) {
    $block[] = "This container is managed by Compose, not by Unraid's Docker page, so Unraid's form must not recreate it. Make the change in its Compose file.";
  }
  $d = cs_xml($savedFile);
  $net = '';
  if ($d) foreach ($d->documentElement->childNodes as $n) if ($n instanceof DOMElement && $n->tagName === 'Network') $net = trim($n->textContent);
  $p = cs_network_problem($net);
  if ($p !== '') $block[] = $p;
  if ($c['status'] !== 'running') $warn[] = 'This container is not running now. Apply starts it.';
  $dep = cs_dependents($a['name'], $c['id']);
  if ($dep) $warn[] = 'These containers use the network of this one and need a restart afterwards: ' . implode(', ', $dep) . '.';
  $warn[] = 'Keep the name in the form. A new name there leaves this container as it is and makes a second one.';
  return [$block, $warn, $c];
}
/* The Finish step for one app: what would change. Reads the saved file and nothing is written. */
function cs_plan_app(array $a, array $tpl, array $answers): ?array {
  $file = cs_saved_file($a);
  if ($file === null || !cs_safe_name((string)$a['name'])) return null;
  $ops = cs_upgrade_ops($a, $answers);
  if (!$ops['renames'] && !$ops['includes'] && !$ops['own'] && !$ops['left']) return null;
  if (!$ops['renames'] && !$ops['includes']) return ['name' => $a['name'], 'rows' => [], 'own' => $ops['own'], 'left' => $ops['left'], 'warnings' => [], 'blocks' => [], 'pinned' => $a['pinned'] ?? ''];
  if (cs_stamp($file) !== ($a['stamp'] ?? '')) return ['name' => $a['name'], 'rows' => [], 'own' => [], 'left' => [], 'warnings' => [], 'pinned' => '', 'blocks' => ['Old settings changed since the last check. Press Check now and go through the issues again.']];
  $b = cs_upgrade_build($file, $tpl, $ops);
  if ($b === null) return null;
  [$block, $warn] = cs_plan_live($a, $file);
  return ['name' => $a['name'], 'rows' => $b['rows'], 'own' => $ops['own'], 'left' => $ops['left'], 'warnings' => array_merge($warn, cs_upgrade_warnings($b['xml'])), 'blocks' => $block,
          'pinned' => $a['pinned'] ?? '', 'backupDir' => rtrim(cs_path('config'), '/') . '/backups/' . $a['name'] . '/'];
}

function cs_jobs_locked(callable $fn) {
  @mkdir(cs_path('config'), 0777, true); @mkdir(cs_path('run'), 0755, true);
  $lock = fopen(rtrim(cs_path('run'), '/') . '/upgrades.lock', 'c');
  if ($lock) flock($lock, LOCK_EX);
  $jobs = cs_read_json(cs_jobs_file());
  $r = $fn($jobs);
  cs_write_json(cs_jobs_file(), $jobs);
  if ($lock) fclose($lock);
  return $r;
}
function cs_form_url(string $file): string { return '/Docker/AddContainer?xmlTemplate=' . rawurlencode("user:$file"); }
function cs_baseline(array &$j, string $app, string $file): void {
  $c = cs_container($app);
  $j['baseId'] = $c['id'] ?? ''; $j['baseStamp'] = cs_stamp($file); $j['phase'] = 'form'; $j['since'] = time();
  unset($j['errorFrom'], $j['container'], $j['checked'], $j['differs']);
}
/* Backup first, then the upgraded settings, then the job. The backup of an earlier attempt stands while the saved file
 * has not changed since. */
function cs_backup(string $app, string $file, array $prev): ?array {
  if (!empty($prev['backup']) && is_file($prev['backup']) && ($prev['savedStamp'] ?? '') === cs_stamp($file)) return ['backup' => $prev['backup'], 'taken' => $prev['taken']];
  $dir = rtrim(cs_path('config'), '/') . "/backups/$app";
  if (!is_dir($dir) && !@mkdir($dir, 0777, true)) return null;
  $t = time(); $base = "$dir/my-$app-" . date('Y-m-d-Hi', $t);
  $p = "$base.xml";
  for ($i = 2; file_exists($p); $i++) $p = "$base-$i.xml";
  if (!@copy($file, $p) || md5_file($p) !== md5_file($file)) { @unlink($p); return null; }
  @chmod($p, 0600);
  return ['backup' => $p, 'taken' => $t];
}
function cs_write_private(string $file, string $text): bool {
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
  @chmod($dir, 0700);
  $t = "$file." . getmypid();
  $old = umask(077);
  $ok = @file_put_contents($t, $text) === strlen($text) && @rename($t, $file);
  umask($old);
  return $ok;
}
/* ['url' => ...] or ['error' => ...] */
function cs_upgrade_open(array $a, array $tpl, array $answers): array {
  $app = (string)$a['name'];
  $plan = cs_plan_app($a, $tpl, $answers);
  if ($plan === null) return ['error' => 'There is nothing to upgrade for this app.'];
  if ($plan['blocks']) return ['error' => $plan['blocks'][0]];
  if (!$plan['rows']) return ['error' => 'There is nothing to upgrade for this app.'];
  $file = cs_saved_file($a);
  $b = cs_upgrade_build($file, $tpl, cs_upgrade_ops($a, $answers));
  if ($b === null) return ['error' => 'Old settings could not be read.'];
  return cs_jobs_locked(function (array &$jobs) use ($app, $file, $b) {
    $prev = $jobs[$app] ?? [];
    $saved = cs_backup($app, $file, $prev);
    if ($saved === null) return ['error' => 'Old settings could not be saved to the flash drive, so nothing was opened.'];
    $target = cs_upgrade_file($app);
    if (!cs_write_private($target, $b['xml'])) return ['error' => 'The upgraded settings could not be written.'];
    $j = ['file' => basename($file), 'backup' => $saved['backup'], 'taken' => $saved['taken'], 'savedStamp' => cs_stamp($file), 'attempt' => 'upgraded'];
    cs_baseline($j, $app, $file);
    $jobs[$app] = $j;
    return ['url' => cs_form_url($target)];
  });
}
/* After a failed Apply, or from the app's row. */
function cs_upgrade_retry(string $app, string $which, bool $confirmed = false): array {
  if (!cs_safe_name($app) || !in_array($which, ['upgraded', 'old'], true)) return ['error' => 'invalid app'];
  return cs_jobs_locked(function (array &$jobs) use ($app, $which, $confirmed) {
    if (!isset($jobs[$app])) return ['error' => 'Nothing is known about this app.'];
    $j = &$jobs[$app];
    $saved = rtrim(cs_path('templates'), '/') . '/' . basename((string)$j['file']);
    $open = $which === 'old' ? (string)$j['backup'] : cs_upgrade_file($app);
    if ($which === 'upgraded' && !is_file($open)) {
      if (($j['attempt'] ?? '') !== 'upgraded' || !is_file($saved)) return ['error' => 'The upgraded settings are gone: they stay in memory only, until a restart. Work through the issues again.'];
      $open = $saved;
    }
    if (!is_file($open)) return ['error' => 'The file is not there any more: ' . $open];
    if (!$confirmed && ($w = cs_drop_warnings((string)@file_get_contents($open)))) return ['warn' => $w];
    $prior = array_intersect_key($j, array_flip(['phase', 'attempt', 'baseId', 'baseStamp', 'container', 'errorFrom', 'checked', 'differs']));
    cs_baseline($j, $app, $saved);
    $j['attempt'] = $which; $j['prior'] = $prior;
    return ['url' => cs_form_url($open)];
  });
}
/* Backups of apps with no saved settings and no container left, newest first. An app with a job on screen is not one. */
function cs_gone_backups(array $jobs): array {
  $out = [];
  $root = rtrim(cs_path('config'), '/') . '/backups';
  foreach ((array)@scandir($root) as $app) {
    if (!is_string($app) || !cs_safe_name($app) || !is_dir("$root/$app")) continue;
    if (in_array($jobs[$app]['phase'] ?? '', ['form', 'working', 'failed'], true)) continue;
    if (is_file(rtrim(cs_path('templates'), '/') . "/my-$app.xml") || cs_container($app) !== null) continue;
    $files = [];
    foreach ((array)glob("$root/$app/my-*.xml") as $p) $files[] = ['file' => basename($p), 'path' => $p, 'taken' => (int)@filemtime($p)];
    if (!$files) continue;
    usort($files, function ($a, $b) { return $b['taken'] <=> $a['taken'] ?: strcmp($b['file'], $a['file']); });
    $out[] = ['app' => $app, 'files' => $files];
  }
  return $out;
}
/* Opens a gone app's backup in Unraid's form. Nothing is written until Apply. */
function cs_upgrade_putback(string $app, string $file, bool $confirmed = false): array {
  if (!cs_safe_name($app) || basename($file) !== $file || !preg_match('/^my-.+\.xml$/', $file)) return ['error' => 'invalid app'];
  $open = rtrim(cs_path('config'), '/') . "/backups/$app/$file";
  if (!is_file($open)) return ['error' => 'The file is not there any more: ' . $open];
  if (!$confirmed && ($w = cs_drop_warnings((string)@file_get_contents($open)))) return ['warn' => $w];
  return cs_jobs_locked(function (array &$jobs) use ($app, $open) {
    $saved = rtrim(cs_path('templates'), '/') . "/my-$app.xml";
    if (is_file($saved) || cs_container($app) !== null) return ['error' => 'This app is back already. Press Check now.'];
    $j = ['file' => "my-$app.xml", 'backup' => $open, 'taken' => (int)@filemtime($open), 'savedStamp' => '', 'attempt' => 'old', 'putback' => true];
    cs_baseline($j, $app, $saved);
    $jobs[$app] = $j;
    return ['url' => cs_form_url($open)];
  });
}
function cs_upgrade_cancel(string $app): bool {
  if (!cs_safe_name($app)) return false;
  return cs_jobs_locked(function (array &$jobs) use ($app) {
    if (($jobs[$app]['phase'] ?? '') !== 'form') return false;
    if (empty($jobs[$app]['prior'])) { @unlink(cs_upgrade_file($app)); unset($jobs[$app]); return true; }
    $jobs[$app] = array_merge($jobs[$app], $jobs[$app]['prior']);
    unset($jobs[$app]['prior']);
    return true;
  });
}

/* ExtraParams as the shell would split them, or null when they use the shell for more than that. */
function cs_split_args(string $s): ?array {
  $out = []; $cur = ''; $in = false; $q = '';
  for ($i = 0, $n = strlen($s); $i < $n; $i++) {
    $ch = $s[$i];
    if ($q !== '') {
      if ($ch === $q) $q = '';
      elseif ($ch === '\\' && $q === '"' && $i + 1 < $n) $cur .= $s[++$i];
      else $cur .= $ch;
    } elseif ($ch === '\'' || $ch === '"') { $q = $ch; $in = true; }
    elseif ($ch === '\\' && $i + 1 < $n) { $cur .= $s[++$i]; $in = true; }
    elseif (ctype_space($ch)) { if ($in) { $out[] = $cur; $cur = ''; $in = false; } }
    elseif (strpos(';|&$`<>()', $ch) !== false) return null;
    else { $cur .= $ch; $in = true; }
  }
  if ($q !== '') return null;
  if ($in) $out[] = $cur;
  return $out;
}
/* No -e value goes on this command line, and the temporary name stays the only name. */
function cs_recheck_args(array $args): array {
  $out = [];
  for ($i = 0, $n = count($args); $i < $n; $i++) {
    $x = $args[$i];
    if (in_array($x, ['-e', '--env', '--env-file', '--name'], true)) { $i++; continue; }
    if (preg_match('/^(-e.+|--env=.*|--env-file=.*|--name=.*)$/', $x)) continue;
    $out[] = $x;
  }
  return $out;
}
function cs_docker_error(string $text): string {
  $keep = [];
  foreach (explode("\n", trim($text)) as $l) {
    $l = trim($l);
    if ($l === '' || preg_match('/^(See \'|Run \'|Usage:|Unable to find image)/', $l)) continue;
    $keep[] = preg_replace('/^(docker: )?(Error response from daemon: |Error: )?/', '', $l);
  }
  return implode(' ', $keep);
}
/* Unraid keeps no error when the container is missing, so the image and the extra parameters are checked again. The
 * temporary container holds the image and the extra parameters only, is never started and is removed at once. */
function cs_recheck(string $file): array {
  $d = cs_xml($file);
  if ($d === null) return ['', ''];
  $image = ''; $extra = '';
  foreach ($d->documentElement->childNodes as $n) {
    if (!($n instanceof DOMElement)) continue;
    if ($n->tagName === 'Repository') $image = trim($n->textContent);
    if ($n->tagName === 'ExtraParams') $extra = trim($n->textContent);
  }
  if ($image === '' || $image[0] === '-') return ['', ''];
  [$rc] = cs_run([cs_docker(), 'image', 'inspect', '--format', '{{.Id}}', $image]);
  if ($rc !== 0) {
    [$rc, , $e] = cs_run([cs_docker(), 'manifest', 'inspect', $image], 30);
    if ($rc !== 0 && $rc !== 124) { $t = cs_docker_error($e); if ($t !== '') return ['image', $t]; }
  }
  if ($extra === '') return ['', ''];
  $args = cs_split_args($extra);
  if ($args === null) return ['', ''];
  $tmp = 'chronostasis-recheck-' . bin2hex(random_bytes(4));
  [$rc, $o, $e] = cs_run(array_merge([cs_docker(), 'create', '--pull', 'never', '--name', $tmp], cs_recheck_args($args), [$image]), 30);
  $id = trim($o);
  cs_run([cs_docker(), 'rm', '-f', $id !== '' && $rc === 0 ? $id : $tmp]);
  if ($rc === 0) return ['', ''];
  $t = cs_docker_error($e);
  return $t === '' || stripos($t, 'No such image') !== false ? ['', ''] : ['params', $t];
}
/* Field names where what Unraid saved differs from the upgraded settings. */
function cs_saved_differs(string $saved, string $want): array {
  $a = cs_xml($saved); $b = cs_xml($want);
  if ($a === null || $b === null) return [];
  $have = [];
  foreach (cs_configs($a) as $c) $have[cs_config_key($c)] = $c->textContent;
  $out = [];
  foreach (cs_configs($b) as $c) {
    $k = cs_config_key($c);
    if (!array_key_exists($k, $have) || ($c->textContent !== '' && $have[$k] !== $c->textContent)) $out[] = cs_config_label($c);
  }
  return $out;
}
/* Where one job stands. Called while the page is open: Apply happens in another tab and tells nobody. Apply writes the
 * template first and removes the container after that, so a change of either means Apply was pressed. */
function cs_job_step(array &$j, string $app): void {
  $phase = $j['phase'] ?? '';
  if (!in_array($phase, ['form', 'working', 'failed'], true)) return;
  $saved = rtrim(cs_path('templates'), '/') . '/' . basename((string)$j['file']);
  $c = cs_container($app);
  $stamp = cs_stamp($saved);
  $id = $c['id'] ?? '';
  if ($stamp === ($j['baseStamp'] ?? '') && $id === ($j['baseId'] ?? '')) return;
  $wrote = $stamp !== ($j['baseStamp'] ?? '') ? (int)@filemtime($saved) : (int)($j['since'] ?? time());
  $settled = time() - $wrote >= cs_settle('CS_SETTLE', CS_SETTLE);
  $new = $id !== '' && $id !== ($j['baseId'] ?? '');
  if ($c !== null && $new && $c['status'] === 'running') {
    $up = strtotime(preg_replace('/\.\d+Z$/', 'Z', $c['started']));
    if ($up !== false && time() - $up < cs_settle('CS_UP', CS_UP)) { if ($phase === 'form') $j['phase'] = 'working'; return; }
    $done = ($j['attempt'] ?? '') === 'old' ? 'restored' : 'applied';
    $file = cs_upgrade_file($app);
    if ($done === 'applied' && is_file($file)) $j['differs'] = cs_saved_differs($saved, $file);
    @unlink($file);
    $j['phase'] = $done; $j['outcome'] = $done; $j['when'] = time();
    unset($j['errorFrom'], $j['container'], $j['checked'], $j['prior']);
    @unlink(cs_error_file($app));
    return;
  }
  $state = $c === null ? 'missing' : ($new ? ($c['status'] === 'created' ? 'created' : 'stopped') : 'unchanged');
  /* with a pull under way the old container stands for as long as the pull takes */
  if ($state === 'unchanged') $settled = time() - $wrote >= 8 * cs_settle('CS_SETTLE', CS_SETTLE);
  if (!$settled) { if ($phase === 'form') $j['phase'] = 'working'; return; }
  $key = "$stamp|$id";
  if (($j['checked'] ?? '') !== $key) {
    $j['checked'] = $key;
    [$from, $text] = ['', ''];
    if ($state === 'created' && trim($c['error']) !== '') [$from, $text] = ['docker', trim($c['error'])];
    elseif ($state === 'stopped') [$from, $text] = ['', ''];
    else [$from, $text] = cs_recheck($saved);
    $j['errorFrom'] = $from;
    cs_write_private(cs_error_file($app), $text);
    /* what the form sent, your changes in it included, is what Try again opens */
    if (($j['attempt'] ?? '') === 'upgraded' && is_file($saved)) { $x = @file_get_contents($saved); if ($x !== false) cs_write_private(cs_upgrade_file($app), $x); }
  }
  $j['container'] = $state; $j['phase'] = 'failed';
}
/* All jobs for the page: names, times, paths and Docker's error text. No value. */
function cs_jobs_state(): array {
  return cs_jobs_locked(function (array &$jobs) {
    $out = [];
    foreach ($jobs as $app => &$j) {
      cs_job_step($j, (string)$app);
      $v = array_intersect_key($j, array_flip(['phase', 'attempt', 'backup', 'taken', 'when', 'container', 'errorFrom', 'differs', 'outcome', 'putback']));
      $v['app'] = (string)$app;
      $v['error'] = ($j['phase'] ?? '') === 'failed' ? (string)@file_get_contents(cs_error_file((string)$app)) : '';
      $out[] = $v;
    }
    return $out;
  });
}
