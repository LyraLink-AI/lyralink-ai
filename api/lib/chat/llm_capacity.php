<?php

declare(strict_types=1);

/**
 * Lyralink LLM capacity control.
 *
 * WHY THIS EXISTS
 * ---------------
 * Measured 2026-09-23 (lyra_traffic.py ramp, 183 requests, 0 errors):
 *
 *   conc  aggregate tok/s   TTFT p50
 *   1     46.2              0.86 s
 *   2     58.5              2.5 s      <- peak throughput
 *   4     58.9              7.6 s
 *   8     53.3             19.5 s
 *   16    38.5             49.2 s
 *   30    33.9            126.8 s
 *
 * Throughput peaks at 2 concurrent and then DECLINES, while latency grows
 * 147x.
 *
 * WHY 2, AND WHY THAT NUMBER IS NOT WHAT IT LOOKS LIKE
 * ---------------------------------------------------
 * The remote GPU generates ONE request at a time, not two. Measured 2026-09-23:
 * llama-server on that box runs with `-np 1`, and a timing test with two
 * concurrent 160-token generations gave t2/t1 = 2.02 (2.54 s vs 1.26 s) while
 * each request still ran at a full ~128 tok/s. That is the signature of
 * serialisation: the second request waited, then ran alone at full speed.
 *
 * So a remote capacity of 2 is NOT two parallel slots. It is a PIPELINE DEPTH:
 * one generating plus one queued. That still earns its place, because a queued
 * request lets the next prompt be prefilled while the GPU finishes the current
 * one, hiding the gap between requests. Measured effect: aggregate throughput
 * rose 46.2 -> 58.5 tok/s going from 1 to 2 in flight. The cost is latency for
 * whoever is second in the queue: first-token time rose 0.86 s -> 2.5 s.
 *
 * The number is therefore a latency/throughput tradeoff, not a hardware limit.
 * Set REMOTE_LLM_MAX_CONCURRENCY=1 for the lowest first-token latency, or 2 to
 * keep the GPU busy at the cost of a queue. Anything above 2 was measured to
 * lose on both axes.
 *
 * The local box serves exactly 1 by deliberate configuration
 * (OLLAMA_NUM_PARALLEL=1), and its single slot is a genuine hardware-slot
 * limit, not a pipeline choice: it is CPU inference on the production box.
 *
 * So dispatching past those counts does not produce more answers. It produces
 * two specific harms:
 *
 *   1. A request dispatched to a saturated remote pool waits out its full
 *      10-35 s remote timeout, then falls back to local anyway. The GPU keeps
 *      processing the abandoned request, so backlog compounds.
 *   2. Requests pushed onto local CPU compete with PHP-FPM and MySQL on the
 *      same 6-core production box, degrading the whole site for everyone.
 *
 * WHAT THIS MODULE IS
 * -------------------
 * Accounting plus a routing signal. It is deliberately FAIL-OPEN: if anything
 * about the mechanism fails (missing dir, permissions, disabled config) the
 * request proceeds untracked. It must never be the reason a user gets no
 * answer. Enforcement lives in the caller, where the existing fallback chain
 * already knows how to recover.
 *
 * HOW SLOTS WORK
 * --------------
 * One lock file per slot. A slot is "held" while a process holds an exclusive
 * flock on it. This is the key property: the Linux kernel releases flock when
 * the process dies, so a PHP fatal, a killed FPM worker, or an OOM does not
 * leak a slot. There is no counter to drift and no stale entry to reap.
 *
 * Tested with PHP 8.3 (the FPM runtime). No APCu dependency.
 */

/**
 * Read a capacity setting from app config, falling back to the environment.
 *
 * api_get_secret() only exists once security.php has loaded, so the
 * function_exists() guard keeps this module usable from standalone CLI
 * tooling and tests.
 */
function llm_capacity_secret(string $key, string $default = ''): string
{
    if (function_exists('api_get_secret')) {
        $value = api_get_secret($key, null);
        if ($value !== null && $value !== '') {
            return (string)$value;
        }
    }

    $env = getenv($key);
    if ($env !== false && $env !== '') {
        return (string)$env;
    }

    return $default;
}

function llm_capacity_enabled(): bool
{
    return llm_capacity_secret('LYRA_LLM_CAPACITY_ENABLED', '1') === '1';
}

function llm_capacity_hard_shed_enabled(): bool
{
    return llm_capacity_secret('LYRA_LLM_HARD_SHED', '0') === '1';
}

/**
 * Root directory for pool state. Lives inside the vhost (not the docroot, so
 * it is not web-reachable) because open_basedir excludes /run and /dev/shm.
 */
function llm_capacity_state_dir(): string
{
    $configured = trim(llm_capacity_secret('LYRA_LLM_STATE_DIR', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $vhost = '/var/www/vhosts/lyralinkai.com';

    return $vhost . '/lyra_runtime';
}

/** Pool names are used to build paths, so restrict them to a safe charset. */
function llm_capacity_safe_pool(string $pool): string
{
    $clean = preg_replace('/[^a-z0-9_]/', '', strtolower($pool)) ?? '';

    return $clean === '' ? 'unknown' : $clean;
}

function llm_capacity_pool_dir(string $pool): string
{
    return llm_capacity_state_dir() . '/slots/' . llm_capacity_safe_pool($pool);
}

/**
 * Slot counts. Defaults are the measured real capacities, not guesses.
 */
function llm_capacity_pool_capacity(string $pool): int
{
    $pool = llm_capacity_safe_pool($pool);
    /* remote=2 is a pipeline depth (1 generating + 1 queued), NOT 2 parallel
     * slots -- see the header. local=1 is a real single-slot limit. */
    $defaults = ['remote' => 2, 'local' => 1, 'default' => 1];
    $key = 'REMOTE_LLM_MAX_CONCURRENCY';
    if ($pool === 'local') {
        $key = 'LOCAL_LLM_MAX_CONCURRENCY';
    } elseif ($pool !== 'remote') {
        $key = 'LYRA_LLM_MAX_CONCURRENCY_' . strtoupper($pool);
    }

    $raw = llm_capacity_secret($key, (string)($defaults[$pool] ?? $defaults['default']));
    $value = (int)$raw;

    return $value > 0 ? $value : (int)($defaults[$pool] ?? $defaults['default']);
}

/**
 * How long a caller may block waiting for a slot.
 *
 * This must stay small. The FPM pool is `pm = ondemand` with
 * pm.max_children = 10, so every millisecond spent waiting holds a worker that
 * the rest of the site cannot use. Waiting is therefore a brief attempt to
 * catch a just-freed slot, never a real queue.
 */
function llm_capacity_pool_wait_ms(string $pool): int
{
    $pool = llm_capacity_safe_pool($pool);
    $key = $pool === 'remote'
        ? 'LYRA_LLM_SLOT_WAIT_MS_REMOTE'
        : ($pool === 'local' ? 'LYRA_LLM_SLOT_WAIT_MS_LOCAL' : 'LYRA_LLM_SLOT_WAIT_MS_DEFAULT');

    $value = (int)llm_capacity_secret($key, '0');
    if ($value < 0) {
        $value = 0;
    }

    return min($value, 1500);
}

/**
 * Create the state directory tree. Returns true when usable.
 *
 * Returns false rather than throwing: a failure here must degrade to
 * "untracked" and never to "no answer".
 */
function llm_capacity_ensure_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return is_writable($dir);
    }

    $parent = dirname($dir);
    if (!is_dir($parent) && !@mkdir($parent, 0770, true) && !is_dir($parent)) {
        return false;
    }

    if (!@mkdir($dir, 0770, true) && !is_dir($dir)) {
        return false;
    }

    return is_writable($dir);
}

function llm_capacity_slot_path(string $pool, int $index): string
{
    return llm_capacity_pool_dir($pool) . '/slot-' . $index . '.lock';
}

/**
 * Try to take a slot in a pool.
 *
 * @return array{state:string,handle:resource|null,pool:string,slot:?int}
 *         state is one of:
 *           acquired  - slot held; caller MUST call llm_capacity_release()
 *           saturated - every slot busy; caller decides whether to fall back
 *           disabled  - feature off, or capacity is zero
 *           error     - state dir unusable; caller should proceed untracked
 */
function llm_capacity_acquire(string $pool, ?int $waitMs = null): array
{
    $pool = llm_capacity_safe_pool($pool);
    $disabled = ['state' => 'disabled', 'handle' => null, 'pool' => $pool, 'slot' => null];

    if (!llm_capacity_enabled()) {
        return $disabled;
    }

    $capacity = llm_capacity_pool_capacity($pool);
    if ($capacity <= 0) {
        return $disabled;
    }

    $dir = llm_capacity_pool_dir($pool);
    if (!llm_capacity_ensure_dir($dir)) {
        return ['state' => 'error', 'handle' => null, 'pool' => $pool, 'slot' => null];
    }

    if ($waitMs === null) {
        $waitMs = llm_capacity_pool_wait_ms($pool);
    }
    $waitMs = max(0, min($waitMs, 1500));
    $deadline = microtime(true) + ($waitMs / 1000.0);

    for (;;) {
        for ($index = 0; $index < $capacity; $index++) {
            $path = $dir . '/slot-' . $index . '.lock';
            $handle = @fopen($path, 'c');
            if ($handle === false) {
                continue;
            }
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                /* Lock files carry no data, and both the root-run CLI tooling
                 * and the lyralinkaiadmin FPM worker must be able to open the
                 * same file, so keep them group/world readable-writable. */
                @chmod($path, 0666);

                return [
                    'state' => 'acquired',
                    'handle' => $handle,
                    'pool' => $pool,
                    'slot' => $index,
                ];
            }
            @fclose($handle);
        }

        if (microtime(true) >= $deadline) {
            return ['state' => 'saturated', 'handle' => null, 'pool' => $pool, 'slot' => null];
        }

        /* 25 ms poll. Short enough that a waiting request does not meaningfully
         * hold an FPM worker. */
        usleep(25000);
    }
}

/**
 * Release a slot taken by llm_capacity_acquire().
 *
 * Safe to call with null, and safe to call twice; a double release is a no-op
 * because the handle is gone. Never throws.
 */
function llm_capacity_release(mixed $handle): void
{
    if (!is_resource($handle)) {
        return;
    }

    try {
        @flock($handle, LOCK_UN);
    } catch (\Throwable) {
        /* Ignore: the kernel releases the lock at process exit regardless. */
    }

    @fclose($handle);
}

/**
 * Count slots currently held in a pool, without taking one.
 *
 * Works by probing: if a non-blocking exclusive lock succeeds, the slot is
 * free and is released immediately. Read-only and side-effect free.
 */
function llm_capacity_active(string $pool): int
{
    $pool = llm_capacity_safe_pool($pool);
    $capacity = llm_capacity_pool_capacity($pool);
    if ($capacity <= 0) {
        return 0;
    }

    $busy = 0;
    for ($index = 0; $index < $capacity; $index++) {
        $path = llm_capacity_slot_path($pool, $index);
        if (!is_file($path)) {
            continue;
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            /* Cannot even open it; assume the slot is in use rather than
             * assuming it is free, so we do not over-dispatch. */
            $busy++;
            continue;
        }

        if (@flock($handle, LOCK_EX | LOCK_NB)) {
            @flock($handle, LOCK_UN);
        } else {
            $busy++;
        }

        @fclose($handle);
    }

    return $busy;
}

/**
 * Capacity picture for all known pools.
 *
 * @return array<string,array{capacity:int,active:int,free:int,wait_ms:int}>
 */
function llm_capacity_snapshot(): array
{
    $pools = ['remote', 'local'];
    $configured = trim(llm_capacity_secret('LYRA_LLM_EXTRA_POOLS', ''));
    if ($configured !== '') {
        foreach (explode(',', $configured) as $extra) {
            $extra = llm_capacity_safe_pool(trim($extra));
            if ($extra !== '' && !in_array($extra, $pools, true)) {
                $pools[] = $extra;
            }
        }
    }

    $snapshot = [];
    foreach ($pools as $pool) {
        $capacity = llm_capacity_pool_capacity($pool);
        $active = llm_capacity_enabled() ? llm_capacity_active($pool) : 0;
        $snapshot[$pool] = [
            'capacity' => $capacity,
            'active' => $active,
            'free' => max(0, $capacity - $active),
            'wait_ms' => llm_capacity_pool_wait_ms($pool),
        ];
    }

    return $snapshot;
}

/**
 * Record a routing/shed decision so the behaviour is observable after the
 * fact. Bounded at 1 MiB with a single-generation rotation, matching the
 * pattern used by lyralink-ollama-memguard.sh.
 */
function llm_capacity_note(string $event, array $context = []): void
{
    if (llm_capacity_secret('LYRA_LLM_STATS_ENABLED', '1') !== '1') {
        return;
    }

    $dir = llm_capacity_state_dir();
    if (!llm_capacity_ensure_dir($dir)) {
        return;
    }

    $log = $dir . '/capacity_events.jsonl';
    if (is_file($log) && (int)@filesize($log) > 1048576) {
        @rename($log, $log . '.1');
    }

    $record = ['ts' => date('c'), 'event' => $event] + $context;
    $line = json_encode($record, JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }

    @file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Full admission decision for one logical request.
 *
 * Kept separate from the routing gate in runtime_core.php so that the policy
 * is testable on its own and so the hard-shed behaviour can be switched on
 * without touching routing logic.
 *
 * @return array{action:string,reason:string,pool:?string}
 *         action 'allow' - proceed (always the default when unsure)
 *         action 'avoid' - pool is saturated; caller should prefer the other
 */
function llm_capacity_admission(string $pool, bool $shedWhenFull = false): array
{
    if (!llm_capacity_enabled()) {
        return ['action' => 'allow', 'reason' => 'capacity control disabled', 'pool' => null];
    }

    $capacity = llm_capacity_pool_capacity($pool);
    $active = llm_capacity_active($pool);
    if ($active < $capacity) {
        return ['action' => 'allow', 'reason' => 'slot available', 'pool' => $pool];
    }

    if ($shedWhenFull && llm_capacity_hard_shed_enabled()) {
        return ['action' => 'shed', 'reason' => 'pool saturated', 'pool' => $pool];
    }

    return ['action' => 'avoid', 'reason' => 'pool saturated', 'pool' => $pool];
}
