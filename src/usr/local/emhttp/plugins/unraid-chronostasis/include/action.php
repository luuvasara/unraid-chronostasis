<?php
/* unraid-chronostasis: POST endpoint of the page. Fixed actions; no input reaches a shell. On Unraid the request
 * prologue checks csrf_token and defines csrf_terminate(); only without it does this file check the token itself. */
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function cs_out(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cs_out(405, ['ok' => false, 'error' => 'POST only']);
if (!function_exists('csrf_terminate')) {
  $var = @parse_ini_file(cs_path('varini'));
  $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!is_array($var) || !is_string($var['csrf_token'] ?? null) || $var['csrf_token'] === ''
      || !is_string($token) || !hash_equals($var['csrf_token'], $token)) cs_out(403, ['ok' => false, 'error' => 'invalid csrf_token']);
}
$a = $_POST['action'] ?? '';
if ($a === 'check') {
  exec(escapeshellarg(cs_path('script')) . ' check 2>&1', $out, $rc);
  cs_out($rc === 0 ? 200 : ($rc === 3 ? 409 : 500), ['ok' => $rc === 0, 'message' => implode("\n", array_slice($out, -2)), 'error' => implode("\n", array_slice($out, -2))]);
}
if ($a === 'dismiss' || $a === 'restore') {
  $id = $_POST['id'] ?? ''; $fp = $_POST['fp'] ?? '';
  if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/', $id) || !is_string($fp) || !preg_match('/^[0-9a-f]{12}$/', $fp)) cs_out(400, ['ok' => false, 'error' => 'invalid item']);
  $dir = cs_path('config'); @mkdir($dir, 0777, true);
  $lock = fopen(rtrim(cs_path('run'), '/') . '/dismiss.lock', 'c');
  if ($lock) flock($lock, LOCK_EX);
  $d = cs_read_json("$dir/dismissed.json");
  if ($a === 'dismiss') $d[$id] = ['fp' => $fp, 'when' => date('Y-m-d')]; else unset($d[$id]);
  $ok = cs_write_json("$dir/dismissed.json", $d);
  cs_out($ok ? 200 : 500, ['ok' => $ok, 'error' => $ok ? '' : 'could not save the dismiss list']);
}
if ($a === 'value') {
  require_once __DIR__ . '/detect.php';
  require_once __DIR__ . '/value.php';
  $app = $_POST['app'] ?? ''; $key = $_POST['key'] ?? '';
  if (!is_string($app) || !is_string($key) || strlen($app) > 255 || strlen($key) > 1024) cs_out(400, ['ok' => false, 'error' => 'invalid field']);
  $v = cs_field_value(cs_read_json(cs_path('cache') . '/report.json'), $app, $key, cs_path('templates'));
  cs_out($v === null ? 404 : 200, $v === null ? ['ok' => false, 'error' => 'no such field'] : ['ok' => true] + $v);
}
if ($a === 'share') {
  require_once __DIR__ . '/detect.php';
  require_once __DIR__ . '/share.php';
  $ids = $_POST['ids'] ?? ''; $maps = json_decode(is_string($_POST['mappings'] ?? null) ? $_POST['mappings'] : '[]', true);
  $asks = $_POST['asks'] ?? '';
  if (!is_string($ids) || !is_string($asks) || !is_array($maps)) cs_out(400, ['ok' => false, 'error' => 'invalid selection']);
  $report = cs_apply_dismissed(cs_read_json(cs_path('cache') . '/report.json'), cs_read_json(cs_path('config') . '/dismissed.json'));
  cs_out(200, ['ok' => true, 'text' => cs_share_text($report, array_filter(explode(',', $ids)), $maps, cs_plugin_version(), cs_unraid_version(), array_filter(explode(',', $asks)))]);
}
if (in_array($a, ['plan', 'open', 'jobs', 'retry', 'cancel', 'putback'], true)) {
  require_once __DIR__ . '/detect.php';
  require_once __DIR__ . '/upgrade.php';
  if ($a === 'jobs') { $js = cs_jobs_state(); $by = []; foreach ($js as $j) $by[$j['app']] = $j; cs_out(200, ['ok' => true, 'jobs' => $js, 'gone' => cs_gone_backups($by)]); }
  $app = is_string($_POST['app'] ?? null) ? $_POST['app'] : '';
  if ($a === 'retry') {
    $r = cs_upgrade_retry($app, is_string($_POST['which'] ?? null) ? $_POST['which'] : '');
    cs_out(isset($r['url']) ? 200 : 409, isset($r['url']) ? ['ok' => true] + $r : ['ok' => false] + $r);
  }
  if ($a === 'putback') {
    $r = cs_upgrade_putback($app, is_string($_POST['file'] ?? null) ? $_POST['file'] : '');
    cs_out(isset($r['url']) ? 200 : 409, isset($r['url']) ? ['ok' => true] + $r : ['ok' => false] + $r);
  }
  if ($a === 'cancel') { $ok = cs_upgrade_cancel($app); cs_out($ok ? 200 : 409, ['ok' => $ok]); }
  $answers = json_decode(is_string($_POST['answers'] ?? null) ? $_POST['answers'] : '', true);
  if (!is_array($answers)) cs_out(400, ['ok' => false, 'error' => 'invalid answers']);
  $report = cs_apply_dismissed(cs_read_json(cs_path('cache') . '/report.json'), cs_read_json(cs_path('config') . '/dismissed.json'));
  $tpls = cs_read_json(cs_path('cache') . '/templates.json');
  if ($a === 'plan') {
    $out = [];
    foreach (($report['apps'] ?? []) as $x) {
      $p = isset($tpls[$x['name']]) ? cs_plan_app($x, $tpls[$x['name']], $answers) : null;
      if ($p !== null) $out[] = $p;
    }
    cs_out(200, ['ok' => true, 'apps' => $out]);
  }
  foreach (($report['apps'] ?? []) as $x) {
    if ($x['name'] !== $app) continue;
    $r = cs_upgrade_open($x, $tpls[$app] ?? [], $answers);
    cs_out(isset($r['url']) ? 200 : 409, isset($r['url']) ? ['ok' => true] + $r : ['ok' => false] + $r);
  }
  cs_out(404, ['ok' => false, 'error' => 'no such app']);
}
cs_out(400, ['ok' => false, 'error' => 'unknown action']);
