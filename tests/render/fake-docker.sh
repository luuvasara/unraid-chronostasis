#!/bin/bash
# fake-docker.sh - stands in for docker in the tests. State in $CS_FAKE_DOCKER: c/<name> holds one inspect line,
# manifest-error and create-error hold an error text, image-ok means the image is on the server. Every call is logged.
S=${CS_FAKE_DOCKER:?}; echo "$*" >>"$S/calls.log"
case $1 in
  ps) ls "$S/c" 2>/dev/null ;;
  inspect)
    if [ "${*: -1}" = "${*: -1}" ] && [[ "$*" == *'.HostConfig.NetworkMode}}'* ]] && [[ "$*" != *'.State.Status'* ]]; then
      shift 5; for n in "$@"; do [ -f "$S/c/$n" ] && echo "/$n|$(cut -d'|' -f6 "$S/c/$n")"; done; exit 0
    fi
    n=${*: -1}; [ -f "$S/c/$n" ] || { echo "Error: No such container: $n" >&2; exit 1; }; cat "$S/c/$n" ;;
  network) printf 'bridge\nhost\nnone\n' ;;
  image) [ -f "$S/image-ok" ] ;;
  manifest) [ -f "$S/manifest-error" ] || exit 0; cat "$S/manifest-error" >&2; exit 1 ;;
  create) [ -f "$S/create-error" ] || { echo tempcontainerid; exit 0; }; cat "$S/create-error" >&2; exit 125 ;;
  rm) ;;
  *) exit 1 ;;
esac
