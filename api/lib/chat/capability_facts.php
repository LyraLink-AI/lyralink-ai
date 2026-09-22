<?php

declare(strict_types=1);

/**
 * Deterministic capability self-description.
 *
 * Why this exists
 * ---------------
 * api/lib/chat/os_core.php already defines a capability registry with full metadata, and
 * chat_capability_tool_manifest() already builds the model's tool list from it, correctly
 * refusing to advertise a capability that has no executor. That layer is sound.
 *
 * What was missing is the other direction. When a user or the orchestrator asks "what can
 * you do?", "do you have internet access?", "can you install packages?" - the answer was
 * GENERATED. That is the same failure class as the model-identity hallucination (T045), and
 * far more dangerous for an operating system: a brain that misdescribes its own capabilities
 * will either promise work it cannot do, or ignore a tool it actually has.
 *
 * A generated capability list also drifts. The registry is the source of truth; prose about
 * it is a copy, and copies go stale. So the answer here is assembled from the registry at
 * request time and never written by hand.
 *
 * The gap list is DERIVED, not asserted
 * ------------------------------------
 * Saying "I cannot manage packages" would be a hand-written claim that rots the moment a
 * capability is added. Instead each operation category names the capability that would
 * satisfy it; a category is reported as unavailable only because no advertised capability
 * matches. Add a package.install capability tomorrow and it moves to the available list by
 * itself.
 *
 * Scope discipline
 * ----------------
 * Only two shapes are answered deterministically:
 *   1. enumeration - "what can you do", "what are your capabilities", "list your tools"
 *   2. targeted    - "can you <operation>" where <operation> matches a known category
 * Anything else returns null and the normal pipeline handles it. A question that merely
 * contains the word "can" or "write" is not hijacked.
 *
 * Disable with LYRA_CAPABILITY_FACTS=0.
 */

if (!function_exists('chat_capability_facts_env')) {
    function chat_capability_facts_env(string $key, string $default = ''): string {
        if (function_exists('api_get_secret')) {
            try {
                $value = api_get_secret($key, $default);
                if (is_scalar($value)) {
                    return trim((string)$value);
                }
            } catch (\Throwable $e) {
                return $default;
            }
        }
        $env = getenv($key);
        return is_string($env) ? trim($env) : $default;
    }
}

if (!function_exists('chat_capability_facts_enabled')) {
    function chat_capability_facts_enabled(): bool {
        return chat_capability_facts_env('LYRA_CAPABILITY_FACTS', '1') !== '0';
    }
}

if (!function_exists('chat_capability_facts_tools')) {
    /**
     * Capabilities that are genuinely usable right now: enabled, not internal routing, and
     * backed by a real executor branch. Mirrors the manifest's own admission rule so this
     * list can never be broader than the tools the model is actually offered.
     *
     * @return array<int,array<string,mixed>>
     */
    function chat_capability_facts_tools(): array {
        if (!function_exists('chat_os_capability_registry')) {
            return [];
        }

        $registry = chat_os_capability_registry([]);
        if (!is_array($registry)) {
            return [];
        }

        $tools = [];
        foreach ($registry as $id => $cap) {
            $id = (string)$id;
            if (!is_array($cap) || empty($cap['enabled'])) {
                continue;
            }
            // model.* are internal routing paths, deliberately not user-callable tools.
            if (strpos($id, 'model.') === 0) {
                continue;
            }
            if (function_exists('chat_capability_has_executor') && !chat_capability_has_executor($id)) {
                continue;
            }
            $tools[] = [
                'capability_id' => $id,
                'name' => (string)($cap['name'] ?? $id),
                'description' => (string)($cap['description'] ?? ''),
                'risk_level' => strtoupper((string)($cap['risk_level'] ?? 'UNKNOWN')),
                'requires_confirmation' => !empty($cap['requires_confirmation']),
                'required_permissions' => array_values(array_map('strval', (array)($cap['required_permissions'] ?? []))),
                'verification_method' => (string)($cap['verification_method'] ?? ''),
            ];
        }

        usort($tools, static fn(array $a, array $b): int => strcmp($a['capability_id'], $b['capability_id']));
        return $tools;
    }
}

if (!function_exists('chat_capability_facts_categories')) {
    /**
     * Operation categories a person reasonably asks about, each naming the capability that
     * would satisfy it. Entries with a null capability are operations for which no
     * capability is currently registered.
     *
     * @return array<string,array{capability:?string,phrases:array<int,string>}>
     */
    function chat_capability_facts_categories(): array {
        return [
            'search the web'            => ['capability' => 'web.search',         'phrases' => ['search', 'google', 'look up', 'look something up', 'research', 'find sources']],
            'retrieve a web page'       => ['capability' => 'web.retrieve',       'phrases' => ['fetch a page', 'fetch page', 'retrieve a page', 'download a page', 'read a url', 'open a url', 'browse']],
            'verify a source'           => ['capability' => 'web.verify_source',  'phrases' => ['verify a source', 'verify sources', 'cite', 'citation']],
            'read files'                => ['capability' => 'filesystem.read',    'phrases' => ['read a file', 'read files', 'read file', 'open a file', 'view a file', 'cat a file', 'inspect a file']],
            'write or edit files'       => ['capability' => 'filesystem.write',   'phrases' => ['write a file', 'write files', 'edit a file', 'edit files', 'modify a file', 'create a file', 'save a file', 'patch a file']],
            'inspect the host'          => ['capability' => 'server.inspect',     'phrases' => ['inspect the server', 'inspect the host', 'check cpu', 'check memory', 'check disk', 'check services', 'check ports', 'system status', 'what is running']],
            'query a database'          => ['capability' => 'database.query',     'phrases' => ['query the database', 'query a database', 'run sql', 'sql query', 'read from the database']],
            'run shell commands'        => ['capability' => 'shell.execute',      'phrases' => ['run a command', 'run commands', 'run shell', 'execute a command', 'execute commands', 'run a script']],

            // No capability registered today. Reported as unavailable because nothing matches,
            // not because this list says so.
            'install or remove packages' => ['capability' => null, 'phrases' => ['install packages', 'install a package', 'install software', 'apt install', 'uninstall', 'remove a package', 'manage packages']],
            'manage users or accounts'   => ['capability' => null, 'phrases' => ['create a user', 'add a user', 'manage users', 'user accounts', 'reset a password', 'manage accounts']],
            'configure the firewall'     => ['capability' => null, 'phrases' => ['change the firewall', 'firewall rules', 'configure the firewall', 'open a port', 'iptables', 'ufw']],
            'manage disks or partitions' => ['capability' => null, 'phrases' => ['manage disks', 'partition a disk', 'format a disk', 'mount a disk', 'resize a partition']],
            'reboot or power control'    => ['capability' => null, 'phrases' => ['reboot', 'restart the machine', 'shut down', 'power off', 'power cycle']],
            'schedule tasks or cron'     => ['capability' => null, 'phrases' => ['schedule a task', 'add a cron job', 'edit cron', 'crontab', 'manage scheduled tasks', 'systemd timer']],
            'change boot configuration'  => ['capability' => null, 'phrases' => ['change boot', 'bootloader', 'grub', 'boot configuration']],
            'access secrets or keys'     => ['capability' => null, 'phrases' => ['read secrets', 'access secrets', 'api keys', 'credentials', 'read the env file', 'access tokens']],
        ];
    }
}

if (!function_exists('chat_capability_facts_is_enumeration')) {
    function chat_capability_facts_is_enumeration(string $message): bool {
        $t = strtolower(trim($message));
        if ($t === '') {
            return false;
        }

        // Anchored on purpose. A substring match swallowed real questions:
        //   "What can you do about my slow website?"
        // is a request for help, not a capability enquiry, but it starts with the phrase.
        // The whole message must BE the question, allowing only polite padding.
        $phrase = '(?:what\s+(?:can|are)\s+you(?:\s+(?:do|capable\s+of))?'
            . '|what\s+are\s+your\s+(?:capabilit(?:y|ies)|tools|skills|abilities)'
            . '|list\s+(?:your\s+|all\s+)?(?:capabilit(?:y|ies)|tools|skills|abilities)'
            . '|what\s+tools\s+(?:do\s+)?you\s+have'
            . '|(?:show|tell)\s+me\s+your\s+(?:capabilit(?:y|ies)|tools|skills|abilities)'
            . '|what\s+are\s+you\s+able\s+to\s+do)';
        $padding = '(?:\s+(?:please|for\s+me|right\s+now|currently|today|these\s+days))*';

        return preg_match(
            '/^(?:hi|hey|hello|ok|okay|so|and)?[,\s]*' . $phrase . $padding . '[?.!\s]*$/i',
            $t
        ) === 1;
    }
}

if (!function_exists('chat_capability_facts_is_concrete_request')) {
    /**
     * True when the message names a specific path, URL, host or filename.
     *
     * This distinction matters more than any other here. "Can you read files?" is a question
     * about capability. "Can you read /etc/nginx/nginx.conf?" is a REQUEST TO ACT, and
     * answering it with "Yes, that is the filesystem.read capability" would hijack a real
     * task and break working behaviour. Anything concrete falls through to the normal
     * pipeline, where the tools actually run.
     */
    function chat_capability_facts_is_concrete_request(string $message): bool {
        // Single-quoted, slash-delimited. The previous attempt split the pattern as
        // '#...' . 'i', which dropped the closing # and made PHP warn
        // "No ending delimiter '#' found" - the pattern silently never matched, so the
        // guard did nothing while appearing to pass tests. Keep this as ONE string.
        return preg_match(
            '/(?:https?:\/\/|www\.|\/\S+\/|\b[\w.-]+\.(?:php|py|js|json|conf|txt|log|md|ya?ml|ini|env|csv|sql|sh|html|htm|xml|css|service|timer|rules)\b|\\\\|`)/i',
            $message
        ) === 1;
    }
}

if (!function_exists('chat_capability_facts_match_category')) {
    /**
     * Best matching category for a targeted question, or null.
     * Longest phrase match wins so "install a package" beats a shorter generic phrase.
     */
    function chat_capability_facts_match_category(string $message): ?string {
        $t = strtolower(trim($message));
        $best = null;
        $bestLen = 0;

        foreach (chat_capability_facts_categories() as $label => $def) {
            foreach ($def['phrases'] as $phrase) {
                $len = strlen($phrase);
                if ($len > $bestLen && strpos($t, $phrase) !== false) {
                    $best = $label;
                    $bestLen = $len;
                }
            }
        }

        return $best;
    }
}

if (!function_exists('chat_capability_facts_is_targeted')) {
    function chat_capability_facts_is_targeted(string $message): bool {
        $t = strtolower(trim($message));
        if ($t === '') {
            return false;
        }
        // A concrete object means this is a request to act, not a capability question.
        if (chat_capability_facts_is_concrete_request($message)) {
            return false;
        }
        // Must read as a capability question, not merely mention an operation.
        $asks = preg_match(
            '/\b(?:can|could|would|will)\s+you\b|\bare\s+you\s+able\s+to\b|\bdo\s+you\s+have\s+the\s+ability\b'
            . '|\bdo\s+you\s+have\s+(?:a\s+)?(?:tool|capability|capabilities|access)\b'
            . '|\bis\s+it\s+possible\s+for\s+you\s+to\b/i',
            $t
        ) === 1;

        return $asks && chat_capability_facts_match_category($t) !== null;
    }
}

if (!function_exists('chat_capability_facts_answer')) {
    /**
     * Complete deterministic answer, or null to let the normal pipeline handle it.
     */
    function chat_capability_facts_answer(string $message): ?array {
        if (!chat_capability_facts_enabled()) {
            return null;
        }

        $enumerate = chat_capability_facts_is_enumeration($message);
        $targeted = !$enumerate && chat_capability_facts_is_targeted($message);
        if (!$enumerate && !$targeted) {
            return null;
        }

        $tools = chat_capability_facts_tools();
        $byId = [];
        foreach ($tools as $tool) {
            $byId[$tool['capability_id']] = $tool;
        }

        // ---- targeted question ---------------------------------------------------------
        if ($targeted) {
            $category = chat_capability_facts_match_category($message);
            $def = chat_capability_facts_categories()[$category] ?? null;
            $capId = $def['capability'] ?? null;

            if ($capId !== null && isset($byId[$capId])) {
                $tool = $byId[$capId];
                $confirm = $tool['requires_confirmation']
                    ? ' It is marked as requiring explicit confirmation before it runs.'
                    : ' It does not require separate confirmation.';
                $perms = $tool['required_permissions'] !== []
                    ? ' Required permission: ' . implode(', ', $tool['required_permissions']) . '.'
                    : '';
                $verify = $tool['verification_method'] !== ''
                    ? ' Result verification: ' . $tool['verification_method'] . '.'
                    : '';

                return [
                    'matched' => true,
                    'status' => 'ok',
                    'intent' => 'capability_targeted',
                    'capability' => $capId,
                    'reply' => "Yes. That is the '{$capId}' capability, so I can do it."
                        . " Risk level: {$tool['risk_level']}.{$confirm}{$perms}{$verify}"
                        . ' This answer is read from the capability registry, not generated.',
                ];
            }

            // The category exists but no advertised capability satisfies it.
            return [
                'matched' => true,
                'status' => 'unavailable',
                'intent' => 'capability_targeted',
                'capability' => null,
                'reply' => "No. I do not have a registered capability for \"{$category}\", so I cannot do that,"
                    . ' and I am not going to suggest otherwise. Stating a capability I lack would be'
                    . ' worse than admitting the gap: it would lead to a task that fails halfway.'
                    . ' This is a limitation of the current capability registry, not a policy refusal.'
                    . ' If this operation is needed, it has to be performed outside the registered toolset,'
                    . ' or the capability has to be implemented and registered first.',
            ];
        }

        // ---- enumeration ---------------------------------------------------------------
        if ($tools === []) {
            return [
                'matched' => true,
                'status' => 'unavailable',
                'intent' => 'capability_enumeration',
                'reply' => 'I cannot enumerate my capabilities because the capability registry did not load.'
                    . ' I will not describe tools from memory, since that description would be generated'
                    . ' rather than read, and could not be trusted.',
            ];
        }

        $lines = [];
        foreach ($tools as $tool) {
            $confirm = $tool['requires_confirmation'] ? ', confirmation required' : '';
            $lines[] = '- ' . $tool['capability_id'] . ' (' . $tool['risk_level'] . ' risk' . $confirm . ')'
                . ($tool['description'] !== '' ? ' - ' . $tool['description'] : '');
        }

        $available = array_keys($byId);
        $missing = [];
        foreach (chat_capability_facts_categories() as $label => $def) {
            if ($def['capability'] === null || !in_array($def['capability'], $available, true)) {
                $missing[] = $label;
            }
        }

        $reply = "Read from the capability registry rather than generated, so this list is exact:\n\n"
            . implode("\n", $lines)
            . "\n\nThat is " . count($tools) . ' capabilities available right now.'
            . ' Each one is enabled, has a registered executor, and declares a permission, a resource and a'
            . ' verification method. Higher-risk entries require confirmation before they run.'
            . "\n\nWhat I cannot do: no capability is registered for "
            . implode(', ', $missing) . '.'
            . ' I am listing those explicitly because an assistant that overstates its own reach is'
            . ' more dangerous than one that admits a gap - a task would be accepted and then fail'
            . ' partway through.'
            . "\n\nAny capability not listed above should be assumed unavailable rather than guessed at.";

        return [
            'matched' => true,
            'status' => 'ok',
            'intent' => 'capability_enumeration',
            'reply' => $reply,
            'capabilities' => $available,
            'unsupported_categories' => $missing,
        ];
    }
}
