<?php
/* unraid-chronostasis: the last report and the check state, for the page. Read only. */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/detect.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$state = cs_read_json(cs_path('run') . '/state.json');
$pid = (int)($state['pid'] ?? 0);
$running = ($state['running'] ?? false) && $pid > 0 && file_exists("/proc/$pid");
$report = cs_read_json(cs_path('cache') . '/report.json');
$dismissed = cs_read_json(cs_path('config') . '/dismissed.json');
if ($report) $report = cs_apply_dismissed($report, $dismissed);
echo json_encode(['running' => $running, 'step' => $running ? ($state['step'] ?? '') : '',
  'error' => $running ? '' : ($state['error'] ?? ''), 'report' => $report ?: null], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
