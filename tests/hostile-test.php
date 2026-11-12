<?php
/* Hostile-input tests. The Community Applications feed, the template history index and template files are written by
 * third parties. Each test feeds one kind of hostile value and checks that it is refused, dropped or made inert before
 * it reaches the report, the page, the upgraded settings or a file path. Example data only; nothing touches a server.
 * usage: php tests/hostile-test.php   (CS_SRC=<plugin dir> tests another copy of the source) */
$H = dirname(__DIR__); $P = getenv('CS_SRC') ?: "$H/src/usr/local/emhttp/plugins/unraid-chronostasis";
$W = "$H/tests/.work/hostile"; exec('rm -rf ' . escapeshellarg($W)); foreach (['tpl', 'cfg/backups/app', 'run', 'cache', 'out'] as $d) mkdir("$W/$d", 0777, true);
foreach (['CS_TEMPLATES' => "$W/tpl", 'CS_CONFIG' => "$W/cfg", 'CS_RUN' => "$W/run", 'CS_CACHE' => "$W/cache", 'CS_DOCKER' => '/bin/false'] as $k => $v) putenv("$k=$v");
require "$P/include/lib.php"; require "$P/include/detect.php"; require "$P/include/share.php"; require "$P/include/value.php";
require "$P/include/upgrade.php"; require "$P/include/tindex.php";
$fail = 0; $n = 0;
function t(bool $c, string $m): void { global $fail, $n; $n++; if ($c) echo "ok   $m\n"; else { echo "FAIL $m\n"; $fail = 1; } }
/* a test that throws counts as failed and the rest still run */
function g(string $m, callable $fn): void { try { $fn(); } catch (Throwable $e) { t(false, "$m (threw " . get_class($e) . ': ' . substr($e->getMessage(), 0, 120) . ')'); } }
/* a PHP snippet in its own process, for limits a test process must not hit itself */
function sub(string $code, string $mem = '256M', array $env = []): array {
  global $P;
  $pre = '$P = ' . var_export($P, true) . '; require "$P/include/lib.php"; require "$P/include/detect.php"; require "$P/include/tindex.php"; ';
  $e = ''; foreach ($env as $k => $v) $e .= escapeshellarg("$k=$v") . ' ';
  exec('env ' . $e . 'php -d memory_limit=' . $mem . ' -r ' . escapeshellarg($pre . $code) . ' 2>&1', $out, $rc);
  return [$rc, implode("\n", $out)];
}
$RAW = 'https://raw.githubusercontent.com/';

/* ---- template history index (tindex.php) ---- */
g('gzip bomb', function () use ($W) {
  /* 1 GiB of zeros, about 1 MB gzip: built in chunks so this process never holds it */
  $z = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 9]); $fh = fopen("$W/bomb.gz", 'w'); $chunk = str_repeat("\0", 1 << 20);
  for ($i = 0; $i < 1024; $i++) fwrite($fh, deflate_add($z, $chunk, ZLIB_NO_FLUSH));
  fwrite($fh, deflate_add($z, '', ZLIB_FINISH)); fclose($fh);
  [$rc, $o] = sub('var_dump(cs_tindex_decode(file_get_contents(' . var_export("$W/bomb.gz", true) . ')));', '256M');
  t($rc === 0 && trim($o) === 'NULL', 'a gzip bomb (1 MB unpacking to 1 GiB) is refused within a 256M memory limit' . ($rc ? " (rc $rc: " . substr($o, 0, 100) . ')' : ''));
});
g('download cap', function () use ($W) {
  $ix = ['format' => 3, 'generated' => 1, 'repos' => [], 'sets' => [], 'files' => [], 'pad' => bin2hex(random_bytes(4000))];
  file_put_contents("$W/out/index.json.gz", gzencode(json_encode($ix)));
  $port = 18000 + getmypid() % 1000;
  $srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', "$W/out"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
  usleep(400000);
  [$rc, $o] = sub('var_dump(cs_tindex_load(0, 5));', '256M', ['CS_TINDEX_URL' => "http://127.0.0.1:$port/index.json.gz", 'CS_TINDEX_FILE' => "$W/cache/t.json.gz", 'CS_TINDEX_MAX_GZ' => '2000']);
  proc_terminate($srv); proc_close($srv);
  t(trim($o) === 'NULL' && !is_file("$W/cache/t.json.gz"), 'a download over the size limit is stopped and not kept');
});
$set = fn(array $k) => cs_tindex_set_id($k);
$v1 = ['path:/config', 'variable:PUID'];
g('malformed index', function () use ($set, $v1, $RAW) {
  $ix = ['format' => 3, 'generated' => 1, 'repos' => ['own/repo' => ['main' => str_repeat('a', 40)], 'bad name/x/y' => 'no'],
    'sets' => [$set($v1) => $v1, 'zzzz' => ['x'], 'aaaaaaaaaa' => 'not a list', 'bbbbbbbbbb' => [['nested']], 'cccccccccc' => ["a\x00b"]],
    'secrets' => ['dddddddddd' => ['m' => 'x']],
    'files' => ['own/repo/main/a.xml' => [[100, 'abcdef1', $set($v1)], ['x', 'zz', ['array']], [1, 'abcdef2', 'aaaaaaaaaa'], 'str', [2, 'abcdef3', $set($v1), 'dddddddddd']],
      'own/repo/main/b.xml' => 'not a list', 'own/repo/main/c.xml' => [[1, 'abcdef4', ['array as set id']]]]];
  $d = cs_tindex_decode(gzencode(json_encode($ix)));
  t(is_array($d) && array_keys($d['sets']) === [$set($v1)], 'only well-formed field sets are kept');
  t(is_array($d) && ($d['files']['own/repo/main/a.xml'] ?? null) === [[100, 'abcdef1', $set($v1)]] && !isset($d['files']['own/repo/main/b.xml']) && !isset($d['files']['own/repo/main/c.xml']),
    'only version entries of the right shape that point at known sets are kept');
  t(is_array($d) && !isset($d['repos']['bad name/x/y']) && !isset($d['secrets']['dddddddddd']), 'bad repository names and secret info are dropped');
  $b = cs_tindex_base(is_array($d) ? $d : $ix, $RAW . 'own/repo/main/c.xml', $v1);
  t($b === null, 'a template whose versions are all malformed gives no base version (and no TypeError)');
  $num = ['path:/x0']; $i = 0; while (!ctype_digit($set($num))) $num = ['path:/x' . ++$i];
  $d2 = cs_tindex_decode(gzencode(json_encode(['format' => 3, 'repos' => [], 'sets' => [$set($num) => $num], 'files' => ['o/r/main/n.xml' => [[1, 'abcdef1', $set($num)]]]])));
  t(is_array($d2) && isset($d2['files']['o/r/main/n.xml']), 'a set id of ten decimal digits (an integer key in PHP) still works');
  t(cs_tindex_decode(gzencode(str_repeat('[', 100000) . str_repeat(']', 100000))) === null, 'deeply nested JSON is refused');
});

/* ---- the Community Applications feed and saved templates (detect.php) ---- */
$bad8 = "bad\xC3\x28utf8";
$feed = ['applist' => [
  'a string entry', null, ['Name' => ['array'], 'Repository' => ['x']],
  ['Name' => '<script>alert(1)</script>', 'Repository' => 'own/xss:latest', 'Repo' => "Own's \u{202E}Repository",
   'TemplateURL' => 'javascript:alert(1)//' . $RAW . 'own/repo/main/xss.xml', 'Support' => 'javascript:alert(document.cookie)',
   'Project' => ' data:text/html,<script>alert(1)</script>', 'ModeratorComment' => '<img src=x onerror=alert(1)>Read <b>this</b>&lt;script&gt;',
   'WebUI' => 'javascript:alert(1)',
   'Config' => [
     ['@attributes' => ['Name' => 'Web"><svg onload=alert(1)>', 'Target' => '8080', 'Default' => '8080', 'Mode' => 'tcp', 'Type' => 'Port', 'Required' => 'false', 'Mask' => 'false']],
     ['@attributes' => ['Name' => 'Ctrl', 'Target' => "CTRL\x01\x1B[31m\u{202E}VAR", 'Default' => "a\x01b$bad8", 'Mode' => '', 'Type' => 'Variable', 'Required' => 'false', 'Mask' => 'false', 'Description' => "line1\nline2\x07"]],
     ['@attributes' => ['Name' => 'Huge', 'Target' => 'HUGE', 'Default' => str_repeat('A', 200000), 'Mode' => '', 'Type' => 'Variable', 'Required' => 'false', 'Mask' => 'false']],
     ['@attributes' => ['Name' => 'Focus', 'Target' => 'FOCUS', 'Default' => 'x" autofocus onfocus="alert(1)', 'Mode' => '', 'Type' => 'Variable', 'Required' => 'false', 'Mask' => 'false']],
     ['@attributes' => ['Name' => 'Proto', 'Target' => '__proto__', 'Default' => 'constructor', 'Mode' => '', 'Type' => 'Variable', 'Required' => 'false', 'Mask' => 'false']],
     ['@attributes' => ['Name' => ['array'], 'Target' => ['array'], 'Type' => 7, 'Default' => true]],
   ]],
  ['Name' => 'badimg', 'Repository' => '"><svg onload=alert(1)>', 'Config' => []],
], 'deprecated' => ['own/dep' => '<script>alert(1)</script>Use another'], 'blacklisted' => []];
file_put_contents("$W/tpl/my-xss.xml", '<?xml version="1.0"?><Container version="2"><Name>xss</Name><Repository>own/xss:latest</Repository>'
  . '<TemplateURL>' . htmlspecialchars($RAW . 'own/repo/main/xss.xml') . '</TemplateURL><Support>javascript:alert(2)</Support><Project>https://example.com/"onmouseover="alert(1)</Project>'
  . '<Config Name="Old" Target="OLD" Default="" Mode="" Type="Variable" Required="false" Mask="false">v</Config></Container>');
g('feed', function () use ($feed, $W, $bad8) {
  $idx = cs_index($feed);
  $t = cs_read_template("$W/tpl/my-xss.xml");
  $a = cs_app($t, $idx, 200);
  $r = cs_apply_dismissed(['apps' => [$a]], []);
  $json = json_encode($r);
  t($json !== false, 'the report is valid UTF-8 (json_encode needs no substitution)');
  t(strpos((string)json_encode($r, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 'javascript:') === false && strpos((string)$json, 'data:text') === false,
    'no javascript: or data: link from the feed or the saved template reaches the report');
  t($a['support'] === '' && $a['project'] === '', 'support and project links that are not http(s), or that carry quotes, are emptied');
  $txt = implode(' ', array_column(array_filter($a['findings'], fn($f) => $f['kind'] === 'moderation'), 'text'));
  t($txt !== '' && strpos($txt, '<') === false && strpos($txt, 'onerror') === false, 'the moderator comment is plain text, its markup removed');
  $labels = implode('|', array_column($a['findings'], 'label'));
  t(!preg_match('/[\x00-\x1F\x7F]|\x{202E}/u', $labels) && strpos($labels, 'HUGE') === false, 'field labels have no control or bidi characters; a 200000-character default leaves its field out');
  t(!isset($idx['apps'][1]) || !in_array('badimg', array_column($idx['apps'], 'Name'), true), 'a feed entry with an invalid image name is left out');
  $d = cs_template_data($idx['apps'][0] ?? []);
  $all = json_encode($d);
  t($all !== false && strpos($all, $bad8) === false && !preg_match('/\\\\u000[0-8b-f]|\\\\u001/', (string)$all), 'template data for the upgraded settings: no control characters, no invalid UTF-8');
});
g('upgraded settings', function () use ($feed, $W) {
  $idx = cs_index($feed);
  $app = null; foreach ($idx['apps'] as $x) if ($x['Repository'] === 'own/xss:latest') $app = $x;
  $tpl = cs_template_data($app ?? $feed['applist'][3]);
  $keys = array_merge(array_keys($tpl['fields']), array_keys($tpl['quoted'] ?? []), ['variable:FOCUS', 'port:8080/tcp']);
  $ops = ['renames' => [], 'includes' => $keys, 'heads' => [], 'own' => [], 'left' => []];
  $b = cs_upgrade_build("$W/tpl/my-xss.xml", $tpl, $ops);
  $d = new DOMDocument();
  $ok = $b !== null && @$d->loadXML($b['xml'], LIBXML_NONET);
  t($ok, 'the upgraded settings are well-formed XML that Unraid\'s form can read again');
  $x = $ok ? simplexml_load_string($b['xml']) : null;
  $names = []; if ($x) foreach ($x->Config as $c) $names[] = (string)$c['Name'];
  t($x !== null && strpos($b['xml'], 'onfocus') === false && strpos($b['xml'], 'svg') === false && !in_array('Focus', $names, true),
    'a template field with a double quote in its default or name is left out of the upgraded settings (Unraid\'s form does not escape it)');
  $rep = cs_apply_dismissed(['apps' => [cs_app(cs_read_template("$W/tpl/my-xss.xml"), $idx, 200)]], []);
  $fa = $rep['apps'][0]; $focus = array_values(array_filter($fa['findings'], fn($f) => $f['kind'] === 'new' && $f['label'] === 'FOCUS'))[0] ?? null;
  $plan = $focus ? cs_plan_app($fa, $tpl, [['id' => $focus['id'], 'type' => 'include']]) : null;
  t($plan !== null && (bool)preg_grep('/Left out of the upgraded settings.*FOCUS/', $plan['warnings'] ?? []), 'the Finish step says which template field was left out, and why');
  t(!preg_match('/&#(x0*[0-8bcef]|x0*1[0-9a-f]|0*[0-8]|0*1[1-9]|0*2[0-9]|0*3[01]);/i', (string)($b['xml'] ?? '')), 'no control character reaches the upgraded settings');
});
g('saved templates and paths', function () use ($W) {
  file_put_contents("$W/tpl/my-lol.xml", '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;"><!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><Container version="2"><Name>lol</Name><Repository>own/lol</Repository><Config Name="L" Target="L" Default="" Mode="" Type="Variable" Required="false" Mask="false">&c;</Config></Container>');
  t(cs_read_template("$W/tpl/my-lol.xml") === null && cs_read_value("$W/tpl/my-lol.xml", 'variable:L') === null, 'a template with a DOCTYPE (entity expansion) is not read');
  file_put_contents("$W/tpl/my-big.xml", '<?xml version="1.0"?><Container version="2"><Name>big</Name><Repository>own/big</Repository><Overview>' . str_repeat('x', 2 << 20) . '</Overview></Container>');
  t(cs_read_template("$W/tpl/my-big.xml") === null, 'a template over 1 MiB is not read');
  file_put_contents("$W/outside.xml", '<?xml version="1.0"?><Container version="2"><Name>out</Name><Repository>o/o</Repository><Config Name="S" Target="S" Default="" Mode="" Type="Variable" Required="false" Mask="false">SECRETOUTSIDE</Config></Container>');
  symlink("$W/outside.xml", "$W/tpl/my-link.xml");
  t(cs_template_file('my-link.xml', "$W/tpl") === null && cs_template_file('../outside.xml', "$W/tpl") === null && cs_template_file("my-\n.xml", "$W/tpl") === null,
    'value.php: a symlinked, ../ or control-character file name is refused');
  t(cs_saved_file(['file' => 'my-link.xml']) === null && cs_saved_file(['file' => '../../outside.xml']) === null, 'upgrade: the saved file is never a symlink or outside templates-user');
  $acc = array_filter(['../x', 'a"b', "a\nb", 'a b', '-rm', str_repeat('a', 200), "a\0b"], 'cs_safe_name');
  t($acc === [], 'app names with ../, quotes, spaces, new lines, NUL, a leading - or over 128 characters are refused' . ($acc ? ': accepted ' . json_encode(array_values($acc)) : ''));
  t(cs_form_url('/var/run/unraid-chronostasis/upgrade/my-app.xml') === '/Docker/AddContainer?xmlTemplate=user%3A%2Fvar%2Frun%2Funraid-chronostasis%2Fupgrade%2Fmy-app.xml',
    'the Finish link is a fixed local path, the file part URL-encoded');
  symlink("$W/outside.xml", "$W/cfg/backups/app/my-app-evil.xml");
  $r = cs_upgrade_putback('app', 'my-app-evil.xml', true);
  t(!isset($r['url']), 'putback refuses a backup that is a symlink to a file elsewhere');
  t(!isset(cs_upgrade_putback('app', '../../../outside.xml', true)['url']) && !isset(cs_upgrade_putback('../cfg', 'my-x.xml', true)['url']), 'putback refuses ../ in the app or file name');
});
g('share text', function () {
  $rep = ['apps' => [['name' => 'x', 'feedName' => '<script>alert(1)</script>', 'repository' => 'evil"<img src=x>', 'feedUrl' => 'javascript:alert(1)', 'savedUrl' => '',
    'findings' => [['kind' => 'new', 'id' => 'i', 'type' => 'Variable<b>', 'label' => '<img src=x onerror=alert(1)>'], ['kind' => 'renamed', 'id' => 'j', 'from' => "A\nB", 'label' => 'C']]]]];
  $s = cs_share_text($rep, [], [['type' => 'field', 'from' => '<a>', 'to' => 'b', 'reason' => 'field-rename', 'source' => 'javascript:alert(1)', 'date' => '2026-01-01']], '1', '7');
  t(strpos($s, '<') === false && strpos($s, 'javascript:') === false, 'the Finish copy text has no markup and no javascript: link');
});
g('csrf', function () use ($P, $W) {
  file_put_contents("$W/var.ini", "csrf_token=\"0123456789ABCDEF\"\n");
  foreach (['' => 403, 'wrong' => 403] as $tok => $want) {
    $code = '$_SERVER["REQUEST_METHOD"]="POST"; $_POST=["action"=>"dismiss","id"=>str_repeat("a",16),"fp"=>str_repeat("b",12),"csrf_token"=>' . var_export($tok, true) . ']; include ' . var_export("$P/include/action.php", true) . ';';
    exec('CS_VARINI=' . escapeshellarg("$W/var.ini") . ' CS_CONFIG=' . escapeshellarg("$W/cfg") . ' CS_RUN=' . escapeshellarg("$W/run") . ' php -r ' . escapeshellarg($code) . ' 2>&1', $o);
    t(strpos(implode('', $o), 'invalid csrf_token') !== false, 'action.php refuses a POST with ' . ($tok === '' ? 'no' : 'a wrong') . ' csrf_token'); $o = [];
  }
});
echo $fail ? "$n tests, some failed\n" : "all $n passed\n";
exit($fail);
