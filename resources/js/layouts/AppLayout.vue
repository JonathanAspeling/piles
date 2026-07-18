<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import type { SharedData, User } from '@/types';

const user = usePage<SharedData>().props.auth.user as User;

const menuOpen = ref(false);

function toggleMenu() {
    menuOpen.value = !menuOpen.value;
}

function closeMenu() {
    menuOpen.value = false;
}

function logout() {
    router.post(route('logout'));
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        closeMenu();
    }
}

if (typeof window !== 'undefined') {
    window.addEventListener('keydown', onKeydown);
    onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="hidden h-12 shrink-0 items-center justify-between border-b border-border px-4 md:flex">
            <Link :href="route('lobby.index')" class="text-sm font-bold uppercase tracking-widest hover:text-primary">
                Piles!
            </Link>
            <div class="flex items-center gap-3">
                <span class="text-xs text-muted-foreground">{{ user.name }}</span>
                <button
                    @click="logout"
                    class="rounded px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                >
                    Sign out
                </button>
            </div>
        </header>

        <!-- Mobile: floating menu toggle -->
        <button
            @click="toggleMenu"
            aria-label="Open menu"
            class="fixed right-2 top-2 z-40 flex h-9 w-9 items-center justify-center rounded-full border border-border bg-card/90 text-foreground shadow backdrop-blur-sm active:scale-95 md:hidden"
        >
            <svg
                v-if="!menuOpen"
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <line x1="4" y1="7" x2="20" y2="7" />
                <line x1="4" y1="12" x2="20" y2="12" />
                <line x1="4" y1="17" x2="20" y2="17" />
            </svg>
            <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
            >
                <line x1="6" y1="6" x2="18" y2="18" />
                <line x1="6" y1="18" x2="18" y2="6" />
            </svg>
        </button>

        <!-- Mobile menu backdrop + panel -->
        <div v-if="menuOpen" class="fixed inset-0 z-30 md:hidden" @click="closeMenu">
            <div class="absolute inset-0 bg-black/30" />
            <div
                @click.stop
                class="absolute right-2 top-12 flex w-52 flex-col gap-3 rounded-lg border border-border bg-card p-3 shadow-lg"
            >
                <div class="flex items-center justify-between">
                    <Link :href="route('lobby.index')" @click="closeMenu" class="text-sm font-bold uppercase tracking-widest">
                        Piles!
                    </Link>
                    <span class="text-xs text-muted-foreground">{{ user.name }}</span>
                </div>
                <Link
                    :href="route('lobby.index')"
                    @click="closeMenu"
                    class="rounded-md border border-border px-3 py-2 text-center text-sm text-foreground transition-colors hover:bg-muted"
                >
                    Lobby
                </Link>
                <button
                    @click="logout"
                    class="rounded-md border border-border px-3 py-2 text-sm text-foreground transition-colors hover:bg-muted"
                >
                    Sign out
                </button>
            </div>
        </div>

        <main class="flex flex-1 flex-col overflow-auto">
            <slot />
        </main>
    </div>
</template>