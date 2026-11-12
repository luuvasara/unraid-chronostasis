<?php
/* unraid-chronostasis: reader for the template history index (formats 1 to 3, see the index's FORMAT.md).
 * The index lists, per template address in the Community Applications feed, every field set the
 * template has had, with the commit time. It answers "which version was this saved copy based on"
 * without asking GitHub for any history. Field keys are the same as cs_field_key().
 *
 * Downloaded at most once a day, conditionally (ETag). When the download fails the last good copy is
 * used; with no copy at all the reader returns null and callers carry on without base versions. */

require_once __DIR__ . '/lib.php';
const CS_TINDEX_FORMATS = [1, 2, 3]; // 2 adds per-version secret info: masked and secret-like field keys; 3 adds the app list (not read here)

function cs_tindex_url(): string {
  return getenv('CS_TINDEX_URL') ?: 'https://luuvasara.github.io/unraid-app-chronicle/index.json.gz';
}
/* Cached under /var/local/emhttp (RAM, root only), not /tmp: nobody else can plant or swap the file. */
function cs_tindex_file(): string {
  return getenv('CS_TINDEX_FILE') ?: '/var/local/emhttp/plugins/unraid-chronostasis/template-index.json.gz';
}
/* The cache directory, created 0755 if needed, or null when it or the file is a symlink. */
function cs_tindex_dir(string $file): ?string {
  $d = dirname($file);
  if (!is_dir($d)) @mkdir($d, 0755, true);
  if (!is_dir($d) || is_link($d) || is_link($file)) return null;
  return $d;
}

/* Size limits: the download (gzip) and what it may unpack to. The published index is about 1.2 MB and 7 MB. A larger
 * file, a gzip bomb included, is refused before it is unpacked in full. CS_TINDEX_MAX_GZ / _RAW exist for the tests. */
function cs_tindex_max(string $what): int {
  $v = (int)getenv($what === 'gz' ? 'CS_TINDEX_MAX_GZ' : 'CS_TINDEX_MAX_RAW');
  return $v > 0 ? $v : ($what === 'gz' ? 16 << 20 : 96 << 20);
}
/* Decoded index from gzip bytes, or null when it is too large or not a format this reader knows. Every entry is
 * checked by cs_tindex_clean(); what does not fit the format is dropped, not trusted. */
function cs_tindex_decode(string $gz): ?array {
  if ($gz === '' || strlen($gz) > cs_tindex_max('gz')) return null;
  $raw = @gzdecode($gz, cs_tindex_max('raw'));
  if (!is_string($raw)) return null;
  $j = json_decode($raw, true, 16);
  unset($raw);
  if (!is_array($j) || !in_array($j['format'] ?? null, CS_TINDEX_FORMATS, true) || !is_array($j['files'] ?? null) || !is_array($j['sets'] ?? null)) return null;
  return cs_tindex_clean($j);
}
function cs_tindex_keys($v): bool {
  if (!is_array($v) || !array_is_list($v) || count($v) > 5000) return false;
  foreach ($v as $k) if (!is_string($k) || strlen($k) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $k)) return false;
  return true;
}
/* Only the parts this reader uses, each in the shape FORMAT.md gives; '_dropped' counts what was left out. */
function cs_tindex_clean(array $j): array {
  $out = ['format' => $j['format'], 'generated' => is_int($j['generated'] ?? null) ? $j['generated'] : 0, 'repos' => [], 'sets' => [], 'secrets' => [], 'files' => [], '_dropped' => 0];
  $drop = 0;
  /* a set id of ten decimal digits is an integer array key in PHP: compare it as a string */
  foreach ($j['sets'] as $id => $keys) {
    $id = (string)$id;
    if (preg_match('/^[0-9a-f]{10}$/', $id) && cs_tindex_keys($keys)) $out['sets'][$id] = $keys; else $drop++;
  }
  foreach ((is_array($j['secrets'] ?? null) ? $j['secrets'] : []) as $id => $s) {
    $id = (string)$id;
    $ok = preg_match('/^[0-9a-f]{10}$/', $id) && is_array($s);
    if ($ok) foreach (['m', 's', 'x'] as $k) if (!cs_tindex_keys($s[$k] ?? [])) $ok = false;
    if ($ok) $out['secrets'][$id] = ['m' => $s['m'] ?? [], 's' => $s['s'] ?? [], 'x' => $s['x'] ?? []]; else $drop++;
  }
  foreach ((is_array($j['repos'] ?? null) ? $j['repos'] : []) as $name => $heads) {
    $ok = is_string($name) && preg_match('~^[A-Za-z0-9._-]{1,100}/[A-Za-z0-9._-]{1,100}$~', $name) && is_array($heads);
    if ($ok) foreach ($heads as $b => $sha) if (!is_string($b) || $b === '' || strlen($b) > 255 || preg_match('/[\x00-\x20\x7F]/', $b) || !is_string($sha) || !preg_match('/^[0-9a-f]{40}$/', $sha)) $ok = false;
    if ($ok) $out['repos'][$name] = $heads; else $drop++;
  }
  foreach ($j['files'] as $k => $versions) {
    if (!is_string($k) || strlen($k) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $k) || !is_array($versions) || !array_is_list($versions)) { $drop++; continue; }
    $good = [];
    foreach (array_slice($versions, -1000) as $e) {
      if (is_array($e) && array_is_list($e) && in_array(count($e), [3, 4], true) && is_int($e[0]) && $e[0] >= 0 && is_string($e[1]) && preg_match('/^[0-9a-f]{7,40}$/', $e[1])
          && is_string($e[2]) && isset($out['sets'][$e[2]]) && (!isset($e[3]) || (is_string($e[3]) && isset($out['secrets'][$e[3]])))) $good[] = $e;
      else $drop++;
    }
    if ($good) $out['files'][$k] = $good;
  }
  $out['_dropped'] = $drop;
  return $out;
}

/* The index, or null. $maxAge: seconds a cached copy is used without asking the server. */
function cs_tindex_load(int $maxAge = 86400, int $timeout = 30): ?array {
  $file = cs_tindex_file(); $etagFile = "$file.etag";
  clearstatcache(true, $file);
  if (is_link($file) || is_link(dirname($file))) return null;
  $have = is_file($file);
  if ($have && time() - filemtime($file) < $maxAge) {
    $j = cs_tindex_decode((string)@file_get_contents($file, false, null, 0, cs_tindex_max('gz') + 1));
    if ($j) return $j;
    $have = false;
  }
  $dir = cs_tindex_dir($file);
  $tmp = $dir ? @tempnam($dir, '.tindex') : false; $fh = $tmp ? @fopen($tmp, 'w') : false; $etag = '';
  if ($fh) {
    $old = $have ? trim((string)@file_get_contents($etagFile, false, null, 0, 256)) : '';
    if (!preg_match('/^[\x21-\x7E]{1,200}$/', $old)) $old = '';
    $h = curl_init(cs_tindex_url());
    curl_setopt_array($h, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'unraid-chronostasis', CURLOPT_FAILONERROR => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
      /* stop a download over the limit while it runs, whatever the server says its size is */
      CURLOPT_MAXFILESIZE => cs_tindex_max('gz'), CURLOPT_NOPROGRESS => false,
      CURLOPT_XFERINFOFUNCTION => fn($c, $total, $now) => ($total > cs_tindex_max('gz') || $now > cs_tindex_max('gz')) ? 1 : 0,
      CURLOPT_HTTPHEADER => $old !== '' ? ["If-None-Match: $old"] : [],
      CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$etag): int {
        unset($handle);
        if (stripos($line, 'etag:') === 0 && preg_match('/^[\x21-\x7E]{1,200}$/', trim(substr($line, 5)))) $etag = trim(substr($line, 5));
        return strlen($line);
      }]);
    $ok = curl_exec($h); $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($h); /* a no-op since 8.0, deprecated in 8.5 */
    fclose($fh);
    if ($ok && $code === 304 && $have) { @unlink($tmp); @touch($file); }
    elseif ($ok && $code === 200 && ($j = cs_tindex_decode((string)@file_get_contents($tmp, false, null, 0, cs_tindex_max('gz') + 1)))) {
      @chmod($tmp, 0644); @rename($tmp, $file); @file_put_contents($etagFile, $etag);
      return $j;
    } else @unlink($tmp);
  } elseif ($tmp) @unlink($tmp);
  /* offline, server error, or a bad download: the last good copy, however old */
  return $have || is_file($file) ? cs_tindex_decode((string)@file_get_contents($file, false, null, 0, cs_tindex_max('gz') + 1)) : null;
}

/* Index key of a template address, or ''. Exact feed spelling first; then the same owner/repo (any
 * case) and path on any indexed branch, so master/main and case changes still match. */
function cs_tindex_key(array $index, string $url): string {
  if (!preg_match('~^https?://raw\.githubusercontent\.com/([^/]+)/([^/]+)/(.+)$~i', trim($url), $m)) return '';
  $k = "$m[1]/$m[2]/$m[3]";
  if (isset($index['files'][$k])) return $k;
  $want = strtolower("$m[1]/$m[2]");
  foreach ($index['repos'] ?? [] as $repo => $branches) {
    if (strtolower($repo) !== $want) continue;
    /* the saved branch is a known one (it may hold a slash), or a renamed one: then its first segment */
    $path = null;
    foreach (array_keys($branches) as $b) if (stripos($m[3], "$b/") === 0) { $path = substr($m[3], strlen($b) + 1); break; }
    if ($path === null && ($p = strpos($m[3], '/')) !== false) $path = substr($m[3], $p + 1);
    if ($path === null) return '';
    foreach (array_keys($branches) as $b) if (isset($index['files']["$repo/$b/$path"])) return "$repo/$b/$path";
  }
  return '';
}

/* Set id of a list of field keys, as the index computes it. */
function cs_tindex_set_id(array $keys): string {
  $keys = array_values(array_unique($keys)); sort($keys, SORT_STRING);
  return substr(sha1(implode("\n", $keys)), 0, 10);
}

/* The version a saved copy was most likely based on, or null when the address is not indexed.
 * $saved: field keys of the saved template (the keys of cs_read_template()['fields']).
 * An exact set match wins (the newest version with that set); otherwise the best Jaccard overlap. */
function cs_tindex_base(array $index, string $url, array $saved): ?array {
  $k = cs_tindex_key($index, $url);
  if ($k === '') return null;
  $versions = $index['files'][$k] ?? [];
  if (!$versions) return null;
  $id = cs_tindex_set_id($saved);
  $best = null; $bestScore = -1.0; $S = array_flip($saved);
  foreach ($versions as $i => [$time, $sha, $set]) {
    if ($set === $id) { $score = 2.0; }
    else {
      $V = array_flip($index['sets'][$set] ?? []);
      $union = count($S + $V);
      $score = $union ? count(array_intersect_key($S, $V)) / $union : 0.0;
    }
    if ($score >= $bestScore) { $bestScore = $score; $best = $i; }
  }
  [$time, $sha, $set] = $versions[$best];
  $next = $versions[$best + 1][0] ?? null;
  $sec = cs_tindex_sec($index, $versions[$best]);
  return ['key' => $k, 'time' => $time, 'until' => $next, 'commit' => $sha, 'set' => $set, 'exact' => $bestScore === 2.0,
    'jaccard' => $bestScore === 2.0 ? 1.0 : round($bestScore, 3), 'later' => count($versions) - 1 - $best, 'versions' => count($versions),
    'fields' => $index['sets'][$set] ?? [], 'secrets' => $sec];
}

/* Secret info of one version entry: ['m' => masked keys, 's' => secret-like keys, 'x' => secret-like
 * ExtraParams/PostArgs keys], all empty when the version has none or the index is format 1. */
function cs_tindex_sec(array $index, array $entry): array {
  $none = ['m' => [], 's' => [], 'x' => []];
  $id = $entry[3] ?? null;
  if (!is_string($id)) return $none;
  $s = $index['secrets'][$id] ?? null;
  return is_array($s) ? $s + $none : $none;
}

/* Secret info of the newest version of a template address, or null when it is not indexed. Callers can
 * treat the secret-like keys the template does not mask ('s' minus 'm') as passwords. */
function cs_tindex_secrets(array $index, string $url): ?array {
  $k = cs_tindex_key($index, $url);
  if ($k === '') return null;
  $v = $index['files'][$k] ?? [];
  if (!$v) return null;
  $s = cs_tindex_sec($index, end($v));
  $s['unmasked'] = array_values(array_diff($s['s'], $s['m']));
  return $s;
}
