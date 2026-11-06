<?php
/* CHANGELOG.md: "## <version>" headings, each followed by "- " lines. Newest first. */
function cs_changelog(string $file): array {
  $out = [];
  foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
    if (preg_match('/^## +(\S+)/', $l, $m)) $out[] = ['version' => $m[1], 'items' => []];
    elseif ($out && strpos($l, '- ') === 0) $out[count($out) - 1]['items'][] = substr($l, 2);
  }
  return $out;
}
