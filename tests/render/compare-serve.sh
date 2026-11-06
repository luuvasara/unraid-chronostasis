#!/bin/bash
# compare-serve.sh - the page with made-up fixtures, black on :18811 and white on :18812, for looking at the comparison.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/../.." && pwd); W=$HERE/tests/.work/compare; rm -rf "$W"; mkdir -p "$W"
"$HERE/tests/render/fetch-webgui.sh" >/dev/null
F=$HERE/tests/fixtures/compare
for t in black:18811 white:18812; do
  theme=${t%:*}; port=${t#*:}; R=$W/$theme; WR=$R/web; mkdir -p "$WR/plugins" "$R/run" "$R/cache" "$R/config" "$R/fake/c"
  cp -a "$HERE/src/usr/local/emhttp/plugins/unraid-chronostasis" "$WR/plugins/"; cp "$HERE/CHANGELOG.md" "$WR/plugins/unraid-chronostasis/"
  cp -a "$HERE/tests/.work/webgui/webGui" "$WR/"; printf 'csrf_token="TOKEN123"\n' >"$R/var.ini"
  cp -a "$F/templates" "$R/tpl"
  CS_HISTORY=$F/history.json CS_TEMPLATES=$R/tpl CS_FCPFEED=$F/feed.json CS_CACHE=$R/cache CS_RUN=$R/run CS_DOCKER=/bin/false CS_NO_URLCHECK=1 php "$HERE/src/usr/local/emhttp/plugins/unraid-chronostasis/scripts/check.php"
  env THEME="$theme" WEBROOT="$WR" WEBGUI= CS_VARINI="$R/var.ini" CS_TEMPLATES="$R/tpl" CS_CACHE="$R/cache" CS_RUN="$R/run" CS_CONFIG="$R/config" CS_DOCKER="$HERE/tests/render/fake-docker.sh" CS_FAKE_DOCKER="$R/fake" CS_SETTLE=0 CS_UP=0 CS_SCRIPT=/bin/true CS_PLUGIN_VERSION=2026.11.05 CS_UNRAID_VERSION=7.3.4 \
    php -S 127.0.0.1:"$port" -t "$WR" "$HERE/tests/render/router.php" >"$R/php.log" 2>&1 &
done
wait
