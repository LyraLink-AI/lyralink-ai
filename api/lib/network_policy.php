<?php

if (!function_exists('netpolicy_is_local_host')) {
    function netpolicy_is_local_host(string $host): bool {
        $host = strtolower(trim($host));
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return true;
        }
        if (str_ends_with($host, '.localhost')) {
            return true;
        }
        return false;
    }
}

if (!function_exists('netpolicy_is_private_ip')) {
    function netpolicy_is_private_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $low = strtolower($ip);
            if ($low === '::1') {
                return true;
            }
            return str_starts_with($low, 'fc') || str_starts_with($low, 'fd') || str_starts_with($low, 'fe80:');
        }
        return false;
    }
}

if (!function_exists('netpolicy_validate_outbound_url')) {
    function netpolicy_validate_outbound_url(string $url, bool $allowLocalHttp = true): array {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'error' => 'URL is empty'];
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'Invalid URL'];
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return ['ok' => false, 'error' => 'URLs with embedded credentials are not allowed'];
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = strtolower((string)$parts['host']);

        if (!in_array($scheme, ['https', 'http'], true)) {
            return ['ok' => false, 'error' => 'Only https URLs are allowed'];
        }

        if ($scheme === 'http' && !($allowLocalHttp && netpolicy_is_local_host($host))) {
            return ['ok' => false, 'error' => 'Insecure http URL is not allowed'];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) && netpolicy_is_private_ip($host) && !netpolicy_is_local_host($host)) {
            return ['ok' => false, 'error' => 'Private network IP targets are not allowed'];
        }

        return ['ok' => true, 'error' => null];
    }
}
