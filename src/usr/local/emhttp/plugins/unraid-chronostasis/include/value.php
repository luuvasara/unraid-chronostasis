<?php
/* unraid-chronostasis: one saved field's value, for the page, while the user has that issue open. Nothing here writes
 * anything. The template comes from the report and the field must be one the report lists for that app, so a request
 * can name neither a file nor a field outside the issues. Mask="true", in the saved copy or in the template, stays
 * hidden. */
require_once __DIR__ . '/lib.php';
function cs_template_file(string $file, string $dir): ?string {
  if ($file !== basename($file) || !preg_match('/^my-[^\/\x00-\x1F]+\.xml$/', $file)) return null;
  return cs_inside(rtrim($dir, '/') . "/$file", $dir);
}
/* state: value, hidden, or absent (the file no longer has that field). null: refused. */
function cs_field_value(array $report, string $app, string $key, string $dir): ?array {
  foreach (($report['apps'] ?? []) as $a) {
    if (($a['name'] ?? null) !== $app) continue;
    foreach (($a['findings'] ?? []) as $f) {
      $kind = $f['kind'] ?? '';
      $saved = $kind === 'renamed' ? ($f['fromKey'] ?? '') : (in_array($kind, ['yours', 'required', 'default'], true) ? ($f['key'] ?? '') : '');
      if ($saved === '' || $saved !== $key) continue;
      $file = cs_template_file((string)($a['file'] ?? ''), $dir);
      if ($file === null) return null;
      if (!empty($f['mask'])) return ['state' => 'hidden'];
      return cs_read_value($file, $key);
    }
  }
  return null;
}
function cs_read_value(string $file, string $key): ?array {
  $raw = cs_template_text($file);
  if ($raw === null) return null;
  $prev = libxml_use_internal_errors(true);
  $x = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NONET);
  libxml_clear_errors(); libxml_use_internal_errors($prev);
  if (!$x) return null;
  $out = ['state' => 'absent'];
  foreach ($x->Config as $c) {
    $a = $c->attributes();
    if (cs_field_key((string)($a['Type'] ?? ''), (string)($a['Target'] ?? ''), (string)($a['Name'] ?? ''), (string)($a['Mode'] ?? '')) !== $key) continue;
    if (strtolower(trim((string)($a['Mask'] ?? ''))) === 'true') return ['state' => 'hidden'];
    $out = ['state' => 'value', 'value' => (string)$c];
  }
  return $out;
}
