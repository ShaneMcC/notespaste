#!/bin/bash
#
# Rebuild the self-hosted highlight.js in public/static/highlight/ from cdnjs.
#
#   scripts/update-highlight.sh [version]     (default: the version in src/Highlight.php)
#
# The prebuilt highlight.min.js only includes the ~36 "common" languages, and
# neither cdnjs nor jsDelivr publish an all-languages build, so this makes one:
# the core bundle with every other language file appended (they register
# themselves against the global hljs). It also copies every theme, so the
# configured one can be served locally.
#
# Every file is checked against the SRI hash cdnjs publishes for it.
#
# Needs curl, jq and openssl.

set -euo pipefail

cd "$(dirname "$0")/.."

HIGHLIGHT_PHP=src/Highlight.php
OUT=public/static/highlight
CDN=https://cdnjs.cloudflare.com/ajax/libs/highlight.js

VERSION=${1:-$(sed -n "s/.*const VERSION = '\([^']*\)'.*/\1/p" "$HIGHLIGHT_PHP")}
if [ -z "$VERSION" ]; then
    echo "Could not work out a version to fetch" >&2
    exit 1
fi

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

echo "Fetching file list for highlight.js $VERSION"
curl -fsS "https://api.cdnjs.com/libraries/highlight.js/$VERSION?fields=sri" > "$WORK/meta.json"
if [ "$(jq '.sri | length' "$WORK/meta.json")" = 0 ]; then
    echo "cdnjs has no files for highlight.js $VERSION" >&2
    exit 1
fi

# Download a file into $WORK and check it against its published hash.
fetch() {
    local file=$1
    local sri alg expected actual

    sri=$(jq -r --arg f "$file" '.sri[$f] // empty' "$WORK/meta.json")
    if [ -z "$sri" ]; then
        echo "No SRI hash published for $file" >&2
        exit 1
    fi

    mkdir -p "$WORK/files/$(dirname "$file")"
    curl -fsS "$CDN/$VERSION/$file" -o "$WORK/files/$file"

    alg=${sri%%-*}
    expected=${sri#*-}
    actual=$(openssl dgst "-$alg" -binary "$WORK/files/$file" | openssl base64 -A)
    if [ "$actual" != "$expected" ]; then
        echo "Integrity check failed for $file" >&2
        exit 1
    fi
}

fetch highlight.min.js

# The core bundle registers its languages as grmr_<name> (with the first "_" as "-").
grep -o 'grmr_[a-z0-9_]\+' "$WORK/files/highlight.min.js" | sed 's/^grmr_//; s/_/-/' | sort -u > "$WORK/core-languages.txt"

jq -r '.sri | keys[] | select(test("^languages/[^/]+\\.min\\.js$"))' "$WORK/meta.json" \
    | sed 's#^languages/##; s#\.min\.js$##' | sort > "$WORK/all-languages.txt"

comm -23 "$WORK/all-languages.txt" "$WORK/core-languages.txt" > "$WORK/extra-languages.txt"

echo "Core has $(wc -l < "$WORK/core-languages.txt") languages, adding $(wc -l < "$WORK/extra-languages.txt") more"

{
    cat "$WORK/files/highlight.min.js"
    echo
    while read -r lang; do
        fetch "languages/$lang.min.js"
        cat "$WORK/files/languages/$lang.min.js"
        echo
    done < "$WORK/extra-languages.txt"
} > "$WORK/bundle.js"

echo "Fetching themes"
jq -r '.sri | keys[] | select(test("^styles/.*\\.(min\\.css|png|jpg)$"))' "$WORK/meta.json" > "$WORK/styles.txt"
while read -r file; do
    fetch "$file"
done < "$WORK/styles.txt"

# cdnjs does not carry the licence; it is BSD-3-Clause and should travel with the copy
curl -fsS "https://cdn.jsdelivr.net/npm/@highlightjs/cdn-assets@$VERSION/LICENSE" -o "$WORK/LICENSE"

rm -rf "$OUT"
mkdir -p "$OUT"
mv "$WORK/bundle.js" "$OUT/highlight.min.js"
cp -r "$WORK/files/styles" "$OUT/styles"
mv "$WORK/LICENSE" "$OUT/LICENSE"

sed -i "s/const VERSION = '[^']*'/const VERSION = '$VERSION'/" "$HIGHLIGHT_PHP"

echo "Done: $OUT/highlight.min.js ($(wc -c < "$OUT/highlight.min.js") bytes), $(find "$OUT/styles" -name '*.min.css' | wc -l) themes"
