<?php

namespace App\Domain\Website\Content;

use App\Domain\Website\PublicCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class WebsitePageAssembler
{
    public function __construct(private readonly PublishedWebsiteDocuments $documents, private readonly PublishedVehiclePresentation $cards,
        private readonly PublicCatalog $catalog) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $dates
     * @return array<string,mixed>
     */
    public function assemble(string $key, string $locale, array $data, array $dates = []): array
    {
        if (config('website.cms.enabled') !== true) {
            return $data;
        }
        $documents = $this->documents->read($key, $locale);
        $catalogs = $this->catalogs($documents['page'], $locale, $dates);
        $ids = [];
        foreach ($data['vehicles'] ?? [] as $vehicle) {
            $ids[] = $vehicle['id'];
        }
        if (isset($data['vehicle'])) {
            $ids[] = $data['vehicle']['id'];
        }
        foreach ($catalogs as $vehicles) {
            foreach ($vehicles as $vehicle) {
                $ids[] = $vehicle['id'];
            }
        }
        $presentations = $this->cards->forVehicles(array_values(array_unique($ids)), $locale);
        $decorate = static fn (array $vehicle): array => [...$vehicle, 'presentation' => $presentations[$vehicle['id']] ?? null];
        if (isset($data['vehicles'])) {
            if ($data['vehicles'] instanceof LengthAwarePaginator) {
                $data['vehicles']->through($decorate);
            } else {
                $data['vehicles'] = array_map($decorate, $data['vehicles']);
            }
        }
        if (isset($data['vehicle'])) {
            $data['vehicle'] = $decorate($data['vehicle']);
        }
        foreach ($catalogs as &$vehicles) {
            $vehicles = array_map($decorate, $vehicles);
        }
        unset($vehicles);

        return [...$data, 'cms_page' => $documents['page'], 'cms_settings' => $documents['settings'], 'cms_catalogs' => $catalogs];
    }

    /**
     * @param  array<string,mixed>|null  $page
     * @param  array<string,mixed>  $dates
     * @return array<string,list<array<string,mixed>>>
     */
    public function catalogs(?array $page, string $locale, array $dates = []): array
    {
        $catalogs = ['all' => [], 'car' => [], 'scooter' => []];
        $limits = [];
        foreach ($page['blocks'] ?? [] as $block) {
            if ($block['type'] === 'catalog') {
                $type = $block['props']['vehicle_type'];
                $limits[$type] = max($limits[$type] ?? 0, $block['props']['limit']);
            }
        }
        foreach ($limits as $type => $limit) {
            $query = $this->catalog->query();
            if ($type !== 'all') {
                $query->where('type', $type);
            }
            if (! empty($dates['starts_on']) && ! empty($dates['ends_on'])) {
                $query->whereDoesntHave('occupancies', fn (Builder $q) => $q->where('blocks_availability', true)
                    ->where('starts_on', '<=', $dates['ends_on'])->where('ends_on', '>=', $dates['starts_on']));
            }
            $catalogs[$type] = array_values($query->orderBy('sort_order')->orderBy('id')->limit($limit)->get()
                ->map(fn ($vehicle): array => $this->catalog->present($vehicle, $locale, $dates))->all());
        }

        return $catalogs;
    }
}
