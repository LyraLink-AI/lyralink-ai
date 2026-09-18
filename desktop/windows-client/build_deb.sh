#!/usr/bin/env bash
set -euo pipefail

APP_NAME="Lyralink"
APP_ID="lyralink"
APP_VENDOR="LyralinkAI"
APP_VERSION="$(node -p "require('./package.json').version")"
DIST_DIR="dist/Lyralink-linux-x64"
PKG_ROOT="dist/deb-pkg"
OUT_DEB="dist/Lyralink-linux-x64.deb"

if [[ ! -d "$DIST_DIR" ]]; then
  echo "Missing $DIST_DIR. Run npm run build:linux first." >&2
  exit 1
fi

rm -rf "$PKG_ROOT" "$OUT_DEB"
mkdir -p "$PKG_ROOT/DEBIAN"
mkdir -p "$PKG_ROOT/opt/lyralink"
mkdir -p "$PKG_ROOT/usr/bin"
mkdir -p "$PKG_ROOT/usr/share/applications"
mkdir -p "$PKG_ROOT/usr/share/icons/hicolor/256x256/apps"

cp -a "$DIST_DIR"/. "$PKG_ROOT/opt/lyralink/"
cp -f assets/app.png "$PKG_ROOT/usr/share/icons/hicolor/256x256/apps/lyralink.png"

cat > "$PKG_ROOT/usr/bin/lyralink" <<'EOF'
#!/usr/bin/env bash
exec /opt/lyralink/Lyralink "$@"
EOF
chmod 0755 "$PKG_ROOT/usr/bin/lyralink"

cat > "$PKG_ROOT/usr/share/applications/lyralink.desktop" <<EOF
[Desktop Entry]
Name=${APP_NAME}
Comment=Desktop wrapper for Lyralink AI
Exec=lyralink
Icon=lyralink
Terminal=false
Type=Application
Categories=Utility;Network;
StartupNotify=true
EOF

cat > "$PKG_ROOT/DEBIAN/control" <<EOF
Package: ${APP_ID}
Version: ${APP_VERSION}
Section: utils
Priority: optional
Architecture: amd64
Maintainer: ${APP_VENDOR}
Depends: libgtk-3-0, libnotify4, libnss3, libxss1, libxtst6, libatspi2.0-0, libdrm2, libgbm1, libasound2
Description: Lyralink desktop client
 Desktop wrapper for hosted Lyralink AI.
EOF

dpkg-deb --build --root-owner-group "$PKG_ROOT" "$OUT_DEB"
echo "Built: $OUT_DEB"
