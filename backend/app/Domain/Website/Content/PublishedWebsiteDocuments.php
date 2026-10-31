<?php

namespace App\Domain\Website\Content;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

class PublishedWebsiteDocuments
{
    public function __construct(private readonly WebsitePageSchema $pages, private readonly WebsiteSettingsSchema $settings,
        private readonly WebsiteStaticAssets $assets) {}

    /** @return array{page:array<string,mixed>|null,settings:array<string,mixed>|null} */
    public function read(string $key, string $locale): array
    {
        $result = ['page' => null, 'settings' => null];
        if (config('website.cms.enabled') !== true || ! in_array($locale, ['en', 'ru', 'uk'], true)) {
            return $result;
        }
        $pages = DB::table('website_pages')->selectRaw('key AS document_key, published_content AS content, published_revision AS revision')->where('key', $key)->whereNotNull('published_at');
        $settings = DB::table('website_site_settings')->selectRaw("'@settings' AS document_key, published_content AS content, published_revision AS revision")->where('id', 1)->whereNotNull('published_at');
        foreach ($pages->unionAll($settings)->get() as $row) {
            try {
                if (! is_string($row->content) || strlen($row->content) > 262144) {
                    continue;
                }
                $content = json_decode($row->content, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($content)) {
                    continue;
                }
                if ($row->document_key === '@settings') {
                    $result['settings'] = $this->settings->normalize($content);
                } elseif (! in_array($key, ['terms', 'privacy'], true) || config('website.cms.policy_publishing_enabled') === true) {
                    $page = $this->pages->normalize($content, true);
                    if (! empty($page['title'][$locale])) {
                        $result['page'] = $this->localize($key, $page, $locale, (int) $row->revision);
                    }
                }
            } catch (JsonException|ValidationException) { /* bad publication never reaches renderer */
            }
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    public function localize(string $key, array $content, string $locale, int $revision): array
    {
        $this->pages->key($key);
        $content = $this->pages->normalize($content);
        $assets = array_column($this->assets->options(), null, 'id');
        $blocks = [];
        foreach ($content['blocks'] as $block) {
            $props = $block['props'];
            foreach (['heading', 'body', 'action_label'] as $field) {
                if (isset($props[$field])) {
                    $props[$field] = $props[$field][$locale] ?? '';
                }
            }
            if (array_key_exists('image_ref', $props)) {
                $props['image'] = $assets[$props['image_ref'] ?? ''] ?? null;
            }
            if (isset($props['items'])) {
                foreach ($props['items'] as &$item) {
                    foreach (['title', 'body', 'question', 'answer'] as $field) {
                        if (isset($item[$field])) {
                            $item[$field] = $item[$field][$locale] ?? '';
                        }
                    }
                }
                unset($item);
            }
            $blocks[] = ['id' => $block['id'], 'type' => $block['type'], 'props' => $props];
        }

        return ['key' => $key, 'revision' => $revision, 'title' => $content['title'][$locale] ?? '',
            'meta_title' => $content['meta_title'][$locale] ?? ($content['title'][$locale] ?? ''),
            'meta_description' => $content['meta_description'][$locale] ?? '', 'blocks' => $blocks];
    }
}
