@php
    $minimumPickup = $website['earliest_pickup_date'] ?? now('Asia/Bangkok')->format('Y-m-d');
    $minimumReturn = \Carbon\CarbonImmutable::parse($minimumPickup)->addDay()->format('Y-m-d');
@endphp
<div class="date-fields" data-date-fields>
    <div class="field"><label for="starts-on">{{ $t('starts_on') }}</label><input id="starts-on" name="starts_on" type="date" min="{{ $minimumPickup }}" value="{{ old('starts_on', $dates['starts_on'] ?? $filters['starts_on'] ?? '') }}" data-start-date @if($datesRequired ?? false) required @endif aria-describedby="date-help" data-invalid-message="{{ $t('date_invalid') }}" data-minimum-message="{{ $t('pickup_date_unavailable', ['date' => $minimumPickup]) }}"></div>
    <div class="field"><label for="ends-on">{{ $t('ends_on') }}</label><input id="ends-on" name="ends_on" type="date" min="{{ $minimumReturn }}" value="{{ old('ends_on', $dates['ends_on'] ?? $filters['ends_on'] ?? '') }}" data-end-date @if($datesRequired ?? false) required @endif aria-describedby="date-help"></div>
</div>
<p class="field-help" id="date-help">{{ $t('date_help') }}</p>
