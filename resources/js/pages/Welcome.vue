<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Piles" />
    <div class="flex min-h-screen flex-col items-center justify-center bg-background px-6 text-foreground">
        <main class="flex w-full max-w-sm flex-col items-center gap-8 text-center">
            <h1 class="text-6xl font-bold tracking-widest uppercase">Piles!</h1>
            <p class="text-sm text-muted-foreground">A fast-paced card game.</p>

            <div class="flex w-full flex-col gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('lobby.index')"
                    class="rounded border border-foreground bg-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-background transition-colors hover:bg-transparent hover:text-foreground"
                >
                    Play
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="rounded border border-foreground bg-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-background transition-colors hover:bg-transparent hover:text-foreground"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('register')"
                        class="rounded border border-foreground px-4 py-2 text-sm font-semibold uppercase tracking-wider text-foreground transition-colors hover:bg-foreground hover:text-background"
                    >
                        Sign up
                    </Link>
                </template>
            </div>
        </main>
    </div>
</template>
