<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/composables/useLocale';
import { bookingStatusClasses, bookingStatusLabels } from '@/lib/bookings';
import type { BookingStatus } from '@/types';

defineProps<{ status: BookingStatus }>();

const { t } = useLocale();
</script>

<template>
    <Badge variant="outline" :class="bookingStatusClasses[status]">
        {{ t(bookingStatusLabels[status]) }}
    </Badge>
</template>
