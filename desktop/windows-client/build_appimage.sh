#!/usr/bin/env bash
set -euo pipefail

APP_NAME="Lyralink"
APP_VERSION="$(node -p "require('./package.json').version")"
DIST_DIR="dist/Lyralink-linux-x64"
APPDIR="dist/Lyralink.AppDir"
OUT_APPIMAGE="dist/Lyralink-linux-x86_64.AppImage"
APPIMAGETOOL_BIN="dist/appimagetool-x86_64.AppImage"
APPIMAGETOOL_URL="https://github.com/AppImage/AppImageKit/releases/download/continuous/appimagetool-x86_64.AppImage"

if [[ ! -d "$DIST_DIR" ]]; then
  echo "Missing $DIST_DIR. Run npm run build:linux first." >&2
  exit 1
fi

rm -rf "$APPDIR" "$OUT_APPIMAGE"
mkdir -p "$APPDIR/usr/bin"
mkdir -p "$APPDIR/usr/share/applications"
mkdir -p "$APPDIR/usr/share/icons/hicolor/256x256/apps"

cp -a "$DIST_DIR"/. "$APPDIR/usr/bin/"
cp -f assets/app.png "$APPDIR/usr/share/icons/hicolor/256x256/apps/lyralink.png"
cp -f assets/app.png "$APPDIR/Lyralink.png"

cat > "$APPDIR/AppRun" <<'EOF'
#!/usr/bin/env bash
HERE="$(cd "$(dirname "$0")" && pwd)"
exec "$HERE/usr/bin/Lyralink" "$@"
EOF
chmod 0755 "$APPDIR/AppRun"

cat > "$APPDIR/Lyralink.desktop" <<EOF
[Desktop Entry]
Name=${APP_NAME}
Comment=Desktop wrapper for Lyralink AI
Exec=Lyralink
Icon=Lyralink
Terminal=false
Type=Application
Categories=Utility;Network;
StartupNotify=true
X-AppImage-Version=${APP_VERSION}
EOF

if [[ ! -f "$APPIMAGETOOL_BIN" ]]; then
  curl -L "$APPIMAGETOOL_URL" -o "$APPIMAGETOOL_BIN"
  chmod +x "$APPIMAGETOOL_BIN"
fi

ARCH=x86_64 APPIMAGE_EXTRACT_AND_RUN=1 "$APPIMAGETOOL_BIN" "$APPDIR" "$OUT_APPIMAGE"
chmod +x "$OUT_APPIMAGE"
echo "Built: $OUT_APPIMAGE"
