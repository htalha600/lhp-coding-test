<script setup lang="ts">
import { CalendarDays, MapPin, Search, SlidersHorizontal } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { EventFilters } from '@/composables/useEvents';

defineProps<{
    cities: string[];
    total: number | null;
}>();

const form = defineModel<EventFilters>('form', { required: true });

const emit = defineEmits<{ (e: 'apply'): void }>();

// shadcn Select can't bind to an empty-string value, so we map "" <-> "all".
const ALL = 'all';
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card/60 p-4 shadow-sm backdrop-blur"
        @submit.prevent="emit('apply')"
    >
        <div class="flex flex-col gap-1.5">
            <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <MapPin class="size-3.5" /> Location
            </Label>
            <Select
                :model-value="form.city || ALL"
                @update:model-value="(v) => (form.city = v === ALL ? '' : String(v))"
            >
                <SelectTrigger class="min-w-48">
                    <SelectValue placeholder="All locations" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem :value="ALL">All locations</SelectItem>
                    <SelectItem v-for="c in cities" :key="c" :value="c">{{ c }}</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="flex flex-col gap-1.5">
            <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <CalendarDays class="size-3.5" /> From date
            </Label>
            <Input v-model="form.from" type="date" class="w-40 cursor-pointer" />
        </div>

        <div class="flex flex-col gap-1.5">
            <Label class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <CalendarDays class="size-3.5" /> To date
            </Label>
            <Input v-model="form.to" type="date" class="w-40 cursor-pointer" />
        </div>

        <Button type="submit" class="active:scale-95">
            <SlidersHorizontal class="size-4" />
            Apply
        </Button>

        <Badge v-if="total !== null" variant="secondary" class="ml-auto gap-1.5 self-center px-3 py-1.5">
            <Search class="size-3.5" />
            {{ total.toLocaleString() }} events
        </Badge>
    </form>
</template>
