<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
});

const playAsGuest = () => {
    form.post(route('guest.store'));
};
</script>

<template>
    <Head title="Piles" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-background px-6 text-foreground">
        <main class="flex w-full max-w-sm flex-col items-center gap-8 text-center">
            <h1 class="text-6xl font-bold tracking-widest uppercase">Piles!</h1>
            <p class="text-sm text-muted-foreground">A fast-paced card game.</p>

            <div v-if="$page.props.auth.user" class="flex w-full flex-col gap-3">
                <Link
                    :href="route('lobby.index')"
                    class="rounded border border-foreground bg-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-background transition-colors hover:bg-transparent hover:text-foreground"
                >
                    Play
                </Link>
            </div>

            <template v-else>
                <form @submit.prevent="playAsGuest" class="flex w-full flex-col gap-3">
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        autofocus
                        maxlength="50"
                        autocomplete="nickname"
                        aria-label="Your name"
                        placeholder="Your name"
                        class="rounded border border-foreground bg-transparent px-4 py-2 text-center text-sm placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-foreground"
                    />
                    <InputError :message="form.errors.name" />
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="rounded border border-foreground bg-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-background transition-colors hover:bg-transparent hover:text-foreground disabled:opacity-50"
                    >
                        Play
                    </button>
                </form>

                <p class="text-xs text-muted-foreground">
                    Have an account?
                    <Link :href="route('login')" class="underline underline-offset-4 hover:text-foreground">Log in</Link>
                    or
                    <Link :href="route('register')" class="underline underline-offset-4 hover:text-foreground">sign up</Link>
                </p>
            </template>
        </main>
    </div>
</template>
