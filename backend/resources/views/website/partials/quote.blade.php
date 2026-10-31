<div class="quote-result" data-quote-result aria-live="polite" aria-atomic="true">
    @if($quote)
        <p class="availability-state">{{ $t('available') }}</p>
        <dl class="quote-breakdown"><div><dt>{{ $t('period_total') }}</dt><dd class="quote-total">{{ number_format((float) $quote['final_total'], 0) }} {{ $quote['currency'] }}</dd></div><div><dt>{{ $t('daily_average') }}</dt><dd>{{ number_format((float) $quote['average_daily'], 2) }} {{ $quote['currency'] }}</dd></div><div><dt>{{ $t($locale === 'en' && (int) $quote['total_days'] === 1 ? 'days_one' : 'days', ['count' => $quote['total_days']]) }}</dt><dd></dd></div></dl>
    @elseif($quoteError ?? null)
        <p class="field-error">{{ $t(in_array($quoteError, ['unavailable', 'vehicle_unavailable'], true) ? 'unavailable' : 'quote_error') }}</p>
    @else
        <p class="muted">{{ $t('choose_dates') }}</p>
    @endif
</div>
<dl class="payment-conditions">
    @foreach(['prepayment', 'deposit'] as $paymentType)
        <div><dt>{{ $t($paymentType) }}</dt><dd data-payment="{{ $paymentType }}">{{ isset($quote['payments'][$paymentType]['amount']) ? number_format((float) $quote['payments'][$paymentType]['amount'], 0).' '.$quote['payments'][$paymentType]['currency'] : $t('confirm_with_team') }}</dd></div>
    @endforeach
</dl>
<p class="field-help">{{ $t('price_note') }}</p>
