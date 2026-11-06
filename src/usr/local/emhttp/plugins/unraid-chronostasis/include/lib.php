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
