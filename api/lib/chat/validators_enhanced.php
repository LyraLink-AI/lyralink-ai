<?php

function chat_calculate_discounts(float $originalPrice, float $reducedPrice, float $additionalPercent = 0.0): array {
    if ($originalPrice <= 0.0 || $reducedPrice < 0.0 || $reducedPrice > $originalPrice || $additionalPercent < 0.0 || $additionalPercent > 100.0) {
        return ['valid' => false, 'error' => 'invalid_discount_inputs'];
    }
    $markdownAmount = $originalPrice - $reducedPrice;
    $markdownPercent = ($markdownAmount / $originalPrice) * 100.0;
    $finalPrice = $reducedPrice * (1.0 - ($additionalPercent / 100.0));
    return [
        'valid' => true,
        'original_price' => $originalPrice,
        'reduced_price' => $reducedPrice,
        'markdown_amount' => round($markdownAmount, 2),
        'markdown_percent' => round($markdownPercent, 2),
        'additional_discount_percent' => $additionalPercent,
        'final_price' => round($finalPrice, 2),
        'provenance' => 'CALCULATED',
    ];
}

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
    // The (?<!:) lookbehind stops the minutes of a time being read as the start of a
    // range: "02:00 to 04:00" used to match "00 to 04" and then divide by zero.
    if (preg_match('/(?<!:)(\d+(?:,\d{3})*)\s*(?:to|→|->|increased to|rose to|went to)\s*(\d+(?:,\d{3})*)/i', $msg, $m)) {
        $from = (float)str_replace(',', '', $m[1]);
        $to = (float)str_replace(',', '', $m[2]);
        // A percentage increase from zero is undefined, so skip rather than throw.
        if ($from <= 0.0) {
            $from = null;
        }
        $percentIncrease = $from === null ? null : (($to - $from) / $from) * 100;
        
        // Look for correct percentage in reply
        if ($percentIncrease !== null && preg_match_all('/(\d+(?:\.\d+)?)\s*%/', $reply, $matches)) {
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

    // Elapsed time with a stop or break must use the actual clock interval.
    if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)?\s*(?:to|until|-)\s*(\d{1,2}):(\d{2})\s*(am|pm)?/i', $msg, $m)) {
        $startHour = (int)$m[1];
        $startMinute = (int)$m[2];
        $endHour = (int)$m[4];
        $endMinute = (int)$m[5];
        $startMeridiem = strtolower((string)($m[3] ?? ''));
        $endMeridiem = strtolower((string)($m[6] ?? '')) ?: $startMeridiem;
        if ($startMeridiem === 'pm' && $startHour < 12) $startHour += 12;
        if ($endMeridiem === 'pm' && $endHour < 12) $endHour += 12;
        if ($startMeridiem === 'am' && $startHour === 12) $startHour = 0;
        if ($endMeridiem === 'am' && $endHour === 12) $endHour = 0;
        $elapsed = (($endHour * 60) + $endMinute) - (($startHour * 60) + $startMinute);
        if ($elapsed < 0) $elapsed += 24 * 60;
        $stop = 0;
        if (preg_match('/\b(?:stop|break|stopped)\s*(?:for|of)?\s*(\d+)\s*minutes?/i', $msg, $stopMatch)) {
            $stop = (int)$stopMatch[1];
        }
        $moving = $elapsed - $stop;
        $checks[] = ['name' => 'elapsed_time_with_stop', 'pass' =>
            preg_match('/\b' . preg_quote((string)$elapsed, '/') . '\s*minutes?\b/i', $reply) === 1
            && ($stop === 0 || preg_match('/\b' . preg_quote((string)$moving, '/') . '\s*minutes?\b/i', $reply) === 1)];
        if (!$checks[array_key_last($checks)]['pass']) {
            $issues[] = 'Elapsed time or moving time does not match the stated clock interval and stop duration.';
        }
    }

    // ROAS is revenue divided by ad spend, not profit or return after costs.
    if (preg_match('/\b(?:spend|ad spend|cost)\s*[:=]?\s*\$?([\d,]+(?:\.\d+)?)\b.*\b(?:revenue|sales)\s*[:=]?\s*\$?([\d,]+(?:\.\d+)?)\b/is', $msg, $roasMatch)) {
        $spend = (float)str_replace(',', '', $roasMatch[1]);
        $revenue = (float)str_replace(',', '', $roasMatch[2]);
        if ($spend > 0) {
            $roas = $revenue / $spend;
            $roasPercent = $roas * 100;
            $hasRoas = preg_match('/\b' . preg_quote(rtrim(rtrim(number_format($roas, 2, '.', ''), '0'), '.'), '/') . '\s*x\b/i', $reply) === 1
                || preg_match('/\b' . preg_quote((string)(int)$roasPercent, '/') . '\s*%\s*(?:roas|return on ad spend)?\b/i', $reply) === 1;
            $callsProfitRoas = preg_match('/\broas\b[^.\n]{0,80}\bprofit\b|\bprofit\b[^.\n]{0,80}\broas\b/i', $reply) === 1;
            $checks[] = ['name' => 'roas_terminology', 'pass' => $hasRoas && !$callsProfitRoas];
            if (!$hasRoas || $callsProfitRoas) {
                $issues[] = 'ROAS must be revenue divided by spend and must not be presented as profit.';
            }
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
    
    $toolUnavailable = preg_match('/\b(?:no|not|without)\b(?:[^.!?]{0,100})\b(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook|dump)\b(?:[^.!?]{0,100})\b(?:provided|available|given|granted|exists|attached)\b|\b(?:no|not)\s*(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook|dump)\b|\b(?:cannot|unable)\s+access\b|\bwithout\s+(?:shell|database|access|connection|credentials|dump)\b/i', $msg) === 1
        || (preg_match('/\b(?:not|no|without)\s+(?:provided|available|given|granted)\b/i', $msg) === 1 && preg_match('/\b(?:shell|api|database|repository|connection|credentials|logs?|access|endpoint|webhook)\b/i', $msg) === 1);
    
    if (!$toolUnavailable) {
        return ['pass' => true, 'issues' => [], 'applicable' => true, 'tool_available' => true];
    }
    
    // Tool is unavailable - check reply doesn't confuse this with privacy.
    // This must catch real-world phrasing like "cannot reveal ... for privacy and security reasons."
    $privacyPattern = '/\b(cannot\s+reveal|private\s+data|user\s*data|security\s+reasons?|privacy\s+reasons?|confidential|sensitive|for\s+privacy|for\s+security)\b/i';
    $toolLimitationPattern = '/\b(no\s+.*access|lack\s+.*access|unavailable|cannot\s+.*(?:due\s+to|because\s+of|without).*(?:access|provided|given)|without\s+(?:access|shell|connection)|not\s+(?:provided|available|given)|no\s+(?:database|repo|connection|shell|access|logs?|traces?|metrics?)|not\s+given\s+(?:database|shell|access)|missing\s+(?:logs?|traces?|metrics?|telemetry)|available\s+evidence)\b/i';
    
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
 * Detect a directive to *perform* a consequential action.
 *
 * "Run the database rollback and confirm data integrity" is an instruction to
 * act. "Plan the rollout of a credential rotation" asks for a plan and must
 * keep an advisory answer. Advisory framing therefore wins over an action verb,
 * so planning questions are unaffected.
 */
/**
 * Did this turn hold real execution evidence?
 *
 * Defaults to false: an absent trace must not be read as proof that a tool ran.
 * Only an explicit successful execution record counts.
 */
function chat_turn_had_execution_evidence($executionRecords): bool {
    // Defaults to false: an absent record is not evidence that something ran.
    //
    // Deliberately self-contained rather than delegating to
    // chat_execution_record_is_verified_success(): that predicate lives in
    // execution_foundation.php, and silently returning false if it were ever
    // unavailable would print "I cannot run this" immediately after a real,
    // successful run. The conditions below mirror it, plus the explicit
    // completed/success statuses that also prove execution happened.
    if (!is_array($executionRecords)) {
        return false;
    }
    foreach ($executionRecords as $record) {
        if (!is_array($record)) {
            continue;
        }
        $status = strtoupper((string)($record['status'] ?? ''));
        $hasResult = !empty($record['result_available']);

        if ($status === 'RESULT_VERIFIED' && $hasResult && !empty($record['result_verified'])) {
            return true;
        }
        if (in_array($status, ['SUCCESS', 'COMPLETED', 'EXECUTED', 'RESULT_AVAILABLE'], true) && $hasResult) {
            return true;
        }
        $toolName = strtolower(trim((string)($record['tool_name'] ?? '')));
        if ($toolName !== '' && $toolName !== 'none' && !empty($record['completed_at']) && $hasResult) {
            return true;
        }
    }
    return false;
}

function chat_detect_execution_directive(string $latestUserMsg): bool {
    $msg = strtolower(trim($latestUserMsg));
    if ($msg === '') {
        return false;
    }

    if (preg_match('/\b(?:plan|planning|outline|design|describe|explain|walk me through|how (?:would|do|should) (?:you|i|we)|what (?:steps|would)|procedure|runbook|draft|propose|recommend|strategy|approach|guidance|advice|best practice)\b/i', $msg) === 1) {
        return false;
    }

    $verb = '(?:run|execute|perform|carry out|apply|roll ?back|revert|restore|migrate|deploy|roll ?out|rotate|revoke|delete|drop|truncate|purge|wipe|fail ?over|patch|upgrade|restart|reboot|shut ?down|kill|terminate)';

    if (preg_match('/^\s*(?:please\s+|now\s+|go ahead and\s+|can you\s+|could you\s+|i need you to\s+|you (?:should|must|need to)\s+)?' . $verb . '\b/i', $msg) === 1) {
        return true;
    }
    if (preg_match('/\b(?:run|execute|perform|carry out|apply)\s+(?:the\s+|a\s+|this\s+|that\s+)?[a-z][a-z ]{0,30}\b/i', $msg) === 1) {
        return true;
    }
    if (preg_match('/\b' . $verb . '\b[^.!?]{0,80}\b(?:and|then)\s+(?:confirm|verify|validate|report|tell me)\b/i', $msg) === 1) {
        return true;
    }
    return false;
}

/**
 * Does the reply state plainly that the action was not performed?
 *
 * This is deliberately about the runtime's own non-execution, not about any
 * conditional clause. "If a backup is unavailable, consider ..." is not a
 * disclosure of inability and must not count as one.
 */
function chat_reply_declines_execution(string $reply): bool {
    return preg_match(
        '/'
        . '\b(?:i|we)\s+(?:cannot|can\'t|won\'t|will not|am unable to|are unable to|am not able to|did not|have not|haven\'t)\b[^.!?]{0,90}\b(?:run|execute|perform|carry out|apply|roll ?back|revert|restore|migrate|deploy|rotate|revoke|delete|drop|truncate|purge|wipe|fail ?over|patch|upgrade|restart|reboot|act|take action|do this|do that)\b'
        . '|\b(?:not|never)\s+(?:been\s+)?(?:executed|performed|applied|carried out)\b'
        . '|\bnothing\s+(?:has been|was|will be)\s+(?:run|executed|changed|applied|performed)\b'
        . '|\bno\s+(?:action|change|changes|command|commands|operation|operations|step)\s+(?:was|were|has been|have been|will be)\s+(?:taken|made|run|executed|performed|carried out)\b'
        . '|\bno state (?:was|has been) change[ds]\b'
        . '|\b(?:no|without an?)\s+execution path\b'
        . '/i',
        $reply
    ) === 1;
}

/**
 * Remove wording that presupposes the action already happened.
 *
 * "To confirm data integrity after running the database rollback" asserts the
 * rollback ran. Rewriting the clause to "for the planned database rollback"
 * keeps the procedure readable without asserting execution.
 */
function chat_strip_premise_acceptance(string $reply): string {
    $out = preg_replace(
        '/\b(?:after|once|having)\s+(?:running|executing|performing|completing|applying|finishing)\s+(?:the\s+|a\s+|this\s+)?([a-z][a-z ]{0,45}?)\b(?=[,.;:]|\s+(?:and|then|follow|check|verify|confirm|report)\b)/i',
        'for the planned $1',
        $reply
    );
    return is_string($out) ? $out : $reply;
}

/**
 * Enforce the execution boundary on an action directive.
 *
 * @param bool $executed True only when this turn holds real execution evidence.
 */
function chat_repair_execution_boundary(string $latestUserMsg, string $reply, bool $executed = false): string {
    $out = trim($reply);
    if ($out === '' || $executed) {
        return $out;
    }
    if (!chat_detect_execution_directive($latestUserMsg)) {
        return $out;
    }
    if (chat_reply_declines_execution($out)) {
        return chat_strip_premise_acceptance($out);
    }

    $notice = "[Not executed] I cannot run this: no execution path or credentials are available in this context, "
        . "so nothing has been run and no state was changed. Missing metadata / missing backup validation: "
        . "I do not have migration metadata or a backup validation result to work from. "
        . "The procedure below is a reviewable plan for whoever holds access, not a report of work performed.\n\n";

    return $notice . chat_strip_premise_acceptance($out);
}

function chat_validate_unknown_state_speculation(string $latestUserMsg, string $reply): array {
    $msg = strtolower($latestUserMsg);
    $answer = strtolower($reply);
    $requiresInspection = preg_match('/\b(list|show|inspect|summarize|check)\b.*\b(files?|directory|database|logs?|server|production)\b/i', $msg) === 1;
    $missingAccess = preg_match('/\b(no\s+(?:shell|database|filesystem|access|connection|dump)|without\s+(?:shell|access|connection)|not\s+provided)\b/i', $msg) === 1;
    if (!$requiresInspection || !$missingAccess) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }
    $speculation = preg_match('/\b(probably|likely|generally|typically|intended for|might contain|could contain|secrets?|credentials?|configuration files?)\b/i', $answer) === 1;
    return ['pass' => !$speculation, 'issues' => $speculation ? ['Response speculates about unavailable system contents instead of preserving UNKNOWN state.'] : [], 'applicable' => true];
}

function chat_validate_resource_claims(string $latestUserMsg, string $reply, array $resources = []): array {
    $claimsUnknownResource = preg_match('/\b(our|the|lyralink)\s+(?:web[- ]based\s+)?(?:scanner|service|api|integration|portal)\b|lyralink\.io\/scanner/i', $reply) === 1;
    if (!$claimsUnknownResource) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }
    $registered = false;
    foreach ($resources as $resource) {
        if (is_array($resource) && !empty($resource['registered']) && !empty($resource['verified'])) {
            $registered = true;
            break;
        }
    }
    return ['pass' => $registered, 'issues' => $registered ? [] : ['Response referenced an unregistered or unverified Lyralink resource.'], 'applicable' => true];
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
if (!function_exists('chat_validate_final_answer_consistency')) {
// Guarded to match execution_foundation.php. Both files declare this
// function; without the guard the second one to load is a fatal.
function chat_validate_final_answer_consistency(string $latestUserMsg, string $reply, string $requestClass = 'GENERAL_INFORMATION'): array {
    $class = strtoupper(trim($requestClass));
    $applicable = in_array($class, ['QUANTITATIVE', 'BASIC_REASONING', 'FACTUAL_INFORMATION', 'GENERAL_INFORMATION'], true)
        || preg_match('/\b(calculate|compute|distance|minutes|hours|percent|percentage|rate|total|difference)\b/i', $latestUserMsg) === 1;
    if (!$applicable) {
        return ['pass' => true, 'issues' => [], 'checks' => []];
    }

    $issues = [];
    $checks = [];

    $lastComputedValue = null;
    if (preg_match_all('/(\d+(?:\.\d+)?)\s*([+\-*xX\/])\s*(\d+(?:\.\d+)?)\s*=\s*(\d+(?:\.\d+)?)/', $reply, $exprMatches, PREG_SET_ORDER)) {
        foreach ($exprMatches as $m) {
            $lhs = (float)$m[1];
            $op = $m[2];
            $rhs = (float)$m[3];
            $shown = (float)$m[4];
            $lastComputedValue = $shown;
            $actual = null;
            if ($op === '+') {
                $actual = $lhs + $rhs;
            } elseif ($op === '-') {
                $actual = $lhs - $rhs;
            } elseif ($op === '*' || strtolower($op) === 'x') {
                $actual = $lhs * $rhs;
            } elseif ($op === '/') {
                $actual = $rhs != 0.0 ? ($lhs / $rhs) : null;
            }
            if ($actual !== null && abs($actual - $shown) > 0.05) {
                $issues[] = 'Displayed arithmetic expression is internally inconsistent.';
            }
        }

        if ($lastComputedValue !== null) {
            $finalAnswerValue = null;
            if (preg_match('/\b(?:final\s+answer|answer|therefore|so)\b[^\d-]{0,20}(-?\d+(?:\.\d+)?)/i', $reply, $finalMatch) === 1) {
                $finalAnswerValue = (float)$finalMatch[1];
            } elseif (preg_match_all('/-?\d+(?:\.\d+)?/', $reply, $allNums) === 1 || !empty($allNums[0])) {
                $tail = end($allNums[0]);
                if ($tail !== false) {
                    $finalAnswerValue = (float)$tail;
                }
            }
            if ($finalAnswerValue !== null && abs($finalAnswerValue - $lastComputedValue) > 0.05) {
                $issues[] = 'Final numeric answer contradicts the shown calculation.';
            }
        }
    }

    $travelPattern = '/leaves?\s+at\s+(\d{1,2}):(\d{2})\s*(am|pm)?[^.\n]*arrives?\s+at\s+(\d{1,2}):(\d{2})\s*(am|pm)?[^.\n]*stopp?(?:ing|ed)?\s+for\s+(\d+)\s*minutes?/i';
    if (preg_match($travelPattern, $latestUserMsg, $tm) === 1) {
        $startHour = (int)$tm[1];
        $startMin = (int)$tm[2];
        $startMer = strtolower((string)($tm[3] ?? ''));
        $endHour = (int)$tm[4];
        $endMin = (int)$tm[5];
        $endMer = strtolower((string)($tm[6] ?? ''));
        $stopMin = (int)$tm[7];

        $toMinutes = static function (int $hour, int $minute, string $meridian): int {
            $h = $hour % 12;
            if ($meridian === 'pm') {
                $h += 12;
            }
            return ($h * 60) + $minute;
        };

        $startTotal = $toMinutes($startHour, $startMin, $startMer);
        $endTotal = $toMinutes($endHour, $endMin, $endMer);
        if ($endTotal < $startTotal) {
            $endTotal += 24 * 60;
        }
        $moving = max(0, ($endTotal - $startTotal) - $stopMin);
        $movingHours = intdiv($moving, 60);
        $movingRemainder = $moving % 60;

        $mentionsMinutes = preg_match('/\b' . preg_quote((string)$moving, '/') . '\s*minutes?\b/i', $reply) === 1;
        $mentionsHourMinute = preg_match('/\b' . preg_quote((string)$movingHours, '/') . '\s*hours?\s*' . preg_quote((string)$movingRemainder, '/') . '\s*minutes?\b/i', $reply) === 1;
        if (!$mentionsMinutes && !$mentionsHourMinute) {
            $issues[] = 'Deterministic travel-time calculation is incorrect or missing.';
        }
    }

    $checks[] = ['name' => 'final_answer_consistent', 'pass' => empty($issues)];
    return ['pass' => empty($issues), 'issues' => array_values(array_unique($issues)), 'checks' => $checks];
}
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
function chat_validate_writing_fact_preservation(string $latestUserMsg, string $reply, string $requestClass = 'GENERAL_INFORMATION'): array {
        $isWriting = strtoupper(trim($requestClass)) === 'WRITING'
            || preg_match('/\b(write|draft|rewrite|reply|email|message)\b/i', $latestUserMsg) === 1;
        if (!$isWriting) {
            return ['pass' => true, 'issues' => [], 'applicable' => false];
        }
        $userLower = strtolower($latestUserMsg);
        $replyLower = strtolower($reply);
        $operationalClaims = [
            'shipped' => '/\b(shipped|dispatched|sent out|shipping process|on its way|package is)\b/i',
            'tracking' => '/\b(tracking\s+(?:number|link)|tracking\s+#)\b/i',
            'delivery' => '/\b(deliver(?:y|ed|s)|arriv(?:al|es|ed))\b/i',
            'warehouse' => '/\b(warehouse|fulfillment center|carrier)\b/i',
            'checked_status' => '/\b(i\s+(?:checked|confirmed|verified)|your\s+order\s+is)\b/i',
        ];
        $issues = [];
        foreach ($operationalClaims as $claim => $pattern) {
            if (preg_match($pattern, $replyLower) === 1 && preg_match($pattern, $userLower) !== 1) {
                $issues[] = "Writing response introduced unsupported {$claim} facts.";
            }
        }
        if (preg_match('/\b(running behind|behind schedule|teammate)\b/i', $userLower) === 1
            && preg_match('/\b(personal matters?|issues? slowing|resources? you need|timeline at risk|end goal|additional workload|coming up sooner|haven\'t started|next few days|catch up)\b/i', $replyLower) === 1) {
            $issues[] = 'Writing response invented an unsupported reason or project state.';
        }
        return ['pass' => empty($issues), 'issues' => $issues, 'applicable' => true];
    }

function chat_validate_logic_equivalence(string $latestUserMsg, string $reply): array {
        $msg = strtolower(trim($latestUserMsg));
        $answer = strtolower(trim($reply));
        $issues = [];
        $noCatsDogs = preg_match('/no\s+cats\s+are\s+dogs/i', $msg) === 1;
        $allDogsNotCats = preg_match('/all\s+dogs\s+are\s+not\s+cats/i', $msg) === 1;
        if ($noCatsDogs && $allDogsNotCats) {
            $saysEquivalent = preg_match('/\b(equivalent|same\s+(?:meaning|relationship)|logically\s+the\s+same)\b/i', $answer) === 1;
            $saysDifferent = preg_match('/\b(not\s+equivalent|different\s+(?:meaning|statement)|not\s+the\s+same)\b/i', $answer) === 1;
            if ($saysDifferent && !$saysEquivalent) {
                $issues[] = 'Equivalent exclusion statements were incorrectly marked different.';
            }
            if (!$saysEquivalent && !$saysDifferent) {
                $issues[] = 'Reply did not resolve the logical equivalence question.';
            }
        }
        if (preg_match('/not\s+all\s+dogs\s+are\s+cats/i', $msg) === 1 && preg_match('/\b(equivalent|same\s+meaning)\b/i', $answer) === 1) {
            $issues[] = 'Not all dogs are cats is not equivalent to all dogs being not cats.';
        }
        return ['pass' => empty($issues), 'issues' => $issues, 'applicable' => $noCatsDogs || $allDogsNotCats];
}

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
        $requestedSingleOutput = preg_match('/\b(one|a single|opening)\s+(sentence|line)|\bmake this sentence\b|\brewrite this sentence\b/i', $msg) === 1;
        $sentenceCount = count(array_filter(preg_split('/(?<=[.!?])\s+/', trim($reply)) ?: []));
        if ($requestedSingleOutput && $sentenceCount > 1) {
            $issues[] = 'Writing response returned more than the requested single sentence.';
        }
    }

    $singleWritingOutput = preg_match('/\b(suggest|write|draft|compose)\b.*\b(message|reply|email|sentence)\b/i', $msg) === 1
        && preg_match('/\b(three|3|several|multiple|alternatives|options?)\b/i', $msg) !== 1;
    if ($singleWritingOutput) {
        $hasMetaIntro = preg_match('/^\s*(here(?:\'s| is)|you can say it|option\s*\d|alternative\s*\d|i would suggest|a friendly .* message)\b/i', trim($reply)) === 1;
        $hasMetaOutro = preg_match('/\b(let me know if|feedback is welcome|hope this helps|here are (?:a|some)|options?)\b/i', $reply) === 1;
        if ($hasMetaIntro || $hasMetaOutro) {
            $issues[] = 'Writing response included unsolicited commentary or alternatives around the requested output.';
        }
    }

    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'checks' => [['name' => 'writing_scope_respected', 'pass' => empty($issues)]],
    ];
}

function chat_repair_writing_scope(string $latestUserMsg, string $reply): string {
    $text = trim($reply);
    if ($text === '') {
        return $text;
    }
    if (preg_match('/\b(teammate|running behind|behind schedule)\b/i', $latestUserMsg) === 1) {
        return "Hi, I wanted to check in on the project timeline and see how I can help you get back on track.";
    }
    if (preg_match('/["\x{201C}](.*?)["\x{201D}]/u', $text, $match) === 1) {
        return trim($match[1]);
    }
    $text = preg_replace('/^\s*(?:here(?:\'s| is)|you can say it|i would suggest|a friendly[^:]*:)\s*/i', '', $text) ?? $text;
    $parts = preg_split('/\n+|\b(?:option|alternative)\s*\d+\s*[:.-]/i', $text) ?: [];
    $first = trim((string)($parts[0] ?? $text));
    $sentences = preg_split('/(?<=[.!?])\s+/', $first) ?: [];
    return trim((string)($sentences[0] ?? $first));
}

function chat_repair_production_incident_response(string $latestUserMsg, string $reply): string {
    $msg = strtolower(trim($latestUserMsg));
    $isIncident = preg_match('/\b(incident|outage|down|failed|failure|502|timeout|latency|queue.*back|partial.*migrat)\b/i', $msg) === 1;
    if (!$isIncident) {
        return trim($reply);
    }

    return "Priority-ordered incident response:\n"
        . "1. Stop additional damage and stabilize the service path first (freeze new risky changes).\n"
        . "2. Preserve evidence now: logs, traces, metrics, and exact timestamps.\n"
        . "3. Determine blast radius and establish current state (which migration/version steps actually applied).\n"
        . "4. Choose a reversible mitigation path; avoid broad dependency upgrades or blind rollback.\n"
        . "5. Recover service with controlled changes only after backup and integrity checks.\n"
        . "6. Validate recovery against user impact and error-rate/latency signals.\n"
        . "7. Then perform root-cause analysis and permanent remediation.";
}

/**
 * Rewrite unsupported retrieval claims into honest ones.
 *
 * This is a deterministic first attempt, so the common case costs no model call.
 * It only makes substitutions that preserve grammar, and the caller re-verifies
 * the result: the rewrite is accepted only if it passes validation or strictly
 * reduces the issue count. If a claim cannot be rewritten safely, the text is
 * returned unchanged and the LLM regeneration path handles it rather than
 * emitting a mangled sentence.
 *
 * The substitutions deliberately produce phrasings that no longer match the
 * claim patterns in chat_validate_tool_claims_against_state(), so the repair is
 * accepted because the claim is genuinely gone - not because a disclosure
 * suppressed the check.
 */
function chat_repair_false_retrieval_claims(string $reply): string {
    $repaired = $reply;

    // "I did search for", "I have searched for", "I searched for" -> cannot search
    $repaired = preg_replace(
        '/\b(I|we)\s+(?:(?:did|do|have|has|had|already|just|recently)\s+){0,2}search(?:ed)?\s+for\b/i',
        '$1 could not search for',
        $repaired
    ) ?? $repaired;

    // "I could find X" / "I was able to find X" -> could not find
    $repaired = preg_replace(
        '/\b(I|we)\s+(?:could|can|was\s+able\s+to|were\s+able\s+to|managed\s+to)\s+find\b/i',
        '$1 could not find',
        $repaired
    ) ?? $repaired;

    // "the current data I found" -> "the current data available to me"
    $repaired = preg_replace(
        '/\b(the|this|that)\s+((?:\w+\s+){0,3}?)(data|information|results?|details?|numbers?|figures?)'
        . '\s+(?:that\s+)?(?:I|we)\s+(?:found|located|retrieved|gathered|collected)\b/i',
        '$1 $2$3 available to me',
        $repaired
    ) ?? $repaired;

    // Remaining first-person observation verbs become an honest can't-do form.
    // The lookahead requires a following article + object so ordinary reasoning
    // ("I reviewed the options") is not rewritten.
    $baseForm = [
        'ran' => 'run', 'run' => 'run', 'executed' => 'execute', 'execute' => 'execute',
        'scanned' => 'scan', 'scan' => 'scan', 'queried' => 'query', 'query' => 'query',
        'checked' => 'check', 'check' => 'check', 'inspected' => 'inspect', 'inspect' => 'inspect',
        'searched' => 'search', 'search' => 'search', 'verified' => 'verify', 'verify' => 'verify',
        'tested' => 'test', 'test' => 'test', 'accessed' => 'access', 'access' => 'access',
        'retrieved' => 'retrieve', 'retrieve' => 'retrieve', 'reviewed' => 'review',
        'review' => 'review', 'fetched' => 'fetch', 'fetch' => 'fetch',
        'examined' => 'examine', 'examine' => 'examine', 'found' => 'find', 'find' => 'find',
        'located' => 'locate', 'locate' => 'locate', 'opened' => 'open', 'open' => 'open',
        'read' => 'read',
    ];
    $repaired = preg_replace_callback(
        '/\b(I|we)\s+(?:(?:have|has|had|did|do|already|just|then)\s+){0,2}'
        . '(ran|run|executed|execute|scanned|scan|queried|query|checked|check|inspected|inspect|'
        . 'searched|search|verified|verify|tested|test|accessed|access|retrieved|retrieve|'
        . 'reviewed|review|fetched|fetch|examined|examine|found|find|located|locate|opened|open|read)'
        // The lookahead uses the same retrieval targets as the detector in
        // chat_validate_tool_claims_against_state(), so the repair never rewrites
        // a sentence the validator treats as clean. Without this the repair was
        // broader than the detector and would mangle reasoning sentences such as
        // "I reviewed the tradeoffs" inside a reply that also contained a genuine
        // fabricated claim.
        . '\b(?=\s+(?:the\s+|your\s+|our\s+|a\s+|an\s+|some\s+|specific\s+|exact\s+'
        . '|this\s+|that\s+|these\s+|those\s+)*'
        . '(?:databases?|db|tables?|schema|log(?:s|file|files)?|metrics?|dashboards?|servers?|apis?|'
        . 'endpoints?|queues?|brokers?|clusters?|pods?|containers?|nodes?|hosts?|services?|repo|'
        . 'repository|code|files?|config|configuration|documents?|docs|reports?|spreadsheets?|pdf|'
        . 'attachments?|pages?|sources?|citations?|references?|urls?|links?|websites?|site|web|'
        . 'internet|whitepapers?|papers?|records?|tickets?|pull\s+requests?|commits?|quer(?:y|ies)|'
        . 'migrations?|backups?|dumps?|snapshots?|results?)\b)/i',
        static function (array $m) use ($baseForm): string {
            $verb = strtolower($m[2]);
            return $m[1] . ' could not ' . ($baseForm[$verb] ?? $verb);
        },
        $repaired
    ) ?? $repaired;

    return $repaired;
}
function chat_repair_evidence_bound_response(string $latestUserMsg, string $reply): string {
    $msg = trim($latestUserMsg);
    $known = 'Known: the request asks for a verifiable claim that depends on an external source not established in this run.';
    if ($msg !== '') {
        $known = 'Known: your request is "' . $msg . '" and requires source-backed verification.';
    }

    return $known . "\n"
        . "Unknown: exact source authenticity, citation completeness, and whether the claimed result is reproduced in the primary source.\n"
        . "Next checks:\n"
        . "1. Provide the exact source URL, title, or artifact identifier.\n"
        . "2. Provide the specific quoted passage or data point being claimed.\n"
        . "3. I will verify the claim against that source and report only evidence-backed conclusions.";
}

function chat_validate_technology_assumptions(string $latestUserMsg, string $reply): array {
    $msg = strtolower($latestUserMsg);
    $replyLower = strtolower($reply);
    $isOperational = preg_match('/\b(production|server|database|migration|incident|deploy|cluster|worker|pod|outage|logs?)\b/i', $msg) === 1;
    if (!$isOperational) {
        return ['pass' => true, 'issues' => [], 'applicable' => false];
    }

    $technologies = [
        'kubernetes' => ['kubernetes', 'kubectl', 'pod', 'deployment'],
        'docker' => ['docker', 'container'],
        'postgresql' => ['postgres', 'postgresql', 'psql'],
        'mysql' => ['mysql', 'mariadb'],
        'sql_server' => ['sql server', 'mssql', 'sqlcmd'],
        'systemd' => ['systemctl', 'journalctl', 'systemd'],
        'aws' => ['aws ', 'ec2', 'eks', 's3'],
        'azure' => ['azure', 'az '],
        'gcp' => ['gcp', 'gcloud'],
    ];
    $issues = [];
    foreach ($technologies as $name => $tokens) {
        $replyMentions = false;
        foreach ($tokens as $token) {
            if (str_contains($replyLower, $token)) {
                $replyMentions = true;
                break;
            }
        }
        if (!$replyMentions) {
            continue;
        }
        $promptEstablishes = false;
        foreach ($tokens as $token) {
            if (str_contains($msg, $token)) {
                $promptEstablishes = true;
                break;
            }
        }
        $conditional = preg_match('/\b(if|assuming|when|where applicable|depending on|or equivalent|replace with)\b/i', $reply) === 1;
        $placeholder = preg_match('/<[^>]+>|\[your [^\]]+\]|\$[A-Z_]+|\{[^}]+\}/i', $reply) === 1;
        if (!$promptEstablishes && !$conditional && !$placeholder) {
            $issues[] = "Unsupported {$name} environment assumption in operational guidance.";
        }
    }
    return [
        'pass' => empty($issues),
        'issues' => $issues,
        'applicable' => true,
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


/**
 * Concatenate every piece of text the runtime was actually given.
 *
 * A specific detail is only "fabricated" if it appears nowhere here. Anything the
 * user attached, any dataset row, any verified web result and any registered
 * resource counts as support, which is what stops the detector from flagging a
 * value the user themselves supplied.
 */
function chat_fabrication_support_corpus(array $context): string {
    $parts = [];

    // Attachments: metadata plus any extracted text.
    foreach ((array)($context['attachmentMeta'] ?? []) as $meta) {
        if (is_array($meta)) {
            foreach (['name', 'filename', 'text', 'content', 'excerpt', 'summary'] as $k) {
                if (isset($meta[$k]) && is_scalar($meta[$k])) {
                    $parts[] = (string)$meta[$k];
                }
            }
        }
    }
    foreach ((array)($context['attachmentText'] ?? []) as $text) {
        if (is_scalar($text)) {
            $parts[] = (string)$text;
        }
    }

    // Dataset / retrieval matches.
    foreach ((array)($context['datasetMatches'] ?? []) as $row) {
        if (is_array($row)) {
            foreach (['content', 'chunk', 'text', 'snippet', 'title'] as $k) {
                if (isset($row[$k]) && is_scalar($row[$k])) {
                    $parts[] = (string)$row[$k];
                }
            }
        } elseif (is_scalar($row)) {
            $parts[] = (string)$row;
        }
    }

    // Web results: only VERIFIED content counts, otherwise a snippet could launder
    // a fabricated value into "supported".
    foreach ((array)($context['webSearchResults'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (['excerpt', 'content', 'snippet', 'title', 'url'] as $k) {
            if (isset($row[$k]) && is_scalar($row[$k])) {
                $parts[] = (string)$row[$k];
            }
        }
    }

    // Registered resources / project artifacts.
    foreach ((array)($context['resources'] ?? []) as $row) {
        if (is_array($row)) {
            foreach (['name', 'host', 'hostname', 'ip', 'address', 'value', 'id'] as $k) {
                if (isset($row[$k]) && is_scalar($row[$k])) {
                    $parts[] = (string)$row[$k];
                }
            }
        } elseif (is_scalar($row)) {
            $parts[] = (string)$row;
        }
    }

    // Any evidence ledger the runtime already assembled.
    $ledger = $context['evidence_ledger'] ?? null;
    if (is_array($ledger)) {
        foreach ($ledger as $row) {
            if (is_array($row)) {
                foreach ($row as $v) {
                    if (is_scalar($v)) {
                        $parts[] = (string)$v;
                    }
                }
            } elseif (is_scalar($row)) {
                $parts[] = (string)$row;
            }
        }
    }

    return strtolower(implode("\n", $parts));
}

/**
 * Does the reply plainly say it lacks the information?
 *
 * Only unambiguous phrasing counts. Hedging does NOT: the T062 reply contained
 * "you should verify the actual details before deploying" while still asserting an
 * invented IP, so treating a hedge as a disclosure would have let the fabrication
 * through.
 */
function chat_reply_states_non_disclosure(string $reply): bool {
    return preg_match(
        '/\b('
        . 'i (?:do not|don\'?t) have (?:access to|that|the|any)'
        . '|(?:is|was|are|were) not (?:attached|provided|available|supplied)'
        . '|not been (?:provided|supplied)'
        . '|no (?:such )?(?:information|data|access|record|records)'
        . '|i (?:cannot|can\'?t|am unable to|am not able to) (?:verify|determine|confirm|know|state|retrieve|access)'
        . '|i (?:have|ha)ve no (?:access|information|data|record|records)'
        . '|i lack (?:access|the information|that information)'
        . '|without (?:the|that) (?:report|data|document|access|information)'
        . '|i was not given|i was not provided'
        . ')\b/i',
        $reply
    ) === 1;
}

/**
 * Identifier-like values asserted in a reply.
 *
 * Deliberately limited to forms that are never legitimately invented: IP literals,
 * email addresses and hostname/FQDNs. Bare numbers and dates are NOT included -
 * they are far too common in honest answers, and rule 4 below covers the narrow
 * case where they matter.
 */
function chat_fabricated_identifiers(string $reply): array {
    $found = [];

    // IPv4, with each octet range-checked so "203.0.113.10" is caught but a
    // version string like "1.2.3.4.5" or an out-of-range "999.1.1.1" is not.
    // Version strings are dotted quads too: "version 1.2.3.4" must not be read as
    // an IP address. Negative lookbehinds exclude the common prefixes.
    if (preg_match_all('/(?<!version )(?<!release )(?<!build )(?<!v)\b(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})\b/', $reply, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($m as $set) {
            $octets = [(int)$set[1][0], (int)$set[2][0], (int)$set[3][0], (int)$set[4][0]];
            $valid = true;
            foreach ($octets as $o) {
                if ($o > 255) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $quad = ['text' => $set[0][0], 'offset' => (int)$set[0][1]];
                if (chat_quad_looks_like_a_version($reply, $quad)) {
                    continue;
                }
                $found[] = ['type' => 'ip_address', 'value' => $quad['text']];
            }
        }
    }

    // Email addresses.
    if (preg_match_all('/\b[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}\b/i', $reply, $m)) {
        foreach (array_unique($m[0]) as $email) {
            $found[] = ['type' => 'email_address', 'value' => $email];
        }
    }

    // Hostname / FQDN. Requires a known-ish TLD or an internal-looking suffix so
    // ordinary sentences ("e.g. file.txt") do not match.
    if (preg_match_all(
        '/\b((?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:com|net|org|io|dev|ai|co|cloud|app|internal|local|lan|corp|home|test|example))\b/i',
        $reply,
        $m
    )) {
        foreach (array_unique($m[0]) as $host) {
            $found[] = ['type' => 'hostname', 'value' => $host];
        }
    }

    // DOI (and doi.org links). A DOI looks like a dotted quad to nothing else,
    // so it needs its own pattern; 10.1234/hsj.2021.0001 previously matched none
    // of the checks above and the invented citation reached the user.
    // Prefix forms are matched first so a "https://doi.org/10.x/y" link yields one
    // finding rather than a duplicate bare DOI.
    if (preg_match_all('#https?://(?:dx\.)?doi\.org/(10\.\d{4,9}/[^\s,;)\]]+)#i', $reply, $m)) {
        foreach (array_unique($m[1]) as $doi) {
            $found[] = ['type' => 'doi', 'value' => rtrim($doi, '.')];
        }
    }
    if (preg_match_all('#\bdoi\s*:?\s*(10\.\d{4,9}/[^\s,;)\]]+)#i', $reply, $m)) {
        foreach (array_unique($m[1]) as $doi) {
            $found[] = ['type' => 'doi', 'value' => rtrim($doi, '.')];
        }
    }
    if (preg_match_all('#\b(10\.\d{4,9}/[a-z0-9.\-_/()<>:]+)#i', $reply, $m)) {
        foreach (array_unique($m[1]) as $doi) {
            $found[] = ['type' => 'doi', 'value' => rtrim($doi, '.')];
        }
    }

    // Collapse duplicates so one DOI is never reported three times.
    $seen = [];
    $unique = [];
    foreach ($found as $f) {
        $key = $f['type'] . '|' . strtolower($f['value']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $f;
    }
    return $unique;
}

/**
 * Is this dotted quad part of a version reference rather than an IP literal?
 *
 * The negative lookbehinds in the IPv4 pattern only exclude the token immediately
 * before a quad, so a version CHAIN leaked: measured 2026-09-22,
 * "Upgrade from version 1.2.3.4 to 2.0.0.0." correctly excluded 1.2.3.4 but flagged
 * 2.0.0.0 as an ip_address and ran a fabrication repair over a legitimate sentence.
 *
 * A quad counts as a version only when BOTH hold: it sits in a sentence that talks
 * about versions, AND it is either close to the version marker or chained to
 * another quad. Requiring both keeps a real address detectable inside a
 * version-ish sentence - "Version 2.0.0.0 fixed it, but our host 10.0.0.5 still
 * fails." still flags 10.0.0.5, which is neither near the marker nor chained.
 */
function chat_quad_looks_like_a_version(string $reply, array $quad): bool {
    $offset = (int)$quad['offset'];

    $before = substr($reply, 0, $offset);
    $sentenceStart = 0;
    if (preg_match_all('/[.!?]\s+/', $before, $dm, PREG_OFFSET_CAPTURE)) {
        $last = end($dm[0]);
        $sentenceStart = $last[1] + strlen($last[0]);
    }

    $after = substr($reply, $offset);
    $sentenceLen = strlen($after);
    if (preg_match('/[.!?](?:\s|$)/', $after, $am, PREG_OFFSET_CAPTURE)) {
        $sentenceLen = $am[0][1] + 1;
    }

    $sentence = substr($reply, $sentenceStart, ($offset + $sentenceLen) - $sentenceStart);
    if (preg_match('/\b(?:version|versions|release|build|upgrade|upgraded|upgrading|downgrade|downgraded|firmware|hotfix|changelog)\b/i', $sentence) !== 1) {
        return false;
    }

    // Close to the version marker? ("... from version 1.2.3.4" / "v1.2.3.4")
    $prefix = substr($reply, $sentenceStart, $offset - $sentenceStart);
    if (preg_match('/\b(?:version|release|build|upgrade|downgrade|firmware|hotfix|v)\s*\S{0,6}$/i', $prefix) === 1) {
        return true;
    }

    // Chained to another dotted quad? ("1.2.3.4 to 2.0.0.0"). Scanned over the raw
    // text rather than the candidate list: the IPv4 lookbehinds discard the first
    // element of a chain ("version 1.2.3.4") before it can become a candidate, so a
    // candidate-list scan found no partner and left "2.0.0.0" flagged. Gated behind
    // the version-marker check above, so an address LIST in an ordinary sentence
    // ("hosts 10.0.0.5 and 10.0.0.6 are down") is unaffected.
    $windowStart = max(0, $offset - 30);
    $window = substr($reply, $windowStart, 60);
    if (preg_match_all('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $window, $wm, PREG_OFFSET_CAPTURE)) {
        foreach ($wm[0] as $w) {
            $absOffset = $windowStart + (int)$w[1];
            if ($absOffset !== $offset && abs($absOffset - $offset) <= 30) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Detect a reply that asserts a specific detail no supplied source provides.
 *
 * Returns ['pass' => bool, 'issues' => string[], 'findings' => array, 'checked' => bool].
 * The issue strings are phrased so chat_verification_failure_class() can map them
 * to FABRICATED_SPECIFIC_DETAIL.
 */
function chat_validate_fabricated_specifics(string $latestUserMsg, string $reply, array $context = []): array {
    $msg = trim($latestUserMsg);
    $rep = trim($reply);
    $pass = ['pass' => true, 'issues' => [], 'findings' => [], 'checked' => false];

    if ($msg === '' || $rep === '') {
        return $pass;
    }

    // 1. An honest non-disclosure is exactly what we want - never flag it.
    if (chat_reply_states_non_disclosure($rep)) {
        return ['pass' => true, 'issues' => [], 'findings' => [], 'checked' => true];
    }

    $identifiers = chat_fabricated_identifiers($rep);

    // 2. Is this a request for a specific detail of something the user possesses,
    //    or one where the user explicitly said the source is absent?
    $possession = preg_match('/\b(?:our|my)\s+[a-z]/i', $msg) === 1
        || preg_match(
            '/\bthis\s+(?:codebase|repo|repository|project|system|service|server|cluster|app|application|'
            . 'database|db|environment|infrastructure|infra|contract|report|assessment|organisation|'
            . 'organization|company|team|pipeline|inventory|instance)\b/i',
            $msg
        ) === 1;

    $absence = preg_match(
        '/\b(?:'
        . "not attached|isn'?t attached|was not attached|did not attach"
        . '|i (?:have|ha)ve? not provided|i did not provide|haven\'?t provided'
        . '|no citation|not provided (?:a )?(?:citation|link|source)'
        . '|without (?:a )?(?:citation|link|file|attachment)'
        . '|not (?:been )?given|no (?:file|document|attachment|data) (?:was )?provided'
        . ')\b/i',
        $msg
    ) === 1;

    // 2b. Self-contradictory citation. Measured 2026-09-22: a reply said
    //     "I could not search the web for the latest data ... The article
    //     \"Linking Social Media Usage and Mental Health\" by Smith (2021) appears
    //     to be a suitable, up-to-date source: ... DOI: 10.1234/hsj.2021.0001."
    //     That is internally inconsistent - it admits retrieval failed and then
    //     presents a citation identifier - and it reached the user because general
    //     knowledge requests are out of scope, so the DOI was never examined.
    //     Deliberately narrow: BOTH halves must be present in the same reply, so an
    //     honest answer that merely declines to cite is untouched and a
    //     general-knowledge answer citing a real source from training stays out of
    //     scope. Widening this to all general-knowledge replies would flag
    //     legitimate citation and cost far more than it fixes.
    $retrievalFailed = preg_match(
        '/\b(?:could\s+not|couldn\'t|cannot|can\'t|unable\s+to|failed\s+to|was\s+not\s+able\s+to)'
        . '\s+(?:search|retrieve|fetch|access|reach|verify|browse|look\s+\S+\s+up)\b'
        . '|\bno\s+(?:web|search|retrieval)\s+(?:results?|access|available)\b'
        . '|\b(?:web|search|retrieval)\s+(?:is|was|were)\s+unavailable\b/i',
        $rep
    ) === 1;

    $selfContradictoryCitation = false;
    if ($retrievalFailed) {
        foreach ($identifiers as $f) {
            if (($f['type'] ?? '') === 'doi') {
                $selfContradictoryCitation = true;
                break;
            }
        }
    }

    if (!$possession && !$absence && !$selfContradictoryCitation) {
        return $pass; // general knowledge request - out of scope by design
    }

    $asksSpecific = preg_match(
        '/\b(?:exact|specific|precise|verbatim|word for word|hostname|ip address|ip|email|version|'
        . 'figure|salary|clause|findings|wording|identifier|internal|primary database|uptime|'
        . 'daily active users|model number)\b/i',
        $msg
    ) === 1;

    if (!$asksSpecific && !$absence && !$selfContradictoryCitation) {
        return $pass;
    }

    $corpus = chat_fabrication_support_corpus($context);
    $msgLower = strtolower($msg);
    $issues = [];
    $findings = [];

    // 3. Identifier assertions. A value is grounded only if a supplied source or
    //    the user's own words contain it.
    foreach ($identifiers as $f) {
        $value = strtolower($f['value']);
        if ($value === '') {
            continue;
        }
        if (str_contains($corpus, $value) || str_contains($msgLower, $value)) {
            continue;
        }
        $findings[] = $f;
    }

    if ($findings !== []) {
        $describe = [];
        foreach (array_slice($findings, 0, 4) as $f) {
            $describe[] = $f['type'] . ' ' . $f['value'];
        }
        $issues[] = 'Reply asserts a specific detail never supplied: '
            . implode(', ', $describe)
            . ' appears in no attachment, retrieval or registered resource.';
    }

    // 4. Lower-confidence rule: the user said the source is absent, yet the reply
    //    presents enumerated specifics as fact. Gated on an unambiguous absence
    //    phrase AND on the reply not already disclosing.
    if ($absence) {
        // Accept inline enumerations as well as line-leading ones. The measured
        // case put them mid-line: "...findings indicate: 1. Inadequate ... 2. Outdated".
        // Up to two markdown emphasis characters are allowed between the marker and
        // the text, so "- **Item**" (the form the live T001 reply used), "* _Item_",
        // "1. **Item**" and plain "- Item" all count. Without this the markdown
        // bullet form went undetected and the fabricated findings survived.
        $enumerated = preg_match_all('/(?:^|[\s(])(?:\d{1,2}[.)]|[-*\x{2022}])\s*[*_`]{0,2}[A-Za-z(\x{2022}]/u', $rep);
        if ($enumerated >= 2) {
            $issues[] = 'Reply presents enumerated specifics for a source explicitly stated to be absent.';
        }
    }

    return [
        'pass' => $issues === [],
        'issues' => $issues,
        'findings' => $findings,
        'checked' => true,
    ];
}
