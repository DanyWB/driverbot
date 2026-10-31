<section class="calendar-panel" data-availability-calendar data-endpoint="{{ $availabilityUrl }}" aria-labelledby="calendar-heading">
    <h2 id="calendar-heading">{{ $t('calendar') }}</h2><p class="field-help">{{ $t('calendar_hint') }}</p>
    <div data-calendar-controls class="calendar-controls" hidden><button class="button button-outline" type="button" data-calendar-prev aria-label="{{ $t('previous_month') }}">‹</button><strong data-calendar-month></strong><button class="button button-outline" type="button" data-calendar-next aria-label="{{ $t('next_month') }}">›</button></div>
    <p class="field-help" data-calendar-message role="status" aria-live="polite">{{ $t('calendar_loading') }}</p><div data-calendar-grid class="calendar-grid" role="group" aria-label="{{ $t('calendar') }}"></div>
    <div class="calendar-legend"><span>{{ $t('free') }}</span><span class="busy-label">{{ $t('busy') }}</span><button type="button" data-calendar-clear class="calendar-clear">{{ $t('clear_dates') }}</button></div>
    <noscript><p class="field-help">{{ $t('calendar_error') }}</p></noscript>
</section>
