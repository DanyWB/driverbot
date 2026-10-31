<?php

namespace App\Domain\Website\Content;

use Illuminate\Validation\ValidationException;

class WebsiteContentSchema
{
    private const LIMITS = ['title' => 160, 'summary' => 4000, 'meta_title' => 160, 'meta_description' => 500, 'image_alt' => 255];

    /** @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public function normalize(array $content): array
    {
        if (array_diff(array_keys($content), ['schema_version', ...array_keys(self::LIMITS)]) !== []
            || (array_key_exists('schema_version', $content) && $content['schema_version'] !== 1)) {
            throw ValidationException::withMessages(['content' => 'Unsupported website content fields or schema version.']);
        }
        $normalized = ['schema_version' => 1];
        foreach (self::LIMITS as $field => $limit) {
            $value = $content[$field] ?? [];
            if (! is_array($value)) {
                throw ValidationException::withMessages([$field => 'Localized website text must be a locale map.']);
            }
            $normalized[$field] = $this->localized($value, $field, $limit, $field === 'summary');
        }

        return $normalized;
    }

    /** @param array<string,mixed> $value
     * @return array<string,string>
     */
    public function localized(array $value, string $field, int $limit = 255, bool $multiline = false): array
    {
        if (array_diff(array_keys($value), ['en', 'ru', 'uk']) !== []) {
            throw ValidationException::withMessages([$field => 'Unsupported website locale.']);
        }
        $normalized = [];
        foreach ($value as $locale => $text) {
            if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > $limit
                || $text !== strip_tags($text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
                throw ValidationException::withMessages([$field => 'Website fields accept bounded plain text only.']);
            }
            $text = trim($multiline ? str_replace(["\r\n", "\r"], "\n", $text) : (string) preg_replace('/\s+/u', ' ', $text));
            if ($text !== '') {
                $normalized[$locale] = $text;
            }
        }

        return $normalized;
    }
}
