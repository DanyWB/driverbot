<script setup lang="ts">
import { formatMoney } from '@/lib/bookings';
import type { PriceBreakdownItem } from '@/types';

defineProps<{
    items: PriceBreakdownItem[];
    currency: string;
}>();
</script>

<template>
    <div class="overflow-hidden rounded-md border">
        <table class="w-full text-sm">
            <thead class="bg-muted/60 text-left text-xs text-muted-foreground">
                <tr>
                    <th class="px-3 py-2 font-medium">Season</th>
                    <th class="px-3 py-2 text-right font-medium">Days</th>
                    <th
                        class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                    >
                        Daily rate
                    </th>
                    <th class="px-3 py-2 text-right font-medium">Subtotal</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <tr v-for="item in items" :key="`${item.season}-${item.tier}`">
                    <td class="px-3 py-2 capitalize">{{ item.season }}</td>
                    <td class="px-3 py-2 text-right tabular-nums">
                        {{ item.days }}
                    </td>
                    <td
                        class="hidden px-3 py-2 text-right tabular-nums sm:table-cell"
                    >
                        {{ formatMoney(item.daily_rate, currency) }}
                    </td>
                    <td class="px-3 py-2 text-right font-medium tabular-nums">
                        {{ formatMoney(item.subtotal, currency) }}
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
