import { defineStore } from 'pinia';
import { ref } from 'vue';

export type NotificationLevel = 'success' | 'error' | 'info' | 'warning';

export interface Notification {
    id: number;
    message: string;
    level: NotificationLevel;
}

// A ten-player table can finish several piles at once. Past this many the
// stack starts covering the board, so the oldest drop early — their pending
// remove() timers are then harmless no-ops.
const MAX_VISIBLE = 4;

export const useNotificationStore = defineStore('notification', () => {
    const notifications = ref<Notification[]>([]);
    let nextId = 0;

    function add(message: string, level: NotificationLevel = 'info', durationMs = 3500) {
        const id = nextId++;
        notifications.value.push({ id, message, level });

        if (notifications.value.length > MAX_VISIBLE) {
            notifications.value.splice(0, notifications.value.length - MAX_VISIBLE);
        }

        setTimeout(() => remove(id), durationMs);
    }

    function remove(id: number) {
        notifications.value = notifications.value.filter((n) => n.id !== id);
    }

    return { notifications, add, remove };
});