import { defineStore } from 'pinia';
import { ref } from 'vue';

export type NotificationLevel = 'success' | 'error' | 'info' | 'warning';

export interface Notification {
    id: number;
    message: string;
    level: NotificationLevel;
}

export const useNotificationStore = defineStore('notification', () => {
    const notifications = ref<Notification[]>([]);
    let nextId = 0;

    function add(message: string, level: NotificationLevel = 'info', durationMs = 3500) {
        const id = nextId++;
        notifications.value.push({ id, message, level });
        setTimeout(() => remove(id), durationMs);
    }

    function remove(id: number) {
        notifications.value = notifications.value.filter((n) => n.id !== id);
    }

    return { notifications, add, remove };
});