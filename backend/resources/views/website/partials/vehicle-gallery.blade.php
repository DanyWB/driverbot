@php
    $websiteCover = $vehicle['presentation']['cover'] ?? null;
@endphp
<div class="vehicle-detail-visual">
    @if($websiteCover)
        <img class="website-cover website-cover-{{ $websiteCover['kind'] }}" src="{{ $websiteCover['variants']['detail']['url'] }}" srcset="{{ $websiteCover['variants']['card_2x']['url'] }} {{ $websiteCover['variants']['card_2x']['width'] }}w, {{ $websiteCover['variants']['detail']['url'] }} {{ $websiteCover['variants']['detail']['width'] }}w" sizes="(max-width: 900px) 100vw, 60vw" alt="{{ $websiteCover['alt'] }}" width="{{ $websiteCover['variants']['detail']['width'] }}" height="{{ $websiteCover['variants']['detail']['height'] }}" fetchpriority="high">
    @elseif($vehicle['primary_photo'])
        <img src="{{ $vehicle['primary_photo']['url'] }}" alt="{{ $vehicle['primary_photo']['alt'] }}" width="1000" height="650" fetchpriority="high">
    @else
        <div class="photo-pending">{{ $t('photo_pending') }}</div>
    @endif
</div>
@if(count($vehicle['photos']) > 1)
    <div class="vehicle-gallery">
        @foreach($vehicle['photos'] as $photo)
            <a href="{{ $photo['url'] }}" target="_blank" rel="noopener"><img src="{{ $photo['url'] }}" alt="{{ $photo['alt'] }}" width="220" height="150" loading="lazy"></a>
        @endforeach
    </div>
@endif
