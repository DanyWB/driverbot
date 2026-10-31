<?php

namespace App\Domain\Website\Content;

use Illuminate\Validation\ValidationException;

class WebsiteSettingsSchema
{
    public function __construct(private readonly WebsiteContentSchema $text) {}

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    public function normalize(array $content): array
    {
        if (array_diff(array_keys($content), ['schema_version', 'business', 'contacts', 'navigation', 'footer_note']) !== [] || (array_key_exists('schema_version', $content) && $content['schema_version'] !== 1)) {
            $this->invalid();
        }
        $business = $content['business'] ?? [];
        $contacts = $content['contacts'] ?? [];
        $navigation = $content['navigation'] ?? ['home', 'cars', 'scooters', 'contact', 'terms', 'privacy'];
        $footer = $content['footer_note'] ?? [];
        if (! is_array($business) || array_diff(array_keys($business), ['name', 'legal_name', 'address', 'telephone']) !== []
            || ! is_array($contacts) || array_diff(array_keys($contacts), ['telegram', 'whatsapp', 'email', 'maps']) !== []
            || ! is_array($navigation) || ! array_is_list($navigation) || count($navigation) > 6 || ! is_array($footer)) {
            $this->invalid();
        }
        foreach ($navigation as $route) {
            if (! is_string($route) || ! in_array($route, WebsitePageSchema::KEYS, true)) {
                $this->invalid();
            }
        }
        if (count(array_unique($navigation)) !== count($navigation)) {
            $this->invalid();
        }
        $safeBusiness = [];
        foreach (['name', 'legal_name', 'address', 'telephone'] as $field) {
            $value = $business[$field] ?? null;
            if ($value !== null && ! is_string($value)) {
                $this->invalid();
            }
            $safeBusiness[$field] = $value === null ? null : ($this->text->localized(['en' => $value], $field, $field === 'address' ? 500 : 160)['en'] ?? null);
        }
        $safeContacts = [];
        foreach (['telegram', 'whatsapp', 'email', 'maps'] as $channel) {
            $value = $contacts[$channel] ?? null;
            if ($value === '') {
                $value = null;
            }
            if ($value !== null && (! is_string($value) || strlen($value) > 500 || preg_match('/[\x00-\x20\x7F]/', $value) || ! $this->contact($channel, $value))) {
                $this->invalid();
            }
            $safeContacts[$channel] = $value;
        }

        return ['schema_version' => 1, 'business' => $safeBusiness, 'contacts' => $safeContacts,
            'navigation' => $navigation, 'footer_note' => $this->text->localized($footer, 'footer_note', 500)];
    }

    private function contact(string $channel, string $value): bool
    {
        return match ($channel) {
            'telegram' => preg_match('~^https://t\.me/[A-Za-z][A-Za-z0-9_]{4,31}$~D', $value) === 1,
            'whatsapp' => preg_match('~^https://wa\.me/[1-9][0-9]{6,14}$~D', $value) === 1,
            'email' => str_starts_with($value, 'mailto:') && filter_var(substr($value, 7), FILTER_VALIDATE_EMAIL) !== false && ! str_contains($value, '?'),
            'maps' => preg_match('~^https://(?:maps\.app\.goo\.gl/[A-Za-z0-9]+|www\.google\.com/maps\?cid=[0-9]+)$~D', $value) === 1,
            default => false,
        };
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['content' => 'Website settings accept only supported public business fields and typed contact links.']);
    }
}
