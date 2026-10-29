<script setup lang="ts">
import { Head, Link, router, useRemember } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    ChevronDown,
    Expand,
    FilterX,
    Minus,
    Minimize,
    Plus,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    unref,
    watch,
} from 'vue';
import AdminDateInput from '@/components/AdminDateInput.vue';
import AdminSelect from '@/components/AdminSelect.vue';
import TimelineBookingSheet from '@/components/timeline/TimelineBookingSheet.vue';
import TimelineVehicleName from '@/components/timeline/TimelineVehicleName.vue';
import TimelineVehicleInfo from '@/components/timeline/TimelineVehicleInfo.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getActiveLocale, useLocale } from '@/composables/useLocale';
import { lockBodyScroll } from '@/lib/bodyScrollLock';
import { timelineEscapeAction } from '@/lib/timelineInteraction';
import {
    timelineTouchPair,
    timelineZoom,
    timelineZoomDay,
    timelineZoomLimits,
    timelineZoomScroll,
    timelineZoomTop,
} from '@/lib/timelineZoom';
import {
    timelineAnchorScroll,
    timelineBookingGeometry,
    timelineDayWidth,
    timelineGridWidth,
    timelineOccupancyLayout,
    timelinePointerMoved,
    timelineScaledVehicleWidth,
    timelineVisibleDay,
} from '@/lib/timelineGeometry';
import { timelineStatusClasses, timelineStatusLabels } from '@/lib/timeline';
import {
    filterTimelineRows,
    timelineVehicleModels,
    timelineVisibleStats,
    occupancyLanes,
    timelineMonthBands,
    vehicleNameLines,
    vehicleNameParts,
    vehicleRowColor,
} from '@/lib/timelinePresentation';
import {
    shiftTimelineRange,
    timelineQuickRangeDays,
    timelineRangeFromToday,
    timelineTwoMonthRange,
} from '@/lib/timelinePeriod';
import type { TimelineQuickRangeDays } from '@/lib/timelinePeriod';
import type {
    TimelineData,
    TimelineBookingSelection,
    TimelineFilters,
    TimelineOccupancy,
    TimelineOptions,
    TimelineVehicle,
} from '@/types';

const props = defineProps<{
    timeline: TimelineData;
    filters: TimelineFilters;
    options: TimelineOptions;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Timeline', href: '/timeline' }],
    },
});

const viewportWidth = ref(1024);
const longestNameWidth = ref(0);
const longestStackedNameWidth = ref(0);
const narrowVehicleColumn = ref(false);
const zoom = ref(1);
const timelineHeaderRangeDays = timelineQuickRangeDays.filter(
    (days) => days !== 45,
);
const display = useRemember(
    {
        filtersOpen: true,
        compact: true,
        customRange: false,
        dayWidth: 48,
    },
    'Timeline:display',
);
const vehicleHeaderHeight = computed(() => Math.max(80, 96 * zoom.value));
const vehicleColumnWidth = computed(() =>
    timelineScaledVehicleWidth(
        narrowVehicleColumn.value
            ? longestStackedNameWidth.value
            : longestNameWidth.value,
        zoom.value,
        narrowVehicleColumn.value,
    ),
);
const dayWidth = computed(() =>
    Math.max(24, timelineDayWidth(unref(display).dayWidth) * zoom.value),
);
const zoomPercent = computed(() => Math.round(zoom.value * 100));
const zoomStyles = computed(() => ({ '--timeline-zoom': zoom.value }));
const localizedDates = computed(() =>
    props.timeline.dates.map((day) => ({
        ...day,
        weekdayIndex: new Date(`${day.date}T00:00:00Z`).getUTCDay(),
        month: new Intl.DateTimeFormat(getActiveLocale(), {
            month: 'long',
            timeZone: 'UTC',
        }).format(new Date(day.date)),
        weekday: new Intl.DateTimeFormat(getActiveLocale(), {
            weekday: 'short',
            timeZone: 'UTC',
        }).format(new Date(day.date)),
    })),
);
const monthBands = computed(() => timelineMonthBands(localizedDates.value));
const timelineHead = ref<HTMLElement | null>(null);
const timelineBody = ref<HTMLElement | null>(null);
let visibleDay = 0;
let restoringScroll = false;
let restoreRevision = 0;
let resizeObserver: ResizeObserver | undefined;
const vehicleInfoOpen = ref(false);
let pointerGesture: {
    id: number;
    x: number;
    y: number;
    moved: boolean;
} | null = null;
let mousePan: {
    id: number;
    x: number;
    y: number;
    scrollLeft: number;
    scrollTop: number;
    active: boolean;
} | null = null;
let suppressPointerClick = false;
let pinchOwned = false;
let pinch: {
    ids: number[];
    distance: number;
    zoom: number;
    day: number;
    contentY: number;
    rowAnchor: { id: number; fraction: number } | null;
} | null = null;
let zoomAnchor: {
    day: number;
    x: number;
    y: number;
    contentY: number;
    rowAnchor: { id: number; fraction: number } | null;
} | null = null;
function syncHorizontal(event: Event, target: HTMLElement | null): void {
    const source = event.currentTarget as HTMLElement;

    if (restoringScroll) {
        return;
    }

    visibleDay = timelineVisibleDay(source.scrollLeft, dayWidth.value);

    if (target && target.scrollLeft !== source.scrollLeft) {
        target.scrollLeft = source.scrollLeft;
    }
}
const hoverDateIndex = ref<number | null>(null);
const loading = ref(false);
const isFullscreen = ref(false);
const timelineContainer = ref<HTMLElement | null>(null);
const rangeError = ref('');
const selectionError = ref('');
const bookingSheetOpen = ref(false);
const bookingSelection = ref<TimelineBookingSelection | null>(null);
const rangeSelection = ref<{
    vehicleId: number;
    vehicleName: string;
    startsOn: string;
    startIndex: number;
} | null>(null);
const hoveredCell = ref<{
    vehicleId: number;
    index: number;
} | null>(null);
const filterState = reactive<TimelineFilters>({ ...props.filters });
const { t } = useLocale();
const vehicleNameSearch = ref('');
const vehicleModelFilter = ref('');
const vehicleModelOptions = computed(() => {
    const models = timelineVehicleModels(props.timeline.rows);

    return [
        { value: '', label: t('All models') },
        ...models.map((model) => ({ value: model, label: model })),
    ];
});
const visibleTimelineRows = computed(() => {
    return filterTimelineRows(
        props.timeline.rows,
        vehicleNameSearch.value,
        vehicleModelFilter.value,
    );
});
const visibleStats = computed(() =>
    timelineVisibleStats(visibleTimelineRows.value),
);
watch(vehicleModelOptions, (options) => {
    if (!options.some((option) => option.value === vehicleModelFilter.value)) {
        vehicleModelFilter.value = '';
    }
});
watch([vehicleNameSearch, vehicleModelFilter], () => {
    clearTimelineSelection();
    hoverDateIndex.value = null;
});
let releaseBodyScroll: (() => void) | undefined;

watch(
    () => props.filters,
    (filters) => Object.assign(filterState, filters),
    { deep: true },
);

watch(isFullscreen, (fullscreen) => {
    releaseBodyScroll?.();
    releaseBodyScroll = fullscreen ? lockBodyScroll(document) : undefined;
});

const gridWidth = computed(() =>
    timelineGridWidth(
        vehicleColumnWidth.value,
        props.timeline.dates.length,
        dayWidth.value,
    ),
);

watch([dayWidth, vehicleColumnWidth, isFullscreen, viewportWidth], async () => {
    const revision = ++restoreRevision;
    const anchor = visibleDay;
    const gestureAnchor = zoomAnchor;
    zoomAnchor = null;
    restoringScroll = true;
    await nextTick();

    if (revision !== restoreRevision) {
        return;
    }

    const body = timelineBody.value;

    if (body) {
        const scrollLeft = gestureAnchor
            ? timelineZoomScroll(
                  gestureAnchor.day,
                  gestureAnchor.x,
                  vehicleColumnWidth.value,
                  dayWidth.value,
                  gridWidth.value,
                  body.clientWidth,
              )
            : timelineAnchorScroll(
                  anchor,
                  dayWidth.value,
                  gridWidth.value,
                  body.clientWidth,
              );
        body.scrollLeft = scrollLeft;

        if (gestureAnchor) {
            const anchoredRow = gestureAnchor.rowAnchor
                ? body.querySelector<HTMLElement>(
                      `[data-timeline-vehicle="${gestureAnchor.rowAnchor.id}"]`,
                  )
                : null;
            body.scrollTop =
                anchoredRow && gestureAnchor.rowAnchor
                    ? anchoredRow.offsetTop +
                      anchoredRow.offsetHeight *
                          gestureAnchor.rowAnchor.fraction -
                      gestureAnchor.y
                    : timelineZoomTop(
                          gestureAnchor.contentY,
                          gestureAnchor.y,
                          zoom.value,
                          body.scrollHeight,
                          body.clientHeight,
                      );
        }

        if (timelineHead.value) {
            timelineHead.value.scrollLeft = scrollLeft;
        }

        visibleDay = timelineVisibleDay(scrollLeft, dayWidth.value);
    }

    // Keep generated scroll events from re-anchoring during the same frame.
    requestAnimationFrame(() => {
        if (revision === restoreRevision) {
            restoringScroll = false;
        }
    });
});

function changeZoom(direction: -1 | 0 | 1): void {
    zoom.value = timelineZoom(
        direction === 0
            ? 1
            : Math.round((zoom.value + direction * 0.05) * 100) / 100,
    );
}

function measureVehicleNames(): void {
    const name = timelineBody.value?.querySelector('.timeline-name');

    if (!name) {
        return;
    }

    const context = document.createElement('canvas').getContext('2d');

    if (!context) {
        return;
    }

    const style = getComputedStyle(name);
    // Measure the unscaled 12px name font. Zoom is applied to the complete grid.
    // All customer Timeline names use Rajdhani 700; geometry reserves the
    // separate unscaled accessible info target rather than scaling the DOM.
    context.font = `700 12px ${style.fontFamily}`;
    longestStackedNameWidth.value = Math.max(
        0,
        ...props.timeline.rows.map((row) => {
            const lines = vehicleNameLines(row.name);

            return Math.max(
                context.measureText(lines.model).width,
                context.measureText(lines.details).width,
            );
        }),
    );
    longestNameWidth.value = Math.max(
        0,
        ...props.timeline.rows.map(
            (row) => context.measureText(row.name).width,
        ),
    );
}

function startTimelineTouch(event: TouchEvent): void {
    const surface = event.currentTarget as HTMLElement;
    // Both contacts must originate inside this grid, never an outside control.
    const touches = Array.from(event.touches);

    if (
        touches.length !== 2 ||
        touches.some(
            (touch) =>
                !(touch.target instanceof Node) ||
                !surface.contains(touch.target),
        )
    ) {
        return;
    }

    const pair = timelineTouchPair(touches);

    if (!pair || !event.cancelable) {
        return;
    }

    event.preventDefault();
    pinchOwned = true;
    suppressPointerClick = true;
    const rect = surface.getBoundingClientRect();
    const anchoredRow = Array.from(
        surface.querySelectorAll<HTMLElement>('[data-timeline-vehicle]'),
    ).find((row) => {
        const bounds = row.getBoundingClientRect();

        return pair.y >= bounds.top && pair.y < bounds.bottom;
    });
    const rowBounds = anchoredRow?.getBoundingClientRect();
    pinch = {
        ids: touches.map((touch) => touch.identifier),
        distance: pair.distance,
        zoom: zoom.value,
        day: timelineZoomDay(
            surface.scrollLeft,
            pair.x - rect.left,
            vehicleColumnWidth.value,
            dayWidth.value,
        ),
        contentY: (surface.scrollTop + pair.y - rect.top) / zoom.value,
        rowAnchor:
            anchoredRow && rowBounds
                ? {
                      id: Number(anchoredRow.dataset.timelineVehicle),
                      fraction: (pair.y - rowBounds.top) / rowBounds.height,
                  }
                : null,
    };
}

function moveTimelineTouch(event: TouchEvent): void {
    if (!pinchOwned) {
        return;
    }

    // Once claimed, do not turn the remaining finger into a tap/drag.
    if (!event.cancelable) {
        return;
    }

    event.preventDefault();
    const touches = Array.from(event.touches);
    const pair = timelineTouchPair(touches, pinch?.ids);

    if (!pinch || !pair) {
        return;
    }

    const nextZoom = timelineZoom(
        (pinch.zoom * pair.distance) / pinch.distance,
    );

    if (nextZoom === zoom.value) {
        return;
    }

    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    zoomAnchor = {
        day: pinch.day,
        x: pair.x - rect.left,
        y: pair.y - rect.top,
        contentY: pinch.contentY,
        rowAnchor: pinch.rowAnchor,
    };
    zoom.value = nextZoom;
}

function endTimelineTouch(event: TouchEvent): void {
    if (!pinchOwned) {
        return;
    }

    suppressPointerClick = true;

    if (event.cancelable) {
        event.preventDefault();
    }

    pinch = null;

    if (event.touches.length === 0) {
        pinchOwned = false;
    }
}

function guardGestureClick(event: MouseEvent): void {
    if (event.detail !== 0 && (pinchOwned || suppressPointerClick)) {
        event.preventDefault();
        event.stopPropagation();
    }
}

function startPointerGesture(event: PointerEvent): void {
    if (pinchOwned) {
        return;
    }

    if (event.pointerType === 'mouse') {
        const target = event.target;
        const surface = event.currentTarget as HTMLElement;

        suppressPointerClick = false;
        pointerGesture = null;
        mousePan = null;

        if (
            event.button !== 0 ||
            !(target instanceof Element) ||
            target.closest(
                'a, button:not(.timeline-day), input, select, textarea, [role="separator"]',
            )
        ) {
            return;
        }

        mousePan = {
            id: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            scrollLeft: surface.scrollLeft,
            scrollTop: surface.scrollTop,
            active: false,
        };

        return;
    }

    if (pointerGesture) {
        pointerGesture.moved = true;
        suppressPointerClick = true;

        return;
    }

    suppressPointerClick = false;
    pointerGesture = {
        id: event.pointerId,
        x: event.clientX,
        y: event.clientY,
        moved: false,
    };
}

function movePointerGesture(event: PointerEvent): void {
    if (mousePan?.id === event.pointerId) {
        const surface = event.currentTarget as HTMLElement;
        const deltaX = event.clientX - mousePan.x;
        const deltaY = event.clientY - mousePan.y;

        if (!mousePan.active && Math.hypot(deltaX, deltaY) >= 5) {
            mousePan.active = true;
            suppressPointerClick = true;
            surface.classList.add('timeline-panning');
            surface.setPointerCapture(event.pointerId);
        }

        if (mousePan.active) {
            if (event.cancelable) {
                event.preventDefault();
            }

            surface.scrollLeft = mousePan.scrollLeft - deltaX;
            surface.scrollTop = mousePan.scrollTop - deltaY;
        }

        return;
    }

    if (!pointerGesture || pointerGesture.id !== event.pointerId) {
        return;
    }

    if (
        timelinePointerMoved(pointerGesture, {
            x: event.clientX,
            y: event.clientY,
        })
    ) {
        pointerGesture.moved = true;
        suppressPointerClick = true;
    }
}

function endPointerGesture(event: PointerEvent): void {
    if (mousePan?.id === event.pointerId) {
        const surface = event.currentTarget as HTMLElement;

        if (mousePan.active) {
            suppressPointerClick = true;
        }

        surface.classList.remove('timeline-panning');

        if (surface.hasPointerCapture(event.pointerId)) {
            surface.releasePointerCapture(event.pointerId);
        }

        mousePan = null;

        return;
    }

    if (pointerGesture?.id !== event.pointerId) {
        return;
    }

    suppressPointerClick =
        suppressPointerClick ||
        pinchOwned ||
        pointerGesture.moved ||
        event.type === 'pointercancel';
    pointerGesture = null;
}

function handleDateClick(
    event: MouseEvent,
    row: TimelineVehicle,
    date: string,
    index: number,
): void {
    // Keyboard activation has detail=0 and remains available after a scroll.
    if (event.detail !== 0 && (suppressPointerClick || pointerGesture?.moved)) {
        return;
    }

    selectTimelineDate(row, date, index);
}
const rangeLabel = computed(() =>
    formatRange(props.timeline.range.starts_on, props.timeline.range.ends_on),
);
const activeQuickRangeDays = computed(() => {
    if (filterState.starts_on !== props.timeline.range.today) {
        return null;
    }

    const start = parseIsoDate(filterState.starts_on);
    const end = parseIsoDate(filterState.ends_on);

    return start && end ? inclusiveDays(start, end) : null;
});
const filteredCategories = computed(() =>
    props.options.categories.filter(
        (category) =>
            !filterState.vehicle_type ||
            category.vehicle_type === filterState.vehicle_type,
    ),
);
const filteredVehicles = computed(() =>
    props.options.vehicles.filter(
        (vehicle) =>
            (!filterState.vehicle_type ||
                vehicle.type === filterState.vehicle_type) &&
            (!filterState.category_id ||
                vehicle.category_id === filterState.category_id),
    ),
);
const appliedTimelineHref = computed(() => timelineHref(props.filters));
const newBookingHref = computed(() => {
    const params = new URLSearchParams({
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/create?${params}`;
});
const selectionPreview = computed(() => {
    const selection = rangeSelection.value;

    if (!selection) {
        return null;
    }

    const row = props.timeline.rows.find(
        (candidate) => candidate.id === selection.vehicleId,
    );
    const hovered =
        hoveredCell.value?.vehicleId === selection.vehicleId &&
        hoveredCell.value.index >= selection.startIndex
            ? hoveredCell.value
            : null;
    const endIndex = hovered?.index ?? selection.startIndex;

    return {
        vehicleId: selection.vehicleId,
        startIndex: selection.startIndex,
        endIndex,
        hasEndCandidate: hovered !== null,
        valid: row ? isRangeFree(row, selection.startIndex, endIndex) : false,
    };
});

onMounted(() => {
    document.addEventListener('keydown', onTimelineKeydown);

    if (window.matchMedia('(max-width: 640px)').matches) {
        zoom.value = 0.7;
    }

    resizeObserver = new ResizeObserver((entries) => {
        viewportWidth.value = entries[0].contentRect.width;
    });

    if (timelineContainer.value) {
        resizeObserver.observe(timelineContainer.value);
    }

    measureVehicleNames();
    void document.fonts.ready.then(() => {
        if (timelineBody.value) {
            measureVehicleNames();
        }
    });
});
watch(
    () => props.timeline.rows,
    () => nextTick(measureVehicleNames),
);
watch([viewportWidth, isFullscreen], () => {
    pinch = null;
    pinchOwned = false;
});
onBeforeUnmount(() => {
    document.removeEventListener('keydown', onTimelineKeydown);
    releaseBodyScroll?.();
    resizeObserver?.disconnect();
});

function toggleFullscreen(): void {
    if (isFullscreen.value) {
        exitFullscreen();

        return;
    }

    isFullscreen.value = true;
}

function exitFullscreen(restoreFocus = false): void {
    if (!isFullscreen.value) {
        return;
    }

    isFullscreen.value = false;

    if (restoreFocus) {
        void nextTick(() => {
            timelineContainer.value
                ?.querySelector<HTMLButtonElement>(
                    '[data-timeline-fullscreen-toggle]',
                )
                ?.focus();
        });
    }
}

function applyFilters(): void {
    visitTimeline();
}

function visitTimeline(): void {
    rangeError.value = validateRange(
        filterState.starts_on,
        filterState.ends_on,
    );

    if (rangeError.value) {
        return;
    }

    clearTimelineSelection();

    router.get('/timeline', queryFor(filterState), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onStart: () => (loading.value = true),
        onError: (errors) => {
            rangeError.value =
                typeof errors.ends_on === 'string'
                    ? errors.ends_on
                    : t('The timeline could not be loaded.');
        },
        onFinish: () => (loading.value = false),
    });
}

function resetFilters(): void {
    vehicleNameSearch.value = '';
    vehicleModelFilter.value = '';
    Object.assign(filterState, {
        vehicle_type: '',
        category_id: null,
        vehicle_id: null,
        visibility: 'active',
        status: '',
        available_only: false,
    });
    visitTimeline();
}

function onVehicleTypeChange(): void {
    if (
        filterState.category_id &&
        !filteredCategories.value.some(
            (category) => category.id === filterState.category_id,
        )
    ) {
        filterState.category_id = null;
    }

    if (
        filterState.vehicle_id &&
        !filteredVehicles.value.some(
            (vehicle) => vehicle.id === filterState.vehicle_id,
        )
    ) {
        filterState.vehicle_id = null;
    }
}

function onCategoryChange(): void {
    if (
        filterState.vehicle_id &&
        !filteredVehicles.value.some(
            (vehicle) => vehicle.id === filterState.vehicle_id,
        )
    ) {
        filterState.vehicle_id = null;
    }
}

function onStatusChange(): void {
    if (filterState.status) {
        filterState.available_only = false;
    }
}

function onAvailabilityChange(): void {
    if (filterState.available_only) {
        filterState.status = '';
    }
}

function applyQuickRange(days: TimelineQuickRangeDays): void {
    const range = timelineRangeFromToday(props.timeline.range.today, days);

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function shiftPeriod(direction: -1 | 1): void {
    const range = shiftTimelineRange(
        filterState.starts_on,
        filterState.ends_on,
        direction,
    );

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function goToToday(): void {
    const range = timelineTwoMonthRange(props.timeline.range.today);

    if (!range) {
        return;
    }

    filterState.starts_on = range.startsOn;
    filterState.ends_on = range.endsOn;
    visitTimeline();
}

function isCellFree(row: TimelineVehicle, index: number): boolean {
    return !row.blocked_ranges.some(
        (range) =>
            range.start_index <= index &&
            range.start_index + range.span_days > index,
    );
}

function isRangeFree(
    row: TimelineVehicle,
    startIndex: number,
    endIndex: number,
): boolean {
    if (
        startIndex < 0 ||
        endIndex < startIndex ||
        endIndex >= props.timeline.dates.length
    ) {
        return false;
    }

    for (let index = startIndex; index <= endIndex; index += 1) {
        if (!isCellFree(row, index)) {
            return false;
        }
    }

    return true;
}

function selectTimelineDate(
    row: TimelineVehicle,
    date: string,
    index: number,
): void {
    if (!row.is_active || !isCellFree(row, index)) {
        return;
    }

    const current = rangeSelection.value;

    if (
        !current ||
        current.vehicleId !== row.id ||
        index < current.startIndex
    ) {
        rangeSelection.value = {
            vehicleId: row.id,
            vehicleName: row.name,
            startsOn: date,
            startIndex: index,
        };
        hoveredCell.value = null;
        selectionError.value = '';
        bookingSelection.value = null;

        return;
    }

    if (!isRangeFree(row, current.startIndex, index)) {
        selectionError.value = t('The selected range includes occupied dates.');

        return;
    }

    bookingSelection.value = {
        vehicleId: row.id,
        vehicleName: row.name,
        startsOn: current.startsOn,
        endsOn: date,
    };
    selectionError.value = '';
    hoveredCell.value = null;
    bookingSheetOpen.value = true;
}

function previewTimelineDate(row: TimelineVehicle, index: number): void {
    hoverDateIndex.value = index;
    const selection = rangeSelection.value;

    if (selection?.vehicleId === row.id && index >= selection.startIndex) {
        hoveredCell.value = { vehicleId: row.id, index };
    }
}

function clearHoveredCell(vehicleId: number): void {
    hoverDateIndex.value = null;

    if (hoveredCell.value?.vehicleId === vehicleId) {
        hoveredCell.value = null;
    }
}

function clearTimelineSelection(): void {
    rangeSelection.value = null;
    hoveredCell.value = null;
    bookingSelection.value = null;
    bookingSheetOpen.value = false;
    selectionError.value = '';
}

function handleBookingSheetOpen(open: boolean): void {
    bookingSheetOpen.value = open;

    if (!open) {
        clearTimelineSelection();
    }
}

function onTimelineKeydown(event: KeyboardEvent): void {
    if (vehicleInfoOpen.value || event.defaultPrevented) {
        return;
    }

    const action = timelineEscapeAction({
        key: event.key,
        fullscreen: isFullscreen.value,
        bookingSheetOpen: bookingSheetOpen.value,
        hasRangeSelection: Boolean(rangeSelection.value),
    });

    if (!action) {
        return;
    }

    if (action === 'exit_fullscreen') {
        event.preventDefault();
        exitFullscreen(true);

        return;
    }

    if (action === 'clear_selection') {
        clearTimelineSelection();
    }
}

function cellSelectionClasses(row: TimelineVehicle, index: number): string[] {
    const preview = selectionPreview.value;

    if (
        !preview ||
        preview.vehicleId !== row.id ||
        index < preview.startIndex ||
        index > preview.endIndex
    ) {
        return [];
    }

    if (!preview.valid) {
        return index === preview.startIndex
            ? [
                  '!bg-amber-100 ring-2 ring-inset ring-amber-500 dark:!bg-amber-950/60',
              ]
            : ['!bg-destructive/15'];
    }

    if (!preview.hasEndCandidate) {
        return [
            '!bg-amber-100 ring-2 ring-inset ring-amber-500 dark:!bg-amber-950/60',
        ];
    }

    return index === preview.startIndex || index === preview.endIndex
        ? [
              '!bg-emerald-200 ring-2 ring-inset ring-emerald-600 dark:!bg-emerald-900/80',
          ]
        : ['!bg-emerald-100 dark:!bg-emerald-950/70'];
}

function isCellSelected(row: TimelineVehicle, index: number): boolean {
    const preview = selectionPreview.value;

    return Boolean(
        preview &&
        preview.vehicleId === row.id &&
        index >= preview.startIndex &&
        index <= preview.endIndex,
    );
}

function cellSelectionLabel(
    row: TimelineVehicle,
    date: string,
    index: number,
): string {
    const selection = rangeSelection.value;

    return selection?.vehicleId === row.id && index >= selection.startIndex
        ? t('Select :date as rental end for :vehicle', {
              date,
              vehicle: row.name,
          })
        : t('Select :date as rental start for :vehicle', {
              date,
              vehicle: row.name,
          });
}

function bookingHref(publicId: string): string {
    const params = new URLSearchParams({
        return_to: appliedTimelineHref.value,
    });

    return `/bookings/${publicId}?${params}`;
}

function occupancyStyle(
    occupancy: TimelineOccupancy & { lane: number },
): Record<string, string> {
    const geometry = timelineBookingGeometry(
        vehicleColumnWidth.value,
        dayWidth.value,
        occupancy.start_index,
        occupancy.span_days,
        zoom.value,
    );
    const layout = timelineOccupancyLayout(
        zoom.value,
        unref(display).compact,
        occupancy.lane,
    );

    return {
        top: `${layout.top}px`,
        height: `${layout.height}px`,
        left: `${geometry.left}px`,
        width: `${geometry.width}px`,
    };
}

function occupancyClass(occupancy: TimelineOccupancy): string[] {
    return [
        timelineStatusClasses[occupancy.status],
        occupancy.continues_before ? 'rounded-l-none border-l-0' : '',
        occupancy.continues_after ? 'rounded-r-none border-r-0' : '',
    ];
}

function occupancyTitle(occupancy: TimelineOccupancy): string {
    return `${occupancy.label} - ${timelineStatusLabels[occupancy.status]} - ${formatRange(occupancy.starts_on, occupancy.ends_on)}`;
}

function vehicleDetails(row: TimelineVehicle): string {
    return [
        t(row.type),
        row.category?.name,
        row.inventory_code,
        !row.is_active ? t('inactive') : !row.is_visible ? t('internal') : null,
    ]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

function rowStyle(row: TimelineVehicle): Record<string, string> {
    return {
        minHeight: `${Math.max(
            32,
            (unref(display).compact ? 36 : 52) * zoom.value,
            ...occupancyLanes(row.occupancies).map((item) => {
                const lane = timelineOccupancyLayout(
                    zoom.value,
                    unref(display).compact,
                    item.lane,
                );

                return lane.top + lane.height + 4;
            }),
        )}px`,
        width: `${gridWidth.value}px`,
        '--bike-color': vehicleRowColor(row.id),
        '--paint-color':
            vehicleNameParts(row.name).ink ?? vehicleRowColor(row.id),
    };
}

function timelineHref(filters: TimelineFilters): string {
    return `/timeline?${new URLSearchParams(queryFor(filters))}`;
}

function queryFor(filters: TimelineFilters): Record<string, string> {
    const query: Record<string, string> = {
        starts_on: filters.starts_on,
        ends_on: filters.ends_on,
    };

    if (filters.vehicle_type) {
        query.vehicle_type = filters.vehicle_type;
    }

    if (filters.category_id) {
        query.category_id = String(filters.category_id);
    }

    if (filters.vehicle_id) {
        query.vehicle_id = String(filters.vehicle_id);
    }

    if (filters.visibility && filters.visibility !== 'active') {
        query.visibility = filters.visibility;
    }

    if (filters.status) {
        query.status = filters.status;
    }

    if (filters.available_only) {
        query.available_only = '1';
    }

    return query;
}

function validateRange(startsOn: string, endsOn: string): string {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end) {
        return t('Select both dates.');
    }

    if (end < start) {
        return t('End date must be on or after the start date.');
    }

    if (inclusiveDays(start, end) > 186) {
        return t('The timeline range cannot exceed 186 days.');
    }

    return '';
}

function parseIsoDate(value: string): Date | null {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return null;
    }

    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(Date.UTC(year, month - 1, day));

    return toIsoDate(date) === value ? date : null;
}

function toIsoDate(value: Date): string {
    return value.toISOString().slice(0, 10);
}

function inclusiveDays(start: Date, end: Date): number {
    return Math.floor((end.getTime() - start.getTime()) / 86_400_000) + 1;
}

function formatRange(startsOn: string, endsOn: string): string {
    const start = parseIsoDate(startsOn);
    const end = parseIsoDate(endsOn);

    if (!start || !end) {
        return `${startsOn} - ${endsOn}`;
    }

    const formatter = new Intl.DateTimeFormat(
        getActiveLocale() === 'ru' ? 'ru-RU' : 'en-GB',
        {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            timeZone: 'UTC',
        },
    );

    return `${formatter.format(start)} - ${formatter.format(end)}`;
}
</script>

<template>
    <Head :title="t('Availability timeline')" />

    <div
        ref="timelineContainer"
        class="timeline-page flex min-w-0 flex-1 flex-col bg-background transition-colors duration-[350ms] motion-reduce:transition-none"
        :class="
            isFullscreen
                ? 'fixed inset-0 z-40 h-dvh min-h-0 w-screen overflow-hidden'
                : ''
        "
        :data-fullscreen="isFullscreen ? 'true' : undefined"
    >
        <header
            v-if="!isFullscreen"
            class="flex flex-col gap-4 border-b px-4 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8"
        >
            <div class="min-w-0">
                <p class="text-sm text-muted-foreground">
                    {{ t('Fleet availability') }}
                </p>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h1 class="text-2xl font-semibold">
                        {{ t('Timeline') }}
                    </h1>
                    <span class="text-sm text-muted-foreground tabular-nums">{{
                        rangeLabel
                    }}</span>
                </div>
            </div>
            <div class="flex items-center gap-2 self-end sm:self-auto">
                <Button as-child>
                    <Link :href="newBookingHref"
                        ><Plus />{{ t('New booking') }}</Link
                    >
                </Button>
                <Tooltip v-if="timeline.rows.length === 0">
                    <TooltipTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            data-timeline-fullscreen-toggle
                            aria-keyshortcuts="Escape"
                            :aria-label="
                                t(
                                    isFullscreen
                                        ? 'Collapse calendar'
                                        : 'Expand calendar',
                                )
                            "
                            :aria-pressed="isFullscreen"
                            :title="
                                t(
                                    isFullscreen
                                        ? 'Collapse calendar'
                                        : 'Expand calendar',
                                )
                            "
                            @click="toggleFullscreen"
                        >
                            <Expand />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {{
                            t(
                                isFullscreen
                                    ? 'Collapse calendar'
                                    : 'Expand calendar',
                            )
                        }}
                    </TooltipContent>
                </Tooltip>
            </div>
        </header>

        <section
            v-if="!isFullscreen"
            class="border-b px-4 py-4 sm:px-6 lg:px-8"
        >
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold">
                    {{ t('Schedule settings') }}
                </h2>
                <Button
                    variant="outline"
                    size="sm"
                    :aria-expanded="display.filtersOpen"
                    aria-controls="timeline-filter-panel"
                    @click="display.filtersOpen = !display.filtersOpen"
                >
                    {{
                        t(
                            display.filtersOpen
                                ? 'Collapse filters'
                                : 'Expand filters',
                        )
                    }}
                    <ChevronDown
                        class="transition-transform duration-[350ms] motion-reduce:transition-none"
                        :class="display.filtersOpen ? 'rotate-180' : ''"
                    />
                </Button>
            </div>
            <div
                class="timeline-collapse"
                :class="{ 'is-collapsed': !display.filtersOpen }"
                :inert="!display.filtersOpen"
            >
                <div id="timeline-filter-panel" class="min-h-0 overflow-hidden">
                    <div class="flex flex-col gap-3 xl:flex-row xl:items-end">
                        <div class="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                :title="t('Previous period')"
                                :disabled="loading"
                                @click="shiftPeriod(-1)"
                            >
                                <ChevronLeft />
                                <span class="sr-only">{{
                                    t('Previous period')
                                }}</span>
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="loading"
                                @click="goToToday"
                            >
                                <CalendarDays />{{ t('Today') }}
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                :title="t('Next period')"
                                :disabled="loading"
                                @click="shiftPeriod(1)"
                            >
                                <ChevronRight />
                                <span class="sr-only">{{
                                    t('Next period')
                                }}</span>
                            </Button>
                        </div>

                        <div
                            class="flex flex-wrap items-center gap-1.5"
                            role="group"
                            :aria-label="t('Calendar range')"
                        >
                            <Button
                                v-for="days in timelineQuickRangeDays"
                                :key="days"
                                variant="outline"
                                :class="
                                    activeQuickRangeDays === days
                                        ? 'timeline-range-active'
                                        : ''
                                "
                                :aria-pressed="activeQuickRangeDays === days"
                                :disabled="loading"
                                @click="applyQuickRange(days)"
                                >{{ t(':count days', { count: days }) }}</Button
                            >
                        </div>
                        <Button
                            variant="ghost"
                            :aria-expanded="display.customRange"
                            @click="display.customRange = !display.customRange"
                            >{{ t('Other period') }}</Button
                        >
                    </div>
                    <form
                        v-if="display.customRange"
                        class="flex flex-1 flex-col gap-3 sm:flex-row sm:items-end"
                        @submit.prevent="applyFilters"
                    >
                        <div class="w-full sm:max-w-44">
                            <Label for="timeline_starts_on">{{
                                t('From')
                            }}</Label>
                            <AdminDateInput
                                id="timeline_starts_on"
                                v-model="filterState.starts_on"
                                class="mt-1.5"
                            />
                        </div>
                        <div class="w-full sm:max-w-44">
                            <Label for="timeline_ends_on">{{ t('To') }}</Label>
                            <AdminDateInput
                                id="timeline_ends_on"
                                v-model="filterState.ends_on"
                                class="mt-1.5"
                            />
                        </div>
                        <Button type="submit" :disabled="loading">{{
                            t('Apply')
                        }}</Button>
                        <p
                            v-if="rangeError"
                            class="pb-2 text-sm text-destructive"
                            role="alert"
                        >
                            {{ rangeError }}
                        </p>
                    </form>

                    <form
                        class="mt-4 grid gap-3 border-t pt-4 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-[repeat(5,minmax(0,1fr))_auto_auto]"
                        @submit.prevent="applyFilters"
                    >
                        <div>
                            <Label for="timeline_type">{{ t('Type') }}</Label>
                            <AdminSelect
                                id="timeline_type"
                                v-model="filterState.vehicle_type"
                                class="mt-1.5"
                                :options="[
                                    { value: '', label: t('All types') },
                                    ...options.vehicle_types.map((type) => ({
                                        value: type,
                                        label: t(type),
                                    })),
                                ]"
                                @change="onVehicleTypeChange"
                            />
                        </div>
                        <div>
                            <Label for="timeline_category">{{
                                t('Category')
                            }}</Label>
                            <AdminSelect
                                id="timeline_category"
                                v-model="filterState.category_id"
                                class="mt-1.5"
                                :options="[
                                    { value: null, label: t('All categories') },
                                    ...filteredCategories.map((category) => ({
                                        value: category.id,
                                        label: category.name,
                                    })),
                                ]"
                                @change="onCategoryChange"
                            />
                        </div>
                        <div>
                            <Label for="timeline_vehicle">{{
                                t('Vehicle')
                            }}</Label>
                            <AdminSelect
                                id="timeline_vehicle"
                                v-model="filterState.vehicle_id"
                                class="mt-1.5"
                                :options="[
                                    { value: null, label: t('All vehicles') },
                                    ...filteredVehicles.map((vehicle) => ({
                                        value: vehicle.id,
                                        label: vehicle.name,
                                    })),
                                ]"
                            />
                        </div>
                        <div>
                            <Label for="timeline_visibility">{{
                                t('Visibility')
                            }}</Label>
                            <AdminSelect
                                id="timeline_visibility"
                                v-model="filterState.visibility"
                                class="mt-1.5"
                                :options="[
                                    { value: 'active', label: t('All active') },
                                    {
                                        value: 'visible',
                                        label: t('Client-visible'),
                                    },
                                    {
                                        value: 'hidden',
                                        label: t('Internal only'),
                                    },
                                    { value: 'inactive', label: t('Inactive') },
                                    { value: 'all', label: t('All records') },
                                ]"
                            />
                        </div>
                        <div>
                            <Label for="timeline_status">{{
                                t('Occupancy')
                            }}</Label>
                            <AdminSelect
                                id="timeline_status"
                                v-model="filterState.status"
                                class="mt-1.5"
                                :options="[
                                    {
                                        value: '',
                                        label: t('All requests and blocks'),
                                    },
                                    ...options.statuses.map((status) => ({
                                        value: status,
                                        label: t(timelineStatusLabels[status]),
                                    })),
                                ]"
                                @change="onStatusChange"
                            />
                        </div>
                        <label
                            class="flex h-9 cursor-pointer items-center gap-2 self-end rounded-md border px-3 text-sm whitespace-nowrap"
                        >
                            <input
                                v-model="filterState.available_only"
                                type="checkbox"
                                class="size-4 accent-foreground"
                                @change="onAvailabilityChange"
                            />
                            {{ t('Available only') }}
                        </label>
                        <div
                            class="flex flex-wrap items-end gap-2 sm:col-span-full lg:col-span-1 lg:gap-1"
                        >
                            <Button
                                type="submit"
                                variant="secondary"
                                :disabled="loading"
                                >{{ t('Filter') }}</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                :title="t('Reset filters')"
                                :disabled="loading"
                                @click="resetFilters"
                            >
                                <FilterX />
                                <span class="sr-only">{{
                                    t('Reset filters')
                                }}</span>
                            </Button>
                            <input
                                id="timeline_vehicle_search_mobile"
                                v-model="vehicleNameSearch"
                                type="search"
                                class="h-9 min-w-0 flex-1 basis-28 rounded-md border bg-background px-3 text-sm lg:hidden"
                                :placeholder="t('Search by name or model')"
                                :aria-label="t('Search by name')"
                                @keydown.enter.prevent
                            />
                            <AdminSelect
                                id="timeline_model_filter_mobile"
                                v-model="vehicleModelFilter"
                                :aria-label="t('Model')"
                                class="w-32 shrink-0 lg:hidden"
                                :options="vehicleModelOptions"
                            />
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section
            v-if="!isFullscreen"
            class="flex flex-wrap items-center gap-x-4 gap-y-3 border-b px-4 py-3 text-sm sm:px-6 lg:px-8"
        >
            <div
                class="flex w-full flex-wrap gap-x-5 gap-y-1 tabular-nums lg:w-auto"
            >
                <span>{{
                    t('Vehicles count', { count: visibleStats.vehicles })
                }}</span>
                <span class="text-emerald-700 dark:text-emerald-400">{{
                    t('Fully free count', { count: visibleStats.available })
                }}</span>
                <span class="text-muted-foreground">{{
                    t('Blocks count', { count: visibleStats.occupancies })
                }}</span>
            </div>
            <div class="hidden min-w-0 items-center gap-2 lg:flex lg:flex-1">
                <label class="sr-only" for="timeline_vehicle_search">{{
                    t('Search by name')
                }}</label>
                <input
                    id="timeline_vehicle_search"
                    v-model="vehicleNameSearch"
                    type="search"
                    class="h-9 w-full min-w-0 rounded-md border bg-background px-3 text-sm sm:max-w-60 sm:flex-1"
                    :placeholder="t('Search by name or model')"
                />
                <label class="sr-only" for="timeline_model_filter">{{
                    t('Model')
                }}</label>
                <AdminSelect
                    id="timeline_model_filter"
                    v-model="vehicleModelFilter"
                    class="w-full sm:w-44 sm:flex-none"
                    :options="vehicleModelOptions"
                />
            </div>
            <div
                class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 lg:w-auto"
            >
                <span
                    v-for="status in options.statuses"
                    :key="status"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <span
                        class="size-2.5 rounded-full border"
                        :class="timelineStatusClasses[status]"
                    />
                    {{ t(timelineStatusLabels[status]) }}
                </span>
            </div>
        </section>
        <section
            v-if="!isFullscreen && rangeSelection"
            class="border-b border-amber-300 bg-amber-50 px-4 py-3 text-amber-950 sm:px-6 lg:px-8 dark:border-amber-800 dark:bg-amber-950/35 dark:text-amber-100"
            aria-live="polite"
        >
            <div class="flex items-start justify-between gap-4">
                <div class="flex min-w-0 gap-3">
                    <CalendarDays class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">
                            {{
                                t('Start selected: :vehicle, :date', {
                                    vehicle: rangeSelection.vehicleName,
                                    date: rangeSelection.startsOn,
                                })
                            }}
                        </p>
                        <p
                            class="mt-0.5 text-xs text-amber-800 dark:text-amber-200"
                        >
                            {{
                                t('Choose the rental end date in the same row.')
                            }}
                        </p>
                        <p
                            v-if="selectionError"
                            class="mt-1 text-sm font-medium text-destructive"
                            role="alert"
                        >
                            {{ selectionError }}
                        </p>
                    </div>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="shrink-0"
                    @click="clearTimelineSelection"
                >
                    <X />{{ t('Cancel selection') }}
                </Button>
            </div>
        </section>

        <section
            class="relative min-h-0 min-w-0 flex-1"
            :style="
                isFullscreen
                    ? { display: 'flex', flexDirection: 'column' }
                    : undefined
            "
            :class="isFullscreen ? 'h-dvh' : ''"
            :aria-label="t('Vehicle availability')"
        >
            <Button
                v-if="isFullscreen && timeline.rows.length === 0"
                type="button"
                variant="secondary"
                size="icon"
                class="absolute top-2 right-2 z-50"
                data-timeline-fullscreen-toggle
                :aria-label="t('Collapse calendar')"
                aria-keyshortcuts="Escape"
                @click="exitFullscreen(true)"
                ><Minimize
            /></Button>
            <div
                v-if="timeline.rows.length === 0"
                class="flex h-full min-h-80 flex-col items-center justify-center px-6 text-center"
            >
                <FilterX class="mb-3 size-8 text-muted-foreground" />
                <h2 class="font-medium">
                    {{ t('No vehicles match these filters') }}
                </h2>
                <Button
                    v-if="!isFullscreen"
                    variant="outline"
                    class="mt-4"
                    @click="resetFilters"
                >
                    {{ t('Reset filters') }}
                </Button>
            </div>

            <div
                v-if="timeline.rows.length > 0"
                ref="timelineHead"
                class="sticky top-0 z-30 shrink-0 overflow-x-auto bg-background"
                @scroll="syncHorizontal($event, timelineBody)"
                :style="zoomStyles"
            >
                <div
                    class="timeline-grid-head flex h-24 border-b bg-background shadow-xs"
                    :style="{
                        width: `${gridWidth}px`,
                        height: `${vehicleHeaderHeight}px`,
                    }"
                >
                    <div
                        class="timeline-vehicle-head sticky left-0 z-40 flex min-w-0 shrink-0 flex-col justify-between border-r bg-background text-xs font-semibold text-muted-foreground"
                        :style="{
                            width: `${vehicleColumnWidth}px`,
                            height: `${vehicleHeaderHeight}px`,
                            fontSize: `${12 * zoom}px`,
                            paddingInline: `${8 * zoom}px`,
                            paddingBlock: `${6 * zoom}px`,
                        }"
                    >
                        <div
                            class="flex items-center justify-between"
                            :class="
                                narrowVehicleColumn
                                    ? 'timeline-compact-header-controls'
                                    : ''
                            "
                            :style="{ gap: `${4 * zoom}px` }"
                        >
                            <div
                                class="flex items-center"
                                :class="
                                    narrowVehicleColumn
                                        ? 'timeline-compact-period-grid'
                                        : ''
                                "
                                :style="{
                                    gap: `${(narrowVehicleColumn ? 2 : 4) * zoom}px`,
                                }"
                                role="group"
                                :aria-label="t('Calendar range')"
                            >
                                <Button
                                    v-for="days in timelineHeaderRangeDays"
                                    :key="days"
                                    type="button"
                                    variant="outline"
                                    :style="{
                                        width: `${(narrowVehicleColumn ? 30 : 40) * zoom}px`,
                                        height: `${(narrowVehicleColumn ? 24 : 32) * zoom}px`,
                                        minWidth: `${(narrowVehicleColumn ? 30 : 40) * zoom}px`,
                                        paddingInline: `${4 * zoom}px`,
                                        paddingBlock: `${(narrowVehicleColumn ? 2 : 4) * zoom}px`,
                                        fontSize: `${(narrowVehicleColumn ? 10 : 12) * zoom}px`,
                                    }"
                                    :class="
                                        activeQuickRangeDays === days
                                            ? 'timeline-range-active'
                                            : ''
                                    "
                                    :disabled="loading"
                                    :aria-pressed="
                                        activeQuickRangeDays === days
                                    "
                                    :aria-label="
                                        t('Show :count days from today', {
                                            count: days,
                                        })
                                    "
                                    :title="
                                        t('Show :count days from today', {
                                            count: days,
                                        })
                                    "
                                    @click="applyQuickRange(days)"
                                    >{{ days }}</Button
                                >
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :class="
                                    narrowVehicleColumn
                                        ? 'timeline-compact-fullscreen'
                                        : ''
                                "
                                :style="{
                                    width: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                    height: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                }"
                                data-timeline-fullscreen-toggle
                                aria-keyshortcuts="Escape"
                                :aria-label="
                                    t(
                                        isFullscreen
                                            ? 'Collapse calendar'
                                            : 'Expand calendar',
                                    )
                                "
                                :aria-pressed="isFullscreen"
                                :title="
                                    t(
                                        isFullscreen
                                            ? 'Collapse calendar'
                                            : 'Expand calendar',
                                    )
                                "
                                @click="toggleFullscreen"
                            >
                                <Minimize
                                    v-if="isFullscreen"
                                    :style="{
                                        width: `${12 * zoom}px`,
                                        height: `${12 * zoom}px`,
                                    }"
                                />
                                <Expand
                                    v-else
                                    :style="{
                                        width: `${12 * zoom}px`,
                                        height: `${12 * zoom}px`,
                                    }"
                                />
                            </Button>
                        </div>
                        <div
                            class="flex items-center justify-between"
                            :class="
                                narrowVehicleColumn
                                    ? 'timeline-compact-tool-grid'
                                    : ''
                            "
                            :style="{ gap: `${6 * zoom}px` }"
                        >
                            <span
                                v-if="!narrowVehicleColumn && zoom >= 1"
                                class="shrink-0"
                                >{{ t('Vehicle') }}</span
                            >
                            <div
                                class="flex items-center"
                                :class="
                                    narrowVehicleColumn
                                        ? 'timeline-compact-zoom'
                                        : ''
                                "
                                :style="{ gap: `${3 * zoom}px` }"
                                role="group"
                                :aria-label="t('Timeline zoom')"
                            >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    :style="{
                                        width: `${(narrowVehicleColumn ? 26 : 32) * zoom}px`,
                                        height: `${(narrowVehicleColumn ? 26 : 32) * zoom}px`,
                                    }"
                                    :disabled="zoom <= timelineZoomLimits.min"
                                    :aria-label="t('Zoom out')"
                                    :title="t('Zoom out')"
                                    @click="changeZoom(-1)"
                                    ><Minus
                                        :style="{
                                            width: `${14 * zoom}px`,
                                            height: `${14 * zoom}px`,
                                        }"
                                /></Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="text-xs tabular-nums"
                                    :style="{
                                        height: `${(narrowVehicleColumn ? 26 : 32) * zoom}px`,
                                        minWidth: `${(narrowVehicleColumn ? 34 : 40) * zoom}px`,
                                        paddingInline: `${4 * zoom}px`,
                                        paddingBlock: `${4 * zoom}px`,
                                        fontSize: `${(narrowVehicleColumn ? 11 : 12) * zoom}px`,
                                    }"
                                    :aria-label="t('Reset zoom')"
                                    :title="`${zoomPercent}% (50–150%)`"
                                    @click="changeZoom(0)"
                                    ><span aria-live="polite"
                                        >{{ zoomPercent }}%</span
                                    ></Button
                                >
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    :style="{
                                        width: `${(narrowVehicleColumn ? 26 : 32) * zoom}px`,
                                        height: `${(narrowVehicleColumn ? 26 : 32) * zoom}px`,
                                    }"
                                    :disabled="zoom >= timelineZoomLimits.max"
                                    :aria-label="t('Zoom in')"
                                    :title="t('Zoom in')"
                                    @click="changeZoom(1)"
                                    ><Plus
                                        :style="{
                                            width: `${14 * zoom}px`,
                                            height: `${14 * zoom}px`,
                                        }"
                                /></Button>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :class="
                                    narrowVehicleColumn
                                        ? 'timeline-compact-toggle'
                                        : ''
                                "
                                :style="{
                                    width: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                    height: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                }"
                                :aria-pressed="narrowVehicleColumn"
                                :aria-label="
                                    t(
                                        narrowVehicleColumn
                                            ? 'Restore vehicle column width'
                                            : 'Compact vehicle column',
                                    )
                                "
                                :title="
                                    t(
                                        narrowVehicleColumn
                                            ? 'Restore vehicle column width'
                                            : 'Compact vehicle column',
                                    )
                                "
                                @click="
                                    narrowVehicleColumn = !narrowVehicleColumn
                                "
                            >
                                <ArrowLeftRight
                                    :style="{
                                        width: `${14 * zoom}px`,
                                        height: `${14 * zoom}px`,
                                    }"
                                />
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :class="
                                    narrowVehicleColumn
                                        ? 'timeline-compact-details'
                                        : ''
                                "
                                :style="{
                                    width: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                    height: `${(narrowVehicleColumn ? 24 : 28) * zoom}px`,
                                }"
                                :aria-expanded="!display.compact"
                                :aria-label="
                                    t(
                                        display.compact
                                            ? 'Show vehicle details'
                                            : 'Hide vehicle details',
                                    )
                                "
                                :title="
                                    t(
                                        display.compact
                                            ? 'Show vehicle details'
                                            : 'Hide vehicle details',
                                    )
                                "
                                @click="display.compact = !display.compact"
                            >
                                <ChevronDown
                                    :class="
                                        !display.compact ? 'rotate-180' : ''
                                    "
                                    :style="{
                                        width: `${14 * zoom}px`,
                                        height: `${14 * zoom}px`,
                                    }"
                                />
                            </Button>
                        </div>
                    </div>
                    <div
                        class="relative flex"
                        :style="{
                            height: `${vehicleHeaderHeight}px`,
                            paddingTop: `${vehicleHeaderHeight / 2}px`,
                        }"
                    >
                        <div
                            class="absolute inset-x-0 top-0 flex"
                            :style="{ height: `${vehicleHeaderHeight / 2}px` }"
                        >
                            <div
                                v-for="band in monthBands"
                                :key="band.start"
                                class="timeline-month flex shrink-0 items-center justify-center border-r text-xs font-extrabold tracking-widest uppercase"
                                :style="{
                                    width: `${band.span * dayWidth}px`,
                                }"
                            >
                                {{ band.label }}
                            </div>
                        </div>
                        <div
                            v-for="(date, index) in localizedDates"
                            :key="date.date"
                            class="timeline-date-head flex shrink-0 flex-col items-center justify-center border-r text-xs tabular-nums"
                            :class="[
                                date.is_weekend ? 'timeline-weekend-head' : '',
                                hoverDateIndex === index
                                    ? 'timeline-date-hover'
                                    : '',
                                date.is_today
                                    ? 'bg-cyan-50 text-cyan-900 dark:bg-cyan-950 dark:text-cyan-100'
                                    : '',
                            ]"
                            :style="{ width: `${dayWidth}px` }"
                            :title="date.date"
                        >
                            <strong>{{ date.day }}</strong>
                            <span
                                class="text-[10px] text-muted-foreground uppercase"
                                :class="[
                                    date.weekdayIndex === 6
                                        ? 'timeline-saturday-label'
                                        : '',
                                    date.weekdayIndex === 0
                                        ? 'timeline-sunday-label'
                                        : '',
                                ]"
                                >{{ date.weekday.slice(0, 2) }}</span
                            >
                        </div>
                    </div>
                </div>
            </div>
            <div
                v-if="timeline.rows.length > 0"
                ref="timelineBody"
                @scroll="syncHorizontal($event, timelineHead)"
                @pointerdown.capture="startPointerGesture"
                @pointermove.capture="movePointerGesture"
                @pointerup.capture="endPointerGesture"
                @pointercancel.capture="endPointerGesture"
                @touchstart.capture="startTimelineTouch"
                @touchmove.capture="moveTimelineTouch"
                @touchend.capture="endTimelineTouch"
                @touchcancel.capture="endTimelineTouch"
                @click.capture="guardGestureClick"
                :style="zoomStyles"
                class="timeline-scroll-area max-w-full overflow-auto overscroll-x-contain overscroll-y-auto [contain:paint]"
                :class="[
                    isFullscreen
                        ? 'max-h-none min-h-0 flex-1'
                        : 'min-h-[360px]',
                    loading ? 'opacity-60' : '',
                ]"
                :aria-busy="loading"
            >
                <div class="relative" :style="{ width: `${gridWidth}px` }">
                    <div
                        v-if="visibleTimelineRows.length === 0"
                        class="flex min-h-32 items-center text-sm text-muted-foreground"
                        :style="{
                            width: `${vehicleColumnWidth + 300}px`,
                            paddingLeft: `${vehicleColumnWidth + 16}px`,
                        }"
                    >
                        {{ t('No vehicles match these filters') }}
                    </div>
                    <div
                        v-for="row in visibleTimelineRows"
                        :key="row.id"
                        :data-timeline-vehicle="row.id"
                        class="timeline-vehicle-row group relative flex border-b"
                        :style="rowStyle(row)"
                        @mouseleave="clearHoveredCell(row.id)"
                    >
                        <div
                            class="timeline-vehicle-label sticky left-0 z-20 flex min-w-0 shrink-0 items-center gap-1.5 border-r px-2 py-2"
                            :style="{ width: `${vehicleColumnWidth}px` }"
                        >
                            <span
                                class="shrink-0 rounded-full"
                                :style="{
                                    width: `${4 * zoom}px`,
                                    height: `${28 * zoom}px`,
                                    backgroundColor: vehicleRowColor(row.id),
                                }"
                            />
                            <div
                                class="min-w-0 flex-1"
                                :class="
                                    display.compact
                                        ? 'flex items-center gap-1'
                                        : ''
                                "
                            >
                                <div class="min-w-0 flex-1">
                                    <Link
                                        :href="`/vehicles/${row.id}/edit`"
                                        class="timeline-name block w-full min-w-0 text-xs font-bold whitespace-nowrap"
                                        :title="row.name"
                                        :aria-label="
                                            t('Edit :vehicle', {
                                                vehicle: row.name,
                                            })
                                        "
                                        ><TimelineVehicleName
                                            :name="row.name"
                                            :stacked="narrowVehicleColumn"
                                    /></Link>
                                    <span
                                        v-if="
                                            display.compact &&
                                            (!row.is_active || !row.is_visible)
                                        "
                                        class="mt-1 inline-block rounded border px-1 py-0.5 text-[9px] leading-none font-medium text-muted-foreground"
                                    >
                                        {{
                                            t(
                                                !row.is_active
                                                    ? 'inactive'
                                                    : 'internal',
                                            )
                                        }}
                                    </span>
                                </div>
                                <TimelineVehicleInfo
                                    v-if="display.compact"
                                    :vehicle="row"
                                    :zoom="zoom"
                                    @update:open="vehicleInfoOpen = $event"
                                />
                                <div
                                    v-else
                                    class="mt-1 flex items-center gap-1"
                                >
                                    <div class="min-w-0 flex-1">
                                        <p
                                            v-if="!display.compact"
                                            class="timeline-vehicle-details text-[11px] leading-4 [overflow-wrap:anywhere] break-words text-muted-foreground"
                                            :title="vehicleDetails(row)"
                                        >
                                            <span>{{ t(row.type) }}</span>
                                            <span v-if="row.category">
                                                · {{ row.category.name }}</span
                                            >
                                            <span v-if="row.inventory_code">
                                                · {{ row.inventory_code }}</span
                                            >
                                        </p>
                                        <span
                                            v-if="
                                                !row.is_active ||
                                                !row.is_visible
                                            "
                                            class="inline-block rounded border px-1 py-0.5 text-[9px] leading-none font-medium text-muted-foreground"
                                            :title="vehicleDetails(row)"
                                        >
                                            {{
                                                t(
                                                    !row.is_active
                                                        ? 'inactive'
                                                        : 'internal',
                                                )
                                            }}
                                        </span>
                                    </div>
                                    <TimelineVehicleInfo
                                        :vehicle="row"
                                        :zoom="zoom"
                                        @update:open="vehicleInfoOpen = $event"
                                    />
                                </div>
                            </div>
                        </div>

                        <template
                            v-for="(date, index) in timeline.dates"
                            :key="date.date"
                        >
                            <button
                                v-if="row.is_active && isCellFree(row, index)"
                                type="button"
                                class="timeline-day group/cell relative shrink-0 self-stretch border-r p-0 focus-visible:z-20 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-emerald-600"
                                :class="[
                                    date.is_weekend
                                        ? 'timeline-weekend-cell'
                                        : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                    ...cellSelectionClasses(row, index),
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                                :title="
                                    cellSelectionLabel(row, date.date, index)
                                "
                                :aria-label="
                                    cellSelectionLabel(row, date.date, index)
                                "
                                :aria-pressed="isCellSelected(row, index)"
                                @mouseenter="previewTimelineDate(row, index)"
                                @focus="previewTimelineDate(row, index)"
                                @blur="clearHoveredCell(row.id)"
                                @click="
                                    handleDateClick(
                                        $event,
                                        row,
                                        date.date,
                                        index,
                                    )
                                "
                            >
                                <Plus
                                    class="pointer-events-none absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-0 transition-opacity group-hover/cell:opacity-50"
                                    :style="{
                                        width: `${14 * zoom}px`,
                                        height: `${14 * zoom}px`,
                                    }"
                                />
                            </button>
                            <div
                                v-else
                                class="timeline-day shrink-0 self-stretch border-r"
                                @mouseenter="hoverDateIndex = index"
                                :class="[
                                    date.is_weekend
                                        ? 'timeline-weekend-cell'
                                        : '',
                                    date.is_today
                                        ? 'bg-cyan-50/50 dark:bg-cyan-950/30'
                                        : '',
                                ]"
                                :style="{ width: `${dayWidth}px` }"
                                :title="
                                    !row.is_active && isCellFree(row, index)
                                        ? t(
                                              'Inactive vehicles cannot be booked.',
                                          )
                                        : undefined
                                "
                            />
                        </template>

                        <template
                            v-for="occupancy in occupancyLanes(row.occupancies)"
                            :key="occupancy.id"
                        >
                            <Link
                                v-if="occupancy.booking_public_id"
                                :href="bookingHref(occupancy.booking_public_id)"
                                class="timeline-occupancy absolute z-10 flex items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                                :class="occupancyClass(occupancy)"
                                :style="occupancyStyle(occupancy)"
                                :title="occupancyTitle(occupancy)"
                                :aria-label="occupancyTitle(occupancy)"
                            >
                                <span class="truncate">{{
                                    occupancy.label
                                }}</span>
                            </Link>
                            <div
                                v-else
                                class="timeline-occupancy absolute z-10 flex items-center overflow-hidden rounded-sm border px-2 text-xs font-semibold shadow-xs"
                                :class="occupancyClass(occupancy)"
                                :style="occupancyStyle(occupancy)"
                                :title="occupancyTitle(occupancy)"
                            >
                                <span class="truncate">{{
                                    occupancy.label
                                }}</span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <TimelineBookingSheet
        :open="bookingSheetOpen"
        :selection="bookingSelection"
        :return-to="appliedTimelineHref"
        @update:open="handleBookingSheetOpen"
    />
</template>

<style>
/* A non-scrolling overflow:hidden layout ancestor traps position:sticky.
   Clip horizontal overflow without creating a second vertical scroll container. */
main:has(> .timeline-page) {
    min-width: 0;
    overflow-x: clip;
    overflow-y: visible;
}
.timeline-page {
    --timeline-base: var(--background);
}
.dark .timeline-page {
    --timeline-base: #111313;
}
.timeline-collapse {
    display: grid;
    grid-template-rows: 1fr;
    opacity: 1;
    transition:
        grid-template-rows 350ms ease,
        opacity 350ms ease;
}
.timeline-collapse.is-collapsed {
    grid-template-rows: 0fr;
    opacity: 0;
}
.timeline-range-active {
    background: #173d79 !important;
    border-color: #3988ff !important;
    color: white !important;
    box-shadow: 0 0 0 1px #3988ff44;
}
.timeline-month {
    background: color-mix(in srgb, #55c5a8 10%, var(--background));
}
.dark .timeline-month {
    background: #222626;
    color: #d9e6e2;
    border-color: #414b48;
}
.dark .timeline-date-head,
.dark .timeline-vehicle-head {
    background: #171919;
}
.timeline-page,
.timeline-page * {
    font-family: 'Rajdhani', ui-sans-serif, system-ui, sans-serif;
    font-weight: 700;
}
.timeline-vehicle-row {
    background: color-mix(in srgb, var(--bike-color) 19%, var(--timeline-base));
}
.timeline-compact-header-controls {
    display: grid !important;
    grid-template-columns: max-content auto;
    width: 100%;
}
.timeline-compact-period-grid {
    display: flex !important;
}
.timeline-compact-fullscreen {
    justify-self: end;
    align-self: center;
}
.timeline-compact-tool-grid {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto auto;
    grid-template-areas: 'zoom toggle details';
}
.timeline-compact-zoom {
    grid-area: zoom;
}
.timeline-compact-toggle {
    grid-area: toggle;
}
.timeline-compact-details {
    grid-area: details;
}
.timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 24%, var(--background));
}
.dark .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 24%, #151717);
}
.timeline-date-head.timeline-weekend-head {
    background-color: color-mix(in srgb, black 14%, var(--background));
    border: 1px solid #252a2a;
}
.dark .timeline-date-head.timeline-weekend-head {
    background-color: color-mix(in srgb, black 14%, #171919);
    border-color: #252a2a;
}
.timeline-day.timeline-weekend-cell {
    background-color: color-mix(
        in srgb,
        black 14%,
        color-mix(in srgb, var(--bike-color) 19%, var(--timeline-base))
    );
    border: 1px solid #252a2a;
}
.timeline-saturday-label {
    color: #050505 !important;
    -webkit-text-stroke: 1px #3988ff;
    paint-order: stroke fill;
}
.timeline-sunday-label {
    color: #050505 !important;
    -webkit-text-stroke: 1px #ec4899;
    paint-order: stroke fill;
}
.timeline-vehicle-row,
.timeline-vehicle-label,
.timeline-day,
.timeline-date-head,
.timeline-name {
    transition:
        background-color 350ms ease,
        box-shadow 350ms ease,
        border-color 350ms ease,
        color 350ms ease;
}
.timeline-vehicle-row:hover,
.timeline-vehicle-row:focus-within {
    background: color-mix(in srgb, var(--bike-color) 48%, var(--timeline-base));
}
.timeline-vehicle-row:hover .timeline-vehicle-label,
.timeline-vehicle-row:focus-within .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 54%, var(--background));
}
.dark .timeline-vehicle-row:hover .timeline-vehicle-label,
.dark .timeline-vehicle-row:focus-within .timeline-vehicle-label {
    background: color-mix(in srgb, var(--bike-color) 54%, #151717);
}
.timeline-day:hover,
.timeline-day:focus-visible {
    background: color-mix(in srgb, var(--bike-color) 92%, white);
    box-shadow:
        inset 0 0 0 1px color-mix(in srgb, var(--bike-color) 45%, white),
        0 0 10px #3988ff88;
}
/* Выходные тоже подсвечиваются, когда курсор на строке байка */
.timeline-vehicle-row:hover .timeline-day.timeline-weekend-cell,
.timeline-vehicle-row:focus-within .timeline-day.timeline-weekend-cell {
    background-color: color-mix(
        in srgb,
        black 10%,
        color-mix(in srgb, var(--bike-color) 48%, var(--timeline-base))
    );
}
/* Сама ячейка под курсором остаётся ярче всех */
.timeline-vehicle-row .timeline-day.timeline-weekend-cell:hover,
.timeline-vehicle-row .timeline-day.timeline-weekend-cell:focus-visible {
    background: color-mix(in srgb, var(--bike-color) 92%, white);
}
.timeline-date-hover,
.timeline-date-head:hover {
    outline: 2px solid #69b7ff;
    outline-offset: -2px;
    box-shadow:
        inset 0 0 6px #4da3ff55,
        0 0 7px 2px #3988ff,
        0 0 15px 4px #3988ff88;
    color: #2878ff;
    text-shadow:
        0 0 2px #b9faff,
        0 0 6px #3988ff;
}
.timeline-name {
    font-family: 'Rajdhani', sans-serif;
    font-weight: 700;
    min-height: 24px;
    padding: calc(1px * var(--timeline-zoom, 1))
        calc(7px * var(--timeline-zoom, 1));
    border: 1px solid color-mix(in srgb, var(--paint-color) 48%, #777e7b);
    border-radius: 6px;
    background: var(--background);
    box-shadow: 0 0 0 1px color-mix(in srgb, var(--paint-color) 22%, #303534);
}
.dark .timeline-name {
    background: #131515;
}
.timeline-vehicle-row:hover .timeline-name,
.timeline-name:focus-visible {
    border-color: var(--paint-color);
    box-shadow:
        0 0 0 2px color-mix(in srgb, var(--paint-color) 72%, #e7eeeb),
        0 0 16px color-mix(in srgb, var(--paint-color) 85%, transparent),
        0 0 34px color-mix(in srgb, var(--paint-color) 58%, transparent);
}
.timeline-paint-word {
    display: inline-grid;
    vertical-align: baseline;
    color: #000000;
    font-weight: 700;
    --paint-stroke: var(--paint-ink);
    --paint-glow: var(--paint-ink);
}
.timeline-paint-outline,
.timeline-paint-fill {
    grid-area: 1 / 1;
}
.timeline-paint-outline {
    /* A foreground glyph covers the inward half even without paint-order. */
    -webkit-text-stroke: 2px var(--paint-stroke);
    paint-order: stroke fill;
    text-shadow:
        0 0 4px var(--paint-glow),
        0 0 10px var(--paint-glow);
}
.timeline-paint-fill {
    position: relative;
    -webkit-text-stroke: 0;
    text-shadow: none;
}
@supports not (-webkit-text-stroke: 1px white) {
    .timeline-paint-outline {
        text-shadow:
            -1px 0 var(--paint-stroke),
            1px 0 var(--paint-stroke),
            0 -1px var(--paint-stroke),
            0 1px var(--paint-stroke),
            0 0 6px var(--paint-glow);
    }
}
.timeline-paint-black {
    --paint-stroke: #aeb4bc;
    --paint-glow: #aeb4bc;
}
/* The grid owns pinch and mouse drag. Native single-finger panning stays
   available; no transform scaling keeps geometry and hit targets aligned. */
.timeline-scroll-area {
    touch-action: pan-x pan-y;
}
.timeline-scroll-area.timeline-panning {
    cursor: grabbing;
    user-select: none;
}
.timeline-vehicle-head button {
    min-width: 32px !important;
    min-height: 32px;
    flex-shrink: 0;
    font-size: max(10px, calc(12px * var(--timeline-zoom, 1))) !important;
}
.timeline-vehicle-head button svg {
    min-width: 12px;
    min-height: 12px;
}
/* Keep today's indicator and selected date states above the weekend wash. */
.timeline-day.bg-cyan-50\/50 {
    background-color: color-mix(in srgb, #22d3ee 14%, var(--timeline-base));
}
.timeline-name,
.timeline-month,
.timeline-date-head,
.timeline-occupancy {
    font-size: calc(12px * var(--timeline-zoom, 1));
    line-height: calc(16px * var(--timeline-zoom, 1));
}
.timeline-name span {
    line-height: inherit;
}
.timeline-date-head > span {
    font-size: calc(10px * var(--timeline-zoom, 1));
}
.timeline-vehicle-details {
    font-size: calc(11px * var(--timeline-zoom, 1));
    line-height: calc(16px * var(--timeline-zoom, 1));
}
.timeline-vehicle-label {
    padding-block: calc(2px * var(--timeline-zoom, 1));
    padding-inline: calc(8px * var(--timeline-zoom, 1));
    gap: calc(6px * var(--timeline-zoom, 1));
}
.timeline-occupancy {
    padding-inline: calc(8px * var(--timeline-zoom, 1));
}
.timeline-vehicle-info[data-state='open'] {
    animation: timeline-info-in 350ms ease;
}
.timeline-vehicle-info[data-state='closed'] {
    animation: timeline-info-out 350ms ease;
}
@keyframes timeline-info-in {
    from {
        opacity: 0;
        transform: translateY(4px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
@keyframes timeline-info-out {
    from {
        opacity: 1;
    }
    to {
        opacity: 0;
    }
}
@media (prefers-reduced-motion: reduce) {
    .timeline-page *,
    .timeline-collapse,
    .timeline-vehicle-info {
        transition: none !important;
        animation: none !important;
    }
}
</style>
