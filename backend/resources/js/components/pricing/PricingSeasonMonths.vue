<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { pricingSeasonLabels, pricingSeasons } from '@/lib/pricingTools';

type Season = (typeof pricingSeasons)[number];

const props = defineProps<{
    calendar: Season[];
    disabled?: boolean;
}>();
const emit = defineEmits<{ apply: [calendar: Season[]] }>();
const { t, locale } = useLocale();

const draft = ref<Season[]>([...props.calendar]);
const brush = ref<Season>('high');

watch(
    () => props.calendar,
    (calendar) => {
        draft.value = [...calendar];
    },
);

const months = computed(() =>
    Array.from({ length: 12 }, (_, index) => {
        const date = new Date(2026, index, 1);
        const lang = locale.value === 'ru' ? 'ru-RU' : 'en-US';

        return {
            index,
            number: index + 1,
            short: date
                .toLocaleDateString(lang, { month: 'short' })
                .replace('.', '')
                .toUpperCase(),
            long: date.toLocaleDateString(lang, { month: 'long' }),
        };
    }),
);
const changed = computed(() =>
    draft.value.some((season, index) => season !== props.calendar[index]),
);
const counts = computed(
    () =>
        Object.fromEntries(
            pricingSeasons.map((s) => [
                s,
                draft.value.filter((season) => season === s).length,
            ]),
        ) as Record<Season, number>,
);

function paint(index: number): void {
    if (props.disabled) {
        return;
    }

    draft.value[index] = brush.value;
}
function cycle(index: number): void {
    if (props.disabled) {
        return;
    }

    const next =
        pricingSeasons[
            (pricingSeasons.indexOf(draft.value[index]) + 1) %
                pricingSeasons.length
        ];
    draft.value[index] = next;
}
function reset(): void {
    draft.value = [...props.calendar];
}
</script>

<template>
    <div class="season-months">
        <p class="my-3 text-sm text-muted-foreground">
            {{
                t(
                    'Choose which season applies to each month. Pick a season, then click months to assign it (right-click cycles). Applying updates every preset, every linked vehicle and legacy quotes. Existing bookings are not recalculated.',
                )
            }}
        </p>

        <div class="season-brushes" role="radiogroup" :aria-label="t('Season')">
            <button
                v-for="s in pricingSeasons"
                :key="s"
                type="button"
                role="radio"
                :aria-checked="brush === s"
                :disabled="disabled"
                :class="['season-brush', `tile-${s}`, { active: brush === s }]"
                @click="brush = s"
            >
                <span class="swatch" />
                {{ t(pricingSeasonLabels[s]) }}
                <span class="count">{{ counts[s] }}</span>
            </button>
        </div>

        <div class="season-grid">
            <button
                v-for="month in months"
                :key="month.index"
                type="button"
                :disabled="disabled"
                :class="[
                    'season-tile',
                    `tile-${draft[month.index]}`,
                    { modified: draft[month.index] !== calendar[month.index] },
                ]"
                :title="`${month.long} · ${t(pricingSeasonLabels[draft[month.index]])}`"
                :aria-label="`${month.long}: ${t(pricingSeasonLabels[draft[month.index]])}`"
                @click="paint(month.index)"
                @contextmenu.prevent="cycle(month.index)"
            >
                <span class="tile-number">{{ month.number }}</span>
                <span class="tile-month">{{ month.short }}</span>
                <span class="tile-season">{{
                    t(pricingSeasonLabels[draft[month.index]])
                }}</span>
            </button>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <Button
                type="button"
                :disabled="disabled || !changed"
                @click="emit('apply', [...draft])"
                >{{ t('Apply season calendar to all prices') }}</Button
            >
            <Button
                type="button"
                variant="outline"
                :disabled="disabled || !changed"
                @click="reset"
                >{{ t('Reset changes') }}</Button
            >
            <span v-if="changed" class="text-sm text-amber-600">{{
                t('Unsaved season changes')
            }}</span>
        </div>
    </div>
</template>

<style scoped>
.season-months {
    --season-high: #c44b53;
    --season-middle: #c29e41;
    --season-low: #4ea063;
}
.tile-high {
    --tile: var(--season-high);
}
.tile-middle {
    --tile: var(--season-middle);
}
.tile-low {
    --tile: var(--season-low);
}
.season-brushes {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 12px;
}
.season-brush {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 14px;
    font-weight: 500;
    background: color-mix(in srgb, var(--tile) 10%, var(--card));
}
.season-brush.active {
    border-color: var(--tile);
    box-shadow: 0 0 0 1px var(--tile);
    background: color-mix(in srgb, var(--tile) 26%, var(--card));
}
.season-brush .swatch {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--tile);
}
.season-brush .count {
    min-width: 20px;
    border-radius: 999px;
    padding: 0 6px;
    font-size: 12px;
    font-variant-numeric: tabular-nums;
    background: color-mix(in srgb, var(--foreground) 10%, transparent);
}
.season-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    border: 1px solid var(--border);
    border-radius: 6px;
    overflow: hidden;
}
.season-tile {
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 10px 4px;
    border-right: 1px solid var(--border);
    background: color-mix(in srgb, var(--tile) 18%, var(--card));
    cursor: pointer;
    font-variant-numeric: tabular-nums;
}
.season-tile:last-child {
    border-right: 0;
}
.season-tile:hover:not(:disabled) {
    background: color-mix(in srgb, var(--tile) 32%, var(--card));
}
.season-tile:disabled {
    cursor: not-allowed;
    opacity: 0.7;
}
.season-tile.modified {
    outline: 2px solid var(--tile);
    outline-offset: -2px;
}
.tile-number {
    font-size: 15px;
    font-weight: 700;
}
.tile-month {
    font-size: 12px;
    font-weight: 600;
    letter-spacing: 0.04em;
}
.tile-season {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 11px;
    color: var(--muted-foreground);
}
.season-brush,
.season-tile {
    transition:
        background-color 350ms ease,
        border-color 350ms ease,
        box-shadow 350ms ease,
        outline-color 350ms ease;
}
@media (max-width: 900px) {
    .season-grid {
        grid-template-columns: repeat(6, minmax(0, 1fr));
    }
    .season-tile:nth-child(6) {
        border-right: 0;
    }
    .season-tile:nth-child(-n + 6) {
        border-bottom: 1px solid var(--border);
    }
}
@media (max-width: 480px) {
    .season-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .season-tile:nth-child(3n) {
        border-right: 0;
    }
    .season-tile:nth-child(-n + 9) {
        border-bottom: 1px solid var(--border);
    }
}
@media (prefers-reduced-motion: reduce) {
    .season-brush,
    .season-tile {
        transition: none;
    }
}
</style>
