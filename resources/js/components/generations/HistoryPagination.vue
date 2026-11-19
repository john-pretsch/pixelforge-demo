<script setup lang="ts">
import type { Pagination } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ pagination: Pagination }>();

// Only the history (and its pager) reload; the form and pricing options stay as they are.
// After the new page loads, bring the top of the history list into view (pages can differ in length).
const nav = ref<HTMLElement | null>(null);
const linkOptions = {
    only: ['generations', 'pagination'],
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => nav.value?.closest('section')?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
};
const href = (page: number) => route('generations.index', page > 1 ? { page } : {});

/** First, last, and the current page ±1, with gaps (null) between: 1 … 4 5 6 … 12 */
const pages = computed(() => {
    const { current_page: current, last_page: last } = props.pagination;
    const wanted = [...new Set([1, current - 1, current, current + 1, last])].filter((p) => p >= 1 && p <= last).sort((a, b) => a - b);

    return wanted.flatMap((p, i) => (i > 0 && p - wanted[i - 1] > 1 ? [null, p] : [p]));
});

const base = 'inline-flex h-9 min-w-9 items-center justify-center rounded-md px-2 text-sm transition';
const idle = `${base} border hover:bg-muted`;
const disabled = `${base} border opacity-40 pointer-events-none`;
</script>

<template>
    <nav
        v-if="pagination.last_page > 1"
        ref="nav"
        class="flex items-center justify-center gap-1"
        aria-label="History pages"
        data-testid="history-pagination"
    >
        <Link
            :href="href(pagination.current_page - 1)"
            v-bind="linkOptions"
            :class="pagination.current_page > 1 ? idle : disabled"
            aria-label="Previous page"
            rel="prev"
        >
            <ChevronLeft class="size-4" />
        </Link>

        <template v-for="(p, i) in pages" :key="p ?? `gap-${i}`">
            <span v-if="p === null" class="px-1 text-sm text-muted-foreground">…</span>
            <span v-else-if="p === pagination.current_page" :class="`${base} bg-primary font-medium text-primary-foreground`" aria-current="page">
                {{ p }}
            </span>
            <Link v-else :href="href(p)" v-bind="linkOptions" :class="idle">{{ p }}</Link>
        </template>

        <Link
            :href="href(pagination.current_page + 1)"
            v-bind="linkOptions"
            :class="pagination.current_page < pagination.last_page ? idle : disabled"
            aria-label="Next page"
            rel="next"
        >
            <ChevronRight class="size-4" />
        </Link>
    </nav>
</template>
