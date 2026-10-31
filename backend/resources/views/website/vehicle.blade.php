@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('content')
<div class="container detail-page" data-vehicle-id="{{ $vehicle['id'] }}">
    <a class="back-link" href="{{ $navigation[$vehicle['type'] === 'car' ? 'cars' : 'scooters'] }}">{{ $t('back_catalog') }}</a>
    <p class="eyebrow">{{ $t('nav_'.($vehicle['type'] === 'car' ? 'cars' : 'scooters')) }}</p><h1 class="page-title">{{ $vehicle['presentation']['title'] ?? $vehicle['name'] }}</h1>
    <div class="detail-grid">
        <div class="detail-media">@include('website.partials.vehicle-gallery')</div>
        <aside class="rental-panel" aria-label="{{ $t('check_price') }}">
            <h2>{{ $t('check_price') }}</h2>
            @include('website.partials.calendar')
            <form method="get" action="{{ $vehicle['url'] }}" data-quote-form data-endpoint="{{ $quoteUrl }}" data-booking-url="{{ $vehicle['booking_url'] }}">
                @include('website.partials.dates', ['datesRequired' => true])
                <button class="button button-primary full-width" type="submit" data-quote-button>{{ $t('check_price') }}</button>
            </form>
            @include('website.partials.quote')
            <a class="button button-primary full-width" data-booking-link
                @if($quoteError === 'vehicle_unavailable')
                    aria-disabled="true" tabindex="-1"
                @else
                    href="{{ $vehicle['booking_url'] }}"
                @endif
            >{{ $t('request_rental') }}</a>
            <p class="field-help">{{ $t('pending_note') }}</p>
        </aside>
        @include('website.partials.vehicle-summary')
    </div>
</div>
@include('website.partials.client-messages')
@endsection
