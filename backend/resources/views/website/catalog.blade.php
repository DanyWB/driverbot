@extends('website.layout')
@php
    $t = fn ($key, $replace = []) => __('website.'.$key, $replace, $locale);
@endphp
@section('content')
@if(!empty($cms_page))
    @include('website.partials.cms-blocks', ['catalogContext' => true])
@else
<section class="catalog-hero catalog-hero-{{ $catalog }}">
    <picture class="catalog-picture"><source media="(max-width: 720px)" srcset="/website/images/{{ $catalog }}-720.webp"><img src="/website/images/{{ $catalog }}.webp" alt="" width="1000" height="360" fetchpriority="high"></picture>
    <div class="container catalog-hero-content"><p class="eyebrow">{{ $t('nav_'.$catalog) }} · Koh Phangan</p><h1>{{ $t($catalog.'_title') }}<span>{{ $t($catalog.'_accent') }}</span></h1><p>{{ $t('catalog_body') }}</p></div>
</section>
@endif
<section class="container catalog-content" aria-labelledby="catalog-heading">
    @php
        $shortcutFilters = array_filter(\Illuminate\Support\Arr::except($filters, ['category_id', 'seats', 'page']), fn ($value) => $value !== null && $value !== '');
    @endphp
    <nav class="catalog-shortcuts" aria-label="{{ $t('filters') }}">
        <a class="button button-outline" href="{{ $navigation[$catalog] }}{{ $shortcutFilters ? '?'.http_build_query($shortcutFilters) : '' }}" @if(empty($filters['seats']) && empty($filters['category_id'])) aria-current="true" @endif>{{ $t('all') }} · {{ $t('nav_'.$catalog) }}</a>
        @if(count($categoryOptions ?? []) > 1)
            @foreach($categoryOptions as $categoryOption)
                <a class="button button-outline" href="{{ $navigation[$catalog] }}?{{ http_build_query([...$shortcutFilters, 'category_id' => $categoryOption['id']]) }}" @if(($filters['category_id'] ?? null) == $categoryOption['id']) aria-current="true" @endif>{{ $categoryOption['name'] }}</a>
            @endforeach
        @endif
        @if($catalog === 'cars' && in_array(7, $filterOptions['seats'] ?? [], false))
            <a class="button button-outline" href="{{ $navigation[$catalog] }}?{{ http_build_query([...$shortcutFilters, 'seats' => 7]) }}" @if(($filters['seats'] ?? null) == 7) aria-current="true" @endif>{{ $t('seven_seats') }}</a>
        @endif
    </nav>
    <form class="filter-panel" action="{{ $navigation[$catalog] }}" method="get" data-filter-form>
        @if(!empty($filters['category_id']))
            <input type="hidden" name="category_id" value="{{ $filters['category_id'] }}">
        @endif
        <fieldset class="filter-fields"><legend>{{ $t('filters') }}</legend>
            @foreach ($filterOptions as $name => $values)
                @if(count($values))
                    @php
                        $selectOptions = [['value' => '', 'label' => $t('all')]];
                        foreach ($values as $option) {
                            $raw = is_array($option) ? ($option['value'] ?? '') : $option;
                            $selectOptions[] = ['value' => (string) $raw, 'label' => $name === 'abs' ? $t($raw ? 'yes' : 'no') : ($name === 'transmission' ? $t($raw) : (string) $raw)];
                        }
                    @endphp
                    @include('website.partials.select', ['name' => $name, 'label' => $t($name), 'value' => $filters[$name] ?? '', 'options' => $selectOptions])
                @endif
            @endforeach
            <div class="field search-field"><label for="catalog-search">{{ $t('search') }}</label><input id="catalog-search" name="q" type="search" value="{{ $filters['q'] ?? '' }}" placeholder="{{ $t('search_hint') }}"></div>
        </fieldset>
        <div class="filter-date-row"><div>@include('website.partials.dates')</div><button class="button button-primary" type="submit">{{ $t('show_vehicles') }}</button></div>
        <a class="reset-link" href="{{ $navigation[$catalog] }}">{{ $t('reset') }}</a>
    </form>
    @include('website.partials.errors')
    <div class="section-heading"><div><p class="eyebrow">{{ $t('choose_vehicle') }}</p><h2 id="catalog-heading">{{ $t('nav_'.$catalog) }}</h2></div><p class="muted">{{ $t('results', ['count' => $vehicles->total()]) }}</p></div>
    <div class="vehicle-grid vehicle-grid-{{ $catalog }}">@forelse ($vehicles as $vehicle)@include('website.partials.card')@empty<div class="empty-state"><h3>{{ $t('empty_title') }}</h3><p>{{ $t('empty_body') }}</p><a class="button button-outline" href="{{ $navigation[$catalog] }}">{{ $t('reset') }}</a></div>@endforelse</div>
    @if($vehicles->hasPages())<nav class="pagination" aria-label="Pages">@if($vehicles->previousPageUrl())<a class="button button-outline" href="{{ $vehicles->previousPageUrl() }}" rel="prev">{{ $vehicles->currentPage() - 1 }}</a>@endif<span aria-current="page">{{ $vehicles->currentPage() }} / {{ $vehicles->lastPage() }}</span>@if($vehicles->nextPageUrl())<a class="button button-outline" href="{{ $vehicles->nextPageUrl() }}" rel="next">{{ $vehicles->currentPage() + 1 }}</a>@endif</nav>@endif
</section>
@endsection
