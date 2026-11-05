#!/bin/sh
# tests/Support/fetch-mock-oidc.sh - download the pinned meigma/mock-oidc
# release (the test IdP of the OIDC feature tests) into .cache/, check its
# SHA-256, and print the path to set as OIDC_MOCK_BIN:
#
#   export OIDC_MOCK_BIN="$(tests/Support/fetch-mock-oidc.sh)"
#
# linux/amd64 only (CI and the dev host). A new version means a new
# VERSION and SHA256, from the release's checksums.txt.
set -eu

VERSION=0.1.0
SHA256=b02d6dbdb6122d609105b4f84e1868d4dae895a214467c36f84e12fb39b89c6f
ASSET="mock-oidc_${VERSION}_linux_amd64"
URL="https://github.com/meigma/mock-oidc/releases/download/v${VERSION}/${ASSET}"

cache="$(cd "$(dirname "$0")/../.." && pwd)/.cache"
binary="$cache/$ASSET"

if [ ! -x "$binary" ] || ! echo "$SHA256  $binary" | sha256sum -c --status; then
    mkdir -p "$cache"
    curl -fsSL --proto '=https' -o "$binary.part" "$URL"
    if ! echo "$SHA256  $binary.part" | sha256sum -c --status; then
        rm -f "$binary.part"
        echo "fetch-mock-oidc: SHA-256 mismatch for $URL" >&2
        exit 1
    fi
    chmod 755 "$binary.part"
    mv "$binary.part" "$binary"
fi
echo "$binary"
