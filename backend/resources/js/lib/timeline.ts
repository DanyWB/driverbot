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
    active: 'border-cyan-400 bg-cyan-100 text-cyan-950 hover:bg-cyan-200 dark:border-cyan-700 dark:bg-cyan-950 dark:text-cyan-100',
    maintenance:
        'border-rose-400 bg-rose-100 text-rose-950 dark:border-rose-700 dark:bg-rose-950 dark:text-rose-100',
};
