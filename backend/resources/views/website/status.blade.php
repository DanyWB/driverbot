@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('no-contact', '1')
@section('content')
@php
    $statusKey = 'status_'.$booking['status'];
    $localDeadline = $booking['pending_expires_at'] ? \Carbon\CarbonImmutable::parse($booking['pending_expires_at'])->setTimezone('Asia/Bangkok')->locale($locale)->translatedFormat('j M Y, H:i') : null;
@endphp
<section class="container status-page">
    <p class="eyebrow">{{ $website['name'] }}</p><h1 class="page-title">{{ $t('status_title') }}</h1>
    <div class="status-panel"><p class="status-badge" role="status">{{ Lang::has('website.'.$statusKey, $locale) ? $t($statusKey) : $t('status_unknown') }}</p><h2>{{ $booking['vehicle_name'] }}</h2><p>{{ $t('status_body') }}</p>
        <dl class="detail-specs"><div><dt>{{ $t('reference') }}</dt><dd class="request-reference">{{ $booking['reference'] }}</dd></div><div><dt>{{ $t('starts_on') }}</dt><dd>{{ $booking['starts_on'] }} · {{ $booking['pickup_time'] }}</dd></div><div><dt>{{ $t('ends_on') }}</dt><dd>{{ $booking['ends_on'] }} · {{ $booking['return_time'] }}</dd></div>@if($booking['price'])<div><dt>{{ $t('period_total') }}</dt><dd>{{ number_format((float) $booking['price']['final_total'], 0) }} {{ $booking['price']['currency'] }}</dd></div>@endif @if($localDeadline)<div><dt>{{ $t('expires_bangkok') }}</dt><dd>{{ $localDeadline }}</dd></div>@endif</dl>
        <dl class="payment-conditions">
            @foreach(['prepayment', 'deposit'] as $paymentType)
                <div><dt>{{ $t($paymentType) }}</dt><dd>
                    @if(isset($booking['payments'][$paymentType]['amount']))
                        {{ number_format((float) $booking['payments'][$paymentType]['amount'], 0) }} {{ $booking['payments'][$paymentType]['currency'] }} · {{ $t($booking['payments'][$paymentType]['received'] ? 'payment_received' : 'payment_pending') }}
                    @else
                        {{ $t('confirm_with_team') }}
                    @endif
                </dd></div>
            @endforeach
        </dl>
        <p class="field-help">{{ $t('price_note') }}</p><p class="field-help">{{ $t('date_help') }}</p><p class="manual-contact">{{ $t('manual_contact') }}</p><a href="{{ request()->url() }}" class="button button-primary">{{ $t('refresh_status') }}</a>
    </div>
</section>
@endsection
