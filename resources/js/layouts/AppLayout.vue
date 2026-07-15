<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import type { SharedData, User } from '@/types';

const user = usePage<SharedData>().props.auth.user as User;

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="flex h-12 shrink-0 items-center justify-between border-b border-border px-4">
            <span class="text-sm font-bold tracking-widest uppercase">Piles!</span>
            <div class="flex items-center gap-3">
                <span class="text-xs text-muted-foreground">{{ user.name }}</span>
                <button
                    @click="logout"
                    class="rounded px-2 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                >
                    Sign out
                </button>
            </div>
        </header>

        <main class="flex flex-1 flex-col overflow-auto">
            <slot />
        </main>
    </div>
</template>