<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

defineProps<{ open: boolean }>();

const emit = defineEmits<{ (e: 'close'): void; (e: 'created'): void }>();

const defaults = () => ({
    title: '',
    description: '',
    type: '',
    status: 'published',
    organizer: '',
    venue: '',
    capacity: '',
    price: '',
    date_time: '',
    latitude: '',
    longitude: '',
});

const TYPES = ['concert', 'conference', 'meetup', 'workshop', 'festival', 'sports', 'networking', 'exhibition'];
const STATUSES = ['draft', 'published', 'cancelled', 'sold_out'];

const MAX_IMAGES = 3;

const form = reactive<Record<string, string>>(defaults());
const files = ref<File[]>([]);
const previews = ref<string[]>([]);
const processing = ref(false);
const errors = ref<Record<string, string>>({});
const fileInput = ref<HTMLInputElement | null>(null);
const tooMany = ref(false);

function pickFiles() {
    fileInput.value?.click();
}

function onFiles(e: globalThis.Event) {
    const input = e.target as HTMLInputElement;
    const chosen = input.files ? Array.from(input.files) : [];
    // Hard cap at MAX_IMAGES; flag if the user tried to add more.
    tooMany.value = chosen.length > MAX_IMAGES;
    files.value = chosen.slice(0, MAX_IMAGES);
    previews.value.forEach((u) => URL.revokeObjectURL(u));
    previews.value = files.value.map((f) => URL.createObjectURL(f));
}

function removeFile(index: number) {
    URL.revokeObjectURL(previews.value[index]);
    files.value.splice(index, 1);
    previews.value.splice(index, 1);
    tooMany.value = false;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

// Open the native date/time picker when the field is clicked (not just the icon).
function openPicker(e: globalThis.Event) {
    const input = e.target as HTMLInputElement & { showPicker?: () => void };

    try {
        input.showPicker?.();
    } catch {
        // showPicker throws if called without a user gesture; ignore.
    }
}

function reset() {
    Object.assign(form, defaults());
    previews.value.forEach((u) => URL.revokeObjectURL(u));
    files.value = [];
    previews.value = [];
    errors.value = {};
    tooMany.value = false;

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function close() {
    reset();
    emit('close');
}

function submit() {
    processing.value = true;
    errors.value = {};

    const payload: Record<string, unknown> = { ...form, images: files.value };

    router.post('/events', payload as never, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            emit('created');
            close();
        },
        onError: (e) => (errors.value = e as Record<string, string>),
        onFinish: () => (processing.value = false),
    });
}
</script>

<template>
    <Transition name="overlay">
        <div
            v-if="open"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
            @click.self="close"
        >
            <div class="dialog-pop max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl border bg-card p-6 shadow-2xl">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-primary">New event</p>
                        <h2 class="text-xl font-semibold">Add an event</h2>
                    </div>
                    <button class="text-muted-foreground hover:text-foreground" @click="close">✕</button>
                </div>

                <form class="grid grid-cols-1 gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <label class="text-sm font-medium">Title</label>
                        <input v-model="form.title" type="text" required class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.title" class="text-xs text-red-500">{{ errors.title }}</span>
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <label class="text-sm font-medium">Description</label>
                        <textarea v-model="form.description" rows="3" class="rounded-md border border-input bg-background px-3 py-2 text-sm" />
                        <span v-if="errors.description" class="text-xs text-red-500">{{ errors.description }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Type</label>
                        <select v-model="form.type" class="h-9 rounded-md border border-input bg-background px-3 text-sm">
                            <option value="">Select type</option>
                            <option v-for="t in TYPES" :key="t" :value="t" class="capitalize">{{ t }}</option>
                        </select>
                        <span v-if="errors.type" class="text-xs text-red-500">{{ errors.type }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Status</label>
                        <select v-model="form.status" class="h-9 rounded-md border border-input bg-background px-3 text-sm">
                            <option v-for="s in STATUSES" :key="s" :value="s">{{ s.replace('_', ' ') }}</option>
                        </select>
                        <span v-if="errors.status" class="text-xs text-red-500">{{ errors.status }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Organizer</label>
                        <input v-model="form.organizer" type="text" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.organizer" class="text-xs text-red-500">{{ errors.organizer }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Venue</label>
                        <input v-model="form.venue" type="text" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.venue" class="text-xs text-red-500">{{ errors.venue }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Capacity</label>
                        <input v-model="form.capacity" type="number" min="0" placeholder="e.g. 500" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.capacity" class="text-xs text-red-500">{{ errors.capacity }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Price (USD)</label>
                        <input v-model="form.price" type="number" step="0.01" min="0" placeholder="0 = free" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.price" class="text-xs text-red-500">{{ errors.price }}</span>
                    </div>

                    <div class="flex flex-col gap-1 sm:col-span-2">
                        <label class="text-sm font-medium">Date &amp; time</label>
                        <input
                            v-model="form.date_time"
                            type="datetime-local"
                            required
                            class="h-9 cursor-pointer rounded-md border border-input bg-background px-3 text-sm"
                            @click="openPicker"
                            @focus="openPicker"
                        />
                        <span v-if="errors.date_time" class="text-xs text-red-500">{{ errors.date_time }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Latitude</label>
                        <input v-model="form.latitude" type="number" step="any" required placeholder="-90 to 90" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.latitude" class="text-xs text-red-500">{{ errors.latitude }}</span>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="text-sm font-medium">Longitude</label>
                        <input v-model="form.longitude" type="number" step="any" required placeholder="-180 to 180" class="h-9 rounded-md border border-input bg-background px-3 text-sm" />
                        <span v-if="errors.longitude" class="text-xs text-red-500">{{ errors.longitude }}</span>
                    </div>

                    <div class="flex flex-col gap-1.5 sm:col-span-2">
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium">Images (up to 3)</label>
                            <button
                                type="button"
                                class="rounded-md border border-input px-3 py-1.5 text-xs font-medium transition hover:bg-accent active:scale-95"
                                @click="pickFiles"
                            >
                                Select images
                            </button>
                        </div>
                        <input
                            ref="fileInput"
                            type="file"
                            accept="image/*"
                            multiple
                            class="hidden"
                            @change="onFiles"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ files.length ? `${files.length} of 3 selected` : 'No images selected — a placeholder is used if none.' }}
                        </p>
                        <span v-if="tooMany" class="text-xs text-amber-600">Only the first 3 images were kept.</span>
                        <span v-if="errors.images" class="text-xs text-red-500">{{ errors.images }}</span>
                        <span v-if="errors['images.0']" class="text-xs text-red-500">{{ errors['images.0'] }}</span>
                        <div v-if="previews.length" class="mt-1 flex flex-wrap gap-2">
                            <div v-for="(src, i) in previews" :key="i" class="group relative">
                                <img :src="src" class="h-16 w-24 rounded-md border object-cover" alt="preview" />
                                <button
                                    type="button"
                                    class="absolute -right-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full bg-foreground text-xs text-background opacity-0 transition group-hover:opacity-100"
                                    aria-label="Remove image"
                                    @click="removeFile(i)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button type="button" class="h-10 rounded-md border px-4 text-sm font-medium" @click="close">Cancel</button>
                        <button
                            type="submit"
                            :disabled="processing"
                            class="h-10 rounded-md bg-primary px-5 text-sm font-medium text-primary-foreground transition hover:opacity-90 active:scale-95 disabled:opacity-60"
                        >
                            {{ processing ? 'Creating…' : 'Create event' }}
                        </button>
                    </div>
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
        transform: scale(0.96) translateY(8px);
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
