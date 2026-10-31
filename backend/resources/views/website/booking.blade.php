@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('no-contact', '1')
@section('content')
<div class="container booking-page">
    <a class="back-link" href="{{ $vehicle['url'] }}">{{ $vehicle['name'] }}</a>
    <p class="eyebrow">{{ $website['name'] }}</p><h1 class="page-title">{{ $t('booking_title') }}</h1><p class="section-intro">{{ $t('booking_intro') }}</p>
    @include('website.partials.errors')
    @if(!$website['accepting_requests'])<div class="notice-panel" role="status"><h2>{{ $t('requests_closed') }}</h2>@include('website.partials.contacts')</div>
    @else
        <div class="booking-grid">
            <form method="post" action="{{ $submitUrl }}" class="booking-form" data-booking-form data-quote-endpoint="{{ $quoteUrl }}">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
                <input type="hidden" name="terms_version" value="{{ $website['terms_version'] }}">
                <div class="honeypot" aria-hidden="true"><label>Company<input name="company" tabindex="-1" autocomplete="off" value=""></label></div>
                <h2>{{ $vehicle['name'] }}</h2>
                @include('website.partials.calendar')
                @include('website.partials.dates', ['datesRequired' => true])
                <button class="button button-outline full-width" type="button" data-booking-quote>{{ $t('check_price') }}</button>
                <noscript><a class="button button-outline full-width" href="{{ $vehicle['url'] }}">{{ $t('check_price') }}</a></noscript>
                <div class="field"><label for="renter-name">{{ $t('name') }}</label><input id="renter-name" name="name" autocomplete="name" maxlength="120" required value="{{ old('name') }}" @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>@error('name')<p id="name-error" class="field-error">{{ $message }}</p>@enderror</div>
                @php
                    $channelOptions = collect($contactChannels)->map(fn ($channel) => ['value' => $channel, 'label' => $t($channel)])->all();
                @endphp
                @include('website.partials.select', ['name' => 'contact_channel', 'label' => $t('contact_channel'), 'value' => old('contact_channel', $contactChannels[0] ?? ''), 'options' => $channelOptions])
                <div class="field"><label for="renter-contact">{{ $t('contact_value') }}</label><input id="renter-contact" name="contact_value" required maxlength="190" value="{{ old('contact_value') }}" autocomplete="off" aria-describedby="contact-help contact-manual" @error('contact_value') aria-invalid="true" @enderror><p class="field-help" id="contact-help">{{ $t('contact_hint') }}</p>@error('contact_value')<p class="field-error">{{ $message }}</p>@enderror</div>
                <p class="manual-contact" id="contact-manual">{{ $t('manual_contact') }}</p>
                <div class="field"><label for="client-comment">{{ $t('client_comment') }}</label><textarea id="client-comment" name="client_comment" rows="3" maxlength="2000">{{ old('client_comment') }}</textarea></div>
                <label class="terms-check"><input type="checkbox" name="terms_accepted" value="1" required @checked(old('terms_accepted'))><span>{{ $t('terms_accept') }}. <a href="{{ $navigation['terms'] }}" target="_blank" rel="noopener">{{ $t('terms') }}</a> · <a href="{{ $navigation['privacy'] }}" target="_blank" rel="noopener">{{ $t('privacy') }}</a></span></label>
                <div class="inline-quote" data-inline-quote aria-live="polite">
                    <span>{{ $t('period_total') }}</span>
                    @if($quote)
                        <strong>{{ number_format((float) $quote['final_total'], 0) }} {{ $quote['currency'] }}</strong>
                    @else
                        <p>{{ $t('choose_dates') }}</p>
                    @endif
                </div>
                <p class="field-help expiration-note">{{ $t('expiration_note') }}</p>
                <button class="button button-primary full-width" type="submit" data-submit-request>{{ $t('send_request') }}</button><p class="field-help">{{ $t('pending_note') }}</p>
            </form>
            <aside class="rental-panel booking-summary"><h2>{{ $t('period_total') }}</h2>@include('website.partials.quote')@if($vehicle['primary_photo'])<img class="booking-vehicle-photo" src="{{ $vehicle['primary_photo']['url'] }}" width="500" height="340" alt="{{ $vehicle['primary_photo']['alt'] }}">@endif</aside>
        </div>
    @endif
</div>
@include('website.partials.client-messages')
@endsection
