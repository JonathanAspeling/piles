<script setup lang="ts">
defineProps<{
    playerName: string;
    isCurrentPlayer: boolean;
}>();
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-3 text-center text-white">
            <p class="text-6xl font-black tracking-tight">PILES!</p>
            <p class="text-xl">
                <span v-if="isCurrentPlayer">Verifying your claim…</span>
                <span v-else><strong>{{ playerName }}</strong> called PILES! — verifying…</span>
            </p>
            <div class="mt-2 h-2 w-48 overflow-hidden rounded-full bg-white/20">
                <div class="h-full animate-[progress_2s_linear_infinite] rounded-full bg-white"></div>
            </div>
        </div>
    </div>
</template>

<style scoped>
@keyframes progress {
    0% {
        width: 0%;
    }
    100% {
        width: 100%;
    }
}
</style>