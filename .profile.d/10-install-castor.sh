#!/usr/bin/env bash
set -euo pipefail

BIN_DIR="$HOME/.local/bin"
mkdir -p "$BIN_DIR"

# Download castor binary (no PHP installer needed)
if [ ! -x "$BIN_DIR/castor" ]; then
  echo "Installing castor..."
  arch="$(uname -m)"
  case "$arch" in
    x86_64|amd64) arch="amd64" ;;
    aarch64|arm64) arch="arm64" ;;
    *) echo "Unsupported arch: $arch" >&2; exit 1 ;;
  esac
  curl -fsSL -o "$BIN_DIR/castor" \
    "https://github.com/jolicode/castor/releases/latest/download/castor.linux-$arch"
  chmod +x "$BIN_DIR/castor"
fi

export PATH="$BIN_DIR:$PATH"
