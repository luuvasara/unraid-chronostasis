<?php
/* unraid-chronostasis: reader for the template history index (formats 1 and 2, see the index's FORMAT.md).
 * The index lists, per template address in the Community Applications feed, every field set the
 * template has had, with the commit time. It answers "which version was this saved copy based on"
 * without asking GitHub for any history. Field keys are the same as cs_field_key().
 *
 * Downloaded at most once a day, conditionally (ETag). When the download fails the last good copy is
 * used; with no copy at all the reader returns null and callers carry on without base versions. */

const CS_TINDEX_FORMATS = [1, 2]; // 2 adds per-version secret info: masked and secret-like field keys

function cs_tindex_url(): string {
  return getenv('CS_TINDEX_URL') ?: 'https://luuvasara.github.io/unraid-template-history/index.json.gz';
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

/* Decoded index from gzip bytes, or null when it is not a format this reader knows. */
function cs_tindex_decode(string $gz): ?array {
  $raw = @gzdecode($gz);
  if ($raw === false) return null;
  $j = json_decode($raw, true);
  if (!is_array($j) || !in_array($j['format'] ?? null, CS_TINDEX_FORMATS, true) || !is_array($j['files'] ?? null) || !is_array($j['sets'] ?? null)) return null;
  return $j;
}

/* The index, or null. $maxAge: seconds a cached copy is used without asking the server. */
function cs_tindex_load(int $maxAge = 86400, int $timeout = 30): ?array {
  $file = cs_tindex_file(); $etagFile = "$file.etag";
  clearstatcache(true, $file);
  if (is_link($file) || is_link(dirname($file))) return null;
  $have = is_file($file);
  if ($have && time() - filemtime($file) < $maxAge) {
    $j = cs_tindex_decode((string)@file_get_contents($file));
    if ($j) return $j;
    $have = false;
  }
  $dir = cs_tindex_dir($file);
  $tmp = $dir ? @tempnam($dir, '.tindex') : false; $fh = $tmp ? @fopen($tmp, 'w') : false; $etag = '';
  if ($fh) {
    $old = $have ? trim((string)@file_get_contents($etagFile)) : '';
    $h = curl_init(cs_tindex_url());
    curl_setopt_array($h, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'unraid-chronostasis', CURLOPT_FAILONERROR => false,
      CURLOPT_HTTPHEADER => $old !== '' ? ["If-None-Match: $old"] : [],
      CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$etag): int {
        unset($handle);
        if (stripos($line, 'etag:') === 0) $etag = trim(substr($line, 5));
        return strlen($line);
      }]);
    $ok = curl_exec($h); $code = (int)curl_getinfo($h, CURLINFO_RESPONSE_CODE);
    if (PHP_VERSION_ID < 80000) curl_close($h); /* a no-op since 8.0, deprecated in 8.5 */
    fclose($fh);
    if ($ok && $code === 304 && $have) { @unlink($tmp); @touch($file); }
    elseif ($ok && $code === 200 && ($j = cs_tindex_decode((string)@file_get_contents($tmp)))) {
      @chmod($tmp, 0644); @rename($tmp, $file); @file_put_contents($etagFile, $etag);
      return $j;
    } else @unlink($tmp);
  } elseif ($tmp) @unlink($tmp);
  /* offline, server error, or a bad download: the last good copy, however old */
  return $have || is_file($file) ? cs_tindex_decode((string)@file_get_contents($file)) : null;
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
  $versions = $index['files'][$k]; $id = cs_tindex_set_id($saved);
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
  $v = $index['files'][$k];
  $s = cs_tindex_sec($index, end($v));
  $s['unmasked'] = array_values(array_diff($s['s'], $s['m']));
  return $s;
}
