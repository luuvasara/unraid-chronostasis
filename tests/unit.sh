#!/bin/bash
# unit.sh - offline tests. Nothing here touches a server.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/.." && pwd)
P=$HERE/src/usr/local/emhttp/plugins/unraid-chronostasis
W=$HERE/tests/.work/unit; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
php "$HERE/tests/detect-test.php" || fail=1
php "$HERE/tests/upgrade-test.php" || fail=1
for f in "$P"/include/*.php "$P"/scripts/*.php; do php -l "$f" >/dev/null || bad "php -l $f"; done
bash -n "$P/scripts/chronostasis" && ok "script syntax"
command -v shellcheck >/dev/null && { shellcheck -S warning "$P/scripts/chronostasis" "$HERE/build.sh" && ok shellcheck || bad shellcheck; }

# check.php against fixtures: writes the report, nothing else, and leaves the templates alone
T=$W/templates; cp -a "$HERE/tests/fixtures/templates" "$T"
snap() { (cd "$T" && find . -type f -printf '%p %s %T@\n' -exec md5sum {} + | sort | md5sum); }
BEFORE=$(snap)
printf '#!/bin/sh\nprintf "alpha\\nchild\\ndup\\nmoved\\ndead\\nmy-homebox.lan-app\\nshape-required-path\\nshape-required-path-default\\nshape-optional-field\\nshape-variable-target\\nshape-default-only\\nshape-pair\\nshape-history\\n"\n' >"$W/docker"; chmod +x "$W/docker"
printf '%s' '{"cron":{"dockerCronFrequency":"daily"},"global":{"dockerUpdateAll":"no"},"containers":{"alpha":{"name":"alpha","update":true}}}' > "$W/auto.json"
# the fixture feed counts as fresh by its file time, which depends on when the repository was checked out: use a copy made now,
# and no download address, so a stale checkout can never fall back to the live feed
cp "$HERE/tests/fixtures/feed.json" "$W/fresh.json"
CS_FEED_URLS=http://127.0.0.1:9/none CS_AUTOUPDATE=$W/auto.json CS_TEMPLATES=$T CS_FCPFEED=$W/fresh.json CS_CACHE=$W/cache CS_RUN=$W/run CS_DOCKER=$W/docker CS_NO_URLCHECK=1 php "$P/scripts/check.php"; RC=$?
[ "$RC" = 0 ] && [ -s "$W/cache/report.json" ] && [ -s "$W/cache/templates.json" ] && ok "check writes a report and the template data" || bad "check rc=$RC"
AFTER=$(snap)
[ "$BEFORE" = "$AFTER" ] && ok "the saved templates are untouched" || bad "templates changed"
N=$(python3 -I -c 'import json,sys; print(len(json.load(open(sys.argv[1]))["apps"]))' "$W/cache/report.json")
[ "$N" = 13 ] && ok "a template with no container is left out" || bad "apps: $N"
python3 -I -c 'import json,sys; r=json.load(open(sys.argv[1])); sys.exit(0 if r["noContainer"]==1 and [a["name"] for a in r["apps"] if a["autoUpdated"]]==["alpha"] else 1)' "$W/cache/report.json" && ok "auto-updated app marked, template with no container counted" || bad "auto or noContainer"
# the reused feed: fresh is used (above); stale, or with no field lists, must not be
NOURL="CS_TEMPLATES=$T CS_FEED_URLS=http://127.0.0.1:9/none CS_RUN=$W/run3 CS_DOCKER=$W/docker CS_NO_URLCHECK=1"
cp "$HERE/tests/fixtures/feed.json" "$W/stale.json"; touch -d '2 days ago' "$W/stale.json"
env $NOURL CS_CACHE=$W/c1 CS_FCPFEED=$W/stale.json php "$P/scripts/check.php" 2>/dev/null && bad "a stale feed copy was used" || ok "a feed copy over a day old is not used"
python3 -I -c 'import json,sys; d=json.load(open(sys.argv[1])); [a.pop("Config",None) for a in d["applist"]]; json.dump(d,open(sys.argv[2],"w"))' "$HERE/tests/fixtures/feed.json" "$W/small.json"
env $NOURL CS_CACHE=$W/c2 CS_FCPFEED=$W/small.json php "$P/scripts/check.php" 2>/dev/null && bad "a feed with no field lists was used" || ok "a feed copy with no field lists is not used"
grep -q 'GitHub/AppFeed' "$P/include/lib.php" "$P/scripts/check.php" && bad "development-only feed path" || ok "no development-only feed path"
grep -q 'applicationFeed-small' "$P/scripts/check.php" && bad "small feed address" || ok "the small feed is not fetched"
grep -q 'SECRET\|MASKVALUE\|192\.168\.77' "$W/cache/templates.json" && bad "a value reached the template data" || ok "no value of yours in the template data"
grep -q 'SECRET\|MASKVALUE\|MASKDEFAULT\|192\.168\.77' "$W/cache/report.json" && bad "a value reached the report" || ok "no value in the report"
grep -nE '(^|[^_a-z])(exec|shell_exec|passthru|system|popen|proc_open)\(|unlink' "$P/scripts/check.php" | grep -v "ps -a\|@unlink(\$tmp)" && bad "check.php runs something new" || ok "check.php only lists containers"
CS_TEMPLATES=$T CS_FCPFEED=/nonexistent CS_FEED_URLS=http://127.0.0.1:9/none CS_CACHE=$W/nofeed CS_RUN=$W/run2 CS_DOCKER=$W/docker php "$P/scripts/check.php" 2>/dev/null
RC=$?; grep -q 'Could not get the Community' "$W/run2/state.json" && [ "$RC" = 1 ] && ok "no feed: a clear error" || bad "no feed rc=$RC"

grep -rIl -iE 'python|\.py\b' "$P" && bad "python in the plugin" || ok "no Python in the plugin"
# status.php gives the page the counts, and the flag behind them, from the report on disk
J=$(CS_CACHE=$W/cache CS_RUN=$W/run CS_CONFIG=$W/noconfig php "$P/include/status.php")
python3 -I -c 'import json,sys; c=json.loads(sys.argv[1])["report"]["counts"]; sys.exit(0 if (c["issues"],c["issueApps"])==(7,5) and c["others"]==22 else 1)' "$J" && ok "status.php: 7 to check on 5 apps (no dead address without the status check), 22 other differences" || bad "status.php counts: $J"
# a report saved by the version before: field differences stay quiet until the next check
OLD=$W/oldcache; mkdir -p "$OLD"
printf '%s' '{"apps":[{"name":"a","confidence":"high","feedUrl":"","savedUrl":"","findings":[{"kind":"new","key":"k","label":"X","fp":"abc"}]}]}' >"$OLD/report.json"
J=$(CS_CACHE=$OLD CS_RUN=$W/run CS_CONFIG=$W/noconfig php "$P/include/status.php")
python3 -I -c 'import json,sys; c=json.loads(sys.argv[1])["report"]["counts"]; sys.exit(0 if (c["issues"],c["others"])==(0,1) else 1)' "$J" && ok "a report from the version before stays quiet" || bad "old report: $J"

# the one promise, once in each place; the check itself has no way to a value
PROMISE='Values are shown only on your own server; they are never in reports or in anything you copy or paste.'
for f in "$HERE/README.md" "$P/README.md" "$P/ChronostasisCheck.page" "$P/include/detect.php"; do
  [ "$(grep -cF "$PROMISE" "$f")" = 1 ] && ok "the promise, once: ${f##*/}" || bad "promise in $f"
done
grep -rIlE 'never read|values are not read|without reading' "$HERE/README.md" "$P/README.md" "$P"/*.page "$P/include" "$P/assets" && bad "old wording about reading values" || ok "no wording about values not being read"
grep -qE 'value\.php|cs_field_value|cs_read_value' "$P/scripts/check.php" "$P/include/share.php" "$P/include/status.php" "$P/include/detect.php" && ! grep -q 'value.php gives the page' "$P/include/detect.php" && bad "the check or the share text reaches a value" || ok "check, status and share do not load the value reader"
grep -qE 'file_put_contents|fopen|error_log|cs_write_json' "$P/include/value.php" && bad "value.php writes" || ok "the value reader writes nothing"

# install message
S=$P/scripts/chronostasis
export CS_CHANGELOG=$HERE/CHANGELOG.md
VER=$(sed -n 's/^## *\([^ ]*\).*/\1/p' "$CS_CHANGELOG" | sed -n 1p)
O=$(bash "$S" installed "" "$VER")
grep -q 'Press\|press Check now' <<<"$O" && grep -q "Latest changes in $VER" <<<"$O" && ok "first install: text and box" || bad "first install"
O=$(bash "$S" installed 2026.01.01 "$VER")
grep -q "updated from 2026.01.01 to $VER" <<<"$O" && grep -q "Latest changes in $VER" <<<"$O" && ok "update: box" || bad "update"
grep -q 'press Check now\|Nothing has been checked' <<<"$O" && bad "update prints first-install text" || ok "update has no first-install text"
O=$(bash "$S" installed "$VER" "$VER")
grep -q '^+--' <<<"$O" && bad "a boot prints the box" || ok "a boot prints one line"
O=$(bash "$S" installed "" "$VER")
W2=$(grep -E '^[|+]' <<<"$O" | awk '{print length}' | sort -u | wc -l)
[ "$W2" = 1 ] && ok "box lines have one width" || bad "box width: $W2"
LC_ALL=C grep -qP '[^\x00-\x7F]' <<<"$O" && bad "box is not ASCII" || ok "box is ASCII"
L1=$(awk '/^## /{n++; next} n==1 && /^- /{print; exit}' "$CS_CHANGELOG" | cut -c1-40)
grep -qF -- "$L1" <<<"$O" && ok "box comes from CHANGELOG.md" || bad "box content"

# one source for the changes
if [ -f "$HERE/unraid-chronostasis.plg" ]; then
  python3 -I - "$HERE/unraid-chronostasis.plg" "$CS_CHANGELOG" <<'PY' && ok ".plg CHANGES equals CHANGELOG.md" || bad ".plg CHANGES differs: run build.sh"
import re, sys
plg = open(sys.argv[1]).read(); log = open(sys.argv[2]).read()
got = re.search(r'<CHANGES>\n(.*?)\n</CHANGES>', plg, re.S)
want = [('###' + l[3:].strip()) if l.startswith('## ') else l.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;') for l in log.split('\n') if l.startswith(('## ', '- '))]
sys.exit(0 if got and got.group(1).split('\n') == want else 1)
PY
fi
M=$(sed -n 's/^ *<!ENTITY md5 *"\([^"]*\)".*/\1/p' "$HERE/unraid-chronostasis.plg")
V=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)".*/\1/p' "$HERE/unraid-chronostasis.plg")
[ "$M" = "$(md5sum "$HERE/packages/unraid-chronostasis-$V-x86_64-1.txz" 2>/dev/null | cut -d' ' -f1)" ] && ok "package matches the .plg" || bad "package is stale (run ./build.sh)"
grep -rIl -E 'not tested|untested|tested on' "$HERE/README.md" "$CS_CHANGELOG" && bad "public text makes a claim about testing" || ok "no claims about testing in public text"
# Visible text must not imply that drift means risk: a difference is only a difference
drift_words() { grep -inE '\b(risk|risks|risky|at risk|unsafe|danger|dangerous|vulnerable|vulnerabilit|insecure|outdated|out of date|stale|must update|should update|wrong|broken|bad)\b' "$@"; }
printf 'this app is risky\n' >"$W/sample.txt"
drift_words "$W/sample.txt" >/dev/null && ok "the wording check catches a bad word" || bad "the wording check cannot fail"
if drift_words "$P/assets/chronostasis.js" "$P/include/detect.php" "$P/ChronostasisCheck.page" "$P/ChronostasisAbout.page" "$P/README.md" "$HERE/README.md" "$HERE/CHANGELOG.md" | grep -v '^[^:]*:[0-9]*: *\(/\*\|\*\|//\)'; then bad "visible text implies risk"; else ok "visible text does not imply that a difference is a risk"; fi
grep -q 'A difference is only a difference' "$P/assets/chronostasis.js" && ok "the list says a difference is only a difference" || bad "plain statement missing"
# Every button says what pressing it does: each label in the code has a tooltip
tip_gaps() {
  python3 - "$1" <<'PY'
import re,sys
s=open(sys.argv[1]).read().replace("\\'","'")
tips=set(re.findall(r"^    '([^\n]+?)': ",s[s.index('var TIPS'):s.index('var SELECT_TIPS')],re.M))
labels=set(re.findall(r"el\('button', '[^']*', '([^\n]+?)'\)",s))|set(re.findall(r"\bb\('([^\n]+?)', function",s))|set(re.findall(r"text: '([^\n]+?)'(?:,| \})",s[s.index('var ANSWERS'):s.index('function walkItems')]))
labels|=set(re.findall(r"\['\w+', '([^\n]+?)'\]",s[s.index('function jobButtons'):]))
labels|={'Dismiss','Bring back'}
labels-={'Dismissed'}
print('\n'.join(sorted(labels-tips)))
PY
}
GAPS=$(tip_gaps "$P/assets/chronostasis.js"); [ -z "$GAPS" ] && ok "every button label has a tooltip" || bad "buttons with no tooltip: $GAPS"
sed "s/^    'Previous difference': .*$//" "$P/assets/chronostasis.js" >"$W/no-tip.js"
[ -n "$(tip_gaps "$W/no-tip.js")" ] && ok "the tooltip check can fail" || bad "the tooltip check cannot fail"
exit $fail
