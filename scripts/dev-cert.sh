#!/usr/bin/env bash
# Generate a locally-trusted development certificate for Juggler so the
# browser warning disappears entirely (no "Proceed anyway" click).
#
# Preferred tool: mkcert (https://github.com/FiloSottile/mkcert) — it
# installs a local CA into the OS/browser trust store. Fallback: openssl,
# which produces the same files but requires importing dev-ca.pem manually.
#
# Usage:
#   scripts/dev-cert.sh                     # -> ./dev-certs/{cert,key}.pem for localhost, 127.0.0.1, ::1
#   scripts/dev-cert.sh mysite.dev          # extra SAN
#   JUGGLER_TLS_DIR=./dev-certs cargo run --release
set -euo pipefail

OUT_DIR="${1:-dev-certs}"
shift || true
EXTRA_DNS=""
for h in "$@"; do
    EXTRA_DNS="$EXTRA_DNS,DNS:$h"
done
SAN="DNS:localhost,IP:127.0.0.1,IP:::1$EXTRA_DNS"

mkdir -p "$OUT_DIR"

# Git Bash / MSYS mangles leading-slash args like -subj "/CN=..." into
# Windows paths. Disable that conversion for the openssl calls below.
case "$(uname -s)" in
    MINGW*|MSYS*|CYGWIN*) export MSYS_NO_PATHCONV=1 ;;
esac

if command -v mkcert >/dev/null 2>&1; then
    echo "==> mkcert found: generating locally-trusted certificate"
    mkcert -install >/dev/null 2>&1 || true
    mkcert -cert-file "$OUT_DIR/cert.pem" \
           -key-file  "$OUT_DIR/key.pem" \
           localhost 127.0.0.1 "$@" 2>/dev/null \
        || mkcert -cert-file "$OUT_DIR/cert.pem" -key-file "$OUT_DIR/key.pem" \
                  -in "$OUT_DIR" 2>/dev/null \
        || true
    if [ ! -f "$OUT_DIR/cert.pem" ]; then
        echo "!! mkcert failed; falling back to openssl" >&2
    fi
fi

if [ ! -f "$OUT_DIR/cert.pem" ]; then
    echo "==> openssl fallback"
    echo "    import $OUT_DIR/dev-ca.pem into your OS/browser trust store:"
    echo "      Windows: certmgr.msc -> Trusted Root Certification Authorities"
    echo "      Linux:   sudo cp dev-certs/dev-ca.pem /usr/local/share/ca-certificates/ && sudo update-ca-certificates"
    echo "      macOS:   sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain dev-certs/dev-ca.pem"
    CA_KEY="$OUT_DIR/dev-ca.key"
    CA_PEM="$OUT_DIR/dev-ca.pem"
    if [ ! -f "$CA_PEM" ]; then
        openssl req -x509 -newkey rsa:2048 -nodes -days 3650 \
            -keyout "$CA_KEY" -out "$CA_PEM" \
            -subj "/CN=Juggler Development CA" \
            -addext "basicConstraints=critical,CA:TRUE" \
            -addext "keyUsage=critical,keyCertSign,cRLSign" 2>/dev/null
        echo "==> created $CA_PEM (keep dev-ca.key private, delete it if you don't need re-issuance)"
    fi
    openssl req -newkey rsa:2048 -nodes \
        -keyout "$OUT_DIR/key.pem" -out "$OUT_DIR/cert.csr" \
        -subj "/CN=localhost" 2>/dev/null
    # NOTE: use a real temp file, not process substitution — the native
    # Windows openssl.exe cannot read MSYS /proc/<pid>/fd paths.
    EXTFILE="$OUT_DIR/ext.cnf"
    printf "subjectAltName=%s\nbasicConstraints=CA:FALSE\nextendedKeyUsage=serverAuth\nkeyUsage=digitalSignature,keyEncipherment\n" "$SAN" > "$EXTFILE"
    openssl x509 -req -in "$OUT_DIR/cert.csr" \
        -CA "$CA_PEM" -CAkey "$CA_KEY" -CAcreateserial \
        -days 825 -out "$OUT_DIR/cert.pem" \
        -extfile "$EXTFILE" 2>/dev/null
    rm -f "$OUT_DIR/cert.csr" "$OUT_DIR/ext.cnf" "$OUT_DIR/dev-ca.srl"
    echo "==> certificate signed by $CA_PEM"
fi

chmod 600 "$OUT_DIR/key.pem" 2>/dev/null || true
echo ""
echo "==> Done. SANs: $SAN"
echo "==> Start Juggler with:"
echo "    JUGGLER_TLS_DIR=$OUT_DIR cargo run --release"
echo "    (or: JUGGLER_TLS_DIR=\$(pwd)/$OUT_DIR ./target/release/juggler)"
