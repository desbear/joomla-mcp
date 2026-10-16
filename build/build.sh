#!/usr/bin/env bash
# Build the packages into dist/, installable through Joomla's extension installer:
#   pkg_mcp-<version>.zip     MCP Server for Joomla
#   pkg_mcpask-<version>.zip  Ask the Site (answers visitors' questions from the site's content)
#   pkg_guides-<version>.zip  Guides (step-by-step guides made from the site's articles)
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

# The browser fetches a module's script and styles again only when their version changes:
# the asset manifest must carry the module's version
for module in mod_mcp_ask; do
  want=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' "src/$module/$module.xml" | head -1)
  if grep -q '"version"' "src/$module/media/joomla.asset.json" && grep '"version"' "src/$module/media/joomla.asset.json" | grep -vq "\"$want\""; then
    echo "src/$module/media/joomla.asset.json: asset versions differ from $want" >&2
    exit 1
  fi
done

build pkg_mcp src/pkg_mcp.xml src/script.php src/language \
  com_mcp plg_webservices_mcp plg_system_mcp plg_mcp_content plg_mcp_stats plg_mcp_menus plg_mcp_modules plg_mcp_akeeba plg_mcp_flexicontent plg_mcp_easyblog plg_mcp_jreviews plg_mcp_mcpask plg_mcp_jlexhelpful plg_mcp_media

build pkg_mcpask src/pkg_mcpask/pkg_mcpask.xml src/pkg_mcpask/script.php src/pkg_mcpask/language \
  com_mcpask mod_mcp_ask mod_mcp_ask_log plg_content_mcpask plg_task_mcpask plg_easyblog_mcpask plg_mcpask_rsform plg_mcpask_livechat

build pkg_guides src/pkg_guides/pkg_guides.xml src/pkg_guides/script.php src/pkg_guides/language \
  com_guides mod_guides_review
