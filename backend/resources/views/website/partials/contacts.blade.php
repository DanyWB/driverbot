<div class="contact-links">
    @forelse ($website['contacts'] as $channel => $url)
        @if (is_string($url) && $url !== '')
            <a class="button button-outline" href="{{ $url }}" rel="noopener noreferrer" data-contact-channel="{{ $channel }}">{{ ['telegram' => 'Telegram', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'maps' => 'Google Maps'][$channel] ?? $t('nav_contact') }}</a>
        @endif
    @empty
        <p class="muted contact-pending">{{ $t('contact_unconfigured') }}</p>
    @endforelse
</div>
