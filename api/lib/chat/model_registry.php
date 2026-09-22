<?php
/**
 * Lyralink local model capability registry.
 *
 * WHY THIS EXISTS
 * ---------------
 * The architecture requires the router to select intelligence by capability
 * ("If adding a model, define its capability metadata so the router can select it
 * intelligently" - OS master context rule 18; see also section 26, which lists
 * "capability metadata" as something Lyralink must understand about its models).
 *
 * Before this file, the router mapped intents onto model NAMES with no recorded
 * capability data, and nothing recorded that several names are the same weights.
 * Measured 2026-09-22 on this host: lyralink-fast, lyralink-creative,
 * lyralink-reasoning, lyralink-code and lyralink-auto-canary all resolve FROM the
 * same base blob (sha256-f616bb4104f36158e1838a5065618f7eba8437504250e80f9a9c51b373f4f1d4).
 * Only lyralink-code-pro differs. So five router intents resolve to one model and
 * routing across them is nominal, not real.
 *
 * RESOLVED 2026-09-22: the router now targets distinct instruct weights -
 * qwen2.5:3b for general/fast/creative and qwen2.5:7b for reasoning/research -
 * so the seven intents now resolve to three distinct weight sets, and the sharing
 * that remains is declared in model_registry_intent_groups(). The five lyralink
 * aliases are retained but are no longer routed.
 *
 * Ollama cannot reveal this over the API: its per-model manifest digests differ for
 * every alias even when they share a base blob, so /api/tags looks healthy. The base
 * identity is only visible in the Modelfile FROM line or the manifest layers, which
 * is why this registry DECLARES the identity and model_registry_verify_live()
 * re-checks it against reality on demand.
 *
 * MEASUREMENT PROVENANCE
 * ----------------------
 * tok_per_sec and runner_rss_mb below were measured on this host (6 vCPU, no GPU,
 * 11.96 GB RAM, OLLAMA_NUM_PARALLEL=1, OLLAMA_CONTEXT_LENGTH=8192,
 * OLLAMA_KV_CACHE_TYPE=f16) using /api/generate with num_predict=80 and each model
 * loaded in isolation. They are workload-dependent estimates, not guarantees.
 *
 * MAINTENANCE
 * -----------
 * Re-run: php api/lib/chat/model_registry.php --verify
 */

/** Base-blob digests identifying the REAL weights behind each alias. */
const MODEL_REGISTRY_BLOB_HERMES3_3B = 'sha256-f616bb4104f36158e1838a5065618f7eba8437504250e80f9a9c51b373f4f1d4';
const MODEL_REGISTRY_BLOB_QWEN25_CODER_7B = 'sha256-60e05f2100071479f596b964f89f510f057ce397ea22f2833a0cfe029bfc2463';
const MODEL_REGISTRY_BLOB_QWEN25_CODER_3B = 'sha256-4a188102020e9c9530b687fd6400f775c45e90a0d7baafe65bd0a36963fbb7ba';
const MODEL_REGISTRY_BLOB_QWEN25_3B = 'sha256-5ee4f07cdb9beadbbb293e85803c569b01bd37ed059d2715faa7bb405f31caa6';
const MODEL_REGISTRY_BLOB_QWEN25_7B = 'sha256-2bada8a7450677000f678be90653b85d364de7db25eb5ea54136ada5f3933730';
const MODEL_REGISTRY_BLOB_LLAVA_7B = 'sha256-170370233dd5c5415250a2ecd5c71586352850729062ccef1496385647293868';
const MODEL_REGISTRY_BLOB_NOMIC_EMBED = 'sha256-970aa74c0a90ef7482477cf803618e776e173c007bf957f635f1015bfcfef0e6';

/**
 * Measured host budget. Recorded so routing decisions that ignore RAM have to do so
 * explicitly rather than by omission.
 */
function model_registry_host_budget(): array {
    return [
        'measured_on'                => '2026-09-22',
        'vcores'                     => 6,
        'gpu'                        => false,
        'ram_total_mb'               => 11956,
        'baseline_services_mb'       => 1124,   // all models unloaded
        'num_parallel'               => 1,
        'max_loaded_models'          => 2,
        'context_length'             => 8192,
        // Both models verified co-resident with ~2.8 GB to spare and no swap use.
        'verified_co_resident_mb'    => 8122,   // qwen2.5:7b + qwen2.5:3b
        'verified_co_resident_free_mb' => 6236,
        // Routing is only free (no reload) while the working set fits max_loaded_models.
        'max_distinct_hot_models'    => 2,
    ];
}

/**
 * Declared capability metadata. Keyed by the model tag the router uses.
 *
 * weights: base-blob digest, i.e. the REAL weights. Two entries sharing this value
 *          are the same model under different names.
 * tier:    rough capability class, used to compare specialisation.
 * specializations: intents this model is the best fit for.
 */
function model_registry(): array {
    $hermes = [
        'weights'         => MODEL_REGISTRY_BLOB_HERMES3_3B,
        'base'            => 'hermes3:3b',
        'params_b'        => 3,
        'quant'           => 'Q4_K_M',
        'tier'            => 'small-general',
        'tok_per_sec'     => 26.62,
        'runner_rss_mb'   => 2900,
        'load_seconds'    => 4.7,
        'prefill_tok_per_sec' => 77.6,
        'context'         => 8192,
        'specializations' => ['general', 'fast', 'creative', 'reasoning'],
        'notes'           => 'Fastest model on this host. Weak on code.',
    ];
    $coder = [
        'weights'         => MODEL_REGISTRY_BLOB_QWEN25_CODER_7B,
        'base'            => 'qwen2.5-coder:7b',
        'params_b'        => 7,
        'quant'           => 'Q4_K_M',
        'tier'            => 'mid-code',
        'tok_per_sec'     => 13.22,
        'runner_rss_mb'   => 4980,
        'load_seconds'    => 8.2,
        'prefill_tok_per_sec' => 29.8,
        'context'         => 8192,
        'specializations' => ['code'],
        'notes'           => 'Half the speed of the 3B but the only real code-specialised weights installed.',
    ];
    $vision = [
        'weights'         => MODEL_REGISTRY_BLOB_LLAVA_7B,
        'base'            => 'llava:7b',
        'params_b'        => 7,
        'quant'           => 'Q4_K_M',
        'tier'            => 'mid-vision',
        'tok_per_sec'     => null,   // not measured in this pass
        'runner_rss_mb'   => null,
        'load_seconds'    => null,
        'prefill_tok_per_sec' => null,
        'context'         => 4096,
        'specializations' => ['vision'],
        'notes'           => 'Image path (LOCAL_LLM_IMAGE_MODEL). Loading it evicts one of the two hot models.',
    ];
    $embed = [
        'weights'         => MODEL_REGISTRY_BLOB_NOMIC_EMBED,
        'base'            => 'nomic-embed-text',
        'params_b'        => null,
        'quant'           => null,
        'tier'            => 'embedding',
        'tok_per_sec'     => null,
        'runner_rss_mb'   => 376,
        'load_seconds'    => null,
        'prefill_tok_per_sec' => null,
        'context'         => 2048,
        'specializations' => ['embedding'],
        'notes'           => 'Cheap and always resident; used by dataset search.',
    ];

    $coder3 = [
        'weights'         => MODEL_REGISTRY_BLOB_QWEN25_CODER_3B,
        'base'            => 'qwen2.5-coder:3b',
        'params_b'        => 3,
        'quant'           => 'Q4_K_M',
        'tier'            => 'small-code',
        'tok_per_sec'     => 27.89,
        'runner_rss_mb'   => 2197,
        'load_seconds'    => 2.9,
        'prefill_tok_per_sec' => 83.1,
        'context'         => 8192,
        'specializations' => ['code'],
        'notes'           => 'Valid PHP on all 4 functional code tasks; the general 3B managed 1/4.',
    ];

    $instruct3 = [
        'weights'         => MODEL_REGISTRY_BLOB_QWEN25_3B,
        'base'            => 'qwen2.5:3b',
        'params_b'        => 3,
        'quant'           => 'Q4_K_M',
        'tier'            => 'small-general',
        'tok_per_sec'     => 25.0,
        'runner_rss_mb'   => 2196,
        'load_seconds'    => 4.1,
        'prefill_tok_per_sec' => null,
        'context'         => 8192,
        'specializations' => ['general', 'fast', 'creative'],
        'notes'           => 'Replaces hermes3:3b for general/creative: measured 3/4 creative
                              and 3/4 reasoning vs 2/4 and 1/4, while being 1182 MB lighter
                              (2196 vs 3378). Degenerated into digit repetition once on a
                              unit-conversion task, so it is not used for reasoning.',
    ];

    $instruct7 = [
        'weights'         => MODEL_REGISTRY_BLOB_QWEN25_7B,
        'base'            => 'qwen2.5:7b',
        'params_b'        => 7,
        'quant'           => 'Q4_K_M',
        'tier'            => 'mid-general',
        'tok_per_sec'     => 14.5,
        'runner_rss_mb'   => 4983,
        'load_seconds'    => 10.3,
        'prefill_tok_per_sec' => null,
        'context'         => 8192,
        'specializations' => ['reasoning', 'research'],
        'notes'           => 'The only candidate to score 4/4 on reasoning AND reject the
                              false premise without degenerating. Half the speed of the 3B
                              but co-resides with it, so the reasoning intent costs memory
                              (nothing extra beyond the resident pair) rather than latency
                              only on a cold swap.',
    ];

    $alias = static fn(array $m): array => $m;

    return [
        // Five aliases, ONE set of weights. As of 2026-09-22 the router no longer
        // targets these: they are retained for backwards compatibility only, because
        // their Modelfile SYSTEM is overridden by chat.php's own system message.
        'lyralink-auto-canary:latest' => $alias($hermes) + ['role' => 'default'],
        'lyralink-fast:latest'        => $alias($hermes) + ['role' => 'fast'],
        'lyralink-reasoning:latest'   => $alias($hermes) + ['role' => 'reasoning'],
        'lyralink-creative:latest'    => $alias($hermes) + ['role' => 'creative'],
        'lyralink-code:latest'        => $alias($hermes) + ['role' => 'code'],
        // The only distinct weight set that is actually usable for text.
        'lyralink-code-pro:latest'    => $alias($coder) + ['role' => 'code-pro'],
        // Not reachable through the router: llm_safe_local_model() accepts only names
        // containing "lyralink", and the image path is selected separately.
        // Code-specialised 3B. Replaces the general 3B for the 'code' intent: measured
        // 8/14 vs 1/14 on functional checks while also being faster and lighter, so
        // routing 'code' here removes an invalid-PHP failure mode at no cost.
        'qwen2.5-coder:3b'            => $alias($coder3) + ['role' => 'code-primary'],
        'llava:7b'                    => $alias($vision) + ['role' => 'vision'],
        'nomic-embed-text:latest'     => $alias($embed) + ['role' => 'embedding'],
        // Distinct instruct weights, added 2026-09-22 to make routing real:
        // six of seven intents now resolve to different weights from a third.
        'qwen2.5:3b'                  => $alias($instruct3) + ['role' => 'general-primary'],
        'qwen2.5:7b'                  => $alias($instruct7) + ['role' => 'reasoning-primary'],
    ];
}

/**
 * Group models that are the SAME weights under different names.
 *
 * @return array<string, string[]> blob digest => list of model tags
 */
function model_registry_duplicate_groups(): array {
    $groups = [];
    foreach (model_registry() as $tag => $meta) {
        $groups[$meta['weights']][] = $tag;
    }
    return array_filter($groups, static fn(array $tags): bool => count($tags) > 1);
}

/**
 * Which router intents resolve to identical weights.
 *
 * A router whose intents collide is not routing; it is renaming. Reporting that is
 * the point: section 28 of the OS master context ("Provenance") wants conclusions
 * traceable, and "the router selected the code model" is a conclusion that must not
 * be an illusion.
 *
 * @param array $routerMap intent => model tag, as returned by chat_model_router_map()
 * @return array{matrix: array, collisions: array, declared_groups: array,
 *               undeclared_collisions: array, distinct_weights: int, routing_is_real: bool}
 */
/**
 * Intents that INTENTIONALLY share one weight set, with the reason.
 *
 * This exists because on this host (6 vCPU, no GPU, 11.9 GB RAM, max 2 resident
 * models) giving every intent its own weights would mean more model reloads and more
 * disk for no measured gain. Four general intents share the 3B because the 7B measured
 * no better on creative constraint-following; reasoning and research share the 7B
 * because research synthesis IS reasoning over retrieved evidence.
 *
 * Anything NOT declared here that shares weights is an accidental collision, and
 * model_registry_router_report() still fails on it.
 */
function model_registry_intent_groups(): array {
    return [
        'qwen2.5:3b' => [
            'intents' => ['default', 'fast', 'creative', 'fallback'],
            'why'     => 'General chat. Measured creative constraint-following 3/4 on both '
                       . 'the 3B and the 7B, so the 3B is chosen for speed and weight. '
                       . 'Fallback shares it because it must never be the slow path.',
        ],
        'qwen2.5:7b' => [
            'intents' => ['reasoning', 'research'],
            'why'     => 'The only candidate to score 4/4 on reasoning and reject the false '
                       . 'premise without degenerating; research synthesis needs the same '
                       . 'capability over retrieved evidence.',
        ],
    ];
}

function model_registry_router_report(array $routerMap): array {
    $reg  = model_registry();
    $seen = [];
    $matrix = [];
    foreach ($routerMap as $intent => $tag) {
        $tag = (string)$tag;
        $weights = $reg[$tag]['weights'] ?? null;
        $matrix[$intent] = [
            'model'   => $tag,
            'base'    => $reg[$tag]['base'] ?? null,
            'weights' => $weights,
            'known'   => $weights !== null,
        ];
        if ($weights !== null) {
            $seen[$weights][] = $intent;
        }
    }
    $collisions = [];
    foreach ($seen as $weights => $intents) {
        if (count($intents) > 1) {
            $collisions[] = ['weights' => $weights, 'intents' => $intents];
        }
    }
    // Sharing weights is not by itself a defect: see model_registry_intent_groups().
    // Only UNdeclared sharing is, because that is the shape of the original bug - many
    // intent names implying many models while all of them resolve to one weight set.
    $declared = [];
    foreach (model_registry_intent_groups() as $tag => $group) {
        $w = $reg[$tag]['weights'] ?? null;
        if ($w !== null) {
            $declared[$w] = array_values($group['intents']);
        }
    }
    $undeclared = [];
    foreach ($collisions as $c) {
        $actual = $c['intents'];
        $expect = $declared[$c['weights']] ?? null;
        if ($expect === null) {
            $undeclared[] = $c;
            continue;
        }
        sort($actual);
        sort($expect);
        if ($expect !== $actual) {
            $undeclared[] = $c;
        }
    }

    return [
        'matrix'                => $matrix,
        'collisions'            => $collisions,
        'declared_groups'       => model_registry_intent_groups(),
        'undeclared_collisions' => $undeclared,
        'distinct_weights'      => count($seen),
        'routing_is_real'       => $undeclared === [],
    ];
}

/**
 * Re-check the declared identities against the installed models.
 * CLI only: shells out to `ollama show --modelfile`.
 *
 * @return array{ok: bool, checked: int, drift: array, missing: string[]}
 */
function model_registry_verify_live(): array {
    if (PHP_SAPI !== 'cli') {
        return ['ok' => false, 'checked' => 0, 'drift' => [], 'missing' => [],
                'error' => 'CLI only'];
    }
    $drift = [];
    $missing = [];
    $checked = 0;
    foreach (model_registry() as $tag => $meta) {
        $out = [];
        $rc = 0;
        @exec('ollama show ' . escapeshellarg($tag) . ' --modelfile 2>/dev/null', $out, $rc);
        $from = '';
        foreach ($out as $line) {
            if (stripos(ltrim($line), 'FROM ') === 0) { $from = trim(substr(ltrim($line), 5)); break; }
        }
        if ($from === '') { $missing[] = $tag; continue; }
        $checked++;
        // A blob path is /.../blobs/sha256-<hex>; compare the full digest we declared.
        $declared = str_replace('sha256-', '', $meta['weights']);
        if (strpos($from, $declared) === false) {
            $drift[] = ['tag' => $tag, 'declared' => $meta['weights'], 'actual_from' => $from];
        }
    }
    return ['ok' => $drift === [] && $missing === [], 'checked' => $checked,
            'drift' => $drift, 'missing' => $missing];
}

// ── CLI: php api/lib/chat/model_registry.php --verify ────────────────────────────
// Main-script-only: this file is also a library, and an argv-blind check would
// fire if a caller's own CLI arguments happened to contain '--verify'.
if (PHP_SAPI === 'cli'
    && isset($argv[0]) && realpath((string)$argv[0]) === realpath(__FILE__)
    && isset($argv[1]) && $argv[1] === '--verify') {
    $v = model_registry_verify_live();
    printf("checked %d declared models; %d missing weight declarations\n", $v['checked'], count($v['missing']));
    foreach ($v['missing'] as $t) { echo "  MISSING (not installed or unreadable): $t\n"; }
    foreach ($v['drift'] as $d) {
        echo "  DRIFT: {$d['tag']}\n    declared: {$d['declared']}\n    actual  : {$d['actual_from']}\n";
    }
    echo $v['ok'] ? "registry matches installed models\n" : "registry DOES NOT match installed models\n";

    // Read the LIVE router map rather than a hardcoded copy. A hardcoded copy drifts
    // from .env and then reports collisions that no longer exist, which is worse than
    // reporting nothing.
    if (!function_exists('api_get_secret') && is_file(dirname(__DIR__, 2) . '/security.php')) {
        require_once dirname(__DIR__, 2) . '/security.php';
    }
    if (is_file(__DIR__ . '/llm_routing.php') && !function_exists('chat_model_router_map')) {
        require_once __DIR__ . '/llm_routing.php';
    }
    if (function_exists('chat_model_router_map')) {
        $map = chat_model_router_map();
    } else {
        fwrite(STDERR, "note: routing layer unavailable; collision report skipped\n");
        $map = [];
    }
    $modelRegistryWeightsByTag = [];
    foreach (model_registry() as $t => $m) {
        $modelRegistryWeightsByTag[$t] = $m['weights'] ?? null;
    }
    $r = model_registry_router_report($map);
    printf("\nrouter intents: %d, distinct weight sets: %d, routing_is_real: %s\n",
        count($r['matrix']), $r['distinct_weights'], $r['routing_is_real'] ? 'yes' : 'NO');
    foreach ($r['collisions'] as $c) {
        $isDeclared = true;
        foreach ($r['undeclared_collisions'] as $u) {
            if ($u['weights'] === $c['weights']) {
                $isDeclared = false;
                break;
            }
        }
        printf("  %-16s on %s : %s
",
            $isDeclared ? 'declared-sharing' : '*** COLLISION ***',
            substr($c['weights'], 0, 26) . '...', implode(', ', $c['intents']));
        if ($isDeclared) {
            foreach ($r['declared_groups'] as $gTag => $g) {
                if (($modelRegistryWeightsByTag[$gTag] ?? null) === $c['weights']) {
                    printf("      reason: %s
", $g['why']);
                }
            }
        }
    }
}

/**
 * Model tags that are usable as LOCAL CHAT models.
 *
 * Excludes embedding and vision-only entries: the router must not select a model
 * that cannot hold a conversation. This is the list the routing layer trusts, so
 * adding a model is a matter of giving it metadata here rather than editing the
 * routing code (rule 18 / section 26).
 */
function model_registry_local_chat_models(): array {
    $out = [];
    foreach (model_registry() as $tag => $meta) {
        $specs = $meta['specializations'] ?? [];
        if (in_array('embedding', $specs, true) || in_array('vision', $specs, true)) {
            continue;
        }
        $out[] = $tag;
    }
    return $out;
}
