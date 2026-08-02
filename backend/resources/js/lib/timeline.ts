import type { TimelineOccupancyStatus } from '@/types';

export const timelineStatusLabels: Record<TimelineOccupancyStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    active: 'Active rental',
    maintenance: 'Maintenance',
};

export const timelineStatusClasses: Record<TimelineOccupancyStatus, string> = {
    pending:
        'border-amber-400 bg-amber-100 text-amber-950 hover:bg-amber-200 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100',
    approved:
        'border-emerald-400 bg-emerald-100 text-emerald-950 hover:bg-emerald-200 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-100',
    active: 'border-blue-700 bg-blue-600 text-white shadow-sm hover:bg-blue-700 dark:border-blue-400 dark:bg-blue-700 dark:text-white dark:hover:bg-blue-600',
    maintenance:
        'border-rose-400 bg-rose-100 text-rose-950 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100',
};
