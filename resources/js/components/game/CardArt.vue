<script setup lang="ts">
import { computed } from 'vue';
import type { ClothingType } from '../../types/game';
import { CLOTHING_TYPE_LABELS } from '../../types/game';
import { getCardArtUrl } from '../../utils/cardArt';

const props = defineProps<{
    type: ClothingType;
}>();

const artUrl = computed(() => getCardArtUrl(props.type));
const hasArt = computed(() => artUrl.value !== undefined);
</script>

<template>
    <img
        v-if="hasArt"
        :src="artUrl"
        :alt="CLOTHING_TYPE_LABELS[type]"
        class="h-full w-full object-contain"
        draggable="false"
    />
    <span
        v-else
        class="px-1 text-center text-[11px] font-bold leading-tight sm:text-[10px]"
    >
        {{ CLOTHING_TYPE_LABELS[type] }}
    </span>
</template>
