<section class="vehicle-information"><h2>{{ $t('vehicle_details') }}</h2>
    @if(!empty($vehicle['presentation']['summary']))
        <p class="vehicle-description website-summary">{{ $vehicle['presentation']['summary'] }}</p>
    @elseif($vehicle['description'])
        <p class="vehicle-description">{{ $vehicle['description'] }}</p>
    @endif
    <dl class="detail-specs">
        @foreach(['brand', 'model', 'year', 'engine_cc', 'color', 'seats', 'transmission', 'abs'] as $spec)
            @if($vehicle[$spec] !== null)
                <div><dt>{{ $t($spec) }}</dt><dd>{{ $spec === 'abs' ? $t($vehicle[$spec] ? 'yes' : 'no') : ($spec === 'transmission' ? $t($vehicle[$spec]) : $vehicle[$spec]) }}{{ $spec === 'engine_cc' ? ' cc' : '' }}</dd></div>
            @endif
        @endforeach
    </dl>
    @if($vehicle['minimum_days'])<p class="field-help">{{ $t($locale === 'en' && (int) $vehicle['minimum_days'] === 1 ? 'minimum_days_one' : 'minimum_days', ['count' => $vehicle['minimum_days']]) }}</p>@endif
</section>
