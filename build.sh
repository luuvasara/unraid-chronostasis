#!/bin/bash
# build.sh - build packages/unraid-chronostasis-<version>-x86_64-1.txz on a workstation, never on a server.
# The .plg's <CHANGES> block is the one source of the changes: this script turns it into CHANGELOG.md and puts that in
# the package, where the About tab and the install message read it. The version must be its newest heading.
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
NAME=unraid-chronostasis
PLG=$HERE/$NAME.plg
VER=$(sed -n 's/^ *<!ENTITY version *"\([^"]*\)">.*/\1/p' "$PLG")
[[ $VER =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] || { echo "FATAL: version $VER is not YYYY.MM.DD[a-z]"; exit 1; }
# plain text of the <CHANGES> block: "###<version>" and "- item" lines
changes() { sed -n '/^<CHANGES>/,/^<\/CHANGES>/p' "$PLG" | sed '1d;$d' | sed -e 's/&lt;/</g; s/&gt;/>/g; s/&amp;/\&/g'; }
TOP=$(changes | sed -n '1s/^###//p')
[ "$TOP" = "$VER" ] || { echo "FATAL: <CHANGES> starts with $TOP, not the version $VER (add this version's changes first)"; exit 1; }
changes | LC_ALL=C grep -nP '[^\x00-\x7F]' && { echo "FATAL: <CHANGES> must be ASCII (install output)"; exit 1; }
{ echo "# Changelog"; echo
  echo "Copied by the build from the <CHANGES> block of $NAME.plg; edit the .plg, not this file."; echo
  changes | sed -e 's/^###/\n## /' | sed '1{/^$/d}'
} >"$HERE/CHANGELOG.md"
OUT=$HERE/packages; mkdir -p "$OUT"
PKG=$OUT/$NAME-$VER-x86_64-1.txz
P=usr/local/emhttp/plugins/$NAME
ST=$(mktemp -d "$HERE/.build.XXXXXX"); trap 'rm -rf "$ST"' EXIT
chmod 755 "$ST"
UNTRACKED=$(git -C "$HERE" ls-files --others --exclude-standard -- src)
[ -z "$UNTRACKED" ] || { echo "FATAL: untracked files under src/:"; echo "$UNTRACKED"; exit 1; }
(cd "$HERE/src" && git ls-files -z --cached -- . | xargs -0 cp -a --parents -t "$ST/")
cp "$HERE/CHANGELOG.md" "$ST/$P/CHANGELOG.md"
cp "$HERE/LICENSE" "$ST/$P/LICENSE"
find "$ST" -type d -exec chmod 755 {} +
find "$ST" -type f -exec chmod 644 {} +
chmod 755 "$ST/$P/scripts/chronostasis"
bash -n "$ST/$P/scripts/chronostasis"
for f in "$ST/$P"/*.page "$ST/$P"/include/*.php "$ST/$P"/scripts/*.php; do
  L=$(php -d short_open_tag=1 -l "$f" 2>&1) || { echo "FATAL: php -l failed: $L"; exit 1; }
done
KEEP=$(for f in "$OUT/$NAME"-*-x86_64-1.txz; do
         [ -e "$f" ] && [ "$f" != "$PKG" ] || continue
         v=${f#"$OUT/$NAME"-}; printf '%s\t%s\n' "${v%-x86_64-1.txz}" "$f"
       done | sort -V -k1,1 | tail -n 1 | cut -f2)
for f in "$OUT/$NAME"-*-x86_64-1.txz; do
  [ -e "$f" ] || continue
  [ "$f" = "$KEEP" ] || rm -f "$f" "$f.md5"
done
tar -C "$ST" --owner=root --group=root --numeric-owner --sort=name --mtime="$(tr . - <<<"${VER:0:10}") 00:00:00 UTC" -cJf "$PKG" .
ROOT=$(tar -tJvf "$PKG" | sed -n 1p)
case "$(awk '{print $1}' <<<"$ROOT")" in drwxr-xr-x) ;; *) echo "FATAL: archive root is not drwxr-xr-x"; rm -f "$PKG"; exit 1 ;; esac
tar -tJvf "$PKG" | awk '$1 ~ /^d/ && $1 != "drwxr-xr-x" {bad=1; print "FATAL dir mode: " $0} END{exit bad}'
tar -tJvf "$PKG" | awk '$2 != "0/0" {bad=1; print "FATAL owner: " $0} END{exit bad}'
(cd "$OUT" && md5sum "$(basename "$PKG")" >"$(basename "$PKG").md5")
MD5=$(cut -d' ' -f1 "$PKG.md5")
sed -i "s|^\( *<!ENTITY md5 *\)\"[^\"]*\">|\1\"$MD5\">|" "$PLG"
python3 -c 'import sys,xml.dom.minidom as m; m.parse(sys.argv[1])' "$PLG" || { echo "FATAL: $PLG is not well-formed XML"; exit 1; }
echo "built $PKG"; cat "$PKG.md5"
