#!/usr/bin/env bash
#
# Baut Rocket Boost als WebAssembly-Binary und legt die Laufzeitdateien in
# public/arcade/rocket-boost/ ab.
#
# Wichtig: wasm_exec.js MUSS aus derselben Go-Installation stammen wie das
# Binary. Dieses Skript kopiert beides zusammen, damit sie nie auseinander-
# laufen. Die erzeugten Dateien sind in .gitignore eingetragen und werden
# beim Deployment per scp auf den Server übertragen.
set -euo pipefail

cd "$(dirname "$0")"

ROOT="$(cd ../.. && pwd)"
OUT="$ROOT/public/arcade/rocket-boost"

if ! command -v go >/dev/null 2>&1; then
    echo "FEHLER: 'go' wurde nicht gefunden. Go >= 1.24 installieren." >&2
    exit 1
fi

GO_VERSION="$(go version)"
echo "→ $GO_VERSION"

mkdir -p "$OUT"

echo "→ go vet / go test"
go vet ./...
go test ./...

echo "→ Baue rocket.wasm"
GOOS=js GOARCH=wasm go build -trimpath -ldflags="-s -w" -o "$OUT/rocket.wasm" .

GOROOT="$(go env GOROOT)"
WASM_EXEC="$GOROOT/lib/wasm/wasm_exec.js"
if [ ! -f "$WASM_EXEC" ]; then
    WASM_EXEC="$GOROOT/misc/wasm/wasm_exec.js"
fi
if [ ! -f "$WASM_EXEC" ]; then
    echo "FEHLER: wasm_exec.js nicht in $GOROOT gefunden." >&2
    exit 1
fi

echo "→ Kopiere wasm_exec.js"
cp "$WASM_EXEC" "$OUT/wasm_exec.js"

RAW_BYTES="$(wc -c < "$OUT/rocket.wasm")"
GZIP_BYTES="$(gzip -9 -c "$OUT/rocket.wasm" | wc -c)"

printf '   rocket.wasm : %s Bytes (gzip: %s Bytes)\n' "$RAW_BYTES" "$GZIP_BYTES"
printf '   wasm_exec.js: %s Bytes\n' "$(wc -c < "$OUT/wasm_exec.js")"
printf '   Ziel        : %s\n' "$OUT"
