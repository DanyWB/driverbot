@php
    $selected = (string) ($value ?? '');
    $selectId = 'select-'.$name;
    $options = collect($options)->map(fn ($option) => is_array($option) ? $option : ['value' => (string) $option, 'label' => (string) $option]);
    $selectedLabel = $options->first(fn ($option) => (string) $option['value'] === $selected)['label'] ?? $t('all');
@endphp
<div class="field custom-select" data-custom-select>
    <label id="{{ $selectId }}-label">{{ $label }}</label>
    <details>
        <summary class="select-trigger" role="combobox" aria-haspopup="listbox" aria-expanded="false" aria-controls="{{ $selectId }}-options" aria-labelledby="{{ $selectId }}-label {{ $selectId }}-value"><span id="{{ $selectId }}-value" data-select-value>{{ $selectedLabel }}</span><span class="chevron" aria-hidden="true"></span></summary>
        <div class="select-options" id="{{ $selectId }}-options" role="listbox" aria-labelledby="{{ $selectId }}-label">
            @foreach ($options as $option)
                <label role="option" aria-selected="{{ (string) $option['value'] === $selected ? 'true' : 'false' }}" data-select-option>
                    <input type="radio" name="{{ $name }}" value="{{ $option['value'] }}" @checked((string) $option['value'] === $selected)><span>{{ $option['label'] }}</span>
                </label>
            @endforeach
        </div>
    </details>
</div>
