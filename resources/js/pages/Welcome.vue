<script setup lang="ts">
import type { SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const REMEMBERED_NAME_KEY = 'playerName';

const page = usePage<SharedData>();
const nameInput = ref<HTMLInputElement | null>(null);
const wasRemembered = ref(false);

const form = useForm({
    name: '',
});

onMounted(() => {
    const remembered = page.props.auth.user?.name ?? localStorage.getItem(REMEMBERED_NAME_KEY);

    if (remembered) {
        form.name = remembered;
        wasRemembered.value = true;
    } else {
        nameInput.value?.focus();
    }
});

function play() {
    form.post(route('play'), {
        onSuccess: (nextPage) => {
            const player = (nextPage.props as unknown as SharedData).auth.user;

            localStorage.setItem(REMEMBERED_NAME_KEY, player?.name ?? form.name.trim());
        },
    });
}
</script>

<template>
    <Head title="Piles" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-background px-6 text-foreground">
        <main class="flex w-full max-w-sm flex-col items-center gap-8 text-center">
            <h1 class="text-6xl font-bold uppercase tracking-widest">Piles!</h1>
            <p class="text-sm text-muted-foreground">A fast-paced card game.</p>

            <form @submit.prevent="play" class="flex w-full flex-col gap-3">
                <div>
                    <input
                        ref="nameInput"
                        type="text"
                        v-model="form.name"
                        placeholder="Your name"
                        maxlength="30"
                        autocomplete="nickname"
                        class="w-full rounded-lg border border-input bg-background px-3 py-3 text-center text-lg tracking-widest focus:outline-none focus:ring-2 focus:ring-ring sm:py-2 sm:text-sm"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-sm text-destructive">{{ form.errors.name }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing || !form.name.trim()"
                    class="rounded border border-foreground bg-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-background transition-colors hover:bg-transparent hover:text-foreground disabled:opacity-50 disabled:hover:bg-foreground disabled:hover:text-background"
                >
                    {{ form.processing ? 'Dealing you in…' : 'Play' }}
                </button>

                <p v-if="wasRemembered" class="text-xs text-muted-foreground">Not you? Type a different name.</p>
            </form>
        </main>
    </div>
</template>
