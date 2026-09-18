<?php

/**
 * Multi-step state tracking validator
 * Ensures that multi-step reasoning correctly accumulates state
 */
function chat_validate_multi_step_state(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    
    // Detect multi-step movement problems
    if (preg_match('/(\d+)\s*km?\s+(east|west|north|south|left|right|up|down).*?(\d+)\s*km?\s+(east|west|north|south|left|right|up|down).*?(\d+)\s*km?\s+(east|west|north|south|left|right|up|down)/i', $msg)) {
        // Extract movements
        $movements = [];
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*km?\s+(east|west|north|south)/i', $msg, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $distance = (float)$match[1];
                $direction = strtolower($match[2]);
                $movements[] = ['distance' => $distance, 'direction' => $direction];
            }
        }
        
        if (count($movements) >= 2) {
            // Calculate expected final position
            $x = 0.0;
            $y = 0.0;
            foreach ($movements as $move) {
                switch($move['direction']) {
                    case 'east': $x += $move['distance']; break;
                    case 'west': $x -= $move['distance']; break;
                    case 'north': $y += $move['distance']; break;
                    case 'south': $y -= $move['distance']; break;
                }
            }
            
            // Calculate expected distance
            $expectedDistance = sqrt($x * $x + $y * $y);
            
            // Check if reply mentions correct distance or retains all steps
            $replyLower = strtolower($reply);
            $distanceFound = false;
            
            // Look for close distance values (within 0.5 km)
            if (preg_match_all('/\d+(?:\.\d+)?/', $reply, $matches)) {
                foreach ($matches[0] as $num) {
                    $numVal = (float)$num;
                    if (abs($numVal - $expectedDistance) < 0.5) {
                        $distanceFound = true;
                        break;
                    }
                }
            }
            
            // Count step retention
            $stepsRetained = count(array_filter($movements, function($move) use ($replyLower) {
                return stripos($replyLower, $move['distance'] . ' km') !== false 
                    || stripos($replyLower, (int)$move['distance'] . ' km') !== false;
            }));
            
            return [
                'pass' => $distanceFound && $stepsRetained >= count($movements),
                'issue' => $distanceFound && $stepsRetained >= count($movements) ? null : 'Multi-step state tracking incomplete or incorrect',
                'details' => [
                    'movements_count' => count($movements),
                    'steps_retained' => $stepsRetained,
                    'distance_found' => $distanceFound,
                    'expected_distance' => round($expectedDistance, 2),
                    'final_x' => round($x, 2),
                    'final_y' => round($y, 2),
                ]
            ];
        }
    }
    
    return ['pass' => true, 'issue' => null, 'details' => ['not_applicable' => true]];
}

/**
 * Quantitative validation
 * Ensures numeric calculations are correct
 */
function chat_validate_quantitative(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    
    $issues = [];
    $checks = [];
    
    // Percentage increase detection
    if (preg_match('/(\d+(?:,\d{3})*)\s*(?:to|→|->|increased to|rose to|went to)\s*(\d+(?:,\d{3})*)/i', $msg, $m)) {
        $from = (float)str_replace(',', '', $m[1]);
        $to = (float)str_replace(',', '', $m[2]);
        $percentIncrease = (($to - $from) / $from) * 100;
        
        // Look for correct percentage in reply
        if (preg_match_all('/(\d+(?:\.\d+)?)\s*%/', $reply, $matches)) {
            $foundCorrect = false;
            foreach ($matches[1] as $pct) {
                if (abs((float)$pct - round($percentIncrease, 1)) < 1.0) {
                    $foundCorrect = true;
                    break;
                }
            }
            $checks[] = ['name' => 'percentage_increase_correct', 'pass' => $foundCorrect];
            if (!$foundCorrect) {
                $issues[] = 'Percentage increase calculation incorrect';
            }
        }
    }
    
    // Percentage points vs relative percentage
    if (preg_match('/(\d+)\s*%\s*(?:to|→|->|increased?|rose|went)\s*(\d+)\s*%/i', $msg, $m)) {
        $from = (float)$m[1];
        $to = (float)$m[2];
        $pointChange = $to - $from;
        $relativeChange = $pointChange !== 0 ? (($pointChange) / $from) * 100 : 0;
        
        // Check for clear distinction in reply
        $replyLower = strtolower($reply);
        $distinguishesProper = (
            (stripos($replyLower, 'percentage point') !== false || stripos($replyLower, 'percentage-point') !== false) &&
            (stripos($replyLower, 'relative') !== false || stripos($replyLower, '%') !== false)
        );
        
        $checks[] = ['name' => 'percentage_point_distinction', 'pass' => $distinguishesProper];
        if (!$distinguishesProper) {
            $issues[] = 'Did not distinguish between percentage-point change and relative percentage change';
        }
    }
    
    // Distance/spatial calculations
    if (preg_match('/(\d+)\s*km.*?(\d+)\s*km.*?distance|how far|distance.*?(\d+)\s*km.*?(\d+)\s*km/i', $msg)) {
        if (preg_match_all('/distance|how far|result.*?(\d+(?:\.\d+)?)/i', $reply)) {
            $checks[] = ['name' => 'spatial_calculation_provided', 'pass' => true];
        }
    }
    
    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'checks' => $checks,
    ];
}

/**
 * Response length controller
 * Adjusts response target length based on request type
 */
function chat_response_length_expectation(string $latestUserMsg, string $requestClass): array {
    $msg = trim($latestUserMsg);
    $msgLen = strlen($msg);
    $lower = strtolower($msg);
    
    $target = 'standard';
    $minChars = 80;
    $maxChars = 800;
    $reasoning = [];
    
    if (in_array($requestClass, ['CASUAL_CONVERSATION', 'SOCIAL_CONVERSATION'], true)) {
        $target = 'minimal';
        $minChars = 20;
        $maxChars = 300;
        $reasoning[] = 'casual_response_short';
    } elseif ($requestClass === 'WRITING') {
        // Check what kind of writing
        if (preg_match('/\b(make.*?sound|rewrite|polish|improve|natural|professional)\b/i', $msg)) {
            $target = 'concise_rewrite';
            $minChars = 30;
            $maxChars = 400;
            $reasoning[] = 'writing_request_concise';
        } elseif (preg_match('/\bgive.*?option|three alternative|option.*?option/i', $msg)) {
            $target = 'multi_option';
            $minChars = 200;
            $maxChars = 1200;
            $reasoning[] = 'user_requested_options';
        } else {
            $target = 'standard_writing';
            $minChars = 60;
            $maxChars = 600;
            $reasoning[] = 'writing_request_general';
        }
    } elseif ($requestClass === 'QUANTITATIVE') {
        $target = 'concise_calc';
        $minChars = 40;
        $maxChars = 400;
        $reasoning[] = 'quantitative_answer_concise';
    } elseif ($requestClass === 'FALSE_PREMISE') {
        $target = 'premise_correction';
        $minChars = 100;
        $maxChars = 600;
        $reasoning[] = 'false_premise_correction_targeted';
    } elseif (in_array($requestClass, ['RESEARCH', 'SOURCE_REQUIRED'], true)) {
        $target = 'evidence_driven';
        $minChars = 150;
        $maxChars = 1000;
        $reasoning[] = 'source_required_structured';
    } elseif ($requestClass === 'PRODUCTION_OPERATIONS') {
        $target = 'incident_hierarchy';
        $minChars = 200;
        $maxChars = 1200;
        $reasoning[] = 'production_safety_sequence';
    } elseif ($requestClass === 'SECURITY') {
        $target = 'security_precise';
        $minChars = 150;
        $maxChars = 900;
        $reasoning[] = 'security_terminology_precise';
    } elseif (in_array($requestClass, ['TOOL_REQUIRED', 'TOOL_UNAVAILABLE'], true)) {
        $target = 'tool_honesty';
        $minChars = 80;
        $maxChars = 600;
        $reasoning[] = 'tool_availability_honest';
    }
    
    // User-supplied length hints
    if (preg_match('/\b(brief|short|concise|quick|one sentence|one line|tldr)\b/i', $msg)) {
        $maxChars = min($maxChars, 300);
        $reasoning[] = 'user_requested_brevity';
    } elseif (preg_match('/\b(detailed|comprehensive|thorough|explain everything|full|deep dive)\b/i', $msg)) {
        $minChars = max($minChars, 400);
        $reasoning[] = 'user_requested_detail';
    }
    
    return [
        'target' => $target,
        'min_chars' => $minChars,
        'max_chars' => $maxChars,
        'reasoning' => $reasoning,
    ];
}

/**
 * Production incident hierarchy validator
 * Ensures safe ordering of incident response actions
 */
function chat_validate_production_safety_order(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    
    // Check if this is a production incident
    $isIncident = preg_match('/\b(incident|outage|down|failed|failure|502|timeout|latency|queue.*back|partial.*migrat)\b/i', $msg) === 1;
    
    if (!$isIncident) {
        return ['pass' => true, 'issues' => [], 'scope' => 'not_incident'];
    }
    
    $replyLower = strtolower($reply);

    $issues = [];

    $riskyChangePatterns = [
        '/\b(upgrade(?:\s+all|\s+every)?\s+dependenc(?:y|ies)|update\s+all\s+packages|update\s+dependencies|bump\s+packages|mass\s+upgrade)\b/i',
        '/\b(restart\s+everything|redeploy\s+everything|full\s+redeploy|rewrite|refactor\s+code|change\s+architecture)\b/i',
        '/\b(rollback\s+database|delete\s+data|drop\s+table|restore\s+backup)\b/i',
    ];

    $stabilizeFirstPatterns = [
        '/\b(contain|stop|disable|isolate|stop\s+the\s+bleed|stabilize|freeze\s+changes?)\b/i',
        '/\b(preserve|capture|collect\s+(?:logs?|traces?|metrics?)|snapshot|forensic)\b/i',
        '/\b(assess|check|identify|scope\s+impact|blast\s+radius)\b/i',
        '/\b(inspect|determine\s+(?:current\s+)?state|current\s+state|which\s+version\s+is\s+applied)\b/i',
    ];

    $hasUnsafeAction = false;
    foreach ($riskyChangePatterns as $pattern) {
        if (preg_match($pattern, $replyLower) === 1) {
            $hasUnsafeAction = true;
            break;
        }
    }

    $hasSafeAction = false;
    foreach ($stabilizeFirstPatterns as $pattern) {
        if (preg_match($pattern, $replyLower) === 1) {
            $hasSafeAction = true;
            break;
        }
    }

    $firstMatchPos = static function (string $text, array $patterns): ?int {
        $best = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int)($m[0][1] ?? -1);
                if ($pos >= 0 && ($best === null || $pos < $best)) {
                    $best = $pos;
                }
            }
        }
        return $best;
    };

    $firstRiskyPos = $firstMatchPos($replyLower, $riskyChangePatterns);
    $firstSafePos = $firstMatchPos($replyLower, $stabilizeFirstPatterns);

    if ($hasUnsafeAction && !$hasSafeAction) {
        $issues[] = 'Production advice recommends unsafe action without containment/assessment first';
    }

    if ($firstRiskyPos !== null && ($firstSafePos === null || $firstRiskyPos < $firstSafePos)) {
        $issues[] = 'Change-introducing action is prioritized before containment, evidence preservation, and state assessment.';
    }

    $isPartialMigrationIncident = preg_match('/\b(partial\s+sql\s+migration|partial\s+migration|partially\s+executed\s+migration|live\s+incident)\b/i', $msg) === 1;
    $mentionsDependencyUpgrade = preg_match('/\b(upgrade\s+dependenc(?:y|ies)|update\s+packages?|bump\s+libraries?)\b/i', $replyLower) === 1;
    $dependencyEvidenceGate = preg_match('/\b(?:only\s+if|only\s+after|after\s+confirming|if\s+evidence\s+shows|if\s+logs\s+show|if\s+metrics\s+show|root\s+cause\s+points\s+to|confirmed\s+incompatibilit)\b[^.]{0,140}\b(?:dependenc(?:y|ies)|package|library)\b/i', $replyLower) === 1;
    if ($isPartialMigrationIncident && $mentionsDependencyUpgrade && !$dependencyEvidenceGate) {
        $issues[] = 'Dependency upgrades are introduced during live partial-migration response without evidence of dependency incompatibility.';
    }

    // Specific bad patterns
    if (preg_match('/\b(partial sql migration|partially executed|active incident)\b/i', $msg) === 1
        && preg_match('/\b(immediate.*rollback|blind rollback|rollback now|rollback everything)\b/i', $replyLower) === 1) {
        $issues[] = 'Recommends rollback for partial migration without state inspection or recovery checks';
    }

    if (preg_match('/\b(incident|outage|production.*fail)\b/i', $msg) === 1
        && preg_match('/\b(upgrade all dependencies now|upgrade every dependency immediately|update all packages)\b/i', $replyLower) === 1) {
        $issues[] = 'Recommends broad dependency upgrade during incident without identifying root cause first';
    }
    
    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'scope' => 'production_incident',
        'has_safe_action' => $hasSafeAction,
        'has_unsafe_action' => $hasUnsafeAction,
    ];
}

/**
 * Tool capability honesty validator
 * Ensures tool unavailable is clearly distinguished from privacy refusal
 */
function chat_validate_tool_honesty(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    $replyLower = strtolower($reply);
    
    // Detect tool requirement signals, including verification and webhook/API checks.
    $toolRequired = preg_match('/\b(query|inspect|check|list|run|execute|access|retrieve|scan|open|verify|confirm|test).*(logs?|database|server|files?|repo|code|tables?|schema|endpoint|webhook|api|credentials|terminal|command|shell)\b/i', $msg) === 1
        || preg_match('/\b(shell|command|terminal|ssh|mysql|psql|api endpoint|webhook|endpoint|database|schema|credential|logs?)\b/i', $msg) === 1;
    
    if (!$toolRequired) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }
    
    $toolUnavailable = preg_match('/\b(?:no|not|without)\b(?:[^.!?]{0,80})\b(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook)\b(?:[^.!?]{0,80})\b(?:provided|available|given|granted)\b|\b(?:no|not)\s*(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook)\b|\b(?:cannot|unable)\s+access\b|\bwithout\s+(?:shell|database|access|connection|credentials)\b/i', $msg) === 1
        || (preg_match('/\b(?:not|no|without)\s+(?:provided|available|given|granted)\b/i', $msg) === 1 && preg_match('/\b(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook)\b/i', $msg) === 1);
    
    if (!$toolUnavailable) {
        return ['pass' => true, 'issues' => [], 'applicable' => true, 'tool_available' => true];
    }
    
    // Tool is unavailable - check reply doesn't confuse this with privacy.
    // This must catch real-world phrasing like "cannot reveal ... for privacy and security reasons."
    $privacyPattern = '/\b(cannot\s+reveal|private\s+data|user\s*data|security\s+reasons?|privacy\s+reasons?|confidential|sensitive|for\s+privacy|for\s+security)\b/i';
    $toolLimitationPattern = '/\b(no\s+.*access|lack\s+.*access|unavailable|cannot\s+.*(?:due\s+to|because\s+of|without).*(?:access|provided|given)|without\s+(?:access|shell|connection)|not\s+(?:provided|available|given)|no\s+(?:database|repo|connection|shell|access)|not\s+given\s+(?:database|shell|access))\b/i';
    
    $usesPrivacyLanguage = preg_match($privacyPattern, $replyLower) === 1;
    $usesToolLanguage = preg_match($toolLimitationPattern, $replyLower) === 1;
    
    $issues = [];
    
    // If tool-unavailable but reply uses privacy language exclusively, that's wrong
    if ($usesPrivacyLanguage && !$usesToolLanguage) {
        $issues[] = 'Confuses tool unavailability with privacy refusal';
    }
    
    // If tool-unavailable and doesn't mention limitation at all, check for mitigating language
    if (!$usesToolLanguage && !$usesPrivacyLanguage) {
        $mitigatingLanguage = preg_match('/\b(still|can help|guidance|recommend|instead|workaround|alternatively|without.*access)\b/i', $replyLower) === 1;
        if (!$mitigatingLanguage) {
            $issues[] = 'Does not acknowledge tool limitation';
        }
    }
    
    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'applicable' => true,
        'tool_available' => false,
        'uses_tool_language' => $usesToolLanguage,
        'uses_privacy_language' => $usesPrivacyLanguage,
    ];
}

/**
 * Web research integration check for SOURCE_REQUIRED
 * Ensures SOURCE_REQUIRED requests attempt web search when available
 */
function chat_validate_source_required_handling(string $latestUserMsg, string $reply, array $webSearchResults = []): array {
    $msg = strtolower(trim($latestUserMsg));
    
    // Detect SOURCE_REQUIRED signals
    $sourceRequired = preg_match('/\b(find.*source|give.*scholarly source|cite|citation|public source|primary source|official|documentation|current|today|latest)\b/i', $msg) === 1;
    
    if (!$sourceRequired) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }
    
    $replyLower = strtolower($reply);
    
    // If web search was performed, check that sources are cited
    if (!empty($webSearchResults)) {
        $citesSource = preg_match('/\b(according to|from.*source|source.*shows|found.*at|reported by|documented|official|link|url|website)\b/i', $replyLower) === 1;
        if (!$citesSource && preg_match('/\d+\s*(found|result|match)/i', $replyLower)) {
            return ['pass' => false, 'issues' => ['Reply states facts from web search without attribution'], 'web_search_available' => true, 'cites_source' => false];
        }
        return ['pass' => true, 'issues' => [], 'applicable' => true, 'web_search_available' => true, 'web_results' => count($webSearchResults)];
    }
    
    // Web search not performed - check reply is honest about capability
    $isHonest = preg_match('/\b(cannot verify|no.*access|not available|cannot.*current|cannot.*source|need.*source|require.*access)\b/i', $replyLower) === 1;
    
    if (!$isHonest && preg_match('/\b(according to|research shows|studies|found that)\b/i', $replyLower)) {
        return ['pass' => false, 'issues' => ['Implies source verification without web search capability'], 'web_search_available' => false];
    }
    
    return ['pass' => $isHonest, 'issues' => $isHonest ? [] : ['Does not clearly state web search inability'], 'applicable' => true, 'web_search_available' => false];
}

/**
 * Final-answer contradiction validator
 * Detects mismatch between computed values and final claims in the same answer.
 */
function chat_validate_final_answer_consistency(string $latestUserMsg, string $reply): array {
    $issues = [];
    $checks = [];

    $calcMatch = [];
    if (preg_match('/(\d+(?:\.\d+)?)\s*[-+]\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/', $reply, $calcMatch) === 1) {
        $lhs = (float)$calcMatch[1];
        $rhs = (float)$calcMatch[2];
        $shown = (float)$calcMatch[3];
        $actual = $lhs - $rhs;
        if (abs($actual - $shown) > 0.01) {
            $issues[] = 'Displayed arithmetic expression is internally inconsistent.';
        }
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*minutes?\b/i', $reply, $minMatch) === 1) {
            $finalMinutes = (float)$minMatch[1];
            if (abs($shown - $finalMinutes) > 0.01 && abs($actual - $finalMinutes) > 0.01) {
                $issues[] = 'Final numeric answer contradicts the shown calculation.';
            }
        }
        $checks[] = ['name' => 'final_answer_consistent', 'pass' => empty($issues)];
    }

    return ['pass' => empty($issues), 'issues' => $issues, 'checks' => $checks];
}

/**
 * Quantifier logic validator
 * Catches known invalid inference patterns involving all/some/none.
 */
function chat_validate_logic_quantifiers(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    $replyLower = strtolower($reply);
    $issues = [];

    $rosePattern = preg_match('/all\s+roses\s+are\s+flowers.*some\s+flowers\s+are\s+red/i', $msg) === 1;
    if ($rosePattern) {
        $incorrectConclusion = preg_match('/\b(?:some|all)\s+roses\s+(?:are|can\s+be|are\s+(?:definitely|clearly|obviously))\s*(?:[a-z]+\s+)*red\b/i', $replyLower) === 1;
        $explicitlyRejects = preg_match('/does\s+not\s+follow|cannot\s+conclude|invalid\s+inference|not\s+logically\s+guaranteed|not\s+necessarily\s+true/i', $replyLower) === 1;
        if ($incorrectConclusion && !$explicitlyRejects) {
            $issues[] = 'Invalid quantifier inference accepted as valid.';
        }
    }

    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'checks' => [['name' => 'logic_quantifier_valid', 'pass' => empty($issues)]],
    ];
}

/**
 * Security precision validator
 * Prevents known incorrect cookie/storage security claims.
 */
function chat_validate_security_precision(string $latestUserMsg, string $reply): array {
    $issues = [];
    $lower = strtolower($reply);

    if (preg_match('/localstorage\s+.*httponly|httponly\s+.*localstorage/i', $lower) === 1) {
        $issues[] = 'Incorrect claim: HttpOnly applies to cookies, not localStorage.';
    }
    if (preg_match('/httponly\s+.*prevents\s+xss|prevents\s+xss\s+.*httponly/i', $lower) === 1) {
        $issues[] = 'Incorrect claim: HttpOnly does not prevent XSS itself.';
    }

    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'checks' => [['name' => 'security_facts_correct', 'pass' => empty($issues)]],
    ];
}

/**
 * Writing scope validator
 * Ensures sentence-rewrite prompts return concise rewrite instead of unsolicited expansions.
 */
function chat_validate_writing_scope(string $latestUserMsg, string $reply): array {
    $msg = strtolower(trim($latestUserMsg));
    $replyLower = strtolower(trim($reply));
    $issues = [];

    $simpleRewrite = preg_match('/\b(make this sentence|rewrite|sound more natural|sound more professional|polish this sentence)\b/i', $msg) === 1
        && preg_match('/\b(three options|3 options|alternatives|explain why|give advice)\b/i', $msg) !== 1;

    if ($simpleRewrite) {
        $lineCount = count(array_filter(preg_split('/\n+/', $replyLower) ?: []));
        $hasUnsolicitedOptions = preg_match('/\b(option\s*1|option\s*2|alternative\s*1|here are\s+\d+)\b/i', $replyLower) === 1;
        if ($lineCount > 5 || $hasUnsolicitedOptions) {
            $issues[] = 'Writing response exceeds requested scope for simple rewrite.';
        }
    }

    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'checks' => [['name' => 'writing_scope_respected', 'pass' => empty($issues)]],
    ];
}

/**
 * Hard refusal-contract validator
 * Reject refusal-only answers unless they contain a structured evidence checklist.
 */
function chat_validate_refusal_contract(string $latestUserMsg, string $reply, array $context = []): array {
    $text = trim($reply);
    if ($text === '') {
        return ['pass' => false, 'issues' => ['Empty response is not allowed.'], 'applicable' => true];
    }

    $refusalLanguage = preg_match('/\b(cannot verify|can\'t verify|insufficient evidence|not enough information|no access|cannot access|unable to access|not available in this runtime|do not have authorization|unverified)\b/i', $text) === 1;
    if (!$refusalLanguage) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }

    $hasKnownSection = preg_match('/\b(known facts?|confirmed facts?|known:)\b/i', $text) === 1;
    $hasUnknownSection = preg_match('/\b(unknowns?|unverified|assumptions?|unknown:)\b/i', $text) === 1;
    $hasNextChecksSection = preg_match('/\b(next checks?|verification checklist|evidence checklist|next steps?)\b/i', $text) === 1;
    preg_match_all('/(?:^|\n)\s*(?:\d+[.)]|[-*])\s+/m', $text, $listMarkers);
    $listItemCount = count($listMarkers[0] ?? []);

    $structuredChecklist = $hasNextChecksSection && $listItemCount >= 2 && ($hasKnownSection || $hasUnknownSection);
    if ($structuredChecklist) {
        return [
            'pass' => true,
            'issues' => [],
            'applicable' => true,
            'structured_checklist' => true,
            'list_item_count' => $listItemCount,
        ];
    }

    return [
        'pass' => false,
        'issues' => ['Refusal-only response rejected: include a structured evidence checklist with knowns/unknowns and at least two concrete next checks.'],
        'applicable' => true,
        'structured_checklist' => false,
        'list_item_count' => $listItemCount,
    ];
}
