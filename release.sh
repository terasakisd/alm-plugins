#!/bin/bash
# GitHub リリースを発行して各サイトの自動更新に載せる
# 使い方: ./release.sh ad-link-manager   または   ./release.sh alm-connector
set -euo pipefail
cd "$(dirname "$0")"

PLUGIN="${1:?使い方: ./release.sh <ad-link-manager|alm-connector>}"
[ -d "$PLUGIN" ] || { echo "ディレクトリがありません: $PLUGIN"; exit 1; }

VER=$(grep -m1 -E "^\s*\*\s*Version:" "$PLUGIN/$PLUGIN.php" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
[ -n "$VER" ] || { echo "Version ヘッダが読めません"; exit 1; }

TAG="$PLUGIN-$VER"
if gh release view "$TAG" >/dev/null 2>&1; then
  echo "リリース $TAG は既に存在します。Version ヘッダを上げてください。"
  exit 1
fi

rm -f "$PLUGIN.zip"
zip -r -q "$PLUGIN.zip" "$PLUGIN" -x "*.DS_Store"
gh release create "$TAG" "$PLUGIN.zip" --title "$PLUGIN $VER" --notes "$PLUGIN v$VER"
rm -f "$PLUGIN.zip"
echo "発行しました: $TAG（各サイトのWPに自動更新として配信されます）"
