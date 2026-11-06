#!/bin/bash
# render-test.sh - the page in a local harness with Unraid's stylesheets and headless Chrome. Hostile strings in the
# report must stay text, the walkthrough must reach the share box, and About must show the CHANGELOG.md version.
set -uo pipefail
HERE=$(cd "$(dirname "$0")/../.." && pwd)
W=$HERE/tests/.work/render; rm -rf "$W"; mkdir -p "$W"
fail=0; ok() { echo "ok   $*"; }; bad() { echo "FAIL $*"; fail=1; }
"$HERE/tests/render/fetch-webgui.sh" >/dev/null || { echo "FAIL fetch"; exit 1; }
"$HERE/tests/render/fetch-webgui.sh" 6.12 >/dev/null || { echo "FAIL fetch 6.12"; exit 1; }
SHOTS=${SHOTS:-$W}
PORT=${RENDER_PORT:-18771}
PIDS=(); trap 'for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done' EXIT
scenario() { # name port theme gui
  local name=$1 port=$2 theme=$3 gui=${4:-}
  local R=$W/$name; local WR=$R/web; mkdir -p "$WR/plugins" "$R/run" "$R/cache" "$R/config" "$R/fake/c"
  cp -a "$HERE/src/usr/local/emhttp/plugins/unraid-chronostasis" "$WR/plugins/"
  cp "$HERE/CHANGELOG.md" "$WR/plugins/unraid-chronostasis/"
  cp -a "$HERE/tests/.work/webgui${gui:+-$gui}/webGui" "$WR/"
  printf 'csrf_token="TOKEN123"\n' >"$R/var.ini"
  for c in shape-pair shape-history shape-variable-target; do echo "aaa|running|2020-01-01T00:00:00.000Z|dockerman||bridge|0|" >"$R/fake/c/$c"; done
  CS_HISTORY=$HERE/tests/fixtures/history.json CS_TEMPLATES=$HERE/tests/fixtures/templates CS_FCPFEED=$HERE/tests/fixtures/feed-hostile.json CS_CACHE=$R/cache CS_RUN=$R/run CS_DOCKER=/bin/false \
    CS_NO_URLCHECK=1 php "$HERE/src/usr/local/emhttp/plugins/unraid-chronostasis/scripts/check.php"
  env THEME="$theme" WEBROOT="$WR" WEBGUI="$gui" CS_VARINI="$R/var.ini" CS_TEMPLATES="$HERE/tests/fixtures/templates" CS_CACHE="$R/cache" CS_RUN="$R/run" CS_CONFIG="$R/config" \
    CS_DOCKER="$HERE/tests/render/fake-docker.sh" CS_FAKE_DOCKER="$R/fake" CS_SETTLE=0 CS_UP=0 CS_SCRIPT=/bin/true CS_PLUGIN_VERSION=2026.11.05 CS_UNRAID_VERSION=7.3.4 \
    php -S 127.0.0.1:"$port" -t "$WR" "$HERE/tests/render/router.php" >"$R/php.log" 2>&1 & PIDS+=($!)
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.1; done
}
chrome() { google-chrome --headless=new --disable-gpu --no-first-run --no-default-browser-check --hide-scrollbars --no-sandbox \
             --user-data-dir="$W/chrome-profile" --virtual-time-budget=6000 "$@" 2>/dev/null; }
python3 -I - "$HERE/tests/fixtures/feed.json" "$W/../feed-hostile.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
for a in d['applist']:
    if a.get('Name') == 'Moved': a['ModeratorComment'] = 'plain <img src=x onerror="document.documentElement.dataset.pwned=1"> MARKCOMMENT'; a['Repo'] = '<svg onload="document.documentElement.dataset.pwned=1">MARKREPO'
    if a.get('Name') == 'AlphaApp': a['Project'] = 'javascript:document.documentElement.dataset.pwned=1'
json.dump(d, open(sys.argv[2], 'w'))
PY
cp "$W/../feed-hostile.json" "$HERE/tests/fixtures/feed-hostile.json"
trap 'rm -f "$HERE/tests/fixtures/feed-hostile.json"; for p in "${PIDS[@]}"; do kill "$p" 2>/dev/null; done' EXIT
scenario black "$PORT" black; scenario white "$((PORT + 1))" white; scenario legacy "$((PORT + 2))" black 6.12
D=$(chrome --dump-dom "http://127.0.0.1:$PORT/")
text() { sed -E 's/<span class="cs-diff">([^<]*)<\/span>/\1/g; s/<[^>]*>/ /g' <<<"$1"; }
J=$(curl -s "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/status.php")
read -r ISS ISA OTH OTA ALL < <(python3 -I -c 'import json,sys; c=json.loads(sys.argv[1])["report"]["counts"]; print(c["issues"],c["issueApps"],c["others"],c["otherApps"],c["apps"])' "$J")
grep -q "$ISS to check on $ISA of $ALL apps" <<<"$D" && ok "summary counts what is to check: $ISS on $ISA of $ALL apps" || bad "summary missing"
grep -q "Go through the $OTH other differences" <<<"$D" && ok "summary counts the other differences: $OTH on $OTA apps" || bad "other differences line missing"
grep -q 'A difference is only a difference' <<<"$D" && ok "the summary keeps the plain statement" || bad "plain statement gone"
grep -q "<details" <<<"$D" && bad "a details list drawn with the app closed" || ok "other differences stay closed until the app is opened"
W1=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=%23cs-walkstart")
grep -q "Issue 1 of $ISS" <<<"$(text "$W1")" && ok "Work through issues counts $ISS" || bad "walkthrough count"
W2=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=%23cs-walkother")
grep -q "Other difference 1 of $OTH" <<<"$(text "$W2")" && ok "other differences can be gone through: $OTH" || bad "other differences walkthrough"
O=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=.cs-head")
grep -q '<details class="cs-others">' <<<"$O" && bad "first app has no closed list" || ok "an app to check that has no other differences has no list"
# alpha has only other differences: its list is there, closed, and opens
ALPHA=$(python3 -I -c 'import json,sys; print(1+[a["name"] for a in json.loads(sys.argv[1])["report"]["apps"] if a["findings"] or a["confidence"]=="none"].index("alpha"))' "$J")
O=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=.cs-app:nth-child($ALPHA)%20.cs-head")
grep -q '<details class="cs-others">' <<<"$O" && grep -q 'Other differences (' <<<"$O" && ok "other differences are there, collapsed" || bad "collapsed list"
O=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=.cs-app:nth-child($ALPHA)%20.cs-head,.cs-others%20summary")
grep -q '<details class="cs-others" open' <<<"$O" && grep -q 'LOG_LEVEL' <<<"$O" && ok "and they open" || bad "open list"
# the comparison: your copy above, the template below, and one question
pv() { chrome --dump-dom "http://127.0.0.1:$PORT/?do=$1" | python3 -I -c 'import re,sys,html; m=re.search(r"id=\"cs-preview\".*?(?=<p class=\"cs-map|<div class=\"cs-answers|$)",sys.stdin.read(),re.S); print(re.sub(r"\s+"," ",html.unescape(re.sub(r"<[^>]+>"," ",re.sub(r"<span class=\"cs-diff\">([^<]*)</span>",r"\1",m.group(0))))) if m else "NO PREVIEW")'; }
dom() { chrome --dump-dom "http://127.0.0.1:$PORT/?do=$1"; }
START=%23cs-walkother,find:Variable%20MOVIES_DIR
P0=$(pv "$START")
grep -q 'This is a guess' <<<"$P0" && grep -q 'Old settings MOVIE_DIR /movies' <<<"$P0" && grep -q 'Template MOVIES_DIR /movies' <<<"$P0" && ok "case 3: the guess, old above and new below, value carried over" || bad "guess: $P0"
P1=$(pv "$START,pick:LIBRARY_DIR")
grep -q 'Old settings LIBRARY_DIR FAKEVALUE-library' <<<"$P1" && grep -q 'Template MOVIES_DIR FAKEVALUE-library' <<<"$P1" && ok "changing the dropdown redraws the preview" || bad "preview follows: $P1"
P2=$(pv "$START,pick:not%20paired")
grep -q 'not in old settings' <<<"$P2" && grep -q 'Do you want to include it' <<<"$P2" && ok "not paired: case 2" || bad "not paired: $P2"
P3=$(pv "$START,%23cs-no")
grep -q 'not in old settings' <<<"$P3" && ok "No, different: the new field falls through to case 2" || bad "no: $P3"
P3=$(pv "$START,%23cs-no,find:Variable%20MOVIE_DIR")
grep -q 'Is it your own' <<<"$P3" && grep -q 'not in the template' <<<"$P3" && ok "and the saved field to case 1" || bad "no yours: $P3"
Y=$(dom "$START,%23cs-yes"); TY=$(text "$Y")
grep -q "Other difference [0-9]* of $((OTH - 1))" <<<"$TY" && grep -q 'Your answer: Yes, same field' <<<"$TY" && grep -q 'Template.*MOVIES_DIR.*/movies' <<<"$TY" && ok "Yes, same field: answered, preview stays, one less to go through" || bad "yes: $TY"
Y=$(dom "$START,%23cs-yes,%23cs-next,%23cs-next,%23cs-next,%23cs-next,%23cs-next,%23cs-next,%23cs-next,%23cs-next,%23cs-finish")
grep -q 'cs-answerlist' <<<"$Y" && grep -q 'MOVIE_DIR is MOVIES_DIR: Yes, same field' <<<"$(text "$Y")" && ok "the answer is listed at Finish" || bad "finish list"
grep -q 'then opens Unraid' <<<"$(text "$Y")" && ok "Finish says where the change is made" || bad "finish note"
N=$(for i in $(seq 1 14); do printf ',%%23cs-next'; done)
A=$(dom "$START,%23cs-yes$N"); grep -q 'Variable MOVIE_DIR$' <<<"$(text "$A" | grep -n 'cs-issue')" && bad "asked again" || ok "a confirmed match is not asked again"
BACK=$(dom "$START,%23cs-yes,%23cs-next,%23cs-prev"); grep -q 'Your answer: Yes, same field' <<<"$(text "$BACK")" && ok "Previous shows the answer" || bad "previous"
F=$(chrome --virtual-time-budget=9000 --dump-dom "http://127.0.0.1:$PORT/?do=$START,%23cs-yes,%23cs-finish,%23cs-go-upgrade"); TF=$(text "$F")
grep -q 'Upgrade: app 1 of' <<<"$TF" && ok "Finish shows the upgraded settings" || bad "no upgraded settings"
grep -q 'Upgraded settings *MOVIES_DIR */movies' <<<"$TF" && grep -q 'Old settings *MOVIE_DIR */movies' <<<"$TF" && ok "old above, upgraded below" || bad "finish rows"
for t in 'That is how Unraid has implemented Apply' 'note it from the Apply window too' 'are not touched' 'never in reports or in anything you copy or paste' 'Review in Unraid' 'Old settings are saved in this folder first' 'Keep the name in the form'; do grep -q "$t" <<<"$TF" && ok "Finish says: $t" || bad "Finish lacks: $t"; done
grep -q 'MASKVALUE' <<<"$F" && bad "a masked value in the page" || ok "no masked value on the Finish page"
R=$(dom "%23cs-walkother,find:Variable%20LIBRARY_DIR,pick:not%20paired,%23cs-report,%23cs-finish")
grep -q 'LIBRARY_DIR: Report it' <<<"$(text "$R")" && ok "Report it is listed" || bad "report list"
O=$(dom "%23cs-walkother,find:Variable%20ODD_VALUE,pick:not%20paired,%23cs-own"); grep -q "Your answer: It's my own\|Your answer: It&#39;s my own" <<<"$O" && ok "It's my own is answered" || bad "own"
curl -s "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/status.php" | grep -q '"dismissed":1' && ok "It's my own is the same as Dismiss" || bad "own is no dismiss"
ODDID=$(curl -s "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/status.php" | python3 -I -c 'import json,sys
for a in json.load(sys.stdin)["report"]["apps"]:
    for f in a["dismissed"]: print(f["id"],f["fp"])')
curl -s -o /dev/null --data "action=restore&csrf_token=TOKEN123&id=${ODDID% *}&fp=${ODDID#* }" "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/action.php"
P=$(pv "%23cs-walkother,find:Variable%20OLD_TOKEN,pick:not%20paired")
grep -q 'OLD_TOKEN hidden' <<<"$P" && ! grep -q MASKVALUE <<<"$P" && ok "a masked field shows hidden" || bad "masked: $P"
P=$(pv "%23cs-walkother,find:Variable%20NEW_KEY,pick:not%20paired")
grep -q 'NEW_KEY hidden' <<<"$P" && ! grep -q feedmask <<<"$P" && ok "a masked template field shows hidden" || bad "masked new: $P"
P=$(pv "%23cs-walkother,find:Variable%20FRESH_OPT,pick:not%20paired")
grep -q 'FRESH_OPT fresh-default' <<<"$P" && ok "case 2: key and template default" || bad "default: $P"
P=$(pv "%23cs-walkother,find:Variable%20SCRATCH_DIR")
grep -q 'Old settings CACHE_DIR /fake/cache' <<<"$P" && grep -q 'CACHE_DIR was in this template until 2025-11 and was replaced by SCRATCH_DIR in the same change' <<<"$P" && ok "history: the evidence line under the comparison" || bad "history: $P"
P=$(pv "%23cs-walkother,find:Variable%20RETIRED_KEY,pick:not%20paired")
grep -q 'used to have' <<<"$P" && ! grep -q 'Is it your own' <<<"$P" && grep -q 'RETIRED_KEY was in this template until 2024-03' <<<"$P" && ok "history: no is-it-your-own question" || bad "past: $P"
P=$(pv "%23cs-walkother,find:Variable%20MOVIES_DIR")
grep -q 'Suggested because' <<<"$P" && ! grep -q 'in this template until' <<<"$P" && ok "no history for that template: nothing about history, the reason shows" || bad "reason: $P"
P=$(pv "%23cs-walkother,find:Port%208088/tcp")
grep -q 'Port in the container Host port' <<<"$P" && grep -q '8088/tcp 18088' <<<"$P" && grep -q 'not in the template' <<<"$P" && ok "case 1: Port and host port" || bad "yours: $P"
P=$(pv "%23cs-walkother,find:Path%20/media")
grep -q 'Host path' <<<"$P" && grep -q '/media /mnt/user/FakeMedia' <<<"$P" && ok "Path and host path" || bad "path: $P"
P=$(pv "%23cs-walkother,find:shape-pair:%20Variable%20LOG_LEVEL")
grep -q 'LOG_LEVEL FAKEVALUE-level' <<<"$P" && grep -q 'template default is now debug' <<<"$P" && ok "default change: your value stays" || bad "default change: $P"
P=$(pv "%23cs-walkother,find:RUN_MODE")
grep -q '(not required)' <<<"$P" && grep -q 'RUN_MODE FAKEVALUE-mode (required)' <<<"$P" && ok "required change" || bad "required: $P"
grep -q "You make the change in Unraid" <<<"$P" && ok "the preview says where the change is made" || bad "no pointer to the form"
P=$(pv "%23cs-walkstart,find:Image%20differs")
grep -q 'Old settings Image maker/moved' <<<"$P" && grep -q 'Template Image ghcr.io/maker/moved' <<<"$P" && ok "image: the same two rows" || bad "image: $P"
P=$(chrome --dump-dom "http://127.0.0.1:$PORT/?do=%23cs-walkother,find:Variable%20ODD_VALUE,pick:not%20paired")
grep -q 'data-pwned=' <<<"$(grep -o '<html[^>]*>' <<<"$P")" && bad "a value ran as markup" || ok "a value with markup stays text"
grep -q '&lt;img src=x' <<<"$P" && ok "and is shown as text" || bad "odd value not shown"
FP=$(pv "%23cs-walkstart,find:FILL_ME")
grep -q 'FILL_ME you fill this in' <<<"$FP" && ok "required with no default: you fill this in" || bad "fill in: $FP"
pa() { curl -s -w ' %{http_code}' --data-urlencode "$1" --data-urlencode "$2" -d "action=value&csrf_token=${3-TOKEN123}" "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/action.php"; }
V=$(pa app=shape-pair key=variable:MOVIE_DIR); grep -q '"value":"\\/movies".* 200$' <<<"$V" && ok "value action answers" || bad "value action: $V"
V=$(pa app=shape-pair key=variable:OLD_TOKEN); grep -q '"state":"hidden".* 200$' <<<"$V" && ! grep -q MASKVALUE <<<"$V" && ok "value action: masked is hidden" || bad "masked action: $V"
V=$(pa app=shape-pair key=variable:MOVIE_DIR BAD); grep -q ' 403$' <<<"$V" && ok "value action: bad token refused" || bad "token: $V"
V=$(pa app=shape-pair key=variable:NOPE); grep -q ' 404$' <<<"$V" && ok "value action: bad key refused" || bad "key: $V"
V=$(pa app=shape-pair key=path:/config); grep -q ' 404$' <<<"$V" && ok "value action: a field that is no issue refused" || bad "no issue: $V"
V=$(pa app=../../etc/passwd key=variable:MOVIE_DIR); grep -q ' 404$' <<<"$V" && ok "value action: bad template refused" || bad "app: $V"
V=$(curl -s -o /dev/null -w '%{http_code}' -F action=value -F csrf_token=TOKEN123 "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/status.php"); [ "$V" = 200 ] && ok "status.php ignores it" || bad "status: $V"
grep -rIlE 'FAKEVALUE|MARKVALUE|MASKVALUE' "$W/black/cache" "$W/black/run" "$W/black/config" "$W/black/php.log" && bad "a value was stored" || ok "after all that, no value in cache, run, config or log"
grep -q 'data-pwned' <<<"$D" && bad "a hostile string ran" || ok "no hostile string ran"
grep -q 'wrong' <<<"$(sed 's/<[^>]*>//g' <<<"$D")" && bad "the page says wrong" || ok "the page does not say wrong"
grep -q 'SECRETVALUE9876\|MASKVALUE7777' <<<"$J" && bad "a value in status.php" || ok "no value in status.php"
A=$(chrome --dump-dom "http://127.0.0.1:$PORT/?about=1")
VER=$(sed -n 's/^## *\([^ ]*\).*/\1/p' "$HERE/CHANGELOG.md" | sed -n 1p)
grep -q "id=\"cs-version\">$VER<" <<<"$A" && ok "About shows the CHANGELOG version $VER" || bad "About version"
grep -q 'github.com/search?q=owner%3Aluuvasara+unraid&amp;type=repositories' <<<"$A" && ok "About has the More Unraid plugins hint" || bad "About hint"
FIRST=$(awk '/^## /{n++; next} n==1 && /^- /{print substr($0,3); exit}' "$HERE/CHANGELOG.md")
grep -qF "$FIRST" <<<"$A" && ok "About shows the latest changes" || bad "About changes"
T=$(curl -s -d 'action=share&csrf_token=TOKEN123&ids=&mappings=[]' "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/action.php")
grep -q 'Chronostasis findings' <<<"$T" && ok "share text built" || bad "share text: $T"
grep -q 'SECRET\|MASK\|192\.168\|PrivateShare' <<<"$T" && bad "a value in the share text" || ok "no value in the share text"
C=$(curl -s -o /dev/null -w '%{http_code}' -d 'action=check' "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/action.php")
[ "$C" = 403 ] && ok "action without a token refused" || bad "no-token action: $C"
C=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORT/plugins/unraid-chronostasis/include/action.php")
[ "$C" = 405 ] && ok "GET refused" || bad "GET: $C"
chrome --window-size=1300,1000 --screenshot="$SHOTS/render-dark.png" "http://127.0.0.1:$PORT/" >/dev/null
chrome --window-size=1300,1000 --screenshot="$SHOTS/render-light.png" "http://127.0.0.1:$((PORT + 1))/" >/dev/null
chrome --window-size=1300,1000 --screenshot="$SHOTS/render-612.png" "http://127.0.0.1:$((PORT + 2))/" >/dev/null
[ -s "$SHOTS/render-dark.png" ] && [ -s "$SHOTS/render-light.png" ] && [ -s "$SHOTS/render-612.png" ] && ok "screenshots" || bad "screenshots"
exit $fail
