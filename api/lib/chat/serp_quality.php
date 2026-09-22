<?php
/**
 * SERP result quality filtering.
 *
 * Measured problem (2026-09-21): a technical query returned a TrustedTech Bing ad,
 * duckduckgo.com/y.js ad redirects, entirely unrelated news homepages (Fox, CNN,
 * NBC, AP, BBC, NPR) and several links that were google.com/goto?url=... wrappers
 * rather than real URLs. Because the model is handed these candidates it can cite
 * an advertisement or a news homepage as a source, which is how a mis-attributed
 * "source" answer is produced.
 *
 * This layer is deterministic and explainable: it unwraps redirect wrappers when
 * the real target is recoverable, drops unfollowable wrappers and ad/tracker hosts,
 * de-duplicates pages, caps results per host, and ranks the remainder using query
 * term overlap, homepage penalty and the existing authority tier. Every removal is
 * reported with a reason so the pipeline can be audited rather than trusted.
 */

if (!function_exists('chat_serp_quality_config')) {
    function chat_serp_quality_config(): array {
        $env = static function (string $key, string $default): string {
            $value = getenv($key);
            return (is_string($value) && trim($value) !== '') ? trim($value) : $default;
        };

        return [
            'max_per_host' => max(1, (int)$env('LYRALINK_SERP_MAX_PER_HOST', '3')),
            // A general news front page is noise for a technical query and was
            // observed being cited as a source. Non-homepage articles are kept.
            'drop_news_homepages' => $env('LYRALINK_SERP_DROP_NEWS_HOMEPAGES', '1') === '1',
            // Ad, tracker and syndication hosts. Never a useful citation.
            'ad_hosts' => $env(
                'LYRALINK_SERP_AD_HOSTS',
                'doubleclick.net,googlesyndication.com,googleadservices.com,adservice.google.com,'
                . 'pagead2.googlesyndication.com,adnxs.com,criteo.com,criteo.net,outbrain.com,'
                . 'taboola.com,zedo.com,adform.net,adroll.com,quantserve.com,scorecardresearch.com,'
                . 'moatads.com,amazon-adsystem.com,adsystem.com,adsafeprotected.com,'
                . 'yandex.ru/ads,ads.yahoo.com,advertising.com,pubmatic.com,rubiconproject.com'
            ),
            // Search-engine internal URLs. Useful only if the real target unwraps.
            'wrapper_hosts' => $env(
                'LYRALINK_SERP_WRAPPER_HOSTS',
                'www.google.com,google.com,duckduckgo.com,l.duckduckgo.com,html.duckduckgo.com,'
                . 'www.bing.com,bing.com,search.yahoo.com,r.search.yahoo.com,yandex.com,'
                . 'www.startpage.com,search.marginalia.nu,www.ecosia.org'
            ),
            // General news outlets: legitimate sources for news, noise for technical
            // queries. Only demoted, never dropped, and only for technical queries.
            'generic_news_hosts' => $env(
                'LYRALINK_SERP_GENERIC_NEWS_HOSTS',
                'foxnews.com,cnn.com,nbcnews.com,apnews.com,bbc.com,bbc.co.uk,npr.org,'
                . 'abcnews.go.com,cbsnews.com,news.google.com,usatoday.com,dailymail.co.uk,'
                . 'nypost.com,huffpost.com,news.yahoo.com,msn.com'
            ),
        ];
    }
}

if (!function_exists('chat_serp_host_list')) {
    /** Parse a comma-separated host list from config into a lookup set. */
    function chat_serp_host_list(string $csv): array {
        $out = [];
        foreach (explode(',', $csv) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $out[$host] = true;
            }
        }
        return $out;
    }
}

if (!function_exists('chat_serp_host_matches')) {
    /**
     * Does $host match an entry in a configured host list?
     *
     * Exact match is not enough: it misses the common "www." variant, which is how
     * the news-homepage rule silently did nothing in its first test run (the list
     * held foxnews.com while the URL host was www.foxnews.com). A configured domain
     * therefore covers its own subdomains.
     */
    function chat_serp_host_matches(array $hostSet, string $host): bool {
        $host = strtolower(trim($host));
        if ($host === '' || $hostSet === []) {
            return false;
        }
        if (isset($hostSet[$host])) {
            return true;
        }
        foreach ($hostSet as $candidate => $_) {
            $candidate = (string)$candidate;
            if ($candidate !== '' && substr($host, -strlen($candidate) - 1) === '.' . $candidate) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('chat_serp_unwrap_url')) {
    /**
     * Recover the real target from a redirect wrapper.
     *
     * google.com/goto?url=..., google.com/url?q=..., duckduckgo.com/l/?uddg=...
     * all carry the destination in a query parameter. When the parameter is not a
     * usable absolute URL we return the input unchanged; the caller then treats the
     * search-engine host as an unfollowable wrapper and drops it. We never guess.
     */
    function chat_serp_unwrap_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }

        parse_str((string)$parts['query'], $params);
        if (!is_array($params)) {
            return $url;
        }

        foreach (['url', 'q', 'u', 'uddg', 'target', 'dest', 'destination', 'redirect', 'r'] as $key) {
            if (empty($params[$key]) || !is_string($params[$key])) {
                continue;
            }
            $candidate = trim($params[$key]);
            if (preg_match('#^https?://#i', $candidate) === 1) {
                return $candidate;
            }
            $decoded = trim(rawurldecode($candidate));
            if (preg_match('#^https?://#i', $decoded) === 1) {
                return $decoded;
            }
        }

        return $url;
    }
}

if (!function_exists('chat_serp_classify_url')) {
    /**
     * Decide whether a result URL may be handed to the model.
     * Returns ['drop' => bool, 'reason' => string, 'host' => string, 'unwrapped' => string].
     */
    function chat_serp_classify_url(string $url, ?array $config = null): array {
        $config = $config ?? chat_serp_quality_config();
        $unwrapped = chat_serp_unwrap_url($url);

        if ($unwrapped === '') {
            return ['drop' => true, 'reason' => 'missing_url', 'host' => '', 'unwrapped' => ''];
        }
        if (preg_match('#^https?://#i', $unwrapped) !== 1) {
            return ['drop' => true, 'reason' => 'not_absolute_url', 'host' => '', 'unwrapped' => $unwrapped];
        }

        $parts = parse_url($unwrapped);
        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return ['drop' => true, 'reason' => 'unparsable_host', 'host' => '', 'unwrapped' => $unwrapped];
        }

        if (chat_serp_host_matches(chat_serp_host_list($config['ad_hosts']), $host)) {
            return ['drop' => true, 'reason' => 'ad_or_tracker_host', 'host' => $host, 'unwrapped' => $unwrapped];
        }

        // Still on a search-engine host after unwrapping means we could not recover
        // the destination, so the URL is not citable.
        if (chat_serp_host_matches(chat_serp_host_list($config['wrapper_hosts']), $host)) {
            return ['drop' => true, 'reason' => 'redirect_wrapper_unresolvable', 'host' => $host, 'unwrapped' => $unwrapped];
        }

        return ['drop' => false, 'reason' => '', 'host' => $host, 'unwrapped' => $unwrapped];
    }
}

if (!function_exists('chat_serp_quality_filter')) {
    /**
     * Filter and rank SERP results.
     *
     * Returns ['results' => array, 'dropped' => array, 'stats' => array].
     * $opts: ['query' => string, 'limit' => int]
     */
    function chat_serp_quality_filter(array $results, array $opts = []): array {
        $config = chat_serp_quality_config();
        $query = strtolower(trim((string)($opts['query'] ?? '')));
        $limit = max(0, (int)($opts['limit'] ?? 0));

        $queryWords = [];
        foreach ((array)preg_split('/[^a-z0-9]+/', $query) as $word) {
            $word = (string)$word;
            if (strlen($word) >= 3) {
                $queryWords[$word] = true;
            }
        }

        $technicalQuery = preg_match(
            '/\b(version|versions|release|released|api|sdk|documentation|docs|library|package|'
            . 'deprecat\w*|changelog|licen[cs]e|sql|php|python|docker|install|config|configur\w*|'
            . 'benchmark|error|bug|patch)\b/',
            $query
        ) === 1;

        $newsHosts = chat_serp_host_list($config['generic_news_hosts']);
        $maxPerHost = (int)$config['max_per_host'];

        $kept = [];
        $dropped = [];
        $seenUrl = [];
        $seenPage = [];
        $perHost = [];

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rawUrl = trim((string)($row['url'] ?? ''));
            $classified = chat_serp_classify_url($rawUrl, $config);
            if ($classified['drop']) {
                $dropped[] = ['url' => $rawUrl, 'reason' => $classified['reason']];
                continue;
            }

            $url = $classified['unwrapped'];
            $host = $classified['host'];
            $parts = parse_url($url);
            $path = strtolower((string)($parts['path'] ?? ''));
            $isHomepage = ($path === '' || $path === '/');

            if ($isHomepage && $technicalQuery && chat_serp_host_matches($newsHosts, $host) && !empty($config['drop_news_homepages'])) {
                $dropped[] = ['url' => $rawUrl, 'reason' => 'irrelevant_news_homepage_for_technical_query'];
                continue;
            }

            $urlKey = strtolower($url);
            if (isset($seenUrl[$urlKey])) {
                $dropped[] = ['url' => $rawUrl, 'reason' => 'duplicate_url'];
                continue;
            }
            // Same page reached through different tracking parameters.
            $pageKey = $host . '|' . rtrim($path, '/');
            if (isset($seenPage[$pageKey])) {
                $dropped[] = ['url' => $rawUrl, 'reason' => 'duplicate_page'];
                continue;
            }

            $hostCount = (int)($perHost[$host] ?? 0);
            if ($hostCount >= $maxPerHost) {
                $dropped[] = ['url' => $rawUrl, 'reason' => 'host_cap_reached'];
                continue;
            }

            $seenUrl[$urlKey] = true;
            $seenPage[$pageKey] = true;
            $perHost[$host] = $hostCount + 1;

            $text = strtolower((string)($row['title'] ?? '') . ' ' . (string)($row['snippet'] ?? ''));
            $score = 0;
            foreach (array_keys($queryWords) as $word) {
                if (strpos($text, (string)$word) !== false) {
                    $score += 2;
                }
            }

            if ($isHomepage) {
                $score -= 4;
            }
            if ($technicalQuery && chat_serp_host_matches($newsHosts, $host)) {
                $score -= 6;
            }
            if (isset($row['authority_tier']) && is_numeric($row['authority_tier'])) {
                $score += max(0, 5 - (int)$row['authority_tier']) * 2;
            }
            if (!empty($row['fetched'])) {
                $score += 2;
            }

            $row['url'] = $url;
            $row['host'] = $host;
            $row['serp_score'] = $score;
            $kept[] = $row;
        }

        usort($kept, static function ($a, $b): int {
            return ((int)($b['serp_score'] ?? 0)) <=> ((int)($a['serp_score'] ?? 0));
        });

        if ($limit > 0 && count($kept) > $limit) {
            foreach (array_slice($kept, $limit) as $extra) {
                $dropped[] = ['url' => (string)($extra['url'] ?? ''), 'reason' => 'over_limit'];
            }
            $kept = array_slice($kept, 0, $limit);
        }

        return [
            'results' => $kept,
            'dropped' => $dropped,
            'stats' => [
                'input' => count($results),
                'kept' => count($kept),
                'dropped' => count($dropped),
                'technical_query' => $technicalQuery,
            ],
        ];
    }
}
