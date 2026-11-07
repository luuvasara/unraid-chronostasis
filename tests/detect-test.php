<?php
/* Offline tests of include/detect.php and include/share.php. */
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-chronostasis';
require "$P/include/detect.php"; require "$P/include/share.php";
if (is_file("$P/include/value.php")) require "$P/include/value.php";
$fail = 0; $n = 0;
function t(bool $c, string $m): void { global $fail, $n; $n++; if ($c) echo "ok   $m\n"; else { echo "FAIL $m\n"; $fail = 1; } }
$F = __DIR__ . '/fixtures';
$idx = cs_index(json_decode(file_get_contents("$F/feed.json"), true));
$T = [];
foreach (glob("$F/templates/my-*.xml") as $f) { $x = cs_read_template($f); $T[$x['name']] = $x; }
$S = fn($n) => $T[$n];
$A = []; foreach ($T as $k => $v) $A[$k] = cs_app($v, $idx, $k === 'dead' ? 404 : 200);
$kinds = fn($a, $k) => array_column(array_values(array_filter($a['findings'], fn($f) => $f['kind'] === $k)), 'label');

t(cs_url_key('https://raw.githubusercontent.com/A/b/master/x.xml') === cs_url_key('https://raw.githubusercontent.com/a/B/main/x.xml'), 'a branch rename is the same address');
t(cs_url_key('https://github.com/a/b/blob/main/x.xml') === 'a/b/x.xml', 'a github.com blob link is the same address');
t(cs_repo_full('docker.io/library/nginx:1.2') === 'nginx', 'repository: docker.io, library and tag removed');
t(cs_repo_full('reg.example:5000/a/b:1') === 'reg.example:5000/a/b', 'repository: a registry port is not a tag');
t(cs_repo_base('ghcr.io/binhex/arch-sonarr') === 'binhex/arch-sonarr', 'base: ghcr.io removed');
t(cs_field_key('Path', '/data/', 'x', 'rw') === cs_field_key('Path', '/data', 'y', 'ro'), 'path key ignores a trailing slash');
t(cs_field_key('Port', '80', '', 'TCP') === 'port:80/tcp', 'port key has the protocol');
t(cs_field_key('Device', '', 'GPU', '') === 'device:name:GPU', 'a device with no target is keyed by name');

t($A['alpha']['how'] === 'address' && $A['alpha']['confidence'] === 'high', 'match by address across master/main');
t($A['alpha']['address'] === 'same', 'master vs main is not an address finding');
t($kinds($A['alpha'], 'new') === ['NEW_VAR'], 'new field found');
t(in_array('OLD_VAR', $kinds($A['alpha'], 'yours'), true), 'field only in the saved copy found');
t($kinds($A['alpha'], 'default') === ['LOG_LEVEL'], 'a changed default is found on a Variable');
t(!in_array('API_TOKEN', $kinds($A['alpha'], 'default'), true), 'a Mask field is never compared by default');
t(!in_array('/config', $kinds($A['alpha'], 'default'), true), 'Path defaults are left out');
$on = ['cron' => ['dockerCronFrequency' => 'daily'], 'global' => ['dockerUpdateAll' => 'no'], 'containers' => ['alpha' => ['name' => 'alpha', 'update' => true]]];
t(cs_auto_updated($on, 'alpha') && !cs_auto_updated($on, 'beta'), 'auto update: the listed app only');
$on2 = $on; $on2['global']['dockerUpdateAll'] = 'yes';
t(cs_auto_updated($on2, 'beta'), 'auto update: update all covers every app');
$on3 = $on; $on3['cron']['dockerCronFrequency'] = 'disabled';
t(!cs_auto_updated($on3, 'alpha'), 'auto update: no schedule means nothing runs');
t(!cs_auto_updated([], 'alpha'), 'auto update: works without that plugin');
$rep = cs_apply_dismissed(['apps' => [['name' => 'a', 'findings' => [['key' => 'x', 'fp' => 'y', 'kind' => 'new', 'label' => 'x', 'warn' => true]], 'confidence' => 'high', 'feedUrl' => '', 'savedUrl' => ''],
  ['name' => 'b', 'autoUpdated' => true, 'findings' => [['key' => 'x2', 'fp' => 'y2', 'kind' => 'new', 'label' => 'x2', 'warn' => true]], 'confidence' => 'high', 'feedUrl' => '', 'savedUrl' => ''],
  ['name' => 'c', 'autoUpdated' => true, 'findings' => [], 'confidence' => 'high', 'feedUrl' => '', 'savedUrl' => '']]], []);
t(array_column($rep['apps'], 'name') === ['b', 'a', 'c'] && $rep['counts']['autoDiffer'] === 1, 'auto update: an app with something to check is listed first');
$rq2 = cs_apply_dismissed(['apps' => [['name' => 'quiet', 'autoUpdated' => true, 'findings' => [['key' => 'x', 'fp' => 'y', 'kind' => 'yours', 'label' => 'x']], 'confidence' => 'high', 'feedUrl' => '', 'savedUrl' => ''],
  ['name' => 'warned', 'findings' => [['key' => 'x', 'fp' => 'y', 'kind' => 'new', 'label' => 'x', 'warn' => true]], 'confidence' => 'high', 'feedUrl' => '', 'savedUrl' => '']]], []);
t(array_column($rq2['apps'], 'name') === ['warned', 'quiet'] && $rq2['counts']['autoDiffer'] === 0, 'auto update: only other differences do not jump the queue');
t(cs_field_key('Disabled:Port', '9090', '', 'tcp') === cs_field_key('Port', '9090', '', 'tcp'), 'Disabled:Port keys as the same port');
t(cs_field_key('disabled:Variable', 'A', '', '') === 'variable:A', 'the Disabled: prefix ignores case');
t(!empty($T['alpha']['fields']['port:9090/tcp']['disabled']) && $T['alpha']['fields']['port:9090/tcp']['type'] === 'Port', 'a disabled field keeps its real type');
$allLabels = array_column($A['alpha']['findings'], 'label');
t(!in_array('9090/tcp', $allLabels, true), 'a disabled field in the template gives no finding of any kind');
t(!in_array('OFF_GONE', $allLabels, true), 'a disabled field gone from the template gives no finding');
t(array_values(array_intersect(['9090/tcp', 'OFF_GONE'], $A['alpha']['disabledFields'])) === ['9090/tcp', 'OFF_GONE'], 'disabled fields are listed as turned off');
$noDef = $T['alpha']; $noDef['fields']['variable:LOG_LEVEL']['default'] = sha1('');
t(isset($T['alpha']['fields']['variable:LOG_LEVEL']) && $kinds(cs_app($noDef, $idx, 200), 'default') === [], 'a saved copy with no default is not compared');
$new = array_values(array_filter($A['alpha']['findings'], fn($f) => $f['kind'] === 'new'))[0];
t($new['required'] === true, 'a required new field is marked');

/* the warning rule, on the five shapes measured on real templates. A field warning: warn mark or a renamed variable */
$warned = fn($a) => array_values(array_map(fn($f) => ($f['kind'] === 'renamed' ? ($f['from'] ?? '') . '>' : '') . $f['label'],
  array_filter($a['findings'], fn($f) => $f['kind'] === 'renamed' || !empty($f['warn']))));
t($warned($A['shape-required-path']) === ['/data'], 'shape: a required Path with no default, missing: warning');
t($warned($A['shape-required-path-default']) === [] && $kinds($A['shape-required-path-default'], 'new') === ['/app/logs'], 'shape: a required Path with a default, missing: no warning, still listed');
t($warned($A['shape-optional-field']) === [] && $kinds($A['shape-optional-field'], 'new') === ['/data/image-cache'], 'shape: an optional field, missing: no warning, still listed');
t($A['shape-variable-target']['findings'] === [] && $A['shape-variable-target']['moved'] === [['name' => 'Email - From Address', 'type' => 'Variable', 'saved' => 'FROM_EMAIL_ADDRESS', 'template' => 'FROM_ADDRESS']] , 'shape: a Variable with a new Target is no finding, listed once as moved');
t($warned($A['shape-default-only']) === [] && $kinds($A['shape-default-only'], 'default') === ['LOG_LEVEL'], 'shape: differing Default only: no warning, still listed');

/* edges of the rule, one app each */
$pair = function (string $saved, array $feedCfg, array $extra = []) {
  $file = tempnam(sys_get_temp_dir(), 'cs');
  file_put_contents($file, '<?xml version="1.0"?><Container version="2"><Name>x</Name><Repository>e/x</Repository><Network>' . ($extra['network'] ?? 'bridge') . "</Network><TemplateURL>false</TemplateURL>$saved</Container>");
  $tpl = cs_read_template($file); unlink($file);
  $cfg = array_map(fn($c) => ['@attributes' => $c + ['Name' => 'N', 'Target' => '/t', 'Default' => '', 'Mode' => '', 'Type' => 'Path', 'Required' => 'false', 'Mask' => 'false']], $feedCfg);
  return cs_app($tpl, cs_index(['applist' => [['Name' => 'X', 'Repository' => 'e/x', 'TemplateURL' => 'https://raw.githubusercontent.com/e/x/main/x.xml', 'Config' => $cfg]]]), 200, isset($extra['history']) ? ['e/x/x.xml' => $extra['history']] : []);
};
$c = fn($n, $tg, $ty, $mode = '', $def = '') => "<Config Name=\"$n\" Target=\"$tg\" Default=\"$def\" Mode=\"$mode\" Type=\"$ty\" Required=\"false\" Mask=\"false\">SECRETVALUE9876</Config>";
$rq = ['Required' => 'true'];
t($warned($pair('', [$rq + ['Target' => 'KEY', 'Type' => 'Variable']])) === ['KEY'], 'a required Variable with no default, missing: warning');
t($warned($pair('', [$rq + ['Target' => '8080', 'Type' => 'Port', 'Mode' => 'tcp']])) === ['8080/tcp'], 'a required Port with no default, missing: warning');
t($warned($pair($c('Web', '8080', 'Port', 'tcp'), [$rq + ['Name' => 'Web', 'Target' => '8080', 'Type' => 'Port', 'Mode' => 'udp']])) === [], 'a required Port under the same Name with another protocol: no warning (a moved Port)');
t($warned($pair('', [$rq + ['Target' => '/dev/dri', 'Type' => 'Device']])) === [], 'a required Device: no warning');
t($warned($pair('', [$rq + ['Target' => '/d', 'Default' => '  ']])) === ['/d'], 'a Default of spaces is empty');
t($warned($pair('', [$rq + ['Target' => 'K', 'Type' => 'Variable', 'Mask' => 'true', 'Default' => 'x']])) === [], 'a masked required field with a default: no warning');
t($warned($pair('', [$rq + ['Target' => 'K', 'Type' => 'Variable', 'Mask' => 'true']])) === ['K'], 'a masked required field with no default: warning');
t($warned($pair('', [$rq + ['Target' => '/x', 'Type' => 'Disabled:Path']])) === [], 'a required Disabled: type in the template: no warning');
t($warned($pair('', [$rq + ['Name' => 'Disabled: Z', 'Target' => 'Z', 'Type' => 'Variable']])) === [], 'a required "Disabled:" name in the template: no warning');
t($warned($pair($c('Off', 'Z', 'Disabled:Variable'), [$rq + ['Name' => 'Off', 'Target' => 'Z', 'Type' => 'Variable']])) === [], 'a field turned off in the copy is there: no warning');
$off = $pair($c('Off', 'OLD', 'Disabled:Variable'), [$rq + ['Name' => 'Off', 'Target' => 'NEW', 'Type' => 'Variable', 'Default' => '1']]);
t($warned($off) === [] && $off['disabledFields'] === ['OLD'], 'a field turned off in the copy is no rename');
t($pair($c('Disabled: Old', 'OLD', 'Variable'), [['Target' => '/other']])['disabledFields'] === ['OLD'], 'a "Disabled:" name in the copy is turned off');
$mv = $pair($c('Media', '/Unite/Media', 'Path', 'rw'), [$rq + ['Name' => 'Media', 'Target' => '/movies', 'Mode' => 'rw']]);
t($mv['findings'] === [] && $mv['moved'] === [['name' => 'Media', 'type' => 'Path', 'saved' => '/Unite/Media', 'template' => '/movies']], 'a Path under the same Name at another Target: no finding, no gap, listed once as moved');
$mvv = $pair($c('Same', 'OLD', 'Variable'), [['Name' => 'same ', 'Target' => 'NEW', 'Type' => 'Variable']]);
t($mvv['findings'] === [] && array_column($mvv['moved'], 'saved') === ['OLD'], 'a Variable with a new Target: moved, not a finding; the Name ignores case and spaces');
t($warned($pair($c('', 'OLD', 'Variable'), [['Name' => '', 'Target' => 'NEW', 'Type' => 'Variable']])) === [], 'an empty Name pairs with nothing');
$two = $pair($c('Same', 'OLD', 'Variable'), [['Name' => 'Same', 'Target' => 'A', 'Type' => 'Variable'], ['Name' => 'Same', 'Target' => 'B', 'Type' => 'Variable']]);
t(array_column($two['moved'], 'template') === ['A'] && $kinds($two, 'new') === ['B'], 'one saved field pairs once');
$dv = $pair($c('Disk 1', '/dev/sda', 'Device'), [['Name' => 'Disk 1', 'Target' => '', 'Type' => 'Device']]);
t($dv['findings'] === [] && $dv['moved'] === [], 'a Device the template leaves for you to fill in: no finding, no line');
t($warned($pair('', [$rq + ['Target' => '9000', 'Type' => 'Port', 'Mode' => 'tcp']], ['network' => 'container:vpn'])) === [], 'a required Port of a container-networked app does not apply: no warning');
t($warned($pair($c('Same', 'K', 'Variable'), [['Name' => 'Same', 'Target' => 'K', 'Type' => 'Variable', 'Required' => 'true']])) === [], 'a field that became required but is there: no warning');

/* image, address and moderation findings stay what the summary counts; field differences are quiet by default */
$rc = cs_apply_dismissed(['apps' => array_values($A)], []);
$byName = array_column($rc['apps'], null, 'name');
$checked = fn($n) => array_column(array_values(array_filter($byName[$n]['findings'], fn($f) => !empty($f['check']))), 'kind');
t($checked('moved') === ['address', 'moderation', 'image'], 'moved image, other address and a moderator comment are all to check');
t($checked('dead') === ['address', 'moderation'], 'a dead address and deprecated are to check');
t($checked('alpha') === [], 'field differences with no warning are not to check');
t(count($byName['alpha']['findings']) > 0, 'and they are still in the report');
t($checked('shape-required-path') === ['new'] && $checked('shape-variable-target') === [], 'a required gap is to check, a moved field is not');
$all = 0; foreach ($rc['apps'] as $a) $all += count($a['findings']);
t(($rc['counts']['issues'] ?? null) === 7 && ($rc['counts']['issueApps'] ?? null) === 4, 'summary: 7 to check on 4 apps');
t(($rc['counts']['others'] ?? null) === $all - 7, 'summary: every other finding is counted as an other difference');
t(($rc['counts']['otherApps'] ?? null) === 6, 'summary: apps with other differences');
t($rc['counts']['differ'] === 9, 'summary: apps that differ at all: the moved-only app is the same now');
$pos = array_flip(array_column($rc['apps'], 'name'));
t($pos['moved'] < $pos['alpha'] && $pos['shape-required-path'] < $pos['shape-optional-field'], 'apps with something to check are listed before apps with only other differences');
$dm = cs_apply_dismissed(['apps' => array_values($A)], array_column(array_map(fn($f) => ['id' => $f['id'], 'fp' => $f['fp']], $byName['shape-required-path']['findings']), null, 'id'));
t(($dm['counts']['issues'] ?? null) === 6, 'a dismissed warning leaves the count');
$wt = cs_share_text($rc, [], [], '2026.11.05', '7.3.4');
t(strpos($wt, 'FROM_EMAIL_ADDRESS') === false && strpos($wt, 'Path /data (required, no default)') !== false, 'the share text names the required gap, not a moved field');

t($kinds($A['child'], 'new') === [] && $A['child']['notApplicable'] === ['9000/tcp'], 'ports of a container-networked app are not findings');
t(cs_is_container_net('bridge', '--network=container:vpn') === true, 'container network in Extra Parameters');

t($A['dup']['confidence'] === 'none' && $A['dup']['how'] === 'several templates' && $A['dup']['candidates'] === 2, 'several templates, nothing to tell them apart: no match');
$d2 = $S('dup'); $d2['support'] = 'https://b.example/s';
t(cs_match($d2, $idx)['entry']['Name'] === 'Dup B', 'the Support address settles it');
t($A['dup']['address'] === 'false' && $kinds($A['dup'], 'address') === [], 'TemplateURL false is respected');

t($A['moved']['how'] === 'image on another registry' && $A['moved']['confidence'] === 'low', 'moved image: low confidence');
t($kinds($A['moved'], 'image') === ['ghcr.io/maker/moved'], 'the image difference is shown');
t($A['moved']['address'] === 'other', 'a saved address that is not the feed one is noticed');
$mc = array_values(array_filter($A['moved']['findings'], fn($f) => $f['kind'] === 'moderation'));
t(count($mc) === 1 && strpos($mc[0]['text'], '<') === false, 'a moderator comment loses its markup');

t($A['dead']['address'] === 'dead', 'a 404 address is dead');
t(in_array('Deprecated', array_column($A['dead']['findings'], 'label'), true), 'deprecated app is reported');
t($A['gone']['how'] === 'not found', 'no template found');
t(cs_read_template("$F/feed.json") === null, 'a file that is not a template is skipped');

/* dismissing */
$rep = ['apps' => array_values($A)];
$r1 = cs_apply_dismissed($rep, []);
$al = array_values(array_filter($r1['apps'], fn($a) => $a['name'] === 'alpha'))[0];
$f0 = $al['findings'][0];
$r2 = cs_apply_dismissed($rep, [$f0['id'] => ['fp' => $f0['fp']]]);
$al2 = array_values(array_filter($r2['apps'], fn($a) => $a['name'] === 'alpha'))[0];
t(count($al2['findings']) === count($al['findings']) - 1 && count($al2['dismissed']) === 1, 'a dismissed item is hidden');
$r3 = cs_apply_dismissed($rep, [$f0['id'] => ['fp' => 'changed']]);
$al3 = array_values(array_filter($r3['apps'], fn($a) => $a['name'] === 'alpha'))[0];
t(count($al3['findings']) === count($al['findings']), 'it returns when the template changes that item');
t($r1['counts']['differ'] > 0 && $r1['counts']['apps'] === count($A), 'counts');

/* the wording */
foreach ($r1['apps'] as $a) foreach ($a['findings'] as $f) {
  t(!preg_match('/\b(wrong|incorrect|outdated|out of date)\b/i', $f['why']), "diagnosis does not call the copy wrong ({$f['kind']})");
}
t(cs_history_url('https://raw.githubusercontent.com/a/b/main/t/x.xml') === 'https://github.com/a/b/commits/main/t/x.xml', 'template history link');

/* a value cannot reach the text to share */
$json = json_encode($rep);
$text = cs_share_text($r1, [], [
  ['type' => 'field', 'from' => 'OLD_VAR', 'to' => 'NEW_VAR', 'reason' => 'field-rename', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05'],
  ['type' => 'field', 'from' => 'SECRETVALUE9876 x', 'to' => 'NEW_VAR', 'reason' => 'field-rename', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05'],
  ['type' => 'field', 'from' => '/mnt/user/PrivateShareName', 'to' => 'NEW_VAR', 'reason' => 'field-rename', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05'],
  ['type' => 'field', 'from' => 'A', 'to' => '10.77.0.88', 'reason' => 'field-rename', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05'],
  ['type' => 'field', 'from' => 'A', 'to' => 'B', 'reason' => 'nonsense', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05'],
  ['type' => 'field', 'from' => 'A', 'to' => 'B', 'reason' => 'rename', 'source' => 'https://git.homebox.lan/x', 'date' => '2026-11-05'],
], '2026.11.05', '7.3.4');
foreach (['SECRETPORT1234', 'SECRETVALUE9876', 'MASKVALUE7777', 'MASKDEFAULT5555', 'feedmask', '10.77.0.88', 'hostbox.lan', 'homebox', 'PrivateShareName', '/mnt/user', 'alert('] as $v) {
  t(strpos($text, $v) === false, "share text does not contain $v");
}
foreach (['SECRETPORT1234', 'SECRETVALUE9876', 'MASKVALUE7777', 'MASKDEFAULT5555'] as $v) t(strpos($json, $v) === false, "report does not contain $v");
t(strpos($text, 'NEW_VAR') !== false && strpos($text, 'Plugin 2026.11.05, Unraid 7.3.4') !== false, 'share text has field names and versions');
t(cs_safe_image('registry.homebox.lan:5000/me/app:1') === '(private image)' && cs_safe_image('ghcr.io/a/b:1') === 'ghcr.io/a/b:1' && cs_safe_image('linuxserver/plex') === 'linuxserver/plex', 'a private registry is hidden');
t(substr_count($text, '"reason"') === 1, 'only the valid mapping passes');
t(strpos($text, '"from": "OLD_VAR"') !== false && strpos($text, '"date": "2026-11-05"') !== false, 'mapping has the schema fields');
t(cs_safe_field('/mnt/user/Movies') === '(path)' && cs_safe_field('host.example.com') === '(hidden)' && cs_safe_field('a b') === '(hidden)' && cs_safe_field('/app/config/logs') === '/app/config/logs', 'field name filter');
t(cs_safe_url('https://user:pw@github.com/a') === '' && cs_safe_url('http://github.com/a') === '' && cs_safe_url('https://github.com/a?x=1') === 'https://github.com/a', 'address filter');
/* one field's value, on request: never in the report or the text to copy */
$TD = "$F/templates";
$val = fn($r, $app, $key) => function_exists('cs_field_value') ? cs_field_value($r, $app, $key, $TD) : 'no such function';
$repo = ['apps' => array_values($A)];
t($A['shape-pair']['file'] === 'my-shape-pair.xml', 'the report names the template file');
t(($val($repo, 'shape-pair', 'variable:MOVIE_DIR')['value'] ?? null) === '/movies', 'a Variable value on request');
t(($val($repo, 'shape-pair', 'port:8088/tcp')['value'] ?? null) === '18088', 'a Port gives the host port');
t(($val($repo, 'shape-pair', 'path:/media')['value'] ?? null) === '/mnt/user/FakeMedia', 'a Path gives the host path');
t(($val($repo, 'shape-pair', 'variable:LOG_LEVEL')['value'] ?? null) === 'FAKEVALUE-level', 'a default change gives the saved value');
t($val($repo, 'shape-variable-target', 'variable:FROM_EMAIL_ADDRESS') === null, 'a moved field is no issue, so its value is not given');
$h = $val($repo, 'shape-pair', 'variable:OLD_TOKEN');
t(is_array($h) && $h === ['state' => 'hidden'], 'a masked field is hidden, with no value in the answer');
$fake = $repo; foreach ($fake['apps'] as &$ap) if ($ap['name'] === 'shape-pair') foreach ($ap['findings'] as &$ff) if ($ff['key'] === 'variable:OLD_TOKEN') $ff['mask'] = false; unset($ap, $ff);
t($val($fake, 'shape-pair', 'variable:OLD_TOKEN') === ['state' => 'hidden'], 'a masked field stays hidden from the file itself, whatever the report says');
$fm = $repo; foreach ($fm['apps'] as &$ap) if ($ap['name'] === 'shape-pair') foreach ($ap['findings'] as &$ff) if ($ff['key'] === 'variable:MOVIE_DIR') $ff['mask'] = true; unset($ap, $ff);
t($val($fm, 'shape-pair', 'variable:MOVIE_DIR') === ['state' => 'hidden'], 'a field the template masks is hidden');
t($val($repo, 'shape-pair', 'variable:NOPE') === null, 'an unknown field is refused');
t($val($repo, 'shape-pair', 'path:/config') === null, 'a field that is no issue is refused');
t($val($repo, 'shape-pair', 'variable:MOVIES_DIR') === null, 'a field only in the template is refused');
t($val($repo, 'nobody', 'variable:MOVIE_DIR') === null, 'an unknown app is refused');
t($val($repo, '../../../etc/passwd', 'variable:MOVIE_DIR') === null, 'a path as app is refused');
foreach (['../my-shape-pair.xml', '/etc/passwd', 'feed.json', '', 'my-gone-for-good.xml'] as $bad) {
  $tm = ['apps' => [['name' => 'x', 'file' => $bad, 'findings' => [['kind' => 'yours', 'key' => 'variable:MOVIE_DIR']]]]];
  t($val($tm, 'x', 'variable:MOVIE_DIR') === null, "a report naming \"$bad\" is refused");
}
$rj = json_encode(cs_apply_dismissed($repo, []));
$st = cs_share_text(cs_apply_dismissed($repo, []), [], [['type' => 'field', 'from' => 'MOVIE_DIR', 'to' => 'MOVIES_DIR', 'reason' => 'field-rename', 'source' => 'https://github.com/acme/alpha', 'date' => '2026-11-05']], '2026.11.05', '7.3.4');
foreach (['FAKEVALUE', 'MASKVALUE7777', 'MARKVALUE', '18088', 'FakeMedia', 'feedmask2'] as $v) {
  t(strpos($rj, $v) === false, "the report does not hold $v");
  t(strpos($st, $v) === false, "the share text does not hold $v");
}

/* the guess: a new field and the saved fields that look like it */
$field = fn($n, $tg, $ty = 'Variable', $def = '', $mode = '') => ['n' => $n, 't' => $tg, 'ty' => $ty, 'd' => $def, 'm' => $mode];
$xml = fn($l) => implode('', array_map(fn($x) => $c($x['n'], $x['t'], $x['ty'], $x['m'], $x['d']), $l));
$feedOf = fn($l) => array_map(fn($x) => ['Name' => $x['n'], 'Target' => $x['t'], 'Type' => $x['ty'], 'Default' => $x['d'], 'Mode' => $x['m']], $l);
$find = fn($a, $k, $label) => array_values(array_filter($a['findings'], fn($f) => $f['kind'] === $k && $f['label'] === $label))[0] ?? [];
$g = $pair($xml([$field('Aa', 'LIBRARY_DIR'), $field('Bb', 'MOVIE_DIR'), $field('Cc', 'ZZZ_OTHER')]), $feedOf([$field('Dd', 'MOVIES_DIR')]));
$nf = $find($g, 'new', 'MOVIES_DIR');
t(($nf['guess'] ?? '') === 'MOVIE_DIR', 'guess: the closest spelling is suggested');
t(($nf['cands'][0] ?? '') === 'MOVIE_DIR' && count($nf['cands'] ?? []) === 3, 'guess: best first, the others still offered');
t(($find($g, 'yours', 'MOVIE_DIR')['guess'] ?? '') === 'MOVIES_DIR', 'guess: the saved field knows it too');
t(($find($g, 'yours', 'LIBRARY_DIR')['guess'] ?? 'x') === '', 'guess: the other saved fields have none');
$g = $pair($xml([$field('Aa', 'ALPHA_ONE')]), $feedOf([$field('Dd', 'ZULU_NINE')]));
$nf = $find($g, 'new', 'ZULU_NINE');
t(($nf['guess'] ?? 'x') === '' && ($nf['cands'] ?? []) === ['ALPHA_ONE'], 'guess: nothing suggested under the threshold, still in the list');
$g = $pair($xml([$field('Aa', 'MOVIE_DIR', 'Path', '', 'rw')]), $feedOf([$field('Dd', 'MOVIES_DIR')]));
$nf = $find($g, 'new', 'MOVIES_DIR');
t(array_key_exists('cands', $nf) && $nf['cands'] === [] && $nf['guess'] === '', 'guess: another Type is not offered');
$g = $pair($xml([$field('Aa', 'XRAY_PORT', 'Variable', 'same-default')]), $feedOf([$field('Dd', 'YANK_PORT', 'Variable', 'same-default')]));
$b1 = cs_pair_score(['label' => 'YANK_PORT', 'name' => 'Dd', 'default' => sha1('x')], ['label' => 'XRAY_PORT', 'name' => 'Aa', 'default' => sha1('y')]);
$b2 = cs_pair_score(['label' => 'YANK_PORT', 'name' => 'Dd', 'default' => sha1('x')], ['label' => 'XRAY_PORT', 'name' => 'Aa', 'default' => sha1('x')]);
$b3 = cs_pair_score(['label' => 'YANK_PORT', 'name' => 'Same', 'default' => ''], ['label' => 'XRAY_PORT', 'name' => 'same', 'default' => '']);
t($b2 > $b1 && $b3 > $b1, 'guess: the same default and the same Name count as evidence');
t(cs_pair_score(['label' => 'A', 'name' => '', 'default' => sha1('')], ['label' => 'B', 'name' => '', 'default' => sha1('')]) < 10, 'guess: two empty defaults are no evidence');
$g = $pair($xml([$field('Aa', 'MOVIE_DIR')]), $feedOf([$field('Dd', 'MOVIES_DIR'), $field('Ee', 'MOVIES_DIRS')]));
t((($find($g, 'new', 'MOVIES_DIR')['guess'] ?? '') === 'MOVIE_DIR') !== (($find($g, 'new', 'MOVIES_DIRS')['guess'] ?? '') === 'MOVIE_DIR'), 'guess: one saved field is suggested for one new field only');
$g = $pair($c('Media', '/Unite/Media', 'Path', 'rw'), [['Name' => 'Media', 'Target' => '/movies', 'Mode' => 'rw']]);
t($find($g, 'new', '/movies') === [] && count($g['moved']) === 1, 'guess: a Path under the same Name is moved, nothing to guess');
$off2 = $pair($c('Aa', 'MOVIE_DIR', 'Disabled:Variable'), $feedOf([$field('Dd', 'MOVIES_DIR')]));
t(($find($off2, 'new', 'MOVIES_DIR')['cands'] ?? ['x']) === [], 'guess: a field turned off is not offered');

$g = $pair($xml([$field('Aa', 'MOVIE_DIR')]), $feedOf([$field('Dd', 'MOVIES_DIR')]));
t(($find($g, 'new', 'MOVIES_DIR')['reason'] ?? '') === 'Suggested because: similar name.', 'guess: says why it was suggested, in plain words');
t(($find($g, 'yours', 'MOVIE_DIR')['past'] ?? 'x') === '', 'no history: nothing about history');

/* history outranks spelling */
$hist = ['keys' => ['variable:CACHE_DIR' => '2025-11', 'variable:RETIRED_KEY' => '2024-03'], 'pairs' => [['from' => 'variable:CACHE_DIR', 'to' => 'variable:SCRATCH_DIR', 'date' => '2025-11']]];
$hs = $xml([$field('Scratch notes', 'SCRATCH_DIRS'), $field('Cache', 'CACHE_DIR'), $field('Retired', 'RETIRED_KEY')]);
$hf = $feedOf([$field('Scratch', 'SCRATCH_DIR')]);
$noh = $pair($hs, $hf);
t(($find($noh, 'new', 'SCRATCH_DIR')['guess'] ?? '') === 'SCRATCH_DIRS', 'history control: without it the spelling wins');
$wh = $pair($hs, $hf, ['history' => $hist]);
$nf = $find($wh, 'new', 'SCRATCH_DIR');
t(($nf['guess'] ?? '') === 'CACHE_DIR' && ($nf['cands'][0] ?? '') === 'CACHE_DIR', 'history: a same-change pair comes before the closest spelling');
t(($nf['reason'] ?? '') === 'CACHE_DIR was in this template until 2025-11 and was replaced by SCRATCH_DIR in the same change.', 'history: the evidence line');
t(($find($wh, 'yours', 'RETIRED_KEY')['past'] ?? '') === 'RETIRED_KEY was in this template until 2024-03.', 'history: a key the template once had is marked');
t(($find($wh, 'yours', 'SCRATCH_DIRS')['past'] ?? 'x') === '', 'history: a key it never had is not');
$kh = $pair($hs, $hf, ['history' => ['keys' => ['variable:RETIRED_KEY' => '2024-03']]]);
t(($find($kh, 'new', 'SCRATCH_DIR')['cands'][0] ?? '') === 'RETIRED_KEY', 'history: a key the template once had ranks above spelling in the list');
t(($find($kh, 'new', 'SCRATCH_DIR')['guess'] ?? '') === 'SCRATCH_DIRS', 'history: but is not suggested on that alone');
$ty = $pair($c('Cache', 'CACHE_DIR', 'Path', 'rw'), $hf, ['history' => $hist]);
t(($find($ty, 'new', 'SCRATCH_DIR')['guess'] ?? 'x') === '', 'history: another Type is still not offered');

/* a dismissal holds for this state of the template only */
$fpOf = fn($a) => $find($a, 'yours', 'CACHE_DIR')['fp'] ?? '';
$st1 = $pair($hs, $hf); $st2a = $pair($hs, $hf); $st2b = $pair($hs, array_merge($hf, $feedOf([$field('Extra', 'EXTRA_KEY')])));
t($fpOf($st1) === $fpOf($st2a) && $fpOf($st1) !== $fpOf($st2b), 'a dismissed saved field returns when the template field list changes');

/* the report text: a question with the key name, never the value */
$rq3 = cs_apply_dismissed(['apps' => array_values($A)], []);
$pairApp = array_column($rq3['apps'], null, 'name')['shape-pair'];
$askId = array_values(array_filter($pairApp['findings'], fn($f) => $f['label'] === 'LIBRARY_DIR'))[0]['id'];
$maskId = array_values(array_filter($pairApp['findings'], fn($f) => $f['label'] === 'OLD_TOKEN'))[0]['id'];
$st2 = cs_share_text($rq3, ['none'], [], '2026.11.05', '7.3.4', [$askId, $maskId]);
t(strpos($st2, 'Question: what does this field mean?') !== false && strpos($st2, 'Variable LIBRARY_DIR') !== false, 'Report it: a question with the key name');
t(strpos($st2, 'FAKEVALUE') === false && strpos($st2, 'MASKVALUE') === false, 'Report it: no value');
$st3 = cs_share_text($rq3, ['none'], [], '2026.11.05', '7.3.4', [array_values(array_filter($pairApp['findings'], fn($f) => $f['label'] === 'LOG_LEVEL'))[0]['id']]);
t(strpos($st3, 'Question') === false, 'Report it: only for a field only in your copy');

$both = $pair($c('Hosts', 'ALLOWED', 'Variable', '', 'old'), [['Name' => 'Hosts', 'Target' => 'ALLOWED', 'Type' => 'Variable', 'Default' => 'new', 'Required' => 'true']]);
$rows = array_values(array_filter($both['findings'], fn($f) => $f['label'] === 'ALLOWED'));
t(count($rows) === 1 && $rows[0]['kind'] === 'required' && $rows[0]['defaultChanged'] === true && $rows[0]['default'] === 'new', 'required and default both changed: one row');
$only = $pair($c('Hosts', 'ALLOWED', 'Variable', '', 'old'), [['Name' => 'Hosts', 'Target' => 'ALLOWED', 'Type' => 'Variable', 'Default' => 'old', 'Required' => 'true']]);
t((array_values(array_filter($only['findings'], fn($f) => $f['label'] === 'ALLOWED'))[0]['defaultChanged'] ?? null) === null, 'only the required flag changed: no default on the row');

echo "$n tests, ", $fail ? 'FAILED' : 'passed', "\n";
exit($fail);
