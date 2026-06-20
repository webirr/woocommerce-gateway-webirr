#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PLUGIN_SLUG=woocommerce-gateway-webirr
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/build"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG" "$DIST_DIR"

tar \
  --exclude='./.git' \
  --exclude='./dist' \
  --exclude='./vendor' \
  --exclude='./node_modules' \
  --exclude='./wordpress' \
  --exclude='./wp-content' \
  -cf - -C "$ROOT_DIR" . | tar -xf - -C "$BUILD_DIR/$PLUGIN_SLUG"

(cd "$BUILD_DIR" && zip -qr "$DIST_DIR/$PLUGIN_SLUG.zip" "$PLUGIN_SLUG")

echo "$DIST_DIR/$PLUGIN_SLUG.zip"

