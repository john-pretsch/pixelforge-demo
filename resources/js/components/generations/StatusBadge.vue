<script setup lang="ts">
import type { Generation } from '@/types';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ generation: Pick<Generation, 'status' | 'status_label'> }>();

const style = computed(() => {
    switch (props.generation.status) {
        case 'succeeded':
            return { icon: CheckCircle2, spin: false, cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' };
        case 'failed':
            return { icon: XCircle, spin: false, cls: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' };
        case 'processing':
            return { icon: Loader2, spin: true, cls: 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300' };
        default:
            return { icon: Clock, spin: false, cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300' };
    }
});
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', style.cls]">
        <component :is="style.icon" :class="['size-3.5', style.spin && 'animate-spin']" />
        {{ generation.status_label }}
    </span>
</template>
