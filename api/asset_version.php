<?php
/**
 * Shared asset versioning.
 *
 * WHY THIS EXISTS
 * ---------------
 * /assets/css/*.css and /assets/js/*.js are served with
 *   cache-control: public, max-age=2592000   (30 days)
 * and were historically linked with no query string. The URL therefore never
 * changed when the file did, so a returning browser kept rendering month-old
 * CSS. Any UI fix was partly invisible to every user who had visited before.
 *
 * The fix is to append a version derived from the file's mtime. That changes
 * the URL whenever the file changes, so the cached copy is simply never
 * requested again.
 *
 * USAGE
 * -----
 *   require_once __DIR__ . '/../api/asset_version.php';
 *
 *   <link rel="stylesheet"
 *         href="<?php echo htmlspecialchars(lyra_asset_url('/assets/css/lyra-theme.css'), ENT_QUOTES, 'UTF-8'); ?>">
 *
 * WHY NOT A CLOSURE PER PAGE
 * --------------------------
 * support_admin.php carried a page-local $assetVersionOf closure. That works,
 * but it has to be copied into every page, and 29 of 31 pages never got one.
 * A single shared helper is the only version of this that actually scales.
 *
 * FAILURE BEHAVIOUR
 * -----------------
 * If the file cannot be stat()ed, this returns '1' rather than throwing. A
 * missing version must never take a page down; the worst case is that the
 * asset is served unversioned, which is exactly the old behaviour.
 */

if (!function_exists('lyra_asset_webroot')) {
    /**
     * Absolute path to the document root.
     *
     * This file lives in <webroot>/api/, so one level up is the webroot.
     * Resolved once per request and cached in a static.
     */
    function lyra_asset_webroot(): string
    {
        static $root = null;
        if ($root === null) {
            $root = dirname(__DIR__);
        }
        return $root;
    }
}

if (!function_exists('lyra_asset_version')) {
    /**
     * Cache-busting version string for a webroot-relative asset path.
     *
     * @param string $relPath e.g. '/assets/css/lyra-theme.css'
     * @return string mtime as a string, or '1' if the file is not stat-able.
     */
    function lyra_asset_version(string $relPath): string
    {
        $rel = '/' . ltrim($relPath, '/');

        // Reject anything that tries to escape the webroot. Asset paths are
        // developer-authored constants, but this helper is shared code and a
        // traversal here would turn a cache helper into a file-existence
        // oracle, so it is cheap to refuse.
        if (strpos($rel, '..') !== false) {
            return '1';
        }

        $full = lyra_asset_webroot() . $rel;
        $mtime = @filemtime($full);

        return ($mtime === false) ? '1' : (string)$mtime;
    }
}

if (!function_exists('lyra_asset_url')) {
    /**
     * Webroot-relative asset URL with a version query string appended.
     *
     * Preserves any query string already present in $relPath, so
     * '/assets/css/x.css?theme=dark' becomes '...css?theme=dark&v=123'.
     *
     * @param string $relPath e.g. '/assets/css/lyra-theme.css'
     * @return string e.g. '/assets/css/lyra-theme.css?v=1789000000'
     */
    function lyra_asset_url(string $relPath): string
    {
        $rel = '/' . ltrim($relPath, '/');
        $version = lyra_asset_version($rel);
        $separator = (strpos($rel, '?') === false) ? '?' : '&';

        return $rel . $separator . 'v=' . $version;
    }
}
