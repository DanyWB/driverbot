<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/composables/useLocale';
import { login } from '@/routes';
import { email } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Forgot password',
        description:
            'Enter your administrator email. The reset link will arrive in your linked Telegram, not by email.',
    },
});

defineProps<{
    status?: string;
}>();
const { t } = useLocale();
</script>

<template>
    <Head :title="t('Forgot password')" />

    <div
        v-if="status"
        role="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <div class="space-y-6">
        <Form v-bind="email.form()" v-slot="{ errors, processing }">
            <div class="grid gap-2">
                <Label for="email">{{ t('Email address') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    required
                    :maxlength="254"
                    autofocus
                    placeholder="email@example.com"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="my-6 flex items-center justify-start">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="telegram-password-reset-link-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('Send link to Telegram') }}
                </Button>
            </div>
        </Form>

        <p class="text-sm text-muted-foreground">
            {{
                t(
                    'The one-time link expires in 15 minutes. Use the Telegram account previously linked in Settings. If you no longer have access to it, contact the owner. Never send your password to the bot.',
                )
            }}
        </p>

        <div class="space-x-1 text-center text-sm text-muted-foreground">
            <span>{{ t('Or, return to') }}</span>
            <TextLink :href="login()">{{ t('log in') }}</TextLink>
        </div>
    </div>
</template>
