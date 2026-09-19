<?php

function chat_web_decode_result_url(string $href): ?string {
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '') {
        return null;
    }
    if (str_starts_with($href, '//')) {
        $href = 'https:' . $href;
    }
    if (preg_match('#^https?://(?:html\.)?duckduckgo\.com/l/\?#i', $href) === 1) {
        $parts = parse_url($href);
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParts);
            $decoded = trim((string)($queryParts['uddg'] ?? ''));
            if ($decoded !== '') {
                $href = urldecode($decoded);
            }
        }
    }
    if (!preg_match('#^https?://#i', $href)) {
        return null;
    }
    return $href;
}

function chat_web_fetch_html(string $url, int $timeoutSec = 8): ?array {
    $validation = netpolicy_validate_outbound_url($url, false);
    if (!($validation['ok'] ?? false)) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_USERAGENT => 'LyralinkWebSearch/1.0 (+https://lyralinkai.com)',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.8',
        ],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 400) {
        return null;
    }

    return [
        'url' => $effectiveUrl !== '' ? $effectiveUrl : $url,
        'http_code' => $httpCode,
        'body' => substr((string)$body, 0, 500000),
        'error' => $error !== '' ? $error : null,
    ];
}

function chat_web_extract_text(string $html, int $maxLen = 1400): string {
    $html = preg_replace('#<(script|style|noscript|svg)[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $text = '';

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $bodyNode = $dom->getElementsByTagName('body')->item(0);
        $text = $bodyNode ? (string)$bodyNode->textContent : (string)$dom->textContent;
    }

    if ($text === '') {
        $text = strip_tags($html);
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen - 3) . '...';
    }
    return $text;
}

function chat_web_content_safety(string $content): array {
    $injection = preg_match('/\b(ignore\s+(?:all\s+)?previous\s+instructions|system\s+message|developer\s+message|send\s+your\s+(?:api\s+)?key|reveal\s+the\s+system\s+prompt|run\s+this\s+command|upload\s+(?:your\s+)?credentials)\b/i', $content) === 1;
    return [
        'untrusted' => true,
        'prompt_injection_detected' => $injection,
        'instruction_authority' => 'NONE',
    ];
}

function chat_web_authority_tier(string $host, string $sourceType = ''): int {
    $host = strtolower(trim($host));
    if ($host === '' || $host === 'unknown') return 4;
    if (str_ends_with($host, '.gov') || str_ends_with($host, '.edu')) return 1;
    if ($sourceType === 'official_documentation' || preg_match('/\b(docs?|documentation|developer|api|github)\b/i', $host) === 1) return 1;
    if (preg_match('/\b(reuters|apnews|bbc|nature|arxiv|ieee|acm)\b/i', $host) === 1) return 2;
    if ($sourceType === 'search_result' || $sourceType === 'rss') return 4;
    return 3;
}

function chat_web_parse_rss_items(string $xml, int $limit = 5): array {
    if (!preg_match_all('#<item>(.*?)</item>#si', $xml, $itemMatches, PREG_SET_ORDER)) {
        return [];
    }

    $results = [];
    foreach ($itemMatches as $itemMatch) {
        $itemXml = (string)($itemMatch[1] ?? '');
        if ($itemXml === '') {
            continue;
        }

        preg_match('#<title><!\[CDATA\[(.*?)\]\]></title>|<title>(.*?)</title>#si', $itemXml, $titleMatch);
        preg_match('#<link>(.*?)</link>#si', $itemXml, $linkMatch);
        preg_match('#<description><!\[CDATA\[(.*?)\]\]></description>|<description>(.*?)</description>#si', $itemXml, $descriptionMatch);
        preg_match('#<pubDate>(.*?)</pubDate>#si', $itemXml, $pubDateMatch);

        $titleRaw = (string)(($titleMatch[1] ?? '') !== '' ? $titleMatch[1] : ($titleMatch[2] ?? ''));
        $linkRaw = (string)($linkMatch[1] ?? '');
        $descriptionRaw = (string)(($descriptionMatch[1] ?? '') !== '' ? $descriptionMatch[1] : ($descriptionMatch[2] ?? ''));
        $publishedAt = trim((string)($pubDateMatch[1] ?? ''));

        $title = trim(strip_tags(html_entity_decode($titleRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $url = trim(html_entity_decode($linkRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $description = trim(strip_tags(html_entity_decode($descriptionRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

        if ($title === '' || $url === '') {
            continue;
        }

        $excerpt = $description;
        if ($publishedAt !== '') {
            $excerpt = trim($excerpt . ' Published: ' . $publishedAt . '.');
        }

        $results[] = [
            'title' => $title,
            'url' => $url,
            'host' => $host !== '' ? $host : 'unknown',
            'snippet' => $description,
            'excerpt' => substr($excerpt, 0, 700),
            'fetched' => false,
            'source_type' => 'rss',
            'content_safety' => chat_web_content_safety($excerpt),
            'authority_tier' => chat_web_authority_tier($host, 'rss'),
            'retrieved_at' => gmdate('c'),
            'evidence_state' => 'SEARCH_RESULT',
        ];

        if (count($results) >= max(1, $limit)) {
            break;
        }
    }

    return $results;
}

function chat_web_curated_news_query(string $query, int $limit = 5): array {
    $msg = strtolower(trim($query));
    if ($msg === '' || preg_match('/\b(news|headline|headlines|world news|global news|top stories|breaking)\b/i', $msg) !== 1) {
        return [];
    }

    $feedUrl = 'https://feeds.bbci.co.uk/news/world/rss.xml';
    $feed = chat_web_fetch_html($feedUrl, 6);
    if (!$feed || empty($feed['body'])) {
        return [];
    }

    return chat_web_parse_rss_items((string)$feed['body'], $limit);
}

function chat_web_news_rss_query(string $query, int $limit = 5): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $rssUrl = 'https://news.google.com/rss/search?q=' . rawurlencode($query) . '&hl=en-US&gl=US&ceid=US:en';
    $feed = chat_web_fetch_html($rssUrl, 6);
    if (!$feed || empty($feed['body'])) {
        return [];
    }

    return chat_web_parse_rss_items((string)$feed['body'], $limit);
}

function chat_web_wikipedia_query(string $query, int $limit = 5): array {
    // Wikipedia's API returns JSON, so it needs no HTML scraping and is not
    // affected by the anti-bot challenge that intermittently blocks the
    // DuckDuckGo path. Verified working on this host (HTTP 200, ~300ms).
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $url = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch='
        . rawurlencode($query) . '&format=json&srlimit=' . max(1, min(10, $limit));

    $page = chat_web_fetch_html($url, 8);
    if (!$page || empty($page['body'])) {
        return [];
    }

    $data = json_decode((string)$page['body'], true);
    $hits = $data['query']['search'] ?? [];
    if (!is_array($hits)) {
        return [];
    }

    $results = [];
    foreach ($hits as $hit) {
        $title = trim((string)($hit['title'] ?? ''));
        if ($title === '') {
            continue;
        }
        $snippet = trim(preg_replace('/\\s+/', ' ', strip_tags((string)($hit['snippet'] ?? ''))) ?? '');
        $link = 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title));
        $host = 'en.wikipedia.org';

        $results[] = [
            'title' => $title,
            'url' => $link,
            'host' => $host,
            'snippet' => $snippet,
            'source_type' => 'search_result',
            'content_safety' => chat_web_content_safety($snippet),
            'authority_tier' => chat_web_authority_tier($host, 'search_result'),
            'retrieved_at' => gmdate('c'),
            'evidence_state' => 'SEARCH_RESULT',
        ];
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function chat_web_searxng_query(string $query, int $limit = 6): array {
    // Local SearXNG instance. Aggregates several engines, so a single engine
    // being blocked cannot empty search, and it needs no HTML scraping.
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    $base = rtrim(trim((string)api_get_secret('SEARXNG_BASE_URL', '')), '/');
    if ($base === '') {
        $base = 'http://127.0.0.1:8888';
    }

    $url = $base . '/search?q=' . rawurlencode($query) . '&format=json';

    // Validate with allowLocalHttp = true. The shared chat_web_fetch_html()
    // helper passes false and therefore rejects plain http to 127.0.0.1, which
    // is how SearXNG is served. This exemption is limited to local hosts by
    // netpolicy_is_local_host(), and it is applied here rather than in the
    // shared helper so no other outbound fetch is weakened.
    if (function_exists('netpolicy_validate_outbound_url')) {
        $validation = netpolicy_validate_outbound_url($url, true);
        if (!($validation['ok'] ?? false)) {
            return [];
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'LyralinkWebSearch/1.0 (+https://lyralinkai.com)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 400) {
        return [];
    }

    $data = json_decode((string)$body, true);
    $rows = $data['results'] ?? [];
    if (!is_array($rows)) {
        return [];
    }

    $results = [];
    $seen = [];
    foreach ($rows as $row) {
        $link = trim((string)($row['url'] ?? ''));
        if ($link === '' || isset($seen[$link])) {
            continue;
        }
        $host = strtolower((string)(parse_url($link, PHP_URL_HOST) ?? ''));
        if ($host === '') {
            continue;
        }
        $seen[$link] = true;

        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = $host;
        }
        $snippet = trim(preg_replace('/\s+/', ' ', (string)($row['content'] ?? '')) ?? '');

        $results[] = [
            'title' => $title,
            'url' => $link,
            'host' => $host,
            'snippet' => $snippet,
            'source_type' => 'search_result',
            'content_safety' => chat_web_content_safety($snippet),
            'authority_tier' => chat_web_authority_tier($host, 'search_result'),
            'retrieved_at' => gmdate('c'),
            'evidence_state' => 'SEARCH_RESULT',
        ];
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function chat_web_search_query(string $query, bool $degradedMode = false): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    // Primary source: local SearXNG (multi-engine). Measured 42 results where
    // the raw DuckDuckGo scrape managed 5-6 and intermittently 0.
    $searxResults = chat_web_searxng_query($query, 6);
    if (!empty($searxResults)) {
        return $searxResults;
    }

    $curatedResults = chat_web_curated_news_query($query, $degradedMode ? 3 : 5);
    if (!empty($curatedResults)) {
        return $curatedResults;
    }

    $searchUrl = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query) . '&kl=us-en';
    $searchPage = chat_web_fetch_html($searchUrl, $degradedMode ? 5 : 7);
    if (!$searchPage || empty($searchPage['body'])) {
        // Previously this returned an empty list immediately, so a single
        // blocked scrape silently emptied the whole research request.
        $wikiFallback = chat_web_wikipedia_query($query, 5);
        if (!empty($wikiFallback)) {
            return $wikiFallback;
        }
        $rssFallback = chat_web_news_rss_query($query, 5);
        if (!empty($rssFallback)) {
            return $rssFallback;
        }
        return [];
    }

    $results = [];
    $seen = [];
    $html = (string)$searchPage['body'];

    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//a[contains(@class,'result__a') or contains(@class,'result-link')]");
        if ($nodes) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                $url = chat_web_decode_result_url((string)$node->getAttribute('href'));
                if (!$url) {
                    continue;
                }
                $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
                if ($host === '' || isset($seen[$url]) || str_contains($host, 'duckduckgo.com')) {
                    continue;
                }

                $snippet = '';
                $parent = $node->parentNode;
                while ($parent instanceof DOMElement) {
                    $class = ' ' . strtolower((string)$parent->getAttribute('class')) . ' ';
                    if (str_contains($class, ' result ')) {
                        $snippetNode = $xpath->query(".//*[contains(@class,'result__snippet') or contains(@class,'result-snippet') or contains(@class,'snippet')]", $parent)->item(0);
                        if ($snippetNode) {
                            $snippet = trim(preg_replace('/\s+/', ' ', (string)$snippetNode->textContent) ?? '');
                        }
                        break;
                    }
                    $parent = $parent->parentNode;
                }

                $seen[$url] = true;
                $results[] = [
                    'title' => trim(preg_replace('/\s+/', ' ', (string)$node->textContent) ?? ''),
                    'url' => $url,
                    'host' => $host,
                    'snippet' => $snippet,
                    'source_type' => 'search_result',
                    'content_safety' => chat_web_content_safety($snippet),
                    'authority_tier' => chat_web_authority_tier($host, 'search_result'),
                    'retrieved_at' => gmdate('c'),
                    'evidence_state' => 'SEARCH_RESULT',
                ];
                if (count($results) >= 6) {
                    break;
                }
            }
        }
    }

    if (empty($results) && preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $url = chat_web_decode_result_url((string)($match[1] ?? ''));
            if (!$url) {
                continue;
            }
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host === '' || isset($seen[$url]) || str_contains($host, 'duckduckgo.com')) {
                continue;
            }
            $title = trim(strip_tags(html_entity_decode((string)($match[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($title === '') {
                continue;
            }
            $seen[$url] = true;
            $results[] = [
                'title' => $title,
                'url' => $url,
                'host' => $host,
                'snippet' => '',
                'source_type' => 'search_result',
                'content_safety' => chat_web_content_safety($title),
                'authority_tier' => chat_web_authority_tier($host, 'search_result'),
                'retrieved_at' => gmdate('c'),
                'evidence_state' => 'SEARCH_RESULT',
            ];
            if (count($results) >= 6) {
                break;
            }
        }
    }

    $fetchPages = api_get_secret('CHAT_WEB_FETCH_PAGES', '0') === '1';
    $pageFetchLimit = $fetchPages ? ($degradedMode ? 1 : 2) : 0;
    foreach ($results as $idx => $result) {
        if ($idx >= $pageFetchLimit) {
            $results[$idx]['excerpt'] = (string)($result['snippet'] ?? '');
            $results[$idx]['fetched'] = false;
            continue;
        }

        $page = chat_web_fetch_html((string)$result['url'], $degradedMode ? 5 : 7);
        if ($page && !empty($page['body'])) {
            $results[$idx]['excerpt'] = chat_web_extract_text((string)$page['body']);
            $results[$idx]['url'] = (string)($page['url'] ?? $result['url']);
            $results[$idx]['host'] = strtolower((string)(parse_url((string)$results[$idx]['url'], PHP_URL_HOST) ?? $result['host']));
            $results[$idx]['fetched'] = true;
            $results[$idx]['source_type'] = 'web_page';
            $results[$idx]['content_safety'] = chat_web_content_safety((string)$results[$idx]['excerpt']);
            $results[$idx]['authority_tier'] = chat_web_authority_tier((string)$results[$idx]['host'], 'web_page');
            $results[$idx]['retrieved_at'] = gmdate('c');
            $results[$idx]['evidence_state'] = 'RETRIEVED_PAGE';
        } else {
            $results[$idx]['excerpt'] = (string)($result['snippet'] ?? '');
            $results[$idx]['fetched'] = false;
            $results[$idx]['content_safety'] = chat_web_content_safety((string)$results[$idx]['excerpt']);
        }
    }

    $needsFresh = chat_needs_fresh_web_context($query);
    $usableExcerptCount = 0;
    foreach ($results as $result) {
        if (trim((string)($result['excerpt'] ?? '')) !== '' || trim((string)($result['snippet'] ?? '')) !== '') {
            $usableExcerptCount++;
        }
    }

    if ($needsFresh && ($usableExcerptCount === 0 || count($results) < 2)) {
        $rssResults = chat_web_news_rss_query($query, 5);
        if (!empty($rssResults)) {
            return $rssResults;
        }
    }

    // The "fresh context" fallback above only runs for time-sensitive queries,
    // so an ordinary topical query that parsed nothing returned an empty list.
    // Try the scrape-independent source for every query before giving up.
    if (empty($results) || $usableExcerptCount === 0) {
        $wikiResults = chat_web_wikipedia_query($query, 5);
        if (!empty($wikiResults)) {
            return $wikiResults;
        }
        if (empty($results)) {
            $rssResults = chat_web_news_rss_query($query, 5);
            if (!empty($rssResults)) {
                return $rssResults;
            }
        }
    }

    return $results;
}

function chat_web_search_query_with_status(string $query, bool $degradedMode = false): array {
    $startedAt = microtime(true);
    $query = trim($query);
    if ($query === '') {
        return [
            'results' => [],
            'status' => [
                'started' => false,
                'succeeded' => false,
                'failed' => true,
                'timed_out' => false,
                'result_available' => false,
                'result_verified' => false,
                'duration_ms' => 0,
                'error' => 'empty_query',
            ],
        ];
    }

    $results = chat_web_search_query($query, $degradedMode);
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $hasResults = !empty($results);
    $timeoutBudget = ($degradedMode ? 5000 : 7000) + 800;
    $fetchedCount = 0;
    $parsedCount = 0;
    foreach ($results as $result) {
        if (!empty($result['fetched'])) {
            $fetchedCount++;
        }
        if (trim((string)($result['excerpt'] ?? '')) !== '') {
            $parsedCount++;
        }
    }
    $timedOut = $durationMs >= $timeoutBudget;

    return [
        'results' => $results,
        'status' => [
            'started' => true,
            'succeeded' => $hasResults,
            'failed' => !$hasResults,
            'timed_out' => $timedOut,
            'result_available' => $hasResults,
            'result_verified' => $fetchedCount > 0 && $parsedCount > 0 && !$timedOut,
            'research_state' => $timedOut ? 'SEARCH_FAILED' : ($hasResults ? 'RESULT_FOUND' : 'NO_RESULTS'),
            'source_state' => $fetchedCount === 0 ? 'SOURCE_FETCH_FAILED' : ($parsedCount > 0 ? 'SOURCE_PARSED' : 'SOURCE_FETCHED'),
            'search_state' => $hasResults ? 'SEARCH_SUCCEEDED' : 'SEARCH_FAILED',
            'fetched_count' => $fetchedCount,
            'parsed_count' => $parsedCount,
            'duration_ms' => $durationMs,
            'error' => $timedOut ? 'research_timeout' : ($hasResults ? null : 'no_results_or_fetch_failure'),
        ],
    ];
}

function chat_should_use_web_search(string $message): bool {
    $message = trim($message);
    if ($message === '') {
        return false;
    }
    if (strlen($message) < 3) {
        return false;
    }
    if (chat_is_fast_casual_prompt($message)) {
        return false;
    }
    return true;
}

// Splits a "<thinking>...</thinking>" preamble out of a reply so it can be shown
// separately (as a hoverable thought bubble) instead of in the visible message.
function chat_extract_thinking(?string $reply): array {
    if (!is_string($reply) || trim($reply) === '') {
        return [$reply, null];
    }
    if (preg_match('/<thinking>(.*?)<\/thinking>/is', $reply, $match)) {
        $thinking = trim(strip_tags((string)$match[1]));
        $clean    = trim(str_replace($match[0], '', $reply));
        if ($clean === '') {
            // Don't leave an empty visible reply if the model put everything in the tag
            return [$reply, null];
        }
        return [$clean, $thinking !== '' ? substr($thinking, 0, 600) : null];
    }
    return [$reply, null];
}

function chat_workspace_root_dir(): string {
    $root = realpath(__DIR__ . '/../../..') ?: dirname(__DIR__, 3);
    return $root !== false && $root !== '' ? rtrim($root, DIRECTORY_SEPARATOR) : getcwd();
}

function chat_workspace_is_sensitive_relative_path(string $relativePath): bool {
    $path = preg_replace('/^(?:\.\/)+/', '', str_replace('\\', '/', (string)$relativePath));
    $path = strtolower((string)$path);
    if ($path === '') {
        return false;
    }
    if (preg_match('/(^|\/)(\.env|\.env\..*|\.git|\.gitignore|\.htaccess|\.htpasswd|\.npmrc|\.ssh|\.aws|\.azure|\.vscode|composer\.lock|package-lock\.json|yarn\.lock|pnpm-lock\.yaml|\.venv|vendor|node_modules|storage\/.*(secret|private|key|credential|token)|.*\.(pem|key|p12|pfx|crt|cer))(\/|$)/i', $path) === 1) {
        return true;
    }
    return false;
}

function chat_workspace_read_file(string $path, int $maxBytes = 20000): ?array {
    $workspaceRoot = chat_workspace_root_dir();
    $candidate = realpath((string)$path) ?: (string)$path;
    $rootReal = realpath($workspaceRoot) ?: $workspaceRoot;
    $rootReal = rtrim($rootReal, DIRECTORY_SEPARATOR);
    $candidateReal = rtrim($candidate, DIRECTORY_SEPARATOR);
    if ($candidateReal === '') {
        return null;
    }
    if ($candidateReal !== $rootReal && strncmp($candidateReal . DIRECTORY_SEPARATOR, $rootReal . DIRECTORY_SEPARATOR, strlen($rootReal) + 1) !== 0) {
        return null;
    }
    if (!is_file($candidateReal) || !is_readable($candidateReal)) {
        return null;
    }
    $relative = ltrim(str_replace('\\', '/', str_replace($rootReal, '', $candidateReal)), '/');
    if ($relative === '' || chat_workspace_is_sensitive_relative_path($relative)) {
        return null;
    }

    $content = @file_get_contents($candidateReal);
    if ($content === false || $content === '') {
        return null;
    }
    $text = is_string($content) ? $content : '';
    if ($maxBytes > 0 && strlen($text) > $maxBytes) {
        $text = substr($text, 0, $maxBytes);
        $truncated = true;
    } else {
        $truncated = false;
    }

    return [
        'path' => $candidateReal,
        'relative_path' => $relative,
        'content' => $text,
        'bytes' => strlen($content),
        'truncated' => $truncated,
    ];
}

function chat_workspace_default_context_targets(string $query, string $workspaceRoot): array {
    $root = realpath($workspaceRoot) ?: $workspaceRoot;
    $targets = [
        'README.md',
        'api/chat.php',
        'api/lib/chat/intelligence_layer.php',
        'api/lib/chat/execution_foundation.php',
        'api/lib/chat/conversation_intelligence.php',
        'api/dataset_search.php',
        'api/security.php',
        'cron/auto_maintenance_runner.php',
        'cron/continuous_model_learning.php',
        'pages/admin.php',
        'wiki/Architecture.md',
    ];
    $lowerQuery = strtolower(trim((string)$query));
    if ($lowerQuery === '') {
        return array_values(array_filter(array_map(static fn($target) => $root . '/' . $target, $targets), static fn($p) => is_file($p)));
    }
    $extras = [];
    $keywordMap = [
        'security' => ['api/security.php', 'api/auth.php', 'pages/security.php'],
        'model' => ['api/lib/chat/llm_routing.php', 'api/lib/chat/runtime_core.php'],
        'dataset' => ['api/dataset_search.php', 'api/lib/chat/self_training.php'],
        'learning' => ['api/lib/chat/self_training.php', 'cron/continuous_model_learning.php'],
        'admin' => ['pages/admin.php', 'api/auth.php'],
        'architecture' => ['README.md', 'wiki/Architecture.md', 'api/chat.php'],
    ];
    foreach ($keywordMap as $keyword => $paths) {
        if (str_contains($lowerQuery, (string)$keyword)) {
            foreach ($paths as $path) {
                $full = $root . '/' . $path;
                if (is_file($full)) {
                    $extras[] = $full;
                }
            }
        }
    }
    $selected = array_values(array_unique(array_merge($targets, $extras)));
    return array_values(array_filter(array_map(static fn($target) => $root . '/' . ltrim((string)$target, '/'), $selected), static fn($p) => is_file($p)));
}

function chat_workspace_context_for_query(string $query, string $workspaceRoot, bool $includeDevHint = true): string {
    $root = realpath($workspaceRoot) ?: $workspaceRoot;
    $files = array_slice(chat_workspace_default_context_targets($query, $root), 0, 8);
    $chunks = [];
    foreach ($files as $path) {
        $read = chat_workspace_read_file($path, 8000);
        if (!is_array($read) || empty($read['content'])) {
            continue;
        }
        $text = trim((string)$read['content']);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        if (strlen($text) < 80) {
            continue;
        }
        $chunks[] = "File: " . str_replace($root . '/', '', (string)$read['path']) . "\n" . substr($text, 0, 3500);
    }
    if (empty($chunks)) {
        return "Project files are available for developer review, but no readable source files matched this request.";
    }
    $hint = $includeDevHint
        ? "Use this project file context as read-only evidence. Do not speculate about missing files or leak secrets. Distinguish between what is in the code and what may require runtime or environment verification.\n\n"
        : '';
    return $hint . implode("\n\n---\n\n", array_slice($chunks, 0, 4));
}

function chat_should_include_workspace_context(string $message, bool $isDevUser): bool {
    if (!$isDevUser) {
        return false;
    }
    $msg = strtolower(trim((string)$message));
    if ($msg === '') {
        return false;
    }
    $patterns = [
        '/\b(?:read|inspect|review|analyze|look at|open|scan)\b.*\b(?:project|workspace|codebase|site|repository|app|architecture|system|files?)\b/i',
        '/\b(?:self[- ]?ask|self[- ]?review|self[- ]?analysis|think about yourself|how do you work|how does this system work|explain the architecture|what files matter|how is the app built)\b/i',
        '/\b(?:project|workspace|repository|site|codebase|architecture|root cause|system design|how it works)\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $msg) === 1) {
            return true;
        }
    }
    return false;
}

function chat_extract_reply_section(?string $reply, array $labels): string {
    if (!is_string($reply) || trim($reply) === '') {
        return '';
    }
    foreach ($labels as $label) {
        $pattern = '/(?:^|\n)\s*(?:\*\*|__)?' . preg_quote((string)$label, '/') . '(?:\*\*|__)?\s*(?:[:\-]\s*)?(?:\n+|\s+)(.+?)(?=\n\s*(?:\*\*|__)?[A-Z][A-Za-z ]{1,24}(?:\*\*|__)?\s*(?:[:\-]|\n|$)|\z)/is';
        if (preg_match($pattern, $reply, $match)) {
            $text = trim(strip_tags((string)($match[1] ?? '')));
            $text = preg_replace('/^[\*_`>:\-\s]+/', '', $text) ?? $text;
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            return trim($text);
        }
    }
    return '';
}

function chat_extract_task_items(?string $reply, int $max = 5): array {
    if (!is_string($reply) || trim($reply) === '') {
        return [];
    }
    $items = [];
    $seen = [];
    foreach (preg_split('/\R+/', $reply) as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(?:\*\*|__)?(status|goal|plan|action|next step|summary|past experience)(?:\*\*|__)?\s*(?:[:\-]|$)/i', $line)) {
            continue;
        }
        $clean = preg_replace('/^[-*•]\s+/', '', $line);
        $clean = preg_replace('/^\d+[.)]\s+/', '', (string)$clean);
        $clean = trim(strip_tags((string)$clean));
        if ($clean === '' || strlen($clean) < 4 || strlen($clean) > 180) {
            continue;
        }
        if (preg_match('/^(status|goal|plan|action|next step|summary|past experience)$/i', trim($clean, '*_ '))) {
            continue;
        }
        if (preg_match('/(high-level overview|break this down|here\'?s a|we\'?ll|let\'?s start|in the process of)/i', $clean)) {
            continue;
        }
        if (str_ends_with($clean, ':')) {
            continue;
        }
        $key = strtolower($clean);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $items[] = $clean;
        if (count($items) >= $max) {
            break;
        }
    }
    return $items;
}

function chat_detect_agent_state(?string $reply, bool $taskMode = false): string {
    if (!$taskMode) {
        return 'idle';
    }
    $text = strtolower((string)$reply);
    if ($text === '') {
        return 'planning';
    }
    if (preg_match('/\b(done|completed|finished|resolved|all set|ready to ship|wrapped up)\b/', $text)) {
        return 'done';
    }
    if (preg_match('/\b(waiting|need your|share|send me|once you|when you|before we continue|to proceed)\b/', $text)) {
        return 'waiting';
    }
    if (preg_match('/\b(action|working|implement|execute|next step|starting now|first step)\b/', $text)) {
        return 'working';
    }
    return 'planning';
}

function chat_infer_project_context(?string $reply, bool $taskMode, array $persistentGoals = []): array {
    $tasks = chat_extract_task_items((string)($reply ?? ''), 5);
    $goalTitles = [];
    foreach ($persistentGoals as $goal) {
        if (!is_array($goal)) {
            continue;
        }
        $title = trim((string)($goal['title'] ?? ''));
        if ($title !== '') {
            $goalTitles[] = $title;
        }
    }

    $missionName = $goalTitles[0] ?? '';
    if ($missionName === '' && preg_match('/(?:goal|mission|project)\s*[:\-]\s*([^\n]+)/i', (string)($reply ?? ''), $match)) {
        $missionName = trim((string)$match[1]);
    }
    if ($missionName === '') {
        $missionName = $taskMode ? 'Execution mission' : 'General chat';
    }

    $focus = 'general';
    $lower = strtolower((string)($reply ?? ''));
    if (preg_match('/\b(debug|root cause|fix|error|bug|trace)\b/i', $lower)) {
        $focus = 'debug';
    } elseif (preg_match('/\b(plan|roadmap|strategy|sequence|launch|ship|release)\b/i', $lower)) {
        $focus = 'plan';
    } elseif (preg_match('/\b(build|implement|code|feature|ship|execute|deploy)\b/i', $lower)) {
        $focus = 'build';
    }

    $workspaceMode = $taskMode ? 'mission' : 'chat';
    $executionSignal = $taskMode ? 'project execution' : 'general conversation';

    return [
        'mission' => substr($missionName, 0, 120),
        'focus' => $focus,
        'mode' => $workspaceMode,
        'execution_signal' => $executionSignal,
        'task_count' => count($tasks),
        'goal_count' => count($goalTitles),
    ];
}

function chat_agent_payload(string $reply, bool $taskMode, array $persistentGoals = []): ?array {
    if (!$taskMode) {
        return null;
    }
    $status = chat_detect_agent_state($reply, $taskMode);
    $summary = chat_extract_reply_section($reply, ['Status', 'Goal', 'Summary']);
    $nextStep = chat_extract_reply_section($reply, ['Next Step', 'Action', 'First Step']);
    $tasks = chat_extract_task_items($reply, 5);
    $activeGoalCount = 0;
    foreach ($persistentGoals as $goal) {
        if (is_array($goal) && (($goal['status'] ?? 'active') !== 'done')) {
            $activeGoalCount++;
        }
    }
    if ($summary === '') {
        $summary = $activeGoalCount > 0
            ? $activeGoalCount . ' active saved goals are being tracked.'
            : 'Agent mode is keeping the task moving.';
    }
    if ($nextStep === '' && !empty($tasks)) {
        $nextStep = $tasks[0];
    }
    if ($summary !== '') {
        $summary = preg_split('/(?<=[.!?])\s+/', $summary, 2)[0] ?: $summary;
    }
    if ($nextStep !== '') {
        $nextStep = preg_split('/(?<=[.!?])\s+/', $nextStep, 2)[0] ?: $nextStep;
    }
    if (strlen($summary) > 180) {
        $summary = substr($summary, 0, 177) . '...';
    }
    if (strlen($nextStep) > 180) {
        $nextStep = substr($nextStep, 0, 177) . '...';
    }
    $completionPct = count($persistentGoals) > 0 ? (int)round((($activeGoalCount > 0 ? count($persistentGoals) - $activeGoalCount : count($persistentGoals)) / max(count($persistentGoals), 1)) * 100) : 0;
    $projectContext = chat_infer_project_context($reply, $taskMode, $persistentGoals);
    return [
        'status' => $status,
        'summary' => $summary,
        'next_step' => $nextStep,
        'suggested_tasks' => $tasks,
        'active_goal_count' => $activeGoalCount,
        'completion_pct' => $completionPct,
        'mission' => $projectContext['mission'],
        'focus' => $projectContext['focus'],
        'mode' => $projectContext['mode'],
        'workspace' => [
            'mode' => $projectContext['mode'],
            'execution_signal' => $projectContext['execution_signal'],
            'goal_count' => $projectContext['goal_count'],
            'task_count' => $projectContext['task_count'],
        ],
        'project' => [
            'name' => $projectContext['mission'],
            'focus' => $projectContext['focus'],
            'tasks' => $tasks,
            'goal_count' => $projectContext['goal_count'],
        ],
    ];
}

function chat_is_fast_casual_prompt(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    if (strlen($msg) > 80) {
        return false;
    }
    if (preg_match('/\b(code|debug|error|stack|trace|api|dataset|query|build|deploy|plan|architecture|security|billing|automation|script|sql|php|javascript|python)\b/i', $msg) === 1) {
        return false;
    }
    if (preg_match('/^(ping|pong|health ?check|status ?check)$/i', $msg) === 1) {
        return true;
    }
    return preg_match('/^(hi|hello|hey|yo|sup|what\'s up|hru|how are you|good morning|good afternoon|good evening|wassup|wasss good|what are you up to|what you up to|lol|lmao|your being weird|you\'re being weird|that\'s funny|that\'s cool|that\'s crazy|nice|cool|i\'m bored|im bored|tell me something interesting)/i', $msg) === 1;
}

function chat_should_skip_expensive_context(string $message, bool $taskMode = false): bool {
    $msg = trim((string)$message);
    if ($msg === '' || $taskMode) {
        return false;
    }
    if (chat_is_fast_casual_prompt($msg)) {
        return true;
    }
    $len = strlen($msg);
    if ($len <= 64 && preg_match('/^(hi|hello|hey|yo|sup|thanks|thank you|ok|okay|good morning|good afternoon|good evening|how are you|what\'s up|who are you|what can you do|lol|lmao|your being weird|you\'re being weird|that\'s funny|that\'s cool|nice|cool)$/i', $msg) === 1) {
        return true;
    }
    return false;
}

function chat_message_is_technical(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/\b(code|debug|error|stack|trace|api|dataset|query|build|deploy|plan|architecture|security|billing|automation|script|sql|php|javascript|python|typescript|docker|kubernetes|infra|latency|performance|bug|refactor)\b/i', $msg) === 1;
}

function chat_reasoning_requested(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/\b(show|add|include|explain)\b.{0,32}\b(reasoning|thought process|how you thought|how you reasoned|why)\b|\breasoning\s*mode\b/i', $msg) === 1;
}

function chat_deep_thinking_requested(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/\b(deep think|deep thinking|think deeply|think step by step|long form|thorough|comprehensive|detailed analysis|tradeoffs|reason deeply|full analysis)\b/i', $msg) === 1;
}

function chat_explicit_numbered_request_detected(string $message): bool {
    $msg = trim((string)$message);
    if ($msg === '') {
        return false;
    }

    $explicitPatterns = [
        '/\b(?:give|show|provide|write|list|break down|summarize|analyze|compare)\b.{0,80}\b(?:[2-9]|1[0-9]|2[0-9])\s*(?:points?|reasons?|ideas?|options?|steps?|sections?|parts?)\b/i',
        '/\b(?:part|section)\s*(?:[1-9]|1[0-9]|2[0-9])\s*(?:of|out of)\s*(?:[2-9]|1[0-9]|2[0-9])\b/i',
        '/\b(?:[2-9]|1[0-9]|2[0-9])\s*(?:[- ]parts?|[- ]sections?|[- ]steps?|[- ]points?|[- ]reasons?|[- ]ideas?)\b/i',
        '/\b(?:step\s*by\s*step|full\s*(?:architecture|roadmap|plan|breakdown|analysis|system|guide))\b/i',
    ];

    foreach ($explicitPatterns as $pattern) {
        if (preg_match($pattern, $msg) === 1) {
            return true;
        }
    }

    return false;
}

function chat_needs_fresh_web_context(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/\b(today|latest|current|right now|breaking|news|recent|this week|this month|new version|release notes|price today|status today|uptime now)\b/i', $msg) === 1;
}

function chat_expand_requested(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/^(expand|go deeper|more detail|elaborate|full answer|full version|detailed version)\b/i', $msg) === 1;
}

function chat_conversation_flow_score(array $messages, string $latestUserMsg, bool $taskMode = false): array {
    $score = 0;
    $signals = [];

    $latest = strtolower(trim($latestUserMsg));
    $latestTokens = chat_estimate_text_tokens($latestUserMsg);
    $turns = count($messages);
    $contextTokens = chat_estimate_messages_tokens($messages, 10);

    if ($taskMode) {
        $score += 2;
        $signals[] = 'task_mode';
    }
    if ($turns >= 6) {
        $score += 1;
        $signals[] = 'long_thread';
    }
    if ($turns >= 10) {
        $score += 1;
        $signals[] = 'very_long_thread';
    }
    if ($contextTokens >= 650) {
        $score += 2;
        $signals[] = 'high_context_tokens';
    }
    if ($latestTokens >= 90) {
        $score += 2;
        $signals[] = 'long_user_turn';
    }
    if (chat_message_is_technical($latestUserMsg)) {
        $score += 2;
        $signals[] = 'technical_topic';
    }
    if (preg_match('/\b(compare|trade.?off|pros? and cons?|architecture|design|strategy|root cause|why|how)\b/i', $latest) === 1) {
        $score += 1;
        $signals[] = 'analysis_prompt';
    }
    if (preg_match('/\b(this|that|it|above|earlier|before|as discussed|based on that)\b/i', $latest) === 1 && $turns >= 4) {
        $score += 1;
        $signals[] = 'cross_turn_reference';
    }

    return [
        'score' => $score,
        'latest_tokens' => $latestTokens,
        'context_tokens' => $contextTokens,
        'turns' => $turns,
        'signals' => $signals,
    ];
}

function chat_make_concise_reply(string $reply, int $maxChars = 520): string {
    $text = trim($reply);
    if ($text === '' || strlen($text) <= $maxChars) {
        return $text;
    }

    $isListHeavy = preg_match('/(?:^|\n)\s*(?:[-*+]|\d+[.)])\s+/m', $text) === 1;
    $isParagraphHeavy = preg_match('/(?:^|\n\n)\s*[^\n]{80,}(?:\n\n|$)/', $text) === 1;

    if (($isListHeavy || $isParagraphHeavy) && strlen($text) > ($maxChars * 1.9)) {
        return $text;
    }

    $parts = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    $compact = '';
    foreach ($parts as $part) {
        $candidate = trim($compact === '' ? $part : ($compact . ' ' . $part));
        if (strlen($candidate) > $maxChars) {
            break;
        }
        $compact = $candidate;
        if (count($parts) <= 3 && substr_count($compact, '.') + substr_count($compact, '!') + substr_count($compact, '?') >= 1) {
            break;
        }
    }

    if ($compact !== '') {
        return trim($compact);
    }

    $wordBoundary = preg_match('/^(.{0,' . $maxChars . '}?)\b/su', $text, $match) === 1 ? trim($match[1]) : '';
    if ($wordBoundary !== '') {
        $tail = preg_replace('/[\s\r\n]+$/u', '', $wordBoundary);
        if ($tail !== '') {
            return rtrim($tail) . '...';
        }
    }

    return trim(substr($text, 0, $maxChars));
}

function chat_full_system_scan_requested(string $message): bool {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return false;
    }
    return preg_match('/\b(full|complete|entire)?\s*(system|server|platform)\s*(scan|diagnostic|health check|audit)\b|\brun\s+(a\s+)?full\s+scan\b/i', $msg) === 1;
}

function chat_read_proc_meminfo(): array {
    $result = ['mem_total' => null, 'mem_available' => null, 'swap_total' => null, 'swap_free' => null];
    $path = '/proc/meminfo';
    if (!is_readable($path)) {
        return $result;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return $result;
    }
    if (preg_match('/^MemTotal:\s+(\d+)\s+kB/im', $raw, $m)) {
        $result['mem_total'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/im', $raw, $m)) {
        $result['mem_available'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^SwapTotal:\s+(\d+)\s+kB/im', $raw, $m)) {
        $result['swap_total'] = (int)$m[1] * 1024;
    }
    if (preg_match('/^SwapFree:\s+(\d+)\s+kB/im', $raw, $m)) {
        $result['swap_free'] = (int)$m[1] * 1024;
    }
    return $result;
}

function chat_bytes_human(?int $bytes): ?string {
    if ($bytes === null || $bytes < 0) {
        return null;
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
}

function chat_scan_directory_snapshot(string $path, int $maxItems = 2000): array {
    $snapshot = [
        'path' => $path,
        'exists' => file_exists($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
        'files' => 0,
        'directories' => 0,
        'truncated' => false,
    ];
    if (!$snapshot['exists'] || !is_dir($path) || !$snapshot['readable']) {
        return $snapshot;
    }
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        $seen = 0;
        foreach ($iter as $item) {
            if ($item->isDir()) {
                $snapshot['directories']++;
            } else {
                $snapshot['files']++;
            }
            $seen++;
            if ($seen >= $maxItems) {
                $snapshot['truncated'] = true;
                break;
            }
        }
    } catch (Throwable $e) {
        $snapshot['error'] = $e->getMessage();
    }
    return $snapshot;
}

function chat_collect_system_scan(mysqli $db, string $workspaceRoot): array {
    $now = gmdate('c');
    $memInfo = chat_read_proc_meminfo();
    $uptimeSeconds = null;
    if (is_readable('/proc/uptime')) {
        $uptimeRaw = trim((string)@file_get_contents('/proc/uptime'));
        if ($uptimeRaw !== '') {
            $parts = preg_split('/\s+/', $uptimeRaw);
            if (!empty($parts[0]) && is_numeric($parts[0])) {
                $uptimeSeconds = (int)floor((float)$parts[0]);
            }
        }
    }

    $diskTotal = @disk_total_space($workspaceRoot);
    $diskFree = @disk_free_space($workspaceRoot);
    $diskUsedPct = (is_numeric($diskTotal) && $diskTotal > 0 && is_numeric($diskFree))
        ? round((1 - ($diskFree / $diskTotal)) * 100, 2)
        : null;

    $tableCount = function (string $table) use ($db): ?int {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        if ($safe === '') {
            return null;
        }
        $sql = "SELECT COUNT(*) AS c FROM `{$safe}`";
        $res = @$db->query($sql);
        if (!$res) {
            return null;
        }
        $row = $res->fetch_assoc();
        return isset($row['c']) ? (int)$row['c'] : null;
    };

    $paths = [
        'root' => $workspaceRoot,
        'api' => $workspaceRoot . '/api',
        'assets' => $workspaceRoot . '/assets',
        'storage' => $workspaceRoot . '/storage',
        'cron' => $workspaceRoot . '/cron',
        'vendor' => $workspaceRoot . '/vendor',
        'desktop_web' => $workspaceRoot . '/desktop/windows-client/web',
    ];

    $pathSnapshots = [];
    foreach ($paths as $label => $path) {
        $limit = $label === 'vendor' ? 1200 : 2000;
        $pathSnapshots[$label] = chat_scan_directory_snapshot($path, $limit);
    }

    $warnings = [];
    if (is_numeric($diskUsedPct) && $diskUsedPct >= 90) {
        $warnings[] = 'Disk usage is above 90%.';
    }
    if (!empty($memInfo['mem_total']) && !empty($memInfo['mem_available'])) {
        $freePct = (float)$memInfo['mem_available'] / (float)$memInfo['mem_total'] * 100;
        if ($freePct < 10) {
            $warnings[] = 'Available system RAM is below 10%.';
        }
    }
    foreach (['storage', 'api'] as $criticalPath) {
        if (!($pathSnapshots[$criticalPath]['writable'] ?? false)) {
            $warnings[] = strtoupper($criticalPath) . ' path is not writable.';
        }
    }
    if ($db->connect_error) {
        $warnings[] = 'Database connection failed during scan.';
    }

    $scan = [
        'generated_at_utc' => $now,
        'host' => [
            'hostname' => php_uname('n'),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'machine' => php_uname('m'),
            'server_name' => $_SERVER['SERVER_NAME'] ?? null,
            'server_addr' => $_SERVER['SERVER_ADDR'] ?? null,
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
            'uptime_seconds' => $uptimeSeconds,
        ],
        'runtime' => [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'display_errors' => ini_get('display_errors'),
            'extensions_loaded' => count(get_loaded_extensions()),
            'required_extensions' => [
                'curl' => extension_loaded('curl'),
                'mysqli' => extension_loaded('mysqli'),
                'json' => extension_loaded('json'),
                'openssl' => extension_loaded('openssl'),
                'mbstring' => extension_loaded('mbstring'),
            ],
            'php_process_memory' => [
                'usage_bytes' => memory_get_usage(true),
                'usage_human' => chat_bytes_human(memory_get_usage(true)),
                'peak_bytes' => memory_get_peak_usage(true),
                'peak_human' => chat_bytes_human(memory_get_peak_usage(true)),
            ],
        ],
        'memory' => [
            'total_bytes' => $memInfo['mem_total'],
            'available_bytes' => $memInfo['mem_available'],
            'total_human' => chat_bytes_human($memInfo['mem_total']),
            'available_human' => chat_bytes_human($memInfo['mem_available']),
            'swap_total_human' => chat_bytes_human($memInfo['swap_total']),
            'swap_free_human' => chat_bytes_human($memInfo['swap_free']),
        ],
        'filesystem' => [
            'workspace_root' => $workspaceRoot,
            'disk_total_bytes' => is_numeric($diskTotal) ? (int)$diskTotal : null,
            'disk_free_bytes' => is_numeric($diskFree) ? (int)$diskFree : null,
            'disk_total_human' => is_numeric($diskTotal) ? chat_bytes_human((int)$diskTotal) : null,
            'disk_free_human' => is_numeric($diskFree) ? chat_bytes_human((int)$diskFree) : null,
            'disk_used_percent' => $diskUsedPct,
            'paths' => $pathSnapshots,
        ],
        'database' => [
            'connected' => !$db->connect_error,
            'connect_error' => $db->connect_error ?: null,
            'server_info' => !$db->connect_error ? $db->server_info : null,
            'host_info' => !$db->connect_error ? $db->host_info : null,
            'tables' => !$db->connect_error ? [
                'users' => $tableCount('users'),
                'conversations' => $tableCount('conversations'),
                'dataset' => $tableCount('dataset'),
                'ai_request_rate_limits' => $tableCount('ai_request_rate_limits'),
            ] : null,
        ],
        'llm' => [
            'default_provider' => strtolower((string)api_get_secret('LLM_PROVIDER', 'local')),
            'default_model' => trim((string)api_get_secret('LOCAL_LLM_MODEL', api_get_secret('LLM_MODEL', 'lyralink-auto-canary:latest'))),
            'speed_priority_local' => api_get_secret('LOCAL_LLM_SPEED_PRIORITY', '1') === '1',
            'full_reply_mode' => api_get_secret('LOCAL_LLM_FULL_REPLY_MODE', '1') === '1',
            'reply_temperature_default' => (float)api_get_secret('CHAT_REPLY_TEMPERATURE', '0.82'),
            'provider_available' => [
                'local' => llm_provider_available('local'),
                'openrouter' => llm_provider_available('openrouter'),
                'openai' => llm_provider_available('openai'),
                'groq' => llm_provider_available('groq'),
            ],
            'secret_presence' => [
                'openai_api_key' => api_get_secret('OPENAI_API_KEY', '') !== '',
                'openrouter_api_key' => api_get_secret('OPENROUTER_API_KEY', '') !== '',
                'groq_api_key' => api_get_secret('GROQ_API_KEY', '') !== '',
            ],
        ],
        'security' => [
            'app_debug' => api_get_secret('APP_DEBUG', '0') === '1',
            'safeguards_loaded' => function_exists('ai_safeguards_analyze_input') && function_exists('ai_safeguards_finalize_reply'),
            'rate_limit_table_accessible' => !$db->connect_error ? ($tableCount('ai_request_rate_limits') !== null) : false,
        ],
        'tooling' => [
            'docker_available' => is_docker_available(),
        ],
        'warnings' => $warnings,
        'warning_count' => count($warnings),
    ];

    return $scan;
}

function chat_format_system_scan_reply(array $scan): string {
    $lines = [];
    $lines[] = 'Full system scan complete. Here is the precise snapshot:';
    $lines[] = '';
    $lines[] = '1) Host & Runtime';
    $lines[] = '- Host: ' . (($scan['host']['hostname'] ?? 'unknown') ?: 'unknown') . ' (' . (($scan['host']['os'] ?? 'unknown') ?: 'unknown') . ')';
    $lines[] = '- PHP: ' . (($scan['runtime']['php_version'] ?? 'unknown') ?: 'unknown') . ' via ' . (($scan['runtime']['sapi'] ?? 'unknown') ?: 'unknown');
    $lines[] = '- Uptime: ' . (isset($scan['host']['uptime_seconds']) ? (int)$scan['host']['uptime_seconds'] . 's' : 'unavailable');
    $lines[] = '';
    $lines[] = '2) Resources';
    $lines[] = '- Disk free: ' . (($scan['filesystem']['disk_free_human'] ?? 'unknown') ?: 'unknown') . ' / ' . (($scan['filesystem']['disk_total_human'] ?? 'unknown') ?: 'unknown') . ' (used ' . (($scan['filesystem']['disk_used_percent'] ?? 'n/a')) . '%)';
    $lines[] = '- RAM available: ' . (($scan['memory']['available_human'] ?? 'unknown') ?: 'unknown') . ' / ' . (($scan['memory']['total_human'] ?? 'unknown') ?: 'unknown');
    $lines[] = '- PHP process peak memory: ' . (($scan['runtime']['php_process_memory']['peak_human'] ?? 'unknown') ?: 'unknown');
    $lines[] = '';
    $lines[] = '3) Database';
    $lines[] = '- Connected: ' . ((!empty($scan['database']['connected'])) ? 'yes' : 'no');
    if (!empty($scan['database']['tables']) && is_array($scan['database']['tables'])) {
        foreach ($scan['database']['tables'] as $table => $count) {
            $lines[] = '- Rows in ' . $table . ': ' . (($count === null) ? 'unavailable' : (string)$count);
        }
    }
    $lines[] = '';
    $lines[] = '4) LLM & Safety';
    $lines[] = '- Default model route: ' . (($scan['llm']['default_provider'] ?? 'unknown') ?: 'unknown') . ' / ' . (($scan['llm']['default_model'] ?? 'unknown') ?: 'unknown');
    $lines[] = '- Safeguards loaded: ' . ((!empty($scan['security']['safeguards_loaded'])) ? 'yes' : 'no');
    $lines[] = '- Docker available: ' . ((!empty($scan['tooling']['docker_available'])) ? 'yes' : 'no');

    if (!empty($scan['warnings']) && is_array($scan['warnings'])) {
        $lines[] = '';
        $lines[] = '5) Warnings';
        foreach ($scan['warnings'] as $warning) {
            $lines[] = '- ' . $warning;
        }
    } else {
        $lines[] = '';
        $lines[] = '5) Warnings';
        $lines[] = '- None detected in this scan.';
    }

    $lines[] = '';
    $lines[] = 'Raw structured details are attached in system_scan for full inspection.';
    return implode("\n", $lines);
}

function chat_build_reasoning_summary(
    string $latestUserMsg,
    string $reply,
    ?string $thinkingText,
    array $trace,
    array $replySafety,
    string $provider,
    string $model,
    int $replyTokenBudget,
    int $trimmedCount,
    int $datasetMatchCount
): array {
    $steps = [];
    foreach ($trace as $entry) {
        $stage = trim((string)($entry['stage'] ?? ''));
        $msg = trim((string)($entry['message'] ?? ''));
        if ($stage === '' || $msg === '') {
            continue;
        }
        $steps[] = strtoupper($stage) . ': ' . $msg;
        if (count($steps) >= 8) {
            break;
        }
    }
    $reasoningText = null;
    if (is_string($thinkingText) && trim($thinkingText) !== '') {
        $reasoningText = trim($thinkingText);
    } else {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($reply)) ?: [];
        $reasoningText = implode(' ', array_slice($sentences, 0, 2));
        if (strlen($reasoningText) > 320) {
            $reasoningText = substr($reasoningText, 0, 317) . '...';
        }
    }

    return [
        'requested' => true,
        'intent' => trim($latestUserMsg),
        'summary' => $reasoningText,
        'decision_path' => $steps,
        'model_route' => [
            'provider' => $provider,
            'model' => $model,
            'reply_token_budget' => $replyTokenBudget,
            'messages_trimmed' => $trimmedCount,
            'dataset_matches' => $datasetMatchCount,
        ],
        'safety' => [
            'flags' => $replySafety['flags'] ?? [],
            'redactions' => $replySafety['redactions'] ?? [],
        ],
    ];
}

function chat_local_instant_greeting_reply(string $message): ?string {
    $msg = strtolower(trim($message));
    if ($msg === '') {
        return null;
    }
    if (preg_match('/^(ping|pong|health ?check|status ?check)$/i', $msg) === 1) {
        return "Pong. Chat is up and ready.";
    }
    if (preg_match('/\b(how are you|hru|how\'re you)\b/i', $msg) === 1) {
        return "Doing great, thanks for asking. I'm ready to help.";
    }
    if (preg_match('/\b(what are you up to|what you up to|wyd|up to right now)\b/i', $msg) === 1) {
        return "Helping users and ready for your next task right now.";
    }
    if (preg_match('/\b(hi|hello|hey|yo|sup|wassup|wasss good)\b/i', $msg) === 1) {
        return "Hey, good to see you. What can I help you with?";
    }
    return null;
}

function llm_message_content_text($content): string {
    if (is_string($content)) {
        return $content;
    }
    if (!is_array($content)) {
        return '';
    }
    $parts = [];
    foreach ($content as $item) {
        if (is_string($item)) {
            $parts[] = $item;
            continue;
        }
        if (!is_array($item)) {
            continue;
        }
        $type = strtolower((string)($item['type'] ?? ''));
        if ($type === 'text') {
            $parts[] = (string)($item['text'] ?? '');
        } elseif ($type === 'image_url') {
            $parts[] = '[image attached]';
        }
    }
    return trim(implode("\n", array_filter($parts, fn($v) => trim((string)$v) !== '')));
}

function chat_estimate_text_tokens(string $text): int {
    $len = strlen(trim($text));
    if ($len <= 0) {
        return 1;
    }
    return max(1, (int)ceil($len / 4));
}

function chat_estimate_messages_tokens(array $messages, int $tailCount = 8): int {
    if (empty($messages)) {
        return 0;
    }
    $slice = array_slice($messages, -max(1, $tailCount));
    $total = 0;
    foreach ($slice as $msg) {
        if (!is_array($msg)) {
            continue;
        }
        $content = llm_message_content_text($msg['content'] ?? '');
        $total += chat_estimate_text_tokens($content) + 4;
    }
    return $total;
}

