<?php
/* unraid-chronostasis: the text the user can copy. This is the only output meant to leave the server, and the plugin
 * never sends it. Allow list: field names, app, image, template address, plugin and Unraid version. Everything is
 * rebuilt here from identity data, so a value, hostname, IP or share path has no way in. */
const CS_REASONS = ['field-rename', 'field-removed', 'field-split', 'rename', 'registry-move', 'template-repo-move', 'author-handover', 'fork-remake', 'deprecation-replacement'];
const CS_REGISTRIES = ['docker.io', 'index.docker.io', 'registry.hub.docker.com', 'ghcr.io', 'lscr.io', 'quay.io', 'gcr.io', 'public.ecr.aws', 'mcr.microsoft.com', 'registry.gitlab.com'];

function cs_safe_text(string $s): bool {
  if ($s === '' || strlen($s) > 120) return false;
  if (preg_match('/\b\d{1,3}(\.\d{1,3}){3}\b/', $s)) return false;          // IPv4
  if (preg_match('/\b[0-9a-f]{0,4}(:[0-9a-f]{0,4}){3,}\b/i', $s)) return false; // IPv6
  if (strpos($s, '@') !== false) return false;
  return true;
}
/* a field name. Container paths are names too, except under /mnt, where they can spell out a share. */
function cs_safe_field(string $f): string {
  if (preg_match('~^/mnt(/|$)~i', $f) || preg_match('~^/(boot|root|home)(/|$)~i', $f)) return '(path)';
  if (!cs_safe_text($f) || !preg_match('~^[A-Za-z0-9_./:\-]+$~', $f)) return '(hidden)';
  if ($f[0] !== '/' && preg_match('~^[a-z0-9-]+(\.[a-z0-9-]+)+$~i', $f)) return '(hidden)';  // looks like a hostname
  return $f;
}
function cs_safe_image(string $r): string {
  $r = trim($r);
  if (!cs_safe_text($r) || !preg_match('~^[A-Za-z0-9_./:\-]+(@sha256:[0-9a-f]+)?$~', $r)) return '(private image)';
  $first = explode('/', $r)[0];
  if (strpos($r, '/') !== false && preg_match('/[.:]/', $first) && !in_array(strtolower($first), CS_REGISTRIES, true)) return '(private image)';
  return $r;
}
function cs_safe_url(string $u): string {
  $p = parse_url(trim($u));
  if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass'])) return '';
  if (!in_array(strtolower($p['host'] ?? ''), ['raw.githubusercontent.com', 'github.com', 'gitlab.com', 'forums.unraid.net'], true)) return '';
  $path = $p['path'] ?? '';
  return preg_match('~^[A-Za-z0-9_./%\-]+$~', $path) ? 'https://' . strtolower($p['host']) . $path : '';
}
function cs_safe_app(array $a): string {
  if (($a['feedName'] ?? '') !== '' && cs_safe_text($a['feedName']) && preg_match('~^[\w .+:\-]+$~u', $a['feedName'])) return $a['feedName'];
  $img = cs_safe_image((string)($a['repository'] ?? ''));
  return $img === '(private image)' ? '(unmatched app)' : basename(preg_replace('/[:@].*$/', '', $img));
}
function cs_plugin_version(): string {
  $v = getenv('CS_PLUGIN_VERSION');
  if ($v) return $v;
  return preg_match('/<!ENTITY version\s+"([^"]+)"/', (string)@file_get_contents('/var/log/plugins/unraid-chronostasis.plg'), $m) ? $m[1] : 'unknown';
}
function cs_unraid_version(): string {
  $v = getenv('CS_UNRAID_VERSION');
  if ($v) return $v;
  return preg_match('/version="([^"]+)"/', (string)@file_get_contents('/etc/unraid-version'), $m) ? $m[1] : 'unknown';
}
/* type, from, to, reason, source, date: the map's schema. Anything that does not pass is left out, whole. */
function cs_safe_mapping($m): ?array {
  if (!is_array($m)) return null;
  $type = $m['type'] ?? ''; $reason = $m['reason'] ?? ''; $date = $m['date'] ?? '';
  if (!in_array($type, ['app', 'field'], true) || !in_array($reason, CS_REASONS, true)) return null;
  if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
  $clean = $type === 'field' ? 'cs_safe_field' : 'cs_safe_image';
  foreach (['from', 'to'] as $k) {
    $v = is_string($m[$k] ?? null) ? $clean($m[$k]) : '';
    if ($v === '' || $v[0] === '(' ) return null;
    $m[$k] = $v;
  }
  $src = cs_safe_url(is_string($m['source'] ?? null) ? $m['source'] : '');
  if ($src === '') return null;
  return ['type' => $type, 'from' => $m['from'], 'to' => $m['to'], 'reason' => $reason, 'source' => $src, 'date' => $date];
}
/* $asks: ids of fields only in the saved copy that the user wants explained. The key name goes in, nothing else. */
function cs_share_text(array $report, array $ids, array $mappings, string $plugin, string $unraid, array $asks = []): string {
  $L = ['Chronostasis findings', 'Plugin ' . (preg_match('/^[\w.]+$/', $plugin) ? $plugin : 'unknown') . ', Unraid ' . (preg_match('/^[\w.\-]+$/', $unraid) ? $unraid : 'unknown'), ''];
  $names = ['new' => 'New in the template', 'yours' => 'Only in old settings', 'required' => 'Required differs', 'default' => 'Default differs'];
  foreach (($report['apps'] ?? []) as $a) {
    $rows = [];
    foreach ($a['findings'] as $f) {
      if ($f['kind'] === 'yours' && in_array($f['id'] ?? '', $asks, true)) {
        $rows[] = '- Question: what does this field mean? It is in old settings and not in the template: ' . preg_replace('/[^A-Za-z]/', '', (string)($f['type'] ?? '')) . ' ' . cs_safe_field((string)$f['label']);
        continue;
      }
      if ($ids !== [] && !in_array($f['id'] ?? '', $ids, true)) continue;
      if (isset($names[$f['kind']])) $rows[] = '- ' . $names[$f['kind']] . ': ' . preg_replace('/[^A-Za-z]/', '', (string)($f['type'] ?? '')) . ' ' . cs_safe_field((string)$f['label']) . (!empty($f['warn']) ? ' (required, no default)' : '');
      elseif ($f['kind'] === 'renamed') $rows[] = '- Variable with a new target: ' . cs_safe_field((string)($f['from'] ?? '')) . ' to ' . cs_safe_field((string)$f['label']);
      elseif ($f['kind'] === 'image') $rows[] = '- Image: in use ' . cs_safe_image((string)($f['saved'] ?? '')) . ', template ' . cs_safe_image((string)$f['label']);
      elseif ($f['kind'] === 'address') $rows[] = '- Template address: ' . (['dead' => 'the one in use answers 404', 'other' => 'in use ' . (cs_safe_url((string)($a['savedUrl'] ?? '')) ?: '(hidden)') . ', current ' . (cs_safe_url((string)($a['feedUrl'] ?? '')) ?: '(hidden)'), 'missing' => 'the template in use has no address'][$f['key']] ?? 'differs');
      elseif ($f['kind'] === 'moderation') $rows[] = '- Community Applications: ' . (['deprecated' => 'deprecated', 'blacklisted' => 'blacklisted'][$f['key']] ?? 'moderator comment');
    }
    if (!$rows) continue;
    $L[] = 'App: ' . cs_safe_app($a);
    $L[] = 'Image: ' . cs_safe_image((string)($a['repository'] ?? ''));
    $feed = cs_safe_url((string)($a['feedUrl'] ?? ''));
    $u = $feed ?: cs_safe_url((string)($a['savedUrl'] ?? ''));
    if ($u !== '') $L[] = ($feed !== '' ? 'Current template: ' : 'Template in use: ') . $u;
    array_push($L, ...$rows);
    $L[] = '';
  }
  $clean = array_values(array_filter(array_map('cs_safe_mapping', $mappings)));
  if ($clean) {
    $L[] = 'Mappings:';
    $L[] = json_encode($clean, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  }
  return implode("\n", $L);
}
