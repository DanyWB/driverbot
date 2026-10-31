@php
    $catalogContext = $catalogContext ?? false;
    $pageHasHero = collect($cms_page['blocks'])->contains(fn ($block) => $block['type'] === 'hero');
    $cmsHeroSeen = false;
@endphp
@unless($pageHasHero)
    <div class="container section cms-page-heading"><h1 class="page-title">{{ $cms_page['title'] }}</h1></div>
@endunless
@foreach($cms_page['blocks'] as $block)
    @php
        $blockProps = $block['props'];
        $blockHeadingId = 'cms-heading-'.$block['id'];
        $blockImage = $blockProps['image'] ?? null;
    @endphp
    @if($block['type'] === 'hero')
        <section class="home-hero cms-hero" aria-labelledby="{{ $blockHeadingId }}">
            @if($blockImage)
                <picture class="hero-picture"><img src="{{ $blockImage['url'] }}" alt="{{ $blockImage['alt'] }}" width="{{ $blockImage['width'] }}" height="{{ $blockImage['height'] }}" fetchpriority="high"></picture>
            @endif
            <div class="container hero-content">
                @if(!$cmsHeroSeen)
                    <h1 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h1>
                @else
                    <h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2>
                @endif
                @php
                    $cmsHeroSeen = true;
                @endphp
                <p class="hero-description cms-plain-text">{{ $blockProps['body'] }}</p>
                <div class="hero-buttons"><a class="button button-primary" href="{{ $navigation[$blockProps['action']] }}">{{ $blockProps['action_label'] }}</a></div>
            </div>
        </section>
    @elseif($block['type'] === 'catalog')
        @if($catalogContext)
            <div class="container cms-catalog-heading"><h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2></div>
        @else
            @php
                $blockVehicles = array_slice($cms_catalogs[$blockProps['vehicle_type']] ?? [], 0, $blockProps['limit']);
            @endphp
            <section class="container section" aria-labelledby="{{ $blockHeadingId }}"><h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2><div class="vehicle-grid vehicle-grid-{{ $blockProps['vehicle_type'] === 'car' ? 'cars' : 'scooters' }} cms-catalog-grid">
                @foreach($blockVehicles as $cmsVehicle)
                    @include('website.partials.card', ['vehicle' => $cmsVehicle])
                @endforeach
            </div></section>
        @endif
    @elseif($block['type'] === 'text_image')
        <section class="container section cms-text-image" aria-labelledby="{{ $blockHeadingId }}"><div><h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2><p class="cms-plain-text">{{ $blockProps['body'] }}</p></div>
            @if($blockImage)
                <img src="{{ $blockImage['url'] }}" alt="{{ $blockImage['alt'] }}" width="{{ $blockImage['width'] }}" height="{{ $blockImage['height'] }}" loading="lazy">
            @endif
        </section>
    @elseif($block['type'] === 'steps')
        <section class="container section how-section" id="{{ $blockHeadingId }}" aria-labelledby="{{ $blockHeadingId }}-title"><h2 id="{{ $blockHeadingId }}-title">{{ $blockProps['heading'] }}</h2><ol class="steps-grid">
            @foreach($blockProps['items'] as $item)
                <li><span class="step-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><h3>{{ $item['title'] }}</h3><p class="cms-plain-text">{{ $item['body'] }}</p></li>
            @endforeach
        </ol></section>
    @elseif($block['type'] === 'faq')
        <section class="container section cms-faq" aria-labelledby="{{ $blockHeadingId }}"><h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2>
            @foreach($blockProps['items'] as $item)
                <details data-disclosure><summary>{{ $item['question'] }}</summary><p class="cms-plain-text">{{ $item['answer'] }}</p></details>
            @endforeach
        </section>
    @elseif($block['type'] === 'contact_cta')
        @php
            $channel = $website['contacts'][$blockProps['channel']] ?? null;
            $channelUrl = is_array($channel) ? ($channel['url'] ?? null) : $channel;
        @endphp
        <section class="container section cms-contact" aria-labelledby="{{ $blockHeadingId }}"><h2 id="{{ $blockHeadingId }}">{{ $blockProps['heading'] }}</h2><p class="cms-plain-text">{{ $blockProps['body'] }}</p>
            @if($channelUrl)
                <a class="button button-primary" href="{{ $channelUrl }}" rel="noopener">{{ $blockProps['action_label'] }}</a>
            @endif
        </section>
    @endif
@endforeach
