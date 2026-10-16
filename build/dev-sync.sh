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
sync_plugin mcp mcpask plg_mcp_mcpask
sync_plugin mcp jlexhelpful plg_mcp_jlexhelpful
sync_plugin mcp media plg_mcp_media
# Ask the Site (pkg_mcpask)
rsync -a --delete src/com_mcpask/administrator/ "$SITE/administrator/components/com_mcpask/" --exclude language
rsync -a --delete src/com_mcpask/site/ "$SITE/components/com_mcpask/" --exclude language
rsync -a --delete src/com_mcpask/media/ "$SITE/media/com_mcpask/"
cp src/com_mcpask/mcpask.xml "$SITE/administrator/components/com_mcpask/mcpask.xml"
rsync -a --delete src/mod_mcp_ask/ "$SITE/modules/mod_mcp_ask/" --exclude language --exclude media
rsync -a --delete src/mod_mcp_ask/media/ "$SITE/media/mod_mcp_ask/"
rsync -a --delete src/mod_mcp_ask_log/ "$SITE/administrator/modules/mod_mcp_ask_log/" --exclude language
for tag in en-GB zh-TW; do
  cp src/com_mcpask/administrator/language/$tag/* src/mod_mcp_ask_log/language/$tag/* "$SITE/administrator/language/$tag/"
  cp src/com_mcpask/site/language/$tag/* src/mod_mcp_ask/language/$tag/* "$SITE/language/$tag/"
done
sync_plugin content mcpask plg_content_mcpask
sync_plugin task mcpask plg_task_mcpask
sync_plugin mcpask rsform plg_mcpask_rsform
sync_plugin mcpask livechat plg_mcpask_livechat
# Guides (pkg_guides)
rsync -a --delete src/com_guides/administrator/ "$SITE/administrator/components/com_guides/" --exclude language
rsync -a --delete src/com_guides/media/ "$SITE/media/com_guides/"
cp src/com_guides/guides.xml "$SITE/administrator/components/com_guides/guides.xml"
rsync -a --delete src/mod_guides_review/ "$SITE/administrator/modules/mod_guides_review/" --exclude language
for tag in en-GB zh-TW; do
  cp src/com_guides/administrator/language/$tag/* src/mod_guides_review/language/$tag/* "$SITE/administrator/language/$tag/"
done
echo "Synced to $SITE"
