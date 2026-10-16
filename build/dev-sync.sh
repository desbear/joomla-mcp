#!/usr/bin/env bash
# Copy the source into an installed Joomla site for quick testing (after a first normal install).
# Usage: build/dev-sync.sh /path/to/joomla
set -euo pipefail
cd "$(dirname "$0")/.."
SITE=${1:?usage: build/dev-sync.sh /path/to/joomla}

rsync -a --delete src/com_mcp/administrator/ "$SITE/administrator/components/com_mcp/" --exclude language
rsync -a --delete src/com_mcp/api/ "$SITE/api/components/com_mcp/"
rsync -a --delete src/com_mcp/media/ "$SITE/media/com_mcp/"
rsync -a --delete src/com_mcp/site/ "$SITE/components/com_mcp/" --exclude language
cp src/com_mcp/mcp.xml "$SITE/administrator/components/com_mcp/mcp.xml"
for tag in en-GB zh-TW; do
  cp src/com_mcp/administrator/language/$tag/* "$SITE/administrator/language/$tag/"
  cp src/com_mcp/site/language/$tag/* "$SITE/language/$tag/"
done

sync_plugin() { # group element srcdir
  rsync -a --delete "src/$3/" "$SITE/plugins/$1/$2/" --exclude language
  for tag in en-GB zh-TW; do
    cp src/$3/language/$tag/* "$SITE/administrator/language/$tag/"
  done
}
sync_plugin webservices mcp plg_webservices_mcp
sync_plugin system mcp plg_system_mcp
sync_plugin mcp content plg_mcp_content
sync_plugin mcp stats plg_mcp_stats
sync_plugin mcp menus plg_mcp_menus
sync_plugin mcp modules plg_mcp_modules
sync_plugin mcp akeeba plg_mcp_akeeba
sync_plugin mcp flexicontent plg_mcp_flexicontent
sync_plugin mcp easyblog plg_mcp_easyblog
sync_plugin mcp jreviews plg_mcp_jreviews
sync_plugin mcp jlexhelpful plg_mcp_jlexhelpful
sync_plugin mcp media plg_mcp_media
