<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#090a0b">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    @foreach ($seo['hreflang'] as $alternate)
        <link rel="alternate" hreflang="{{ $alternate['locale'] }}" href="{{ $alternate['url'] }}">
    @endforeach
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $seo['title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    @if ($seo['og_image'])<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
    @foreach ($seo['json_ld'] as $schema)
        <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endforeach
    @vite(['resources/css/website.css', 'resources/js/website/app.ts'])
</head>
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
<body data-website data-locale="{{ $locale }}" data-today-date="{{ $website['today_date'] ?? '' }}" data-earliest-pickup-date="{{ $website['earliest_pickup_date'] ?? '' }}">
    <a class="skip-link" href="#main">{{ $t('skip') }}</a>
    @if ($website['demo'])<div class="demo-notice" role="note">{{ $t('demo') }}</div>@endif
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="{{ $navigation['home'] }}" aria-label="{{ $website['name'] }}">
                <img src="/website/images/brand-mark.webp" alt="" width="72" height="72">
                <img src="/website/images/brand-word.webp" alt="Drive Phangan" width="190" height="68">
            </a>
            <button class="menu-toggle button button-outline" type="button" data-menu-toggle aria-expanded="false" aria-controls="site-navigation">{{ $t('menu') }}</button>
            <nav class="primary-nav" id="site-navigation" aria-label="{{ $t('menu') }}">
                @foreach ($website['navigation_order'] ?? ['home', 'scooters', 'cars'] as $key)
                    <a href="{{ $navigation[$key] }}" @if(request()->url() === $navigation[$key]) aria-current="page" @endif>{{ $t(in_array($key, ['terms', 'privacy'], true) ? $key : 'nav_'.$key) }}</a>
                @endforeach
                @unless(array_key_exists('navigation_order', $website))
                    <a href="{{ $navigation['home'] }}#how-it-works">{{ $t('nav_how') }}</a>
                    <a href="{{ $navigation['contact'] }}">{{ $t('nav_contact') }}</a>
                @endunless
            </nav>
            <div class="header-actions">
                <a class="button button-outline header-contact" href="{{ $navigation['contact'] }}">{{ $t('talk') }}</a>
                <nav class="language-nav" aria-label="{{ $t('language') }}">
                    @foreach ($languages as $language)
                        <a lang="{{ $language['locale'] }}" hreflang="{{ $language['locale'] }}" href="{{ $language['url'] }}" @if($language['locale'] === $locale) aria-current="true" @endif>{{ ['en' => 'EN', 'ru' => 'RU', 'uk' => 'UK'][$language['locale']] }}</a>
                    @endforeach
                </nav>
            </div>
            <img class="header-robot" src="/website/images/robot-phone.webp" width="95" height="115" alt="">
        </div>
    </header>
    <main id="main">@yield('content')</main>
    @unless (View::hasSection('no-contact'))
        <section class="contact-banner" aria-labelledby="contact-heading">
            <div class="container contact-inner">
                <p class="eyebrow">{{ $t('contact_kicker') }}</p>
                <h2 id="contact-heading">{{ $t('contact_title') }}</h2>
                <p>{{ $t('contact_body') }}</p>
                @include('website.partials.contacts')
                <img class="contact-robot" src="/website/images/robot-wheel.webp" width="170" height="240" alt="" loading="lazy">
            </div>
        </section>
    @endunless
    <footer class="site-footer container">
        <a class="brand footer-brand" href="{{ $navigation['home'] }}" aria-label="{{ $website['name'] }}"><img src="/website/images/brand-mark.webp" alt="" width="64" height="64" loading="lazy"><img src="/website/images/brand-word.webp" alt="Drive Phangan" width="180" height="64" loading="lazy"></a>
        <nav class="footer-nav" aria-label="{{ $t('menu') }}">
            @foreach ($website['navigation_order'] ?? ['scooters', 'cars', 'contact', 'terms', 'privacy'] as $key)
                <a href="{{ $navigation[$key] }}">{{ $t(in_array($key, ['terms', 'privacy'], true) ? $key : 'nav_'.$key) }}</a>
            @endforeach
        </nav>
        @if(!empty($website['business']))
            <div class="footer-business">
                @foreach(['legal_name', 'address', 'telephone'] as $field)
                    @if(!empty($website['business'][$field]))
                        <p>{{ $website['business'][$field] }}</p>
                    @endif
                @endforeach
            </div>
        @endif
        @if(!empty($website['footer_note']))
            <p class="cms-plain-text">{{ $website['footer_note'] }}</p>
        @endif
        <small>© {{ date('Y') }} {{ $t('copyright') }}</small>
    </footer>
</body>
</html>
