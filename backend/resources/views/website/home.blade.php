@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('content')
@if(!empty($cms_page))
    @include('website.partials.cms-blocks')
@else
<section class="home-hero">
    <picture class="hero-picture"><source media="(max-width: 720px)" srcset="/website/images/home-hero-720.webp"><img src="/website/images/home-hero.webp" alt="" width="1600" height="811" fetchpriority="high"></picture>
    <div class="container hero-content">
        <p class="eyebrow">{{ $t('eyebrow') }}</p>
        <h1>{{ $t('hero_line') }}<span>{{ $t('hero_accent') }}</span></h1>
        <p class="hero-description">{{ $t('hero_body') }}</p>
        <div class="hero-buttons"><a class="button button-primary" href="{{ $navigation['scooters'] }}">{{ $t('explore_scooters') }}</a><a class="button button-outline" href="{{ $navigation['cars'] }}">{{ $t('explore_cars') }}</a></div>
        <div class="hero-process"><span>{{ $t('step_1') }}</span><span>{{ $t('step_2') }}</span><span>{{ $t('step_3') }}</span></div>
    </div>
</section>
<section class="container section" aria-labelledby="find-heading">
    <p class="eyebrow">{{ $t('choose_vehicle') }}</p><h2 id="find-heading">{{ $t('find_ride') }}</h2><p class="section-intro">{{ $t('choose_intro') }}</p>
    <div class="category-grid">
        @foreach (['scooters', 'cars'] as $category)
            <a class="category-card" href="{{ $navigation[$category] }}">
                <img src="/website/images/{{ $category }}.webp" srcset="/website/images/{{ $category }}-720.webp 720w, /website/images/{{ $category }}.webp 1000w" sizes="(max-width: 700px) 100vw, 50vw" width="1000" height="360" alt="" loading="lazy">
                <div class="category-copy"><div><h3>{{ $t('nav_'.$category) }}</h3><p>{{ $t($category.'_body') }}</p></div><span class="button button-outline">{{ $t('explore_'.$category) }}</span></div>
            </a>
        @endforeach
    </div>
</section>
<section class="container section how-section" id="how-it-works" aria-labelledby="how-heading">
    <p class="eyebrow">{{ $t('nav_how') }}</p><h2 id="how-heading">{{ $t('how_title') }}</h2>
    <ol class="steps-grid">@foreach ([1, 2, 3] as $step)<li><span class="step-number">0{{ $step }}</span><h3>{{ $t('step_'.$step) }}</h3><p>{{ $t('step_'.$step.'_body') }}</p></li>@endforeach</ol>
</section>
@endif
@endsection
