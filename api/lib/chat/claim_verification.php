<?php
/**
 * Claim-level content verification.
 *
 * The existing chat_claim_provenance_summary() classifies a sentence by whether it
 * CONTAINS a source-like phrase ("according to", "source", "official
 * documentation"). If any web result was fetched, the sentence is marked
 * WEB_SOURCE / verified and receives E4 (TOOL_VERIFIED). Measured consequence on
 * 2026-09-21: the claim "PostgreSQL 15, January 12, 2022 ... according to the
 * official PostgreSQL website" was recorded as verified against evidence that
 * actually said PostgreSQL 18 (released 2025-09-25). Mentioning a source is not
 * evidence that the claim's content came from it.
 *
 * This module checks the CONTENT of a claim against the evidence actually
 * gathered, using deterministic extraction only:
 *
 *   atoms      - dates, years, versions, percentages, numbers, domains and quoted
 *                strings extracted from the claim, then looked up in the evidence.
 *   relations  - superlative/temporal assertions ("latest", "current", "as of",
 *                "released on") which atom presence alone cannot substantiate.
 *   conflict   - the case that matters most: the evidence asserts a DIFFERENT
 *                version or date as the superlative than the claim does. That is a
 *                contradiction, not merely a gap, and is reported as such.
 *
 * It is deliberately not an attempt at general semantic verification. It catches
 * fabricated specifics and contradicted specifics, and returns "not_applicable"
 * for ordinary prose so non-factual sentences are never penalised.
 */

if (!function_exists('chat_claim_normalise')) {
    /** Lowercase, collapse whitespace, keep characters that carry meaning in atoms. */
    function chat_claim_normalise(string $text): string {
        $text = strtolower($text);
        $text = (string)preg_replace('/[^a-z0-9.%\-\/\s]/', ' ', $text);
        return trim((string)preg_replace('/\s+/', ' ', $text));
    }
}

if (!function_exists('chat_claim_extract_atoms')) {
    /**
     * Extract checkable factual atoms from a claim.
     * Returns a list of ['value' => string, 'type' => string].
     */
    function chat_claim_extract_atoms(string $text): array {
        $atoms = [];
        $add = static function (string $value, string $type) use (&$atoms): void {
            $value = trim($value);
            if ($value === '') {
                return;
            }
            $atoms[$type . '|' . strtolower($value)] = ['value' => $value, 'type' => $type];
        };

        if (preg_match_all('/\b\d{4}-\d{2}-\d{2}\b/', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'date'); }
        }
        if (preg_match_all(
            '/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)'
            . '\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}\b/i',
            $text,
            $m
        ) > 0) {
            foreach ($m[0] as $v) { $add($v, 'date'); }
        }
        if (preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'year'); }
        }
        /*
         * Trailing-dot handling matters.
         *
         * These patterns used to end with (?![0-9.]) / (?![-?]), which rejected a
         * version or number followed by a sentence-final period. Measured: the regex
         * found NOTHING in "The latest version is 18.6." - a completely ordinary
         * sentence - so the atom simply vanished and the claim could not be checked.
         * The lookaheads are now split so a terminating period is allowed while a real
         * continuation (18.6.1, 5, 1.5) is still consumed correctly.
         */
        if (preg_match_all('/(?<![a-z0-9])v?\d+\.\d+(?:\.\d+)*(?!\d)(?!\.\d)/i', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'version'); }
        }
        if (preg_match_all('/(?<![0-9.])\d+(?:\.\d+)?\s?%/', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'percent'); }
        }
        if (preg_match_all('/(?<![\d.])\d+(?:\.\d+)?(?!\d)(?!\.\d)/', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'number'); }
        }
        if (preg_match_all('/\b[a-z0-9][a-z0-9.\-]*\.(?:com|org|net|io|dev|gov|edu|ai|co|uk|de|fr)\b/i', $text, $m) > 0) {
            foreach ($m[0] as $v) { $add($v, 'domain'); }
        }

        return array_values($atoms);
    }
}

if (!function_exists('chat_claim_atom_present')) {
    /**
     * Is this atom present in normalised evidence text?
     *
     * Numeric atoms use digit/dot lookarounds so "15" does not match inside
     * "16.15" - a plain substring test would wrongly report support.
     */
    function chat_claim_atom_present(string $atom, string $normalisedEvidence): bool {
        $needle = chat_claim_normalise($atom);
        if ($needle === '' || $normalisedEvidence === '') {
            return false;
        }
        if (preg_match('/^\d+(?:\.\d+)?%?$/', $needle) === 1) {
            $bare = rtrim($needle, '%');
            return preg_match('/(?<![\d.])' . preg_quote($bare, '/') . '(?![\d.])/', $normalisedEvidence) === 1;
        }
        return strpos($normalisedEvidence, $needle) !== false;
    }
}

if (!function_exists('chat_claim_version_matches')) {
    /**
     * Version-aware matching.
     *
     * A claim of major version "18" is supported by evidence "18.6", and a claim of
     * "18.6" by evidence "18.6". This keeps correct answers from being penalised
     * while the relation/conflict checks below handle the case where the evidence
     * names a DIFFERENT version as current.
     */
    function chat_claim_version_matches(string $atom, string $normalisedEvidence): bool {
        $needle = chat_claim_normalise($atom);
        if ($needle === '' || $normalisedEvidence === '') {
            return false;
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?/', $needle, $m) !== 1) {
            return false;
        }
        $major = (string)$m[1];
        $minor = isset($m[2]) ? (string)$m[2] : '';

        if ($minor === '') {
            // Bare major: evidence must contain major.x somewhere.
            return preg_match('/(?<![\d.])' . preg_quote($major, '/') . '\.\d/', $normalisedEvidence) === 1;
        }
        return preg_match(
            '/(?<![\d.])' . preg_quote($major . '.' . $minor, '/') . '(?![\d.])/',
            $normalisedEvidence
        ) === 1;
    }
}

if (!function_exists('chat_claim_relations')) {
    /** Which relational assertions does the claim make, if any? */
    function chat_claim_relations(string $claim): array {
        $found = [];
        if (preg_match('/\b(latest|newest|most\s+recent|current|upcoming|next|first|only|last)\b/i', $claim) === 1) {
            $found[] = 'superlative';
        }
        if (preg_match('/\b(as\s+of|released\s+(?:on|in)|announced\s+(?:on|in)|published\s+(?:on|in)|since)\b/i', $claim) === 1) {
            $found[] = 'temporal';
        }
        return $found;
    }
}

if (!function_exists('chat_claim_evidence_sentences')) {
    /** Split evidence text into sentences for relation-localised checks. */
    function chat_claim_evidence_sentences(string $evidenceText): array {
        $parts = (array)preg_split('/(?<=[.!?])\s+|\n+/', $evidenceText);
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }
}

if (!function_exists('chat_claim_relation_conflict')) {
    /**
     * Does the evidence assert a DIFFERENT version/number/date as the superlative?
     *
     * This is the check that catches the observed failure. The evidence says
     * "PostgreSQL 18 Released! ... the latest version"; the claim says 15 is the
     * latest. Atom presence alone would not catch it (15 appears in the evidence as
     * an older release), but the evidence's superlative sentence names 18, which the
     * claim never mentions. That is a contradiction.
     *
     * Returns ['conflict' => bool, 'evidence_atoms' => array, 'sentence' => string].
     */
    function chat_claim_relation_conflict(array $claimAtoms, string $evidenceText): array {
        $relationWords = ['latest', 'newest', 'most recent', 'current', 'newest version', 'as of'];
        $claimValues = [];
        foreach ($claimAtoms as $atom) {
            $value = chat_claim_normalise((string)($atom['value'] ?? ''));
            if ($value !== '') {
                $claimValues[$value] = true;
            }
        }

        $candidates = [];
        foreach (chat_claim_evidence_sentences($evidenceText) as $sentence) {
            $norm = chat_claim_normalise($sentence);
            if ($norm === '') {
                continue;
            }
            $hasRelation = false;
            foreach ($relationWords as $word) {
                if (strpos($norm, $word) !== false) {
                    $hasRelation = true;
                    break;
                }
            }
            if (!$hasRelation) {
                continue;
            }

            /*
             * Evidence tokens, split into STRONG and weak.
             *
             * Strong: a dotted version (18.6) or a number tied to a product name or the
             * word version/release ("PostgreSQL 18"). Only these may justify a
             * contradiction.
             *
             * Weak: a bare year or bare number. Observed live, treating a bare year as a
             * version assertion made the page title "Postgresql Release Notes - August
             * 2026 Latest Updates - Releasebot" contradict a correct answer, because the
             * title happens to contain the word "Latest" and the number 2026. Weak tokens
             * remain checkable for SUPPORT; they simply cannot trigger a conflict.
             */
            $strongAtoms = [];
            $weakAtoms = [];
            if (preg_match_all('/(?<![a-z0-9])v?\d+\.\d+(?:\.\d+)*(?!\d)(?!\.\d)/i', $sentence, $m) > 0) {
                foreach ($m[0] as $v) {
                    $strongAtoms[strtolower(trim($v))] = true;
                }
            }
            if (preg_match_all('/(?<!\d)(?:19|20)\d{2}(?!\d)/', $sentence, $m) > 0) {
                foreach ($m[0] as $v) {
                    $weakAtoms[strtolower(trim($v))] = true;
                }
            }

            /*
             * Entity + number pairs, e.g. "PostgreSQL 18" or "version 18".
             *
             * Without this the conflict was detected correctly but the correction only
             * reported the year ("state 2025"), which tells the user almost nothing about
             * what actually changed. Naming the product version is the difference between
             * a usable correction and a vague one. A unit test caught the weak output.
             */
            $skipWords = ['the', 'a', 'an', 'on', 'in', 'of', 'and', 'or', 'for', 'to', 'as',
                'released', 'version', 'beta', 'since', 'from', 'by', 'is', 'was', 'at', 'with'];
            $monthNames = ['january', 'february', 'march', 'april', 'may', 'june', 'july',
                'august', 'september', 'october', 'november', 'december'];
            if (preg_match_all('/\b([A-Za-z][A-Za-z0-9._+-]{1,30})\s+v?(\d{1,3})(?![\d.])/', $sentence, $m) > 0) {
                foreach ($m[1] as $i => $word) {
                    $wordLower = strtolower((string)$word);
                    if (in_array($wordLower, $skipWords, true) || in_array($wordLower, $monthNames, true)) {
                        continue;
                    }
                    // Descriptive pair, original casing preserved for the correction text.
                    $strongAtoms[(string)$word . ' ' . (string)$m[2][$i]] = true;
                    /*
                     * Also record the bare number. The pair is for describing the
                     * disagreement; the bare number is what an AGREEING claim has to be
                     * able to match. Without it, a correct claim of "18" would be reported
                     * as contradicting evidence that says "PostgreSQL 18" - a false
                     * positive that would withdraw a right answer.
                     */
                    $strongAtoms[(string)$m[2][$i]] = true;
                }
            }

            // A bare year or bare number on its own is not an assertion about versions.
            if ($strongAtoms === []) {
                continue;
            }
            $evidenceAtoms = $strongAtoms + $weakAtoms;


            $matchesClaim = false;
            foreach (array_keys($evidenceAtoms) as $evidenceValue) {
                if (isset($claimValues[$evidenceValue])) {
                    $matchesClaim = true;
                    break;
                }
                // Compare by major version too: claim "18" vs evidence "18.6".
                if (preg_match('/^(\d+)/', (string)$evidenceValue, $em) === 1) {
                    foreach (array_keys($claimValues) as $claimValue) {
                        if (preg_match('/^v?(\d+)/', (string)$claimValue, $cm) === 1 && $cm[1] === $em[1]) {
                            $matchesClaim = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$matchesClaim) {
                $candidates[] = [
                    'evidence_atoms' => array_keys($evidenceAtoms),
                    'sentence' => $sentence,
                ];
            }
        }

        if ($candidates === []) {
            return ['conflict' => false, 'evidence_atoms' => [], 'sentence' => ''];
        }

        /*
         * Choose which contradicting sentence to quote.
         *
         * The first candidate used to be returned verbatim. On a real fetched page that
         * produced a correction quoting the site's navigation menu -
         * "Support Deadlines API Switch Theme Containers & Orchestration Docker Engine
         * Kubernetes ..." - instead of the sentence carrying the fact. Fetched bodies are
         * full of boilerplate, so prefer the SHORTEST plausible sentence and reject
         * anything that looks like a nav dump. Quoting noise defeats the point of
         * quoting evidence.
         */
        $best = null;
        foreach ($candidates as $candidate) {
            $candidateSentence = trim((string)$candidate['sentence']);
            $wordCount = count((array)preg_split('/\s+/', $candidateSentence));
            if ($wordCount > 40 || strlen($candidateSentence) > 260) {
                continue;
            }
            if ($best === null || strlen($candidateSentence) < strlen(trim((string)$best['sentence']))) {
                $best = $candidate;
            }
        }
        if ($best === null) {
            // Everything looked like boilerplate: report the conflict, quote nothing.
            $best = ['evidence_atoms' => (array)$candidates[0]['evidence_atoms'], 'sentence' => ''];
        }

        return [
            'conflict' => true,
            'evidence_atoms' => (array)$best['evidence_atoms'],
            'sentence' => substr(trim((string)$best['sentence']), 0, 240),
        ];
    }
}

if (!function_exists('chat_claim_content_support')) {
    /**
     * Check one claim against gathered evidence text.
     *
     * Returns [
     *   'status' => supported | unsupported | contradicted | not_applicable,
     *   'atoms' => [...], 'unsupported_atoms' => [...],
     *   'ratio' => float, 'relations' => [...], 'detail' => string
     * ]
     */
    function chat_claim_content_support(string $claim, string $evidenceText): array {
        $claimAtoms = chat_claim_extract_atoms($claim);
        $relations = chat_claim_relations($claim);
        $normalisedEvidence = chat_claim_normalise($evidenceText);

        // Ordinary prose with no checkable specifics and no relational assertion.
        if ($claimAtoms === [] && $relations === []) {
            return [
                'status' => 'not_applicable', 'atoms' => [], 'unsupported_atoms' => [],
                'ratio' => 1.0, 'relations' => [], 'detail' => 'no_factual_atoms',
            ];
        }

        if ($normalisedEvidence === '') {
            return [
                'status' => 'unsupported', 'atoms' => $claimAtoms, 'unsupported_atoms' => $claimAtoms,
                'ratio' => 0.0, 'relations' => $relations, 'detail' => 'no_evidence_gathered',
            ];
        }

        // Contradiction is checked first: it is stronger than a gap.
        if ($relations !== []) {
            $conflict = chat_claim_relation_conflict($claimAtoms, $evidenceText);
            if (!empty($conflict['conflict'])) {
                return [
                    'status' => 'contradicted',
                    'atoms' => $claimAtoms,
                    'unsupported_atoms' => [],
                    'ratio' => 0.0,
                    'relations' => $relations,
                    'detail' => 'evidence_asserts_other_version_as_latest:'
                        . implode(', ', array_slice((array)$conflict['evidence_atoms'], 0, 4)),
                    'evidence_sentence' => (string)($conflict['sentence'] ?? ''),
                ];
            }
        }

        $supported = 0;
        $unsupported = [];
        foreach ($claimAtoms as $atom) {
            $value = (string)($atom['value'] ?? '');
            $type = (string)($atom['type'] ?? '');
            $present = chat_claim_atom_present($value, $normalisedEvidence);
            if (!$present && ($type === 'version' || $type === 'year' || $type === 'number')) {
                $present = chat_claim_version_matches($value, $normalisedEvidence);
            }
            if ($present) {
                $supported++;
            } else {
                $unsupported[] = ['value' => $value, 'type' => $type];
            }
        }

        /*
         * A relational claim ("X is the latest") with no extractable specific could not
         * be checked at all. It must not be reported as supported merely because there
         * were no atoms to fail on - that was a hole: ratio measured 0 yet the status
         * said supported. Nothing is rewritten for an unsupported claim by default, so
         * this stays metadata-only.
         */
        if ($claimAtoms === [] && $relations !== []) {
            return [
                'status' => 'unsupported',
                'atoms' => [],
                'unsupported_atoms' => [],
                'ratio' => 0.0,
                'relations' => $relations,
                'detail' => 'no_checkable_facts_in_relational_claim',
            ];
        }

        $total = max(1, count($claimAtoms));
        $ratio = $supported / $total;

        $status = 'supported';
        $detail = 'atoms_present';
        if ($unsupported !== [] && $ratio < 0.5) {
            $status = 'unsupported';
            $detail = 'specifics_absent_from_evidence';
        } elseif ($unsupported !== []) {
            $detail = 'partially_supported';
        }

        return [
            'status' => $status,
            'atoms' => $claimAtoms,
            'unsupported_atoms' => $unsupported,
            'ratio' => round($ratio, 3),
            'relations' => $relations,
            'detail' => $detail,
        ];
    }
}
