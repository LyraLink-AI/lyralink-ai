<?php
/**
 * Wiring for claim-level content verification.
 *
 * Applied as a post-pass over the claims produced by chat_claim_provenance_summary()
 * so that function's existing sentence classification stays untouched. For any
 * sentence that was recorded as externally sourced or tool observed, the CONTENT is
 * now checked against the evidence actually gathered. A sentence that merely
 * mentions a source but whose specifics are absent from - or contradicted by - that
 * evidence loses its verified flag and its evidence level.
 *
 * Only sentences that already claimed external provenance are reconsidered, so
 * ordinary prose is never penalised.
 */

if (!function_exists('chat_claim_evidence_texts')) {
    /**
     * Flatten the evidence actually gathered this turn into one searchable string.
     *
     * Walks the context recursively but bounded in depth and size, and skips keys
     * that hold credentials so secrets cannot leak into a verification detail.
     */
    function chat_claim_evidence_texts(array $context = []): string {
        $parts = [];
        $skipKeys = ['password', 'pass', 'token', 'secret', 'api_key', 'apikey', 'authorization', 'cookie', 'session'];

        $harvest = static function ($value, int $depth = 0) use (&$parts, &$harvest, $skipKeys): void {
            if ($depth > 4) {
                return;
            }
            if (is_string($value)) {
                $trimmed = trim($value);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
                return;
            }
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $skipKeys, true)) {
                    continue;
                }
                $harvest($item, $depth + 1);
            }
        };

        foreach (['webSearchResults', 'execution_records', 'toolResults', 'tool_results', 'evidence', 'sources', 'datasetMatches'] as $key) {
            if (isset($context[$key])) {
                $harvest($context[$key]);
            }
        }

        $joined = implode("\n", array_slice($parts, 0, 400));
        return substr($joined, 0, 200000);
    }
}

if (!function_exists('chat_claim_provenance_annotate')) {
    /**
     * Post-pass over claim records: content-check every externally sourced claim.
     *
     * Adds 'content_support' to each claim. On a failing check the claim loses
     * 'verified', its evidence level is recomputed as unverified, and the original
     * provenance is preserved in 'provenance_before_content_check' so the reason for
     * the downgrade remains auditable.
     */
    function chat_claim_provenance_annotate(array $claims, array $context = []): array {
        if ($claims === []) {
            return $claims;
        }

        $evidence = chat_claim_evidence_texts($context);
        if ($evidence === '') {
            // Nothing was gathered, so there is no basis to re-check either way.
            foreach ($claims as $index => $claim) {
                $claims[$index]['content_support'] = [
                    'status' => 'not_applicable',
                    'detail' => 'no_evidence_gathered',
                    'ratio' => 0.0,
                    'atoms' => [],
                    'unsupported_atoms' => [],
                ];
            }
            return $claims;
        }

        $checkable = ['WEB_SOURCE', 'WEB_RETRIEVED', 'TOOL_OBSERVED', 'TOOL_RESULT'];

        foreach ($claims as $index => $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $text = (string)($claim['claim'] ?? '');
            if ($text === '') {
                continue;
            }

            $check = chat_claim_content_support($text, $evidence);
            $claims[$index]['content_support'] = $check;

            $provenance = strtoupper((string)($claim['provenance'] ?? ''));
            $claimedExternal = in_array($provenance, $checkable, true);

            if (!$claimedExternal) {
                /*
                 * The sentence never claimed a source, but it is still a factual
                 * assertion made in a turn where evidence was retrieved - and a bare
                 * no-keyword claim was previously never checked at all, so a false one
                 * was delivered as a plain statement of fact. Only a CONTRADICTION acts
                 * here: absence from the evidence is not proof of falsehood for model
                 * knowledge, so an unsupported verdict remains metadata-only.
                 */
                if ($check['status'] !== 'contradicted') {
                    continue;
                }
                $claims[$index]['verified'] = false;
                $claims[$index]['provenance_before_content_check'] = (string)($claim['provenance'] ?? '');
                $claims[$index]['provenance'] = 'CONTRADICTED_BY_EVIDENCE';
                $claims[$index]['downgrade_reason'] = (string)($check['detail'] ?? '');
                $claims[$index]['downgrade_source'] = 'unsourced_claim_content_check';
                if (function_exists('chat_evidence_level_for_provenance')) {
                    $claims[$index]['evidence_level'] = chat_evidence_level_for_provenance('CONTRADICTED_BY_EVIDENCE', false);
                }
                continue;
            }

            if (empty($claim['verified'])) {
                continue;
            }
            if (!in_array($check['status'], ['contradicted', 'unsupported'], true)) {
                continue;
            }

            $claims[$index]['verified'] = false;
            $claims[$index]['verified_before_content_check'] = true;
            $claims[$index]['provenance_before_content_check'] = (string)($claim['provenance'] ?? '');
            $claims[$index]['provenance'] = ($check['status'] === 'contradicted')
                ? 'CONTRADICTED_BY_EVIDENCE'
                : 'UNSUPPORTED_BY_EVIDENCE';
            $claims[$index]['downgrade_reason'] = (string)($check['detail'] ?? '');

            if (function_exists('chat_evidence_level_for_provenance')) {
                $claims[$index]['evidence_level'] = chat_evidence_level_for_provenance(
                    (string)$claims[$index]['provenance'],
                    false
                );
            }
        }

        return $claims;
    }
}


if (!function_exists('chat_claim_repair_record')) {
    /**
     * Per-request store for the contradiction-repair record.
     *
     * Needed because $verificationSummary is REASSIGNED by later repair and
     * regeneration branches ("$verificationSummary = $repairVerification;"), which
     * silently dropped the record from the response: the reply was corrected live but
     * the response carried no evidence that a correction had happened. A static keeps
     * it alive independent of that variable's lifetime.
     */
    function chat_claim_repair_record($record = null) {
        static $stored = null;
        if ($record !== null) {
            $stored = $record;
        }
        return $stored;
    }
}

if (!function_exists('chat_claim_repair_attach_record')) {
    /**
     * Re-attach the repair record to a verification summary.
     * Idempotent, and safe to call inline inside an array literal.
     */
    function chat_claim_repair_attach_record($verificationSummary) {
        if (!is_array($verificationSummary)) {
            return $verificationSummary;
        }
        if (!empty($verificationSummary['contradiction_repair'])) {
            return $verificationSummary;
        }
        $stored = chat_claim_repair_record();
        if (is_array($stored) && !empty($stored['applied'])) {
            $verificationSummary['contradiction_repair'] = $stored;
        }
        return $verificationSummary;
    }
}

if (!function_exists('chat_repair_contradicted_claims')) {
    /**
     * Act on a contradiction instead of only recording it.
     *
     * Before this existed the pipeline detected that the retrieved evidence
     * contradicted a claim, withdrew the claim's verified flag, and then delivered
     * the claim to the user anyway. Measured on 2026-09-21: the reply said
     * "The latest supported major version of PostgreSQL is 14", the evidence said 18,
     * and the response carried CONTRADICTED_BY_EVIDENCE while the user still read
     * "14". Detection without repair is not verification.
     *
     * The false sentence is withdrawn and replaced with a correction that states the
     * value the evidence actually supports plus the sentence it came from, so the
     * user can check it. Nothing is invented: the correction is assembled from the
     * atoms and sentence the evidence check already extracted.
     *
     * Only active contradictions are repaired by default. Claims that are merely
     * unsupported are left in place because they are not necessarily false, and
     * rewriting them risks damaging a correct answer; metadata still flags them. Set
     * LYRALINK_REPAIR_UNSUPPORTED_CLAIMS=1 to repair those too, and
     * LYRALINK_REPAIR_CONTRADICTED_CLAIMS=0 to disable this entirely.
     */
    /**
     * Is this extracted value safe to quote back to the user?
     *
     * The atoms come from an automated downgrade reason, so they can contain
     * fragments rather than values. Measured 2026-09-21: T093 quoted
     * "Aug 25, 25, 2026" - a malformed date - inside a correction that read as
     * if the system had compared the claim against a source. A correction is
     * the one place that must not introduce a new false specific.
     */
    function chat_correction_atoms_are_usable(string $atoms): bool {
        $t = trim($atoms);
        if ($t === '' || strlen($t) < 2) {
            return false;
        }
        // Punctuation or whitespace only: nothing to quote.
        if (preg_match('/^[^a-z0-9]+$/i', $t) === 1) {
            return false;
        }
        // A month name followed by three numeric components is a malformed
        // date, not a value.
        if (preg_match('/\b[A-Z][a-z]{2,8}\s+\d{1,2}\s*,\s*\d{1,2}\s*,\s*\d{4}\b/', $t) === 1) {
            return false;
        }
        if (preg_match('/^\d{1,2}\s*,\s*\d{1,2}\s*,\s*\d{4}$/', $t) === 1) {
            return false;
        }
        return true;
    }

    function chat_repair_contradicted_claims(string $reply, array $claims, array $opts = []): array {
        $result = ['applied' => false, 'reply' => $reply, 'repairs' => 0, 'replaced' => []];

        if (getenv('LYRALINK_REPAIR_CONTRADICTED_CLAIMS') === '0') {
            return $result;
        }
        if ($reply === '' || $claims === []) {
            return $result;
        }

        $targets = ['CONTRADICTED_BY_EVIDENCE'];
        if (getenv('LYRALINK_REPAIR_UNSUPPORTED_CLAIMS') === '1') {
            $targets[] = 'UNSUPPORTED_BY_EVIDENCE';
        }

        $repaired = $reply;

        foreach ($claims as $claim) {
            if (!is_array($claim)) {
                continue;
            }
            $provenance = strtoupper((string)($claim['provenance'] ?? ''));
            if (!in_array($provenance, $targets, true)) {
                continue;
            }

            $sentence = trim((string)($claim['claim'] ?? ''));
            if ($sentence === '' || strpos($repaired, $sentence) === false) {
                // Cannot locate the sentence verbatim, so do not attempt surgery.
                continue;
            }

            $support = (array)($claim['content_support'] ?? []);
            $atoms = '';
            $reason = (string)($claim['downgrade_reason'] ?? '');
            $colon = strpos($reason, ':');
            if ($colon !== false) {
                $atoms = trim(substr($reason, $colon + 1));
            }
            if ($atoms === '') {
                $atoms = implode(', ', array_filter(array_map(
                    static fn($atom) => (string)($atom['value'] ?? ''),
                    array_slice((array)($support['unsupported_atoms'] ?? []), 0, 5)
                )));
            }
            // Never quote back a fragment that is not a real value.
            if ($atoms !== '' && !chat_correction_atoms_are_usable($atoms)) {
                $atoms = '';
            }

            $evidenceSentence = trim((string)($support['evidence_sentence'] ?? ''));
            if (strlen($evidenceSentence) > 300) {
                $evidenceSentence = substr($evidenceSentence, 0, 297) . '...';
            }

            $hasConcreteBasis = ($atoms !== '' || $evidenceSentence !== '');

            if ($provenance === 'CONTRADICTED_BY_EVIDENCE') {
                if ($hasConcreteBasis) {
                    $correction = '[Correction] The sources retrieved for this request state '
                        . ($atoms !== '' ? $atoms . '. ' : '')
                        . 'The statement above does not match them, so it has been withdrawn '
                        . 'rather than restated.';
                    $correction .= $evidenceSentence !== ''
                        ? ' Retrieved evidence: "' . $evidenceSentence . '"'
                        : ' The retrieved source contained no quotable sentence confirming this.';
                } else {
                    // No usable value and no quotable sentence, so there is nothing to
                    // compare against. Say the claim cannot be verified rather than
                    // asserting a comparison that did not happen. Measured on T093,
                    // 2026-09-21: this path produced "The sources retrieved for this
                    // request state Aug 25, 25, 2026." on a task where no citation had
                    // been supplied at all.
                    $correction = '[Correction] I cannot verify the statement above: no citation or '
                        . 'link was provided for it, and nothing retrieved supports it. It has been '
                        . 'withdrawn rather than restated.';
                }
            } else {
                $correction = '[Unverified] This statement could not be confirmed against the sources '
                    . 'retrieved for this request'
                    . ($atoms === '' ? '' : ' (unsupported specifics: ' . $atoms . ')')
                    . '. Treat it as unverified.';
                if ($evidenceSentence !== '') {
                    $correction .= ' Retrieved evidence: "' . $evidenceSentence . '"';
                }
            }

            $repaired = str_replace($sentence, $correction, $repaired);
            $result['replaced'][] = [
                'withdrawn' => substr($sentence, 0, 160),
                'correction' => substr($correction, 0, 260),
            ];
            $result['corrections'][] = $correction;
            $result['repairs']++;
        }

        if ($result['repairs'] > 0) {
            /*
             * Consolidated correction.
             *
             * When most of the answer is withdrawn the reply is fundamentally wrong, so
             * patching sentence by sentence produced 4 to 6 near-duplicate corrections
             * leading a 900-2400 character reply, every one restating the same disproved
             * fact - observed live. In that case emit ONE correction carrying the
             * evidence and drop the rest, instead of a wall of warnings.
             *
             * The first guard attempt measured LENGTH, which does not work here: the
             * correction text is longer than the sentence it replaces, so the reply never
             * looked short. Counting withdrawn sentences against total sentences is the
             * measure that actually reflects over-correction.
             */
            $withdrawnCount = count($result['replaced']);
            /*
             * Consolidate on a COUNT threshold, not a ratio.
             *
             * A ratio rule was tried first and mis-fired in both directions: with a reply
             * of two sentences it consolidated after a single withdrawal and discarded a
             * correct paragraph, and for a one-sentence reply it consolidated the full
             * replacement, which is the right outcome there. The failure actually observed
             * - three to six near-duplicate corrections from the model restating one
             * disproved fact - is a repeated-restatement problem, so counting withdrawals
             * is the measure that matches it. One or two withdrawals are patched in place.
             */
            $mostlyWithdrawn = ($withdrawnCount >= 3);

            if ($mostlyWithdrawn) {
                $full = (array)($result['corrections'] ?? []);
                $lead = trim((string)($full[0] ?? ''));
                $result['reply'] = ($lead !== '' ? $lead : '[Correction] The retrieved sources do not support this answer.')
                    . ' The remainder of the answer repeated the same unsupported claim, so it has been'
                    . ' withdrawn rather than restated.';
                $result['consolidated'] = true;
                $result['over_correction_guard'] = true;
            } else {
                $result['reply'] = $repaired;
            }
        } else {
            $result['reply'] = $repaired;
        }

        $result['applied'] = $result['repairs'] > 0;
        return $result;
    }
}
