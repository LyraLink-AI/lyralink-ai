<?php

declare(strict_types=1);

/**
 * Capability integrity audit for the LyraLink brain.
 *
 * Purpose
 * -------
 * An OS brain is asked about its own capabilities constantly - by users ("can you install
 * packages?") and by its own orchestrator ("what tool fits this task?"). If that answer is
 * generated, the brain will eventually claim a capability it lacks, or ignore one it has.
 * That is the model-identity failure class (T045) with far worse consequences: an OS that
 * misdescribes itself cannot be delegated to.
 *
 * The registry already carries rich metadata, and chat_capability_tool_manifest() already
 * refuses to advertise a capability with no executor branch. That is correct and safe - but
 * it FILTERS SILENTLY. A capability that is declared and enabled but has no executor is
 * dropped from the model's tool list while chat_os_capability_registry() still reports it as
 * present. Nothing surfaces that disagreement. This audit does.
 *
 * Method (and why)
 * ----------------
 * The registry is loaded dynamically, because that is the real data.
 *
 * Function existence is checked STATICALLY, by scanning the source tree for `function`
 * declarations. The first version of this file used function_exists() after requiring two
 * modules and produced nine HIGH findings that were all false: execution_method targets live
 * in the other twenty-one modules (lyra_db_query_execute in data_plane.php,
 * chat_web_search_query_with_status in conversation_intelligence.php, and so on). Requiring
 * the entire bootstrap to fix that would execute application code during an audit and could
 * have side effects. Scanning the source answers the real question - "is this symbol defined
 * anywhere in the tree?" - without running anything.
 *
 * Routing capabilities (model.*) are classified separately. The manifest excludes them by
 * design (capability_executor.php:618, "model.* capabilities are internal routing, not
 * user-callable tools"), so "has no executor branch" is expected for them, not a defect.
 *
 * Read-only. Changes nothing.
 *
 * Exit status
 * -----------
 *   0  no CRITICAL findings
 *   1  at least one CRITICAL finding
 *   2  the registry could not be loaded
 *
 * Usage
 * -----
 *   php cap_audit.php
 *   php cap_audit.php --json
 *   php cap_audit.php --json --out /path/to/capability_manifest.json
 */

$docroot = getenv('LYRA_DOCROOT');
if (!is_string($docroot) || $docroot === '') {
    $docroot = '/var/www/vhosts/lyralinkai.com/httpdocs';
}

if (!function_exists('api_get_secret')) {
    function api_get_secret(string $key, string $default = ''): string { return $default; }
}

$apiRoot = $docroot . '/api';
$osCore  = $apiRoot . '/lib/chat/os_core.php';
$capExec = $apiRoot . '/lib/chat/capability_executor.php';

foreach ([$osCore, $capExec] as $required) {
    if (!is_file($required)) {
        fwrite(STDERR, "FATAL: missing {$required}\n");
        exit(2);
    }
}

require $osCore;
require $capExec;

if (!function_exists('chat_os_capability_registry')) {
    fwrite(STDERR, "FATAL: chat_os_capability_registry() not available\n");
    exit(2);
}

/* ------------------------------------------------------------------ static source index */

/** @return array{functions: array<string,string>, permission_hits: array<string,int>, files: int} */
function lyra_scan_php(string $root): array
{
    $functions = [];
    $sources = [];
    $files = 0;

    if (!is_dir($root)) {
        return ['functions' => [], 'permission_hits' => [], 'files' => 0, 'sources' => []];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (strpos($path, '/node_modules/') !== false) {
            continue;
        }
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = @file_get_contents($path);
        if ($src === false) {
            continue;
        }
        $files++;
        $sources[$path] = $src;

        if (preg_match_all('/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', $src, $m)) {
            foreach ($m[1] as $fn) {
                $key = strtolower($fn);
                if (!isset($functions[$key])) {
                    $functions[$key] = str_replace($root, '', $path);
                }
            }
        }
    }

    return ['functions' => $functions, 'sources' => $sources, 'files' => $files];
}

$scan = lyra_scan_php($apiRoot);
$definedFunctions = $scan['functions'];
$sources = $scan['sources'];

/* ---------------------------------------------------------------------------- the audit */

$registry = chat_os_capability_registry([]);
if (!is_array($registry)) {
    fwrite(STDERR, "FATAL: registry did not return an array\n");
    exit(2);
}

$resourceRegistry = function_exists('chat_os_resource_registry')
    ? (array)chat_os_resource_registry()
    : [];
$resourceIds = array_map('strval', array_keys($resourceRegistry));

$findings = [];
$inventory = [];
$permissionVocabulary = [];
$executionMethods = [];

$add = static function (string $severity, string $capability, string $issue, string $detail) use (&$findings): void {
    $findings[] = ['severity' => $severity, 'capability' => $capability, 'issue' => $issue, 'detail' => $detail];
};

foreach ($registry as $rawId => $cap) {
    $id = (string)$rawId;
    if (!is_array($cap)) {
        $add('HIGH', $id, 'malformed_entry', 'registry entry is not an array');
        continue;
    }

    // Routing capabilities are excluded from the tool manifest on purpose.
    $isRouting = strpos($id, 'model.') === 0;

    $enabled     = !empty($cap['enabled']);
    $execMethod  = (string)($cap['execution_method'] ?? '');
    $declaredId  = (string)($cap['capability_id'] ?? '');
    $perms       = array_values(array_map('strval', (array)($cap['required_permissions'] ?? [])));
    $resources   = array_values(array_map('strval', (array)($cap['required_resources'] ?? [])));
    $verify      = (string)($cap['verification_method'] ?? '');
    $timeout     = (int)($cap['timeout_sec'] ?? 0);
    $retry       = (array)($cap['retry_policy'] ?? []);
    $hasExecutor = function_exists('chat_capability_has_executor')
        ? (bool)chat_capability_has_executor($id)
        : false;
    $execExists  = $execMethod !== '' && isset($definedFunctions[strtolower($execMethod)]);

    if ($enabled && !$hasExecutor && !$isRouting) {
        $add('CRITICAL', $id, 'declared_enabled_but_no_executor',
            'Enabled in the registry, but chat_capability_has_executor() is false, so '
            . 'chat_capability_tool_manifest() silently drops it. The registry overstates what '
            . 'the system can do.');
    }

    if ($execMethod !== '') {
        $executionMethods[$execMethod] = true;
        if (!$execExists) {
            $add('HIGH', $id, 'execution_method_not_defined_anywhere',
                "execution_method '{$execMethod}' does not appear as a function declaration anywhere under api/.");
        }
    } elseif ($enabled && !$isRouting) {
        $add('MEDIUM', $id, 'no_execution_method', 'Enabled capability declares no execution_method.');
    }

    foreach ($resources as $r) {
        if ($resourceIds !== [] && !in_array($r, $resourceIds, true)) {
            $add('HIGH', $id, 'unknown_resource',
                "requires resource '{$r}' which is absent from chat_os_resource_registry().");
        }
    }

    if ($enabled && $verify === '') {
        $add('MEDIUM', $id, 'no_verification_method', 'Enabled capability declares no verification_method, so outcomes cannot be proven.');
    }
    if ($enabled && $timeout <= 0 && !$isRouting) {
        $add('LOW', $id, 'no_timeout', 'Enabled capability declares no positive timeout_sec.');
    }
    if ($enabled && $retry === [] && !$isRouting) {
        $add('LOW', $id, 'no_retry_policy', 'Enabled capability declares no retry_policy.');
    }
    if ($declaredId !== '' && $declaredId !== $id) {
        $add('HIGH', $id, 'id_mismatch', "array key '{$id}' does not match capability_id '{$declaredId}'.");
    }

    foreach ($perms as $p) {
        if (!isset($permissionVocabulary[$p])) {
            $permissionVocabulary[$p] = 0;
        }
        // Count how many OTHER files mention this permission string, i.e. is it ever enforced?
        $hits = 0;
        foreach ($sources as $path => $src) {
            if (strpos($path, 'os_core.php') !== false || strpos($path, 'capability_executor.php') !== false) {
                continue;
            }
            if (strpos($src, $p) !== false) {
                $hits++;
            }
        }
        $permissionVocabulary[$p] += $hits;
    }

    $inventory[] = [
        'capability_id' => $id,
        'class' => $isRouting ? 'routing' : 'tool',
        'name' => (string)($cap['name'] ?? ''),
        'description' => (string)($cap['description'] ?? ''),
        'enabled' => $enabled,
        'risk_level' => (string)($cap['risk_level'] ?? ''),
        'requires_confirmation' => !empty($cap['requires_confirmation']),
        'required_permissions' => $perms,
        'required_resources' => $resources,
        'execution_method' => $execMethod,
        'execution_method_defined' => $execExists,
        'execution_method_defined_in' => $execExists ? ltrim($definedFunctions[strtolower($execMethod)], '/') : null,
        'has_executor_branch' => $hasExecutor,
        'advertised_to_model' => $enabled && $hasExecutor && !$isRouting,
        'verification_method' => $verify,
        'timeout_sec' => $timeout,
        'provider' => (string)($cap['provider'] ?? ''),
    ];
}

// Unenforced permissions: declared and required, but nothing outside the registry checks them.
foreach ($permissionVocabulary as $perm => $hits) {
    if ($hits === 0) {
        $add('MEDIUM', '(policy)', 'permission_never_enforced',
            "permission '{$perm}' is required by at least one capability but appears in no file "
            . 'outside the registry, so nothing enforces it.');
    }
}

usort($inventory, static fn(array $a, array $b): int => strcmp($a['capability_id'], $b['capability_id']));

$rank = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
usort($findings, static function (array $a, array $b) use ($rank): int {
    $r = ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9);
    return $r !== 0 ? $r : strcmp($a['capability'], $b['capability']);
});

$bySeverity = ['CRITICAL' => 0, 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0];
foreach ($findings as $f) {
    $bySeverity[$f['severity']] = ($bySeverity[$f['severity']] ?? 0) + 1;
}

$advertised = count(array_filter($inventory, static fn(array $r): bool => $r['advertised_to_model']));

$result = [
    'generated_at' => gmdate('c'),
    'docroot' => $docroot,
    'method' => [
        'registry' => 'dynamic (chat_os_capability_registry)',
        'symbol_check' => 'static scan of api/ for function declarations',
        'php_files_scanned' => $scan['files'],
        'functions_indexed' => count($definedFunctions),
    ],
    'summary' => [
        'capabilities_declared' => count($inventory),
        'capabilities_enabled' => count(array_filter($inventory, static fn(array $r): bool => $r['enabled'])),
        'capabilities_advertised_to_model' => $advertised,
        'routing_capabilities' => count(array_filter($inventory, static fn(array $r): bool => $r['class'] === 'routing')),
        'distinct_permissions' => count($permissionVocabulary),
        'distinct_execution_methods' => count($executionMethods),
        'resources_in_registry' => count($resourceIds),
        'findings' => $bySeverity,
        'total_findings' => count($findings),
    ],
    'permission_enforcement' => $permissionVocabulary,
    'execution_methods' => array_values(array_keys($executionMethods)),
    'resource_registry' => $resourceIds,
    'findings' => $findings,
    'inventory' => $inventory,
];

$asJson = in_array('--json', $argv, true);
$outPath = null;
foreach ($argv as $i => $arg) {
    if ($arg === '--out' && isset($argv[$i + 1])) {
        $outPath = $argv[$i + 1];
    }
}

if ($outPath !== null) {
    $dir = dirname($outPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $outPath . '.tmp';
    file_put_contents($tmp, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    rename($tmp, $outPath);
}

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    printf("LyraLink capability audit - %s\n", $result['generated_at']);
    printf("  scanned     : %d php files, %d functions indexed (static)\n",
        $scan['files'], count($definedFunctions));
    printf("  declared    : %d  (routing %d, tool %d)\n",
        $result['summary']['capabilities_declared'],
        $result['summary']['routing_capabilities'],
        $result['summary']['capabilities_declared'] - $result['summary']['routing_capabilities']);
    printf("  advertised  : %d  (enabled AND has an executor branch AND not routing)\n", $advertised);
    printf("  permissions : %d distinct\n", count($permissionVocabulary));
    printf("  resources   : %d in registry\n", count($resourceIds));
    printf("  findings    : %d total  (CRITICAL %d, HIGH %d, MEDIUM %d, LOW %d)\n\n",
        count($findings), $bySeverity['CRITICAL'], $bySeverity['HIGH'], $bySeverity['MEDIUM'], $bySeverity['LOW']);

    if ($findings === []) {
        echo "  No findings. Registry, executors, resources, verification and policy agree.\n";
    } else {
        foreach ($findings as $f) {
            printf("  [%-8s] %-24s %s\n", $f['severity'], $f['capability'], $f['issue']);
            printf("             %s\n", $f['detail']);
        }
    }
    echo "\n";
}

exit($bySeverity['CRITICAL'] > 0 ? 1 : 0);
