<?php
/* Offline tests of include/tindex.php. A local PHP server stands in for the index host. */
$P = dirname(__DIR__) . '/src/usr/local/emhttp/plugins/unraid-chronostasis';
require "$P/include/detect.php"; require "$P/include/tindex.php";
$fail = 0;
function t(bool $c, string $m): void { global $fail; if ($c) echo "ok   $m\n"; else { echo "FAIL $m\n"; $fail = 1; } }
$W = __DIR__ . '/.work/tindex'; @mkdir($W, 0755, true); foreach (glob("$W/{,cache/}*", GLOB_BRACE) ?: [] as $f) if (is_file($f)) unlink($f);
$set = fn(array $k) => cs_tindex_set_id($k);
$v1 = ['path:/config', 'port:8080/tcp', 'variable:PUID'];
$v2 = ['path:/config', 'port:8080/tcp', 'variable:PUID', 'variable:TZ'];
$v3 = ['path:/config', 'port:8080/tcp', 'variable:TZ', 'variable:UMASK'];
$ix = ['format' => 1, 'generated' => 1, 'repos' => ['Own/Repo' => ['main' => str_repeat('a', 40)]],
  'sets' => [$set($v1) => $v1, $set($v2) => $v2, $set($v3) => $v3],
  'files' => ['Own/Repo/main/t/app.xml' => [[100, 'aaaaaaa', $set($v1)], [200, 'bbbbbbb', $set($v2)], [300, 'ccccccc', $set($v3)]]]];
$gz = gzencode(json_encode($ix));
file_put_contents("$W/index.json.gz", $gz);
file_put_contents("$W/bad.json.gz", gzencode(json_encode(['format' => 2, 'files' => [], 'sets' => []])));
file_put_contents("$W/router.php", '<?php $f = __DIR__ . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if (!is_file($f)) { http_response_code(404); exit; }
$e = "\"" . md5_file($f) . "\""; header("ETag: $e"); if (($_SERVER["HTTP_IF_NONE_MATCH"] ?? "") === $e) { http_response_code(304); exit; }
file_put_contents(__DIR__ . "/hits", "x", FILE_APPEND); readfile($f);');

/* ids match what build.py computes: sha1 over sorted keys joined by \n */
t(cs_tindex_set_id(['b', 'a']) === substr(sha1("a\nb"), 0, 10), 'set id is sha1 of the sorted keys');
t(cs_field_key('Port', '8080', '', '') === 'port:8080/tcp' && cs_field_key('Path', '/config/', '', '') === 'path:/config', 'saved keys use the same form');

$b = cs_tindex_base($ix, 'https://raw.githubusercontent.com/Own/Repo/main/t/app.xml', $v2);
t($b && $b['exact'] && $b['commit'] === 'bbbbbbb' && $b['later'] === 1 && $b['until'] === 300, 'exact set: that version, one change since');
$b = cs_tindex_base($ix, 'https://raw.githubusercontent.com/own/repo/master/t/app.xml', array_merge($v1, ['variable:MINE']));
t($b && !$b['exact'] && $b['commit'] === 'aaaaaaa' && $b['jaccard'] === 0.75, 'renamed branch and other case still match; best overlap wins');
t(cs_tindex_base($ix, 'https://raw.githubusercontent.com/own/repo/main/t/other.xml', $v1) === null, 'an address not in the index gives null');
t(cs_tindex_base($ix, 'https://example.com/app.xml', $v1) === null, 'a non-GitHub address gives null');

$port = 18000 + getmypid() % 1000;
$srv = proc_open(['php', '-S', "127.0.0.1:$port", "$W/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, $W);
usleep(400000);
putenv("CS_TINDEX_FILE=$W/cache/template-index.json.gz");
putenv("CS_TINDEX_URL=http://127.0.0.1:$port/index.json.gz");
$j = cs_tindex_load(86400, 5);
t($j !== null && count($j['files']) === 1, 'first load downloads the index');
t(cs_tindex_load(86400, 5) !== null && strlen((string)@file_get_contents("$W/hits")) === 1, 'a fresh copy is used without asking');
touch("$W/cache/template-index.json.gz", time() - 90000);
t(cs_tindex_load(86400, 5) !== null && strlen((string)@file_get_contents("$W/hits")) === 1, 'an old copy is revalidated with the ETag (304, no download)');
touch("$W/cache/template-index.json.gz", time() - 90000);
putenv("CS_TINDEX_URL=http://127.0.0.1:$port/bad.json.gz");
$j = cs_tindex_load(86400, 5);
t($j !== null && $j['format'] === 1, 'an unknown format is refused and the old copy kept');
proc_terminate($srv); proc_close($srv);
touch("$W/cache/template-index.json.gz", time() - 90000);
putenv("CS_TINDEX_URL=http://127.0.0.1:$port/index.json.gz");
t(cs_tindex_load(86400, 2) !== null, 'offline: the last good copy is used');
unlink("$W/cache/template-index.json.gz");
t(cs_tindex_load(86400, 2) === null, 'offline with no copy: null, no error');
exit($fail);
