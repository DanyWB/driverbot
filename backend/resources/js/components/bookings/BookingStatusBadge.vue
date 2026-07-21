<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { bookingStatusClasses, bookingStatusLabels } from '@/lib/bookings';
import type { BookingStatus } from '@/types';

defineProps<{ status: BookingStatus }>();
</script>

<template>
    <Badge variant="outline" :class="bookingStatusClasses[status]">
        {{ bookingStatusLabels[status] }}
    </Badge>
</template>
