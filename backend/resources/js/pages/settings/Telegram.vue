<script setup lang="ts">
import { Form, Head, router, usePoll } from '@inertiajs/vue3';
import {
    BellRing,
    Bot,
    Check,
    CircleCheck,
    Copy,
    ExternalLink,
    Link2Off,
    Send,
    ShieldCheck,
    Users,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { ServerCountdown } from '@/lib/serverCountdown';
import TelegramController from '@/actions/App/Http/Controllers/Settings/TelegramController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useLocale } from '@/composables/useLocale';
import { edit } from '@/routes/telegram';
import {
    createServerCountdown,
    serverCountdownSeconds,
} from '@/lib/serverCountdown';

type TelegramBinding = {
    telegramUserId: string;
    telegramChatId: string;
    username: string | null;
    firstName: string | null;
    lastName: string | null;
    locale: string | null;
    generation: number;
    connectedAt: string | null;
    lastTestedAt: string | null;
    notificationsEnabled: boolean;
};

type PendingCode = {
    code: string;
    command: string;
    expiresAt: string;
};

const props = defineProps<{
    binding: TelegramBinding | null;
    pendingCode: PendingCode | null;
    botUsername: string | null;
    serverNow: string;
    legacyFallbackActive: boolean;
    activeRecipientCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Telegram notifications',
                href: edit(),
            },
        ],
    },
});

const { locale, t } = useLocale();
const visibleCode = ref<PendingCode | null>(props.pendingCode);
const bindingGenerationAtIssue = ref<number | null>(
    props.pendingCode ? (props.binding?.generation ?? null) : null,
);
const copied = ref(false);
const copyFailed = ref(false);
const disconnectDialogOpen = ref(false);
const monotonicNow = ref(monotonicMilliseconds());
const countdown = ref<ServerCountdown | null>(
    props.pendingCode
        ? createServerCountdown(
              props.pendingCode.expiresAt,
              props.serverNow,
              monotonicNow.value,
          )
        : null,
);
const expiryReloadStarted = ref(false);
let clock: ReturnType<typeof setInterval> | null = null;
let copyReset: ReturnType<typeof setTimeout> | null = null;

const { start: startPolling, stop: stopPolling } = usePoll(
    4000,
    {
        only: ['binding', 'activeRecipientCount', 'legacyFallbackActive'],
    },
    { autoStart: false, keepAlive: false, mode: 'rest' },
);

const displayName = computed(() => {
    if (!props.binding) {
        return '';
    }

    const name = [props.binding.firstName, props.binding.lastName]
        .filter(Boolean)
        .join(' ')
        .trim();

    return (
        name ||
        (props.binding.username
            ? `@${props.binding.username}`
            : t('Telegram account'))
    );
});

const botUrl = computed(() =>
    props.botUsername ? `https://t.me/${props.botUsername}` : null,
);

const secondsRemaining = computed(() => {
    return visibleCode.value
        ? serverCountdownSeconds(countdown.value, monotonicNow.value)
        : 0;
});

const expiryText = computed(() => {
    const minutes = Math.floor(secondsRemaining.value / 60);
    const seconds = secondsRemaining.value % 60;

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});

function formatDate(value: string | null): string {
    if (!value) {
        return t('Not yet');
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return t('Not yet');
    }

    return new Intl.DateTimeFormat(locale.value === 'ru' ? 'ru-RU' : 'en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function monotonicMilliseconds(): number {
    return globalThis.performance?.now() ?? Date.now();
}

function resetCountdown(code: PendingCode, serverNow: string): void {
    monotonicNow.value = monotonicMilliseconds();
    countdown.value = createServerCountdown(
        code.expiresAt,
        serverNow,
        monotonicNow.value,
    );
    expiryReloadStarted.value = false;
}

async function copyCommand(): Promise<void> {
    if (!visibleCode.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(visibleCode.value.command);
        copied.value = true;
        copyFailed.value = false;

        if (copyReset) {
            clearTimeout(copyReset);
        }

        copyReset = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
        copyFailed.value = true;
    }
}

function beginConnectionPolling(): void {
    if (visibleCode.value && secondsRemaining.value > 0) {
        startPolling();
    }
}

function finishExpiredConnectionAttempt(): void {
    if (!visibleCode.value || expiryReloadStarted.value) {
        return;
    }

    expiryReloadStarted.value = true;
    visibleCode.value = null;
    bindingGenerationAtIssue.value = null;
    countdown.value = null;
    stopPolling();

    router.reload({
        only: ['binding', 'activeRecipientCount', 'legacyFallbackActive'],
        onFinish: () => {
            expiryReloadStarted.value = false;
        },
    });
}

watch(
    () => props.serverNow,
    (serverNow) => {
        if (visibleCode.value) {
            resetCountdown(visibleCode.value, serverNow);
        }
    },
);

watch(
    () => props.pendingCode,
    (pendingCode) => {
        if (pendingCode) {
            visibleCode.value = pendingCode;
            bindingGenerationAtIssue.value = props.binding?.generation ?? null;
            resetCountdown(pendingCode, props.serverNow);
            beginConnectionPolling();
        }
    },
);

watch(
    () => props.binding,
    (binding) => {
        if (
            visibleCode.value &&
            binding &&
            (bindingGenerationAtIssue.value === null ||
                binding.generation !== bindingGenerationAtIssue.value)
        ) {
            visibleCode.value = null;
            bindingGenerationAtIssue.value = null;
            countdown.value = null;
            stopPolling();
        }
    },
);

watch(secondsRemaining, (remaining) => {
    if (remaining === 0 && visibleCode.value) {
        finishExpiredConnectionAttempt();
    }
});

onMounted(() => {
    clock = setInterval(() => {
        monotonicNow.value = monotonicMilliseconds();
    }, 1000);

    if (visibleCode.value && secondsRemaining.value === 0) {
        finishExpiredConnectionAttempt();
    } else {
        beginConnectionPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();

    if (clock) {
        clearInterval(clock);
    }

    if (copyReset) {
        clearTimeout(copyReset);
    }
});
</script>

<template>
    <Head :title="t('Telegram notifications')" />

    <h1 class="sr-only">{{ t('Telegram notifications') }}</h1>

    <div class="space-y-8">
        <Heading
            variant="small"
            title="Telegram notifications"
            description="Connect your personal Telegram account to receive administrator alerts"
        />

        <Alert
            v-if="legacyFallbackActive"
            class="border-amber-300 bg-amber-50 dark:border-amber-700/60 dark:bg-amber-950/30"
        >
            <ShieldCheck class="size-4 text-amber-700 dark:text-amber-300" />
            <AlertTitle>{{ t('Legacy recipient is active') }}</AlertTitle>
            <AlertDescription>
                {{
                    t(
                        'The previous server recipient still receives alerts. It will be replaced as soon as the first administrator connects Telegram here.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <div class="rounded-xl border bg-card p-5 shadow-xs sm:p-6">
            <div
                class="flex flex-col gap-5 sm:flex-row sm:items-start sm:justify-between"
            >
                <div class="flex min-w-0 gap-4">
                    <div
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                    >
                        <Bot class="size-6" />
                    </div>
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="font-semibold">
                                {{ t('Your Telegram account') }}
                            </h2>
                            <Badge
                                v-if="binding"
                                class="bg-emerald-600 text-white"
                            >
                                <CircleCheck class="size-3" />
                                {{ t('Connected') }}
                            </Badge>
                            <Badge v-else variant="secondary">{{
                                t('Not connected')
                            }}</Badge>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Each administrator connects a separate Telegram account. Alerts are sent independently to every active recipient.',
                                )
                            }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex shrink-0 items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                >
                    <Users class="size-4 text-muted-foreground" />
                    <span>{{
                        t('Active recipients: :count', {
                            count: activeRecipientCount,
                        })
                    }}</span>
                </div>
            </div>

            <template v-if="binding">
                <div
                    class="mt-6 grid gap-4 rounded-lg border bg-background p-4 sm:grid-cols-2"
                >
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Connected account') }}
                        </p>
                        <p class="mt-1 truncate font-medium">
                            {{ displayName }}
                        </p>
                        <p
                            v-if="binding.username"
                            class="truncate text-sm text-muted-foreground"
                        >
                            @{{ binding.username }}
                        </p>
                    </div>
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ t('Connection') }}
                        </p>
                        <p class="mt-1 text-sm">
                            {{ formatDate(binding.connectedAt) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ t('Last successful test') }}:
                            {{ formatDate(binding.lastTestedAt) }}
                        </p>
                    </div>
                </div>

                <div class="mt-5 flex flex-col gap-3 sm:flex-row">
                    <Form
                        v-bind="TelegramController.test.form()"
                        :options="{ preserveScroll: true }"
                        class="flex flex-col gap-2"
                        v-slot="{ errors, processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                            data-test="telegram-test"
                        >
                            <Send class="size-4" />
                            {{
                                processing
                                    ? t('Sending…')
                                    : t('Send test message')
                            }}
                        </Button>
                        <InputError
                            :message="
                                errors.telegram ? t(errors.telegram) : undefined
                            "
                        />
                    </Form>

                    <Form
                        v-if="!visibleCode"
                        v-bind="TelegramController.issueCode.form()"
                        :options="{ preserveScroll: true }"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            variant="outline"
                            :disabled="processing"
                            data-test="telegram-replace"
                        >
                            <ShieldCheck class="size-4" />
                            {{
                                processing
                                    ? t('Generating…')
                                    : t('Replace Telegram account')
                            }}
                        </Button>
                    </Form>

                    <Dialog v-model:open="disconnectDialogOpen">
                        <DialogTrigger as-child>
                            <Button
                                variant="destructive"
                                data-test="telegram-disconnect"
                            >
                                <Link2Off class="size-4" />
                                {{ t('Disconnect Telegram') }}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <Form
                                v-bind="TelegramController.destroy.form()"
                                :options="{ preserveScroll: true }"
                                @success="disconnectDialogOpen = false"
                                v-slot="{ processing }"
                            >
                                <DialogHeader class="space-y-3">
                                    <DialogTitle>{{
                                        t('Disconnect Telegram?')
                                    }}</DialogTitle>
                                    <DialogDescription>
                                        {{
                                            t(
                                                'This administrator will stop receiving alerts. Other connected administrators will not be affected.',
                                            )
                                        }}
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter class="mt-6 gap-2">
                                    <DialogClose as-child>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            >{{ t('Cancel') }}</Button
                                        >
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        :disabled="processing"
                                        data-test="telegram-confirm-disconnect"
                                    >
                                        {{ t('Disconnect') }}
                                    </Button>
                                </DialogFooter>
                            </Form>
                        </DialogContent>
                    </Dialog>
                </div>
            </template>

            <template v-if="!binding || visibleCode">
                <div
                    v-if="visibleCode"
                    class="mt-6 space-y-4 rounded-lg border border-sky-200 bg-sky-50 p-4 dark:border-sky-900 dark:bg-sky-950/40"
                >
                    <div class="flex items-start gap-3">
                        <BellRing
                            class="mt-0.5 size-5 shrink-0 text-sky-700 dark:text-sky-300"
                        />
                        <div>
                            <p class="font-medium">
                                {{ t('Finish connecting in Telegram') }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                {{
                                    t(
                                        'Open the bot and send this one-time command. Do not share it with anyone.',
                                    )
                                }}
                            </p>
                            <p
                                v-if="binding"
                                class="mt-1 text-sm text-muted-foreground"
                            >
                                {{
                                    t(
                                        'Your current account remains connected until the new command is accepted.',
                                    )
                                }}
                            </p>
                        </div>
                    </div>

                    <div
                        class="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <code
                            class="min-w-0 flex-1 overflow-x-auto rounded-md border bg-background px-4 py-3 text-center text-base font-semibold tracking-wider select-all"
                        >
                            {{ visibleCode.command }}
                        </code>
                        <Button
                            type="button"
                            variant="outline"
                            @click="copyCommand"
                            data-test="telegram-copy-command"
                        >
                            <Check
                                v-if="copied"
                                class="size-4 text-emerald-600"
                            />
                            <Copy v-else class="size-4" />
                            {{ copied ? t('Copied') : t('Copy command') }}
                        </Button>
                    </div>

                    <p v-if="copyFailed" class="text-sm text-destructive">
                        {{
                            t(
                                'Copy failed. Select the command and copy it manually.',
                            )
                        }}
                    </p>
                    <div
                        class="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p class="text-muted-foreground">
                            {{
                                t('Code expires in :time', { time: expiryText })
                            }}
                        </p>
                        <Button v-if="botUrl" variant="outline" as-child>
                            <a
                                :href="botUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {{ t('Open Telegram bot') }}
                                <ExternalLink class="size-4" />
                            </a>
                        </Button>
                    </div>
                </div>

                <Alert v-if="!botUsername" class="mt-6" variant="destructive">
                    <AlertTitle>{{
                        t('Bot username is not configured')
                    }}</AlertTitle>
                    <AlertDescription>
                        {{
                            t(
                                'You can still copy the command, but the direct bot link is unavailable.',
                            )
                        }}
                    </AlertDescription>
                </Alert>

                <Form
                    v-bind="TelegramController.issueCode.form()"
                    :options="{ preserveScroll: true }"
                    class="mt-6"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        :disabled="processing"
                        data-test="telegram-generate-code"
                    >
                        <ShieldCheck class="size-4" />
                        {{
                            processing
                                ? t('Generating…')
                                : visibleCode
                                  ? t('Generate a new code')
                                  : t('Connect Telegram')
                        }}
                    </Button>
                </Form>
            </template>
        </div>

        <div
            class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
        >
            <p class="font-medium text-foreground">
                {{ t('How multiple administrators work') }}
            </p>
            <p class="mt-1">
                {{
                    t(
                        'Every administrator signs in to the web panel and connects their own Telegram account here. Disconnecting one account never disables notifications for the others.',
                    )
                }}
            </p>
        </div>
    </div>
</template>
