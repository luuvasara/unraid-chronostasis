<?php
/* unraid-chronostasis: paths. CS_* environment variables exist for the tests. */
function cs_path(string $what): string {
  $d = [
    'run' => getenv('CS_RUN') ?: '/var/run/unraid-chronostasis',
    'cache' => getenv('CS_CACHE') ?: '/tmp/unraid-chronostasis',
    'config' => getenv('CS_CONFIG') ?: '/boot/config/plugins/unraid-chronostasis',
    'templates' => getenv('CS_TEMPLATES') ?: '/boot/config/plugins/dockerMan/templates-user',
    'varini' => getenv('CS_VARINI') ?: '/var/local/emhttp/var.ini',
    'script' => getenv('CS_SCRIPT') ?: dirname(__DIR__) . '/scripts/chronostasis',
    'fcpfeed' => getenv('CS_FCPFEED') ?: '/tmp/fix.common.problems/templates.json',
    'history' => getenv('CS_HISTORY') ?: (getenv('CS_CACHE') ?: '/tmp/unraid-chronostasis') . '/template-history.json',
    'autoupdate' => getenv('CS_AUTOUPDATE') ?: '/boot/config/plugins/ca.update.applications/DockerUpdateSettings.json',
  ];
  return $d[$what];
}
function cs_read_json(string $file): array {
  $j = @json_decode((string)@file_get_contents($file), true);
  return is_array($j) ? $j : [];
}
function cs_write_json(string $file, array $data): bool {
  $tmp = "$file." . getmypid();
  if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false) return false;
  return @rename($tmp, $file);
}

/* Values from the Community Applications feed, from the template history index and from template files are written
 * by third parties. Each one is checked here before it is kept, and the page draws every value with textContent and
 * every link through an http(s) check, so nothing in them can act as markup or script. */
const CS_MAX_TEMPLATE = 1048576;  // bytes: a saved template larger than this is not read
const CS_MAX_FIELDS = 1000;       // Config fields per template, feed or saved
/* A string with invalid UTF-8 replaced, control characters (\t and \n kept when $multi), bidi overrides and line
 * separators removed, and cut to $max characters. Not a string or number: ''. */
function cs_text($v, int $max = 200, bool $multi = false): string {
  if (is_int($v) || is_float($v)) $v = (string)$v;
  if (!is_string($v)) return '';
  if (strlen($v) > 4 * $max + 16) $v = substr($v, 0, 4 * $max + 16);
  $v = function_exists('mb_scrub') ? mb_scrub($v, 'UTF-8') : htmlspecialchars_decode(htmlspecialchars($v, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
  $v = (string)preg_replace($multi ? '/[\x00-\x08\x0B-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2028}\x{2029}\x{2066}-\x{2069}\x{FEFF}]/u'
                                   : '/[\x00-\x1F\x7F\x{80}-\x{9F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2028}\x{2029}\x{2066}-\x{2069}\x{FEFF}]/u', '', $v);
  return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}
/* An http or https address with a host, no user info, spaces, quotes or angle brackets; else ''. For links. */
function cs_http_url($v): string {
  if (!is_string($v)) return '';
  $v = trim($v);
  if ($v === '' || strlen($v) > 2048 || preg_match('/[\x00-\x20\x7F"\'<>\\\\^`{|}]/', $v)) return '';
  $p = parse_url($v);
  if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || ($p['host'] ?? '') === '' || isset($p['user']) || isset($p['pass'])) return '';
  return $v;
}
/* $file when it is a plain file (no symlink) whose real directory is $dir's, else null. */
function cs_inside(string $file, string $dir): ?string {
  if (strpos($file, "\0") !== false || !is_file($file) || is_link($file)) return null;
  $rd = realpath($dir); $rf = realpath($file);
  return $rd !== false && $rf !== false && dirname($rf) === $rd ? $file : null;
}
/* A template file's text for parsing, or null: missing, over CS_MAX_TEMPLATE, or with a DOCTYPE (entities never expand). */
function cs_template_text(string $file): ?string {
  clearstatcache(true, $file);
  if (!is_file($file) || (int)@filesize($file) > CS_MAX_TEMPLATE) return null;
  $raw = @file_get_contents($file, false, null, 0, CS_MAX_TEMPLATE + 1);
  if (!is_string($raw) || $raw === '' || strlen($raw) > CS_MAX_TEMPLATE || stripos($raw, '<!DOCTYPE') !== false || stripos($raw, '<!ENTITY') !== false) return null;
  return $raw;
}
