<script setup lang="ts">
import type { EventFilters } from '@/composables/useEvents';

defineProps<{
    cities: string[];
    total: number | null;
}>();

const form = defineModel<EventFilters>('form', { required: true });

const emit = defineEmits<{ (e: 'apply'): void }>();

// Open the native calendar when the date field is clicked (not just the icon).
function openPicker(e: Event) {
    const input = e.target as HTMLInputElement & { showPicker?: () => void };

    try {
        input.showPicker?.();
    } catch {
        // showPicker throws without a user gesture; ignore.
    }
}
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card/60 p-4 shadow-sm backdrop-blur"
        @submit.prevent="emit('apply')"
    >
        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-muted-foreground">Location</label>
            <select v-model="form.city" class="h-9 min-w-45 rounded-md border border-input bg-background px-3 text-sm">
                <option value="">All locations</option>
                <option v-for="c in cities" :key="c" :value="c">{{ c }}</option>
            </select>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-muted-foreground">From date</label>
            <input
                v-model="form.from"
                type="date"
                class="h-9 cursor-pointer rounded-md border border-input bg-background px-3 text-sm"
                @click="openPicker"
                @focus="openPicker"
            />
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-xs font-medium text-muted-foreground">To date</label>
            <input
                v-model="form.to"
                type="date"
                class="h-9 cursor-pointer rounded-md border border-input bg-background px-3 text-sm"
                @click="openPicker"
                @focus="openPicker"
            />
        </div>

        <button
            type="submit"
            class="h-9 rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground transition hover:opacity-90 active:scale-95"
        >
            Apply
        </button>

        <span v-if="total !== null" class="ml-auto self-center text-sm text-muted-foreground">
            {{ total.toLocaleString() }} events
        </span>
    </form>
</template>
