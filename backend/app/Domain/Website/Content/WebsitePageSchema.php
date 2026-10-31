<?php

namespace App\Domain\Website\Content;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebsitePageSchema
{
    public const KEYS = ['home', 'cars', 'scooters', 'contact', 'terms', 'privacy'];

    public const TYPES = ['hero', 'catalog', 'text_image', 'steps', 'faq', 'contact_cta'];

    public function __construct(private readonly WebsiteContentSchema $text, private readonly WebsiteStaticAssets $assets) {}

    public function key(string $key): void
    {
        if (! in_array($key, self::KEYS, true)) {
            $this->invalid('key');
        }
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    public function normalize(array $content, bool $publishing = false): array
    {
        $encoded = json_encode($content);
        if ($encoded === false || strlen($encoded) > 262144) {
            $this->invalid('content');
        }
        $this->keys($content, ['schema_version', 'title', 'meta_title', 'meta_description', 'blocks']);
        if (array_key_exists('schema_version', $content) && $content['schema_version'] !== 1) {
            $this->invalid('content');
        }
        $result = ['schema_version' => 1, 'title' => $this->localized($content, 'title', 160),
            'meta_title' => $this->localized($content, 'meta_title', 160), 'meta_description' => $this->localized($content, 'meta_description', 500), 'blocks' => []];
        $blocks = $content['blocks'] ?? [];
        if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) > 30) {
            $this->invalid('blocks');
        }
        $seen = [];
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                $this->invalid('blocks');
            }
            $this->keys($block, ['id', 'type', 'props']);
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            $props = $block['props'] ?? [];
            if (! is_string($id) || ! Str::isUuid($id) || isset($seen[strtolower($id)]) || ! in_array($type, self::TYPES, true) || ! is_array($props)) {
                $this->invalid('blocks');
            }
            $seen[strtolower($id)] = true;
            $allowed = match ($type) {
                'hero' => ['heading', 'body', 'image_ref', 'action', 'action_label'],
                'catalog' => ['heading', 'vehicle_type', 'limit'],
                'text_image' => ['heading', 'body', 'image_ref'],
                'steps','faq' => ['heading', 'items'],
                'contact_cta' => ['heading', 'body', 'channel', 'action_label'],
            };
            $this->keys($props, $allowed);
            $normalized = ['heading' => $this->localized($props, 'heading', 160)];
            if (in_array('body', $allowed, true)) {
                $normalized['body'] = $this->localized($props, 'body', 4000, true);
            }
            if (in_array('image_ref', $allowed, true)) {
                $image = $props['image_ref'] ?? null;
                if ($image !== null && (! is_string($image) || ! $this->assets->contains($image))) {
                    $this->invalid('image_ref');
                }
                $normalized['image_ref'] = $image;
            }
            if ($type === 'catalog') {
                $vehicleType = $props['vehicle_type'] ?? 'all';
                $limit = $props['limit'] ?? 6;
                if (! in_array($vehicleType, ['car', 'scooter', 'all'], true) || ! is_int($limit) || $limit < 1 || $limit > 12) {
                    $this->invalid('blocks');
                }
                $normalized += ['vehicle_type' => $vehicleType, 'limit' => $limit];
            }
            if ($type === 'hero' || $type === 'contact_cta') {
                $field = $type === 'hero' ? 'action' : 'channel';
                $value = $props[$field] ?? ($type === 'hero' ? 'cars' : 'telegram');
                if (! in_array($value, $type === 'hero' ? ['cars', 'scooters'] : ['telegram', 'whatsapp', 'email'], true)) {
                    $this->invalid($field);
                }
                $normalized[$field] = $value;
                $normalized['action_label'] = $this->localized($props, 'action_label', 160);
            }
            if ($type === 'steps' || $type === 'faq') {
                $items = $props['items'] ?? [];
                if (! is_array($items) || ! array_is_list($items) || count($items) > ($type === 'steps' ? 8 : 20)) {
                    $this->invalid('items');
                }
                $normalized['items'] = [];
                $ids = [];
                foreach ($items as $item) {
                    $fields = $type === 'steps' ? ['title', 'body'] : ['question', 'answer'];
                    if (! is_array($item)) {
                        $this->invalid('items');
                    }
                    $this->keys($item, ['id', ...$fields]);
                    $itemId = $item['id'] ?? null;
                    if (! is_string($itemId) || ! Str::isUuid($itemId) || isset($ids[strtolower($itemId)])) {
                        $this->invalid('items');
                    }
                    $ids[strtolower($itemId)] = true;
                    $normalized['items'][] = ['id' => strtolower($itemId), $fields[0] => $this->localized($item, $fields[0], 160),
                        $fields[1] => $this->localized($item, $fields[1], 4000, true)];
                }
            }
            $result['blocks'][] = ['id' => strtolower($id), 'type' => $type, 'props' => $normalized];
        }
        if ($publishing) {
            if ($result['title'] === []) {
                $this->invalid('title');
            }
            if ($result['blocks'] === []) {
                $this->invalid('blocks');
            }
            foreach (array_keys($result['title']) as $locale) {
                foreach ($result['blocks'] as $block) {
                    $props = $block['props'];
                    foreach (['heading', ...($block['type'] === 'text_image' ? ['body'] : []), ...(in_array($block['type'], ['hero', 'contact_cta'], true) ? ['action_label'] : [])] as $field) {
                        if (empty($props[$field][$locale])) {
                            $this->invalid('blocks');
                        }
                    }
                    if (in_array($block['type'], ['steps', 'faq'], true)) {
                        if (($props['items'] ?? []) === []) {
                            $this->invalid('items');
                        }
                        foreach ($props['items'] as $item) {
                            foreach ($block['type'] === 'steps' ? ['title', 'body'] : ['question', 'answer'] as $field) {
                                if (empty($item[$field][$locale])) {
                                    $this->invalid('items');
                                }
                            }
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  list<string>  $allowed
     */
    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed) !== []) {
            $this->invalid('content');
        }
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function localized(array $data, string $field, int $limit, bool $multiline = false): array
    {
        $value = $data[$field] ?? [];
        if (! is_array($value)) {
            $this->invalid($field);
        }

        return $this->text->localized($value, $field, $limit, $multiline);
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Website page accepts only supported bounded fields and complete published translations.']);
    }
}
