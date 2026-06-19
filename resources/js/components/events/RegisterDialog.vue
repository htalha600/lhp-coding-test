<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import type { EventCard } from '@/composables/useEvents';

const props = defineProps<{ event: EventCard | null }>();
const emit = defineEmits<{ (e: 'close'): void }>();

const form = reactive({ name: '', email: '', status: 'attending' });
const processing = ref(false);
const done = ref(false);
const errors = ref<Record<string, string>>({});

watch(
    () => props.event,
    () => {
        done.value = false;
        errors.value = {};
        form.name = '';
        form.email = '';
        form.status = 'attending';
    },
);

function submit() {
    if (!props.event) {
return;
}

    processing.value = true;
    errors.value = {};
    router.post(`/events/${props.event.id}/attendees`, { ...form }, {
        preserveScroll: true,
        onSuccess: () => (done.value = true),
        onError: (e) => (errors.value = e as Record<string, string>),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Transition name="overlay">
        <div
            v-if="event"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            @click.self="emit('close')"
        >
            <div class="dialog-pop w-full max-w-md rounded-2xl border bg-card p-6 shadow-2xl">
                <div class="mb-4 flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-primary">Register</p>
                        <h2 class="text-xl font-semibold leading-tight">{{ event.title }}</h2>
                        <p class="mt-1 text-sm text-muted-foreground">📍 {{ event.location?.label ?? 'Unknown' }}</p>
                    </div>
                    <button class="text-muted-foreground hover:text-foreground" @click="emit('close')">✕</button>
                </div>

                <div v-if="done" class="flex flex-col items-center gap-2 py-6 text-center">
                    <span class="text-4xl">✅</span>
                    <p class="font-medium">You're on the list!</p>
                    <p class="text-sm text-muted-foreground">A confirmation email is on its way.</p>
                    <button
                        class="mt-3 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground"
                        @click="emit('close')"
                    >
                        Done
                    </button>
                </div>

                <form v-else class="flex flex-col gap-3" @submit.prevent="submit">
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Name</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        />
                        <span v-if="errors.name" class="text-xs text-red-500">{{ errors.name }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Email</label>
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm"
                        />
                        <span v-if="errors.email" class="text-xs text-red-500">{{ errors.email }}</span>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">I'm…</label>
                        <select v-model="form.status" class="h-9 rounded-md border border-input bg-background px-3 text-sm">
                            <option value="attending">Attending</option>
                            <option value="interested">Interested</option>
                        </select>
                    </div>
                    <button
                        type="submit"
                        :disabled="processing"
                        class="mt-2 h-10 rounded-md bg-primary text-sm font-medium text-primary-foreground transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                    >
                        {{ processing ? 'Registering…' : 'Confirm registration' }}
                    </button>
                </form>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.overlay-enter-active,
.overlay-leave-active {
    transition: opacity 0.2s ease;
}
.overlay-enter-from,
.overlay-leave-to {
    opacity: 0;
}
@keyframes dialog-pop {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(8px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}
.dialog-pop {
    animation: dialog-pop 0.22s ease-out both;
}
</style>
