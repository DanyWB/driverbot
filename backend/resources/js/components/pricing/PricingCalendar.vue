<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import { computed, nextTick, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/composables/useLocale';
import { pricingSeasonLabels } from '@/lib/pricingTools';

const start = defineModel<string>('start', { required: true });
const end = defineModel<string>('end', { required: true });
const props = defineProps<{ seasonCalendar: ('high' | 'middle' | 'low')[] }>();
const { t, locale } = useLocale();
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const opened = ref(false);
const view = ref(new Date(new Date().getFullYear(), new Date().getMonth(), 1));
const chooseEnd = ref(false);
const language = computed(() => (locale.value === 'ru' ? 'ru-RU' : 'en-GB'));
function iso(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}
function parse(value: string): Date {
    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}
function display(value: string): string {
    return value
        ? parse(value).toLocaleDateString(language.value, {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : t('Select date');
}
const months = computed(() =>
    Array.from({ length: 3 }, (_, offset) => {
        const first = new Date(
            view.value.getFullYear(),
            view.value.getMonth() + offset,
            1,
        );

        return {
            key: iso(first),
            label: first.toLocaleDateString(language.value, {
                month: 'long',
                year: 'numeric',
            }),
            season: props.seasonCalendar[first.getMonth()],
            padding: (first.getDay() + 6) % 7,
            days: Array.from(
                {
                    length: new Date(
                        first.getFullYear(),
                        first.getMonth() + 1,
                        0,
                    ).getDate(),
                },
                (_, index) =>
                    iso(
                        new Date(
                            first.getFullYear(),
                            first.getMonth(),
                            index + 1,
                        ),
                    ),
            ),
        };
    }),
);
const weekdays = computed(() =>
    Array.from({ length: 7 }, (_, day) =>
        new Date(2026, 9, 26 + day).toLocaleDateString(language.value, {
            weekday: 'short',
        }),
    ),
);
function select(value: string): void {
    if (!chooseEnd.value || !start.value || value <= start.value) {
        start.value = value;
        end.value = '';
        chooseEnd.value = true;
    } else {
        end.value = value;
        chooseEnd.value = false;
        opened.value = false;
        trigger.value?.focus();
    }
}
function toggle(): void {
    opened.value = !opened.value;

    if (opened.value) {
        chooseEnd.value = Boolean(start.value && !end.value);
        const date = start.value ? parse(start.value) : new Date();
        view.value = new Date(date.getFullYear(), date.getMonth(), 1);
        void nextTick(() =>
            root.value
                ?.querySelector<HTMLButtonElement>(
                    `[data-date="${start.value || iso(date)}"]`,
                )
                ?.focus(),
        );
    }
}
function shift(offset: number): void {
    view.value = new Date(
        view.value.getFullYear(),
        view.value.getMonth() + offset,
        1,
    );
}
async function key(event: KeyboardEvent, value: string): Promise<void> {
    const offset =
        event.key === 'ArrowRight'
            ? 1
            : event.key === 'ArrowLeft'
              ? -1
              : event.key === 'ArrowDown'
                ? 7
                : event.key === 'ArrowUp'
                  ? -7
                  : 0;

    if (offset) {
        event.preventDefault();
        const date = parse(value);
        date.setDate(date.getDate() + offset);
        const next = iso(date);

        if (
            date.getMonth() !== view.value.getMonth() ||
            date.getFullYear() !== view.value.getFullYear()
        ) {
            view.value = new Date(date.getFullYear(), date.getMonth(), 1);
        }

        await nextTick();
        root.value
            ?.querySelector<HTMLButtonElement>(`[data-date="${next}"]`)
            ?.focus();
    }
}
useEventListener(document, 'pointerdown', (event: PointerEvent) => {
    if (opened.value && !root.value?.contains(event.target as Node)) {
        opened.value = false;
    }
});
</script>

<template>
    <div
        ref="root"
        class="pricing-calendar"
        @keydown.esc.prevent="
            opened = false;
            trigger?.focus();
        "
        @focusout="
            !root?.contains($event.relatedTarget as Node) && (opened = false)
        "
    >
        <button
            ref="trigger"
            type="button"
            class="calendar-trigger"
            :aria-expanded="opened"
            aria-haspopup="dialog"
            @click="toggle"
        >
            <span>{{ display(start) }}</span
            ><span aria-hidden="true">→</span><span>{{ display(end) }}</span>
        </button>
        <Transition name="pricing-calendar"
            ><section
                v-if="opened"
                role="dialog"
                :aria-label="t('Rental period')"
                class="calendar-popover"
            >
                <div class="mb-3 flex items-center justify-between gap-3">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :aria-label="t('Previous month')"
                        @click="shift(-1)"
                        >‹</Button
                    >
                    <p class="text-sm font-medium" aria-live="polite">
                        {{
                            t(
                                chooseEnd
                                    ? 'Select return date'
                                    : 'Select pickup date',
                            )
                        }}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :aria-label="t('Next month')"
                        @click="shift(1)"
                        >›</Button
                    >
                </div>
                <div class="calendar-months">
                    <section
                        v-for="(month, index) in months"
                        :key="month.key"
                        class="calendar-month"
                        :class="`month-${index}`"
                    >
                        <header
                            class="mb-3 flex items-center justify-between gap-2"
                        >
                            <h4 class="text-sm font-semibold">
                                {{ month.label }}
                            </h4>
                            <span class="text-xs">{{
                                t(pricingSeasonLabels[month.season])
                            }}</span>
                        </header>
                        <div class="calendar-grid weekdays">
                            <span v-for="day in weekdays" :key="day">{{
                                day
                            }}</span>
                        </div>
                        <div class="calendar-grid">
                            <span
                                v-for="i in month.padding"
                                :key="`pad-${i}`"
                            ></span
                            ><button
                                v-for="date in month.days"
                                :key="date"
                                type="button"
                                :data-date="date"
                                class="calendar-day"
                                :class="[
                                    `season-${month.season}`,
                                    {
                                        'range-day':
                                            start &&
                                            end &&
                                            date > start &&
                                            date < end,
                                        'selected-day':
                                            date === start || date === end,
                                    },
                                ]"
                                :aria-label="`${display(date)} · ${t(pricingSeasonLabels[month.season])}`"
                                :aria-pressed="date === start || date === end"
                                @click="select(date)"
                                @keydown="key($event, date)"
                            >
                                {{ Number(date.slice(-2)) }}
                            </button>
                        </div>
                    </section>
                </div>
                <div
                    class="mt-3 flex items-center justify-between gap-3 border-t pt-3"
                >
                    <span class="text-xs text-muted-foreground">{{
                        t('The return day is excluded.')
                    }}</span
                    ><Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="
                            start = '';
                            end = '';
                            chooseEnd = false;
                        "
                        >{{ t('Clear dates') }}</Button
                    >
                </div>
            </section></Transition
        >
    </div>
</template>

<style scoped>
.pricing-calendar {
    position: relative;
    min-width: 0;
}
.calendar-trigger {
    display: flex;
    width: 100%;
    min-height: 40px;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    border: 1px solid var(--input);
    border-radius: 8px;
    background: var(--background);
    padding: 8px 12px;
    text-align: left;
    font-size: 13px;
}
.calendar-popover {
    position: absolute;
    top: calc(100% + 8px);
    left: 0;
    z-index: 30;
    width: min(850px, calc(100vw - 72px));
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card);
    padding: 16px;
    box-shadow: 0 20px 70px #0005;
}
.calendar-months {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}
.calendar-month {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px;
}
.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 3px;
}
.weekdays {
    margin-bottom: 5px;
    color: var(--muted-foreground);
    font-size: 10px;
    text-align: center;
}
.calendar-day {
    min-width: 0;
    min-height: 32px;
    border: 1px solid transparent;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
}
.season-high {
    background: color-mix(in srgb, #c44b53 20%, var(--card));
}
.season-middle {
    background: color-mix(in srgb, #c29e41 20%, var(--card));
}
.season-low {
    background: color-mix(in srgb, #4ea063 20%, var(--card));
}
.calendar-day:hover,
.calendar-day:focus-visible {
    outline: 2px solid var(--ring);
    outline-offset: 1px;
}
.range-day {
    filter: brightness(1.2);
    border-radius: 3px;
}
.calendar-day.selected-day {
    background: var(--foreground);
    color: var(--background);
    border-color: var(--foreground);
}
.calendar-trigger,
.calendar-day {
    transition:
        background-color 350ms ease,
        border-color 350ms ease,
        color 350ms ease,
        filter 350ms ease;
}
.pricing-calendar-enter-active,
.pricing-calendar-leave-active {
    transition:
        opacity 350ms ease,
        transform 350ms ease;
}
.pricing-calendar-enter-from,
.pricing-calendar-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
@media (max-width: 980px) {
    .calendar-months {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .month-2 {
        display: none;
    }
    .calendar-popover {
        width: min(600px, calc(100vw - 72px));
    }
}
@media (max-width: 640px) {
    .calendar-months {
        grid-template-columns: 1fr;
    }
    .month-1 {
        display: none;
    }
    .calendar-popover {
        width: min(360px, calc(100vw - 72px));
    }
}
@media (prefers-reduced-motion: reduce) {
    .pricing-calendar-enter-active,
    .pricing-calendar-leave-active,
    .calendar-trigger,
    .calendar-day {
        transition: none;
    }
}
</style>
