<?php
// Legacy redirect target — browsers that cached the old 301 (/download → /download.php)
// will land here. Serve the download page directly so they get working content.
require __DIR__ . '/pages/download.php';
