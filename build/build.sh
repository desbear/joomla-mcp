#!/usr/bin/env bash
# Build the packages into dist/, installable through Joomla's extension installer:
#   pkg_mcp-<version>.zip     MCP Server for Joomla
set -euo pipefail
cd "$(dirname "$0")/.."

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir -p dist

# build <package> <manifest> <script> <language dir> <extension>...
build() {
  local name=$1 manifest=$2 script=$3 language=$4 version ext
  shift 4
  version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "$manifest" | head -1)

  mkdir -p "$TMP/$name/packages"
  for ext in "$@"; do
    (cd "src/$ext" && zip -qrX "$TMP/$name/packages/$ext.zip" . -x '.*' -x '*/.*')
  done
  cp "$manifest" "$TMP/$name/"
  cp "$script" "$TMP/$name/script.php"
  cp -R "$language" "$TMP/$name/language"

  rm -f "dist/$name-$version.zip"
  (cd "$TMP/$name" && zip -qrX "$OLDPWD/dist/$name-$version.zip" . -x '.*' -x '*/.*')
  echo "dist/$name-$version.zip"
}

build pkg_mcp src/pkg_mcp.xml src/script.php src/language \
  com_mcp plg_webservices_mcp plg_system_mcp plg_mcp_content plg_mcp_stats plg_mcp_menus plg_mcp_modules plg_mcp_akeeba plg_mcp_flexicontent plg_mcp_easyblog plg_mcp_jreviews plg_mcp_jlexhelpful plg_mcp_media

