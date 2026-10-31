@php
    $cardQuote = $vehicle['quote'] ?? null;
    $pricePreview = $vehicle['pricePreview'] ?? null;
    $formatPrice = fn ($amount) => rtrim(rtrim(number_format((float) $amount, 2, '.', ','), '0'), '.');
    $hasPreview = !$cardQuote && $pricePreview && ($pricePreview['day'] || $pricePreview['month']);
    $presentation = $vehicle['presentation'] ?? null;
    $cardName = $presentation['title'] ?? $vehicle['name'];
    $cover = $presentation['cover'] ?? null;
@endphp
<article class="vehicle-card" data-vehicle-card="{{ $vehicle['id'] }}">
    <a class="vehicle-visual" href="{{ $vehicle['url'] }}">
        <div class="vehicle-card-heading"><h3>{{ $cardName }}</h3>@if($vehicle['category'])<p>{{ $vehicle['category'] }}</p>@endif</div>
        @if($cover)
            <img class="vehicle-photo website-cover website-cover-{{ $cover['kind'] }}" src="{{ $cover['variants']['card']['url'] }}" srcset="{{ $cover['variants']['card']['url'] }} {{ $cover['variants']['card']['width'] }}w, {{ $cover['variants']['card_2x']['url'] }} {{ $cover['variants']['card_2x']['width'] }}w" sizes="(max-width: 700px) 100vw, (max-width: 1100px) 50vw, 33vw" alt="{{ $cover['alt'] }}" width="{{ $cover['variants']['card']['width'] }}" height="{{ $cover['variants']['card']['height'] }}" loading="lazy">
        @elseif($vehicle['primary_photo'])
            <img class="vehicle-photo" src="{{ $vehicle['primary_photo']['url'] }}" alt="{{ $vehicle['primary_photo']['alt'] }}" width="620" height="340" loading="lazy">
        @else
            <div class="photo-pending">{{ $t('photo_pending') }}</div>
        @endif
        <dl class="card-specs">
        @foreach (['year', 'engine_cc', 'seats', 'transmission'] as $spec)
            @if($vehicle[$spec] !== null)
                <div><dt>{{ $t($spec) }}</dt><dd>{{ $spec === 'transmission' ? $t($vehicle[$spec]) : $vehicle[$spec] }}{{ $spec === 'engine_cc' ? ' cc' : '' }}</dd></div>
            @endif
        @endforeach
        </dl>
    </a>
    <div class="card-offer">
        <div class="card-rate-row">
            @if($hasPreview)
            <div class="preview-rates">
                @if($pricePreview['day'])
                    <div><strong>{{ $formatPrice($pricePreview['day']['average_daily']) }} <span>{{ $pricePreview['day']['currency'] }}</span></strong><b>{{ $t('preview_day') }}</b></div>
                @endif
                @if($pricePreview['month'])
                    <div><strong>{{ $formatPrice($pricePreview['month']['final_total']) }} <span>{{ $pricePreview['month']['currency'] }}</span></strong><b>{{ $t('preview_month') }}</b></div>
                @endif
            </div>
            @else
                <div class="card-price">
                    @if($cardQuote)
                        <span>{{ $t('period_total') }}</span><strong>{{ $formatPrice($cardQuote['final_total']) }} {{ $cardQuote['currency'] }}</strong><small>{{ $t($locale === 'en' && (int) $cardQuote['total_days'] === 1 ? 'days_one' : 'days', ['count' => $cardQuote['total_days']]) }} · {{ $formatPrice($cardQuote['average_daily']) }} {{ $cardQuote['currency'] }} {{ $t('preview_day') }}</small>
                    @else
                        <span>{{ $t('choose_dates') }}</span>
                    @endif
                </div>
            @endif
            <a class="button button-primary" href="{{ $vehicle['url'] }}">{{ $t('details') }}</a>
        </div>
        @if($hasPreview)
            <details class="price-scope" data-disclosure>
                <summary>{{ $pricePreview['day'] && $pricePreview['month'] ? $t('preview_scope', ['date' => $pricePreview['starts_on'], 'day_count' => $pricePreview['day']['total_days'], 'month_count' => $pricePreview['month']['total_days']]) : $t('preview_scope_single', ['date' => $pricePreview['starts_on'], 'count' => ($pricePreview['day'] ?? $pricePreview['month'])['total_days']]) }}</summary>
                <div class="price-explanation">
                    @if($pricePreview['day'])
                        <p>{{ $t($locale === 'en' && (int) $pricePreview['day']['total_days'] === 1 ? 'preview_minimum_one' : 'preview_minimum', ['count' => $pricePreview['day']['total_days']]) }}.</p>
                    @endif
                    @if($pricePreview['month'])
                        <p>{{ $t('preview_month_days', ['count' => $pricePreview['month']['total_days']]) }}: {{ $pricePreview['month']['start_date'] }} / {{ $pricePreview['month']['end_date'] }}.</p>
                    @endif
                    <p>{{ $t('preview_note') }}</p>
                </div>
            </details>
        @endif
    </div>
</article>
