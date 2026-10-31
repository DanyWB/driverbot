@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('no-contact', '1')
@section('content')
<div class="cms-preview-notice" role="note">{{ $t('cms_preview') }}</div>
@if($preview_kind === 'page' && $cms_page)
    @include('website.partials.cms-blocks')
@elseif($preview_kind === 'card' && $vehicle)
    @php
        $vehicle['presentation'] = $presentation;
    @endphp
    <section class="container section"><h1 class="page-title">{{ $presentation['title'] ?? $vehicle['name'] }}</h1><div class="cms-preview-card vehicle-grid vehicle-grid-{{ $vehicle['type'] === 'car' ? 'cars' : 'scooters' }}">@include('website.partials.card')</div><div class="detail-grid"><div class="detail-media">@include('website.partials.vehicle-gallery')</div>@include('website.partials.vehicle-summary')</div></section>
@endif
@endsection
