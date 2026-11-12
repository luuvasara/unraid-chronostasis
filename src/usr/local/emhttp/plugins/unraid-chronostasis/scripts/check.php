<?php
/* unraid-chronostasis: one check. Reads saved templates (identity only), gets the Community Applications feed, asks
 * each saved template address for its status, writes the report to RAM. Started by scripts/chronostasis. It writes
 * nothing to templates-user, starts no container and changes no setting. */
require_once dirname(__DIR__) . '/include/lib.php';
require_once dirname(__DIR__) . '/include/detect.php';
ini_set('memory_limit', '768M');
$run = cs_path('run'); $cache = cs_path('cache');
@mkdir($run, 0755, true); @mkdir($cache, 0755, true);
function st(string $step, string $error = '', bool $running = true): void {
  cs_write_json(cs_path('run') . '/state.json', ['pid' => getmypid(), 'running' => $running, 'step' => $step, 'error' => $error]);
}
function fail(string $msg): void { st('', $msg, false); fwrite(STDERR, "$msg\n"); exit(1); }

$feedUrls = array_filter(explode(' ', getenv('CS_FEED_URLS') ?: 'https://ca.unraid.net/assets/feed/applicationFeed.json https://assets.ca.unraid.net/feed/applicationFeed.json https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json'));
/* The feed is about 21 MB; a larger download is stopped and a larger copy is not read. CS_FEED_MAX exists for the tests. */
function feed_max(): int { $v = (int)getenv('CS_FEED_MAX'); return $v > 0 ? $v : 128 << 20; }
/* conditional download: an unchanged feed costs one small request */
function get_feed(array $urls, string $cache): ?string {
  $file = "$cache/feed.json"; $etags = cs_read_json("$cache/feed-etag.json");
  foreach ($urls as $u) {
    $tmp = "$file.part"; $fh = fopen($tmp, 'w'); $etag = '';
    $h = curl_init($u);
    curl_setopt_array($h, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 180,
      CURLOPT_USERAGENT => 'unraid-chronostasis', CURLOPT_FAILONERROR => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
      CURLOPT_MAXFILESIZE => feed_max(), CURLOPT_NOPROGRESS => false,
      CURLOPT_XFERINFOFUNCTION => fn($c, $total, $now) => ($total > feed_max() || $now > feed_max()) ? 1 : 0,
      CURLOPT_HTTPHEADER => (is_file($file) && is_string($etags[$u] ?? null) && preg_match('/^[\x21-\x7E]{1,200}$/', $etags[$u])) ? ['If-None-Match: ' . $etags[$u]] : [],
      CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$etag) { if (stripos($l, 'etag:') === 0) $etag = trim(substr($l, 5)); return strlen($l); }]);
    $ok = curl_exec($h); $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE); curl_close($h); fclose($fh);
    if ($ok && $code === 304) { @unlink($tmp); return $file; }
    if ($ok && $code === 200 && filesize($tmp) > 1024 && filesize($tmp) <= feed_max()) {
      rename($tmp, $file);
      file_put_contents("$cache/feed-etag.json", json_encode([$u => $etag]));
      return $file;
    }
    @unlink($tmp);
  }
  return is_file($file) ? $file : null;
}
/* Parsed feed, or null. A feed with no field lists at all (the small one Community Applications uses) is no use here. */
function load_feed(string $file): ?array {
  clearstatcache(true, $file);
  if (!is_file($file) || (int)@filesize($file) > feed_max()) return null;
  $f = json_decode((string)@file_get_contents($file, false, null, 0, feed_max() + 1), true, 64);
  if (!is_array($f) || empty($f['applist']) || !is_array($f['applist'])) return null;
  foreach ($f['applist'] as $a) if (is_array($a) && !empty($a['Config'])) return $f;
  return null;
}
/* Fix Common Problems keeps the raw feed for its daily scan: a copy from the last day saves the download */
function reused_feed(): ?array {
  $f = cs_path('fcpfeed');
  if (!is_file($f) || time() - filemtime($f) >= 86400) return null;
  return load_feed($f);
}
function containers(): ?array {
  $bin = getenv('CS_DOCKER') ?: 'docker';
  exec('timeout 15 ' . escapeshellarg($bin) . " ps -a --format '{{.Names}}' 2>/dev/null", $out, $rc);
  return $rc === 0 ? array_values(array_filter(array_map('trim', $out))) : null;
}
function http_status(array $urls): array {
  if (getenv('CS_NO_URLCHECK')) return [];
  $mh = curl_multi_init(); $res = []; $queue = array_values($urls); $active = [];
  $add = function ($u) use ($mh, &$active) {
    $h = curl_init($u);
    curl_setopt_array($h, [CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'unraid-chronostasis', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
    curl_multi_add_handle($mh, $h); $active[(int)spl_object_id($h)] = [$h, $u];
  };
  do {
    while (count($active) < 6 && $queue) $add(array_shift($queue));
    curl_multi_exec($mh, $running);
    while (($i = curl_multi_info_read($mh)) !== false) {
      $h = $i['handle']; $id = (int)spl_object_id($h);
      $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
      if ($code > 0) $res[$active[$id][1]] = $code;
      curl_multi_remove_handle($mh, $h); curl_close($h); unset($active[$id]);
    }
    if ($running) curl_multi_select($mh, 0.5);
  } while ($active || $queue);
  curl_multi_close($mh);
  return $res;
}

st('Reading the saved templates');
$names = containers();
$tpls = []; $noContainer = 0;
foreach (glob(cs_path('templates') . '/my-*.xml') ?: [] as $f) {
  $t = cs_read_template($f);
  if ($t === null) continue;
  if ($names !== null && !in_array($t['name'], $names, true)) { $noContainer++; continue; }
  $tpls[] = $t;
}
st('Getting the Community Applications feed');
$feed = reused_feed();
if ($feed === null) {
  $file = get_feed($feedUrls, $cache);
  if ($file === null) fail('Could not get the Community Applications feed. Check the network and try again.');
  $feed = load_feed($file);
  if ($feed === null) fail('The Community Applications feed could not be read.');
}
$idx = cs_index($feed);
$stamp = cs_text($feed['last_updated'] ?? '', 64);
unset($feed);
st('Checking the template addresses');
$urls = [];
foreach ($tpls as $t) if (preg_match('~^https?://~i', $t['url'])) $urls[$t['url']] = $t['url'];
$codes = http_status($urls);
st('Comparing');
$apps = []; $tplData = [];
$auto = cs_read_json(cs_path('autoupdate'));
$history = cs_read_json(cs_path('history'));
foreach ($tpls as $t) {
  $a = cs_app($t, $idx, $codes[$t['url']] ?? null, $history);
  $entry = cs_match($t, $idx)['entry'];
  if ($entry) $tplData[$t['name']] = cs_template_data($entry);
  $a['autoUpdated'] = cs_auto_updated($auto, $t['name']);
  $apps[] = $a;
}
usort($apps, fn($a, $b) => strcasecmp($a['name'], $b['name']));
$ok = cs_write_json("$cache/report.json", ['generated' => time(), 'feedUpdated' => is_string($stamp) ? $stamp : '', 'docker' => $names !== null, 'noContainer' => $noContainer, 'apps' => $apps]);
$ok = $ok && cs_write_json("$cache/templates.json", $tplData);
if (!$ok) fail("Could not write the report to $cache.");
st('', '', false);
