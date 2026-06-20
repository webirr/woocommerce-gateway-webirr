#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PLUGIN_SLUG=woocommerce-gateway-webirr
DIST_DIR="$ROOT_DIR/dist"
BUILD_DIR="$DIST_DIR/build"

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR/$PLUGIN_SLUG" "$DIST_DIR"
rm -f "$DIST_DIR/$PLUGIN_SLUG.zip"

copy_file() {
  if [ -f "$ROOT_DIR/$1" ]; then
    cp "$ROOT_DIR/$1" "$BUILD_DIR/$PLUGIN_SLUG/$1"
  fi
}

copy_dir() {
  if [ -d "$ROOT_DIR/$1" ]; then
    mkdir -p "$BUILD_DIR/$PLUGIN_SLUG/$1"
    (cd "$ROOT_DIR/$1" && tar -cf - .) | (cd "$BUILD_DIR/$PLUGIN_SLUG/$1" && tar -xf -)
  fi
}

copy_file "woocommerce-gateway-webirr.php"
copy_file "readme.txt"
copy_dir "assets"
copy_dir "includes"
copy_dir "languages"

find "$BUILD_DIR/$PLUGIN_SLUG" -name '.DS_Store' -delete

(cd "$BUILD_DIR" && zip -qr "$DIST_DIR/$PLUGIN_SLUG.zip" "$PLUGIN_SLUG")

echo "$DIST_DIR/$PLUGIN_SLUG.zip"
