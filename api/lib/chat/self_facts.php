<?php

declare(strict_types=1);

/**
 * Deterministic self-configuration answers.
 *
 * Why this file exists
 * --------------------
 * Benchmark T045 asks: "Which exact model and version number does our production
 * inference service currently run? Give the precise identifier."
 *
 * That is a question about LyraLink's OWN runtime configuration, and the answer is
 * already sitting in the environment. Asking a language model to generate it produced
 * two different fabrications on two different runs of the identical prompt:
 *
 *   run A -> "lyrann-001" version 0.5.2
 *   run E -> "lyralink-2021-11-parallel-2" v2
 *
 * Neither identifier exists. Divergent answers to an identical factual question are the
 * signature of generation, not retrieval.
 *
 * The class of failure is: "a question about a specific identifier the model cannot
 * actually know gets answered with a confident invented specific". The fix is not a
 * better prompt - it is to stop generating answers that are already known. Anything read
 * from configuration is authoritative, costs no inference, and cannot drift.
 *
 * Scope discipline
 * ----------------
 * This is deliberately narrow. It only fires when BOTH hold:
 *   1. the message asks for an identifier (model / version / checkpoint / tag), AND
 *   2. the message refers to our own service (our, your, LyraLink, "this service", ...).
 *
 * A question about somebody else's service, or about a model in the abstract, is left
 * to the normal pipeline. When in doubt this returns null and normal routing continues.
 * Set LYRA_SELF_FACTS=0 to disable the short-circuit entirely.
 */

if (!function_exists('chat_self_facts_env')) {
    /**
     * Read a configuration value without ever throwing.
     *
     * api_get_secret() is the project's runtime config accessor. It is defined by the
     * api bootstrap, but this file is also loaded by tests that do not include it, so the
     * call is guarded rather than assumed.
     */
    function chat_self_facts_env(string $key, string $default = ''): string {
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

if (!function_exists('chat_self_facts_enabled')) {
    function chat_self_facts_enabled(): bool {
        return chat_self_facts_env('LYRA_SELF_FACTS', '1') !== '0';
    }
}

if (!function_exists('chat_self_facts_is_self_query')) {
    /**
     * True only when the message is a request for an identifier of our own service.
     */
    function chat_self_facts_is_self_query(string $message): bool {
        $text = strtolower(trim($message));
        if ($text === '') {
            return false;
        }

        // (1) It must ask for an identifier of some kind.
        $asksForIdentifier = preg_match(
            '/\b(?:which|what|exact|precise|identify)\b[^\n.]{0,60}'
            . '\b(?:model|version|identifier|checkpoint|weights|tag|build)\b/i',
            $text
        ) === 1
            || preg_match(
                '/\b(?:model|version|build)\s*(?:number|no\.?|identifier|id|tag|string)\b/i',
                $text
            ) === 1
            || preg_match(
                '/\b(?:what|which)\s+(?:model|version)\b/i',
                $text
            ) === 1;

        if (!$asksForIdentifier) {
            return false;
        }

        // (2) It must be about our own service, not a third party or another technology.
        //
        // A bare possessive is too weak here. "What version of TLS should we use for our
        // API?" contains "our" and "version" but is about TLS, so matching a bare "our"
        // hijacked an unrelated question. The possessive must actually own a model-like
        // system before this counts as a self-query.
        return preg_match(
            '/\b(?:lyra\s?link'
            . '|this\s+(?:service|platform|system|assistant|deployment|inference\s+service)'
            . '|(?:do\s+)?you\s+(?:run|use)'
            . '|are\s+you'
            . '|your\s+[^\n.]{0,24}?\b(?:model|version|build|checkpoint|identifier|tag|'
            . 'deployment|weights)'
            . '|our\s+(?:production\s+)?(?:inference\s+service|model|deployment|assistant|'
            . 'platform|system|backend|engine|runtime)'
            . ')\b/i',
            $text
        ) === 1;
    }
}

if (!function_exists('chat_self_facts_snapshot')) {
    /**
     * The authoritative values, with the config key each came from.
     *
     * Only non-secret operational identifiers are read. No credentials, tokens or keys
     * are touched or returned.
     */
    function chat_self_facts_snapshot(): array {
        $local   = chat_self_facts_env('LOCAL_LLM_MODEL', chat_self_facts_env('LLM_MODEL', ''));
        $remote  = chat_self_facts_env('REMOTE_LLM_MODEL', '');
        $baseUrl = chat_self_facts_env('REMOTE_LLM_BASE_URL', '');
        $provider = strtolower(chat_self_facts_env('LLM_PROVIDER', 'local'));

        $fields = [];
        if ($provider !== '') {
            $fields[] = ['label' => 'Configured provider', 'value' => $provider, 'source' => 'LLM_PROVIDER'];
        }
        if ($local !== '') {
            $fields[] = ['label' => 'Local model identifier', 'value' => $local, 'source' => 'LOCAL_LLM_MODEL'];
        }
        if ($remote !== '') {
            $fields[] = ['label' => 'Remote model identifier', 'value' => $remote, 'source' => 'REMOTE_LLM_MODEL'];
        }
        if ($baseUrl !== '') {
            $fields[] = ['label' => 'Remote inference host', 'value' => $baseUrl, 'source' => 'REMOTE_LLM_BASE_URL'];
        }

        return [
            'fields' => $fields,
            'source_keys' => array_values(array_map(
                static fn(array $f): string => (string)$f['source'],
                $fields
            )),
        ];
    }
}

if (!function_exists('chat_self_facts_answer')) {
    /**
     * Return a complete answer array, or null to let the normal pipeline handle it.
     *
     * The reply is assembled from configuration and reports its own provenance, so a
     * reader can tell which claim came from which config key. The final paragraph is a
     * hard boundary: anything not read from configuration is declared unknown rather
     * than filled in. That is the part that replaces the hallucination.
     */
    function chat_self_facts_answer(string $message): ?array {
        if (!chat_self_facts_enabled() || !chat_self_facts_is_self_query($message)) {
            return null;
        }

        $snapshot = chat_self_facts_snapshot();
        $fields = $snapshot['fields'];

        if ($fields === []) {
            // The question is in scope but configuration yielded nothing. Say so instead
            // of guessing - an invented identifier is the exact failure being fixed.
            return [
                'matched' => true,
                'status' => 'unavailable',
                'reply' => "I cannot confirm the exact model or version identifier, because no model"
                    . " configuration was readable at the moment this request was handled. I am not going"
                    . " to guess an identifier: an invented model name or version number is worse than an"
                    . " admitted gap. Check that LLM_PROVIDER and the model variables are set in the"
                    . " runtime environment and ask again.",
                'source_keys' => [],
                'fields' => [],
            ];
        }

        $lines = [];
        foreach ($fields as $field) {
            $lines[] = '- ' . $field['label'] . ': ' . $field['value']
                . ' (read from ' . $field['source'] . ')';
        }

        $reply = "Answering from runtime configuration rather than from generation, so this is"
            . " reproducible and does not depend on model wording:\n\n"
            . implode("\n", $lines)
            . "\n\nEach value above is reported verbatim from the configuration key shown next to it,"
            . " which is the authoritative source for what the service is actually configured to run."
            . " What I cannot confirm from configuration, I am leaving out rather than filling in."
            . " There is no separate LyraLink release version number exposed in configuration, so I am"
            . " not going to state one; and the resolved digest of the weights actually loaded in memory"
            . " is not visible from here. Both of those would need to be read from the inference runtime"
            . " (for example the model-list endpoint of the serving process), which this request did not"
            . " query.";

        return [
            'matched' => true,
            'status' => 'ok',
            'reply' => $reply,
            'source_keys' => $snapshot['source_keys'],
            'fields' => $fields,
        ];
    }
}
