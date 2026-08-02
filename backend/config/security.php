<?php

$trustedProxies = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('TRUSTED_PROXIES', '')),
)));

return [
    'trusted_proxies' => $trustedProxies,
    'csp' => [
        'enabled' => (bool) env('SECURITY_CSP_ENABLED', false),
        'upgrade_insecure_requests' => (bool) env('SECURITY_CSP_UPGRADE_INSECURE_REQUESTS', true),
    ],
    'hsts' => [
        'enabled' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', false),
    ],
];
