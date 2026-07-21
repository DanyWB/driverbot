import type { BookingSource, BookingStatus } from '@/types';

export const bookingStatusLabels: Record<BookingStatus, string> = {
    process: 'Draft',
    pending: 'Pending',
    approved: 'Approved',
    active: 'Active rental',
    completed: 'Completed',
    cancelled: 'Cancelled',
    cancelled_by_client: 'Client cancelled',
    expired: 'Expired',
    no_show: 'No-show',
};

export const bookingStatusClasses: Record<BookingStatus, string> = {
    process:
        'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300',
    pending:
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200',
    approved:
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200',
    active: 'border-cyan-200 bg-cyan-50 text-cyan-800 dark:border-cyan-900 dark:bg-cyan-950 dark:text-cyan-200',
    completed:
        'border-zinc-200 bg-zinc-100 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
    cancelled:
        'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200',
    cancelled_by_client:
        'border-rose-200 bg-rose-50 text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-200',
    expired:
        'border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400',
    no_show:
        'border-red-300 bg-red-100 text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-200',
};

export const bookingSourceLabels: Record<BookingSource, string> = {
    telegram: 'Telegram bot',
    admin_phone: 'Phone',
    admin_whatsapp: 'WhatsApp',
    admin_instagram: 'Instagram',
    admin_manual: 'Manual',
    website: 'Website',
};

export function formatMoney(value: number | string, currency = 'THB'): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(Number(value));
}

export function formatDate(value: string): string {
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${value}T00:00:00Z`));
}

export function formatDateTime(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Bangkok',
    }).format(new Date(value));
}

export function shortBookingId(value: string): string {
    return value.slice(0, 8).toUpperCase();
}
