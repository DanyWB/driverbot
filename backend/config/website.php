<?php

$demo = (bool) env('WEBSITE_DEMO', false);
$demoPolicies = $demo ? array_fill_keys(['en', 'ru', 'uk'], 'DEMO ONLY. Preview catalog, sample availability and test requests; no live rental, payment or customer contact takes place. These demonstration terms are not production legal terms.') : [];

return [
    'enabled' => (bool) env('WEBSITE_ENABLED', false),
    'domain' => env('WEBSITE_DOMAIN'),
    'url' => env('WEBSITE_URL'),
    'name' => 'Drive Phangan',
    'demo' => $demo,
    'block_outbound' => (bool) env('WEBSITE_BLOCK_OUTBOUND', false),
    'indexable' => (bool) env('WEBSITE_INDEXABLE', false),
    'accepting_requests' => (bool) env('WEBSITE_ACCEPTING_REQUESTS', false),
    'terms_version' => env('WEBSITE_TERMS_VERSION', $demo ? 'DEMO-2026-10-31' : null),
    'locales' => ['en', 'ru', 'uk'],
    'contacts' => [
        'telegram' => env('WEBSITE_TELEGRAM_URL'),
        'whatsapp' => env('WEBSITE_WHATSAPP_URL'),
        'email' => env('WEBSITE_EMAIL_URL'),
        'maps' => env('WEBSITE_MAPS_URL'),
    ],
    'business' => [
        'legal_name' => env('WEBSITE_LEGAL_NAME'),
        'address' => env('WEBSITE_ADDRESS'),
        'telephone' => env('WEBSITE_TELEPHONE'),
    ],
    'policies' => ['terms' => $demoPolicies, 'privacy' => $demoPolicies, 'contact' => []],
    'cms' => [
        'admin_enabled' => (bool) env('WEBSITE_CMS_ADMIN_ENABLED', false),
        'policy_publishing_enabled' => (bool) env('WEBSITE_CMS_POLICY_PUBLISHING_ENABLED', false),
        'enabled' => (bool) env('WEBSITE_CMS_ENABLED', false),
        'static_assets' => [
            'home_hero' => '/website/images/home-hero.webp',
            'cars' => '/website/images/cars.webp',
            'scooters' => '/website/images/scooters.webp',
            'island' => '/website/images/island.webp',
            'robot_phone' => '/website/images/robot-phone.webp',
            'robot_standing' => '/website/images/robot-standing.webp',
            'robot_wheel' => '/website/images/robot-wheel.webp',
        ],
        'media' => [
            'max_bytes' => 6 * 1024 * 1024,
            'max_pixels' => 6_000_000,
            'max_dimension' => 4096,
            'max_variant_bytes' => 2 * 1024 * 1024,
            'memory_budget_bytes' => 128 * 1024 * 1024,
        ],
    ],
    'preview' => [
        'enabled' => (bool) env('WEBSITE_PREVIEW_MODE', false),
        // Intentionally not cast: unknown strings must fail the runtime guard.
        'public_access' => env('WEBSITE_PREVIEW_PUBLIC_ACCESS', false),
        'host' => env('WEBSITE_PREVIEW_HOST'),
        'snapshot_sha256' => env('WEBSITE_PREVIEW_SNAPSHOT_SHA256'),
        'pricing_source_sha256' => env('WEBSITE_PREVIEW_PRICING_SHA256'),
        'mapping_sha256' => env('WEBSITE_PREVIEW_MAPPING_SHA256'),
        'media_manifest_sha256' => env('WEBSITE_PREVIEW_MEDIA_SHA256'),
        'instance_id' => env('WEBSITE_PREVIEW_INSTANCE_ID'),
        'auth_user' => env('WEBSITE_PREVIEW_AUTH_USER'),
    ],
];
