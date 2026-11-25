<script setup lang="ts">
import { usePasskeyVerify } from '@laravel/passkeys/vue';
import { index as confirmOptions } from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/InputError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import { SolarKeyRound as KeyRound } from '@/lib/solarIcons';
import { Spinner } from '@/components/ui/spinner';

defineProps<{ primary?: boolean }>();

const emit = defineEmits<{ unlocked: [] }>();

const { verify, isLoading, error, isSupported } = usePasskeyVerify({
    routes: {
        options: confirmOptions().url,
        submit: '/console/unlock/passkey',
    },
    onSuccess: () => emit('unlocked'),
});
</script>

<template>
    <div v-if="isSupported" class="flex flex-col items-center gap-2">
        <AppButton
            :variant="primary ? 'primary' : 'secondary'"
            size="sm"
            :disabled="isLoading"
            @click="verify"
        >
            <Spinner v-if="isLoading" />
            <KeyRound v-else class="size-4" />
            {{ isLoading ? $t('Checking…') : $t('Use a passkey') }}
        </AppButton>
        <InputError :message="error ?? undefined" />
    </div>
</template>
