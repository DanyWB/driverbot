@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('no-contact', '1')
@section('content')
@if(!empty($cms_page))
    @include('website.partials.cms-blocks')
@else
<section class="container policy-page"><p class="eyebrow">{{ $website['name'] }}</p><h1 class="page-title">{{ $t($policy === 'contact' ? 'contact_heading' : $policy) }}</h1>
    @if($website['demo'] && $policy !== 'contact')
        <div class="policy-content">{{ $t('preview_policy') }}</div>
    @elseif($policyContent)
        <div class="policy-content">{{ $policyContent }}</div>
    @elseif($policy !== 'contact')
        <div class="notice-panel"><p>{{ $t('policy_pending') }}</p></div>
    @endif
    @if($policy === 'contact')@include('website.partials.contacts')@endif
</section>
@endif
@endsection
