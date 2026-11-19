<script setup lang="ts">
import CreditBalance from '@/components/credits/CreditBalance.vue';
import GenerationForm from '@/components/generations/GenerationForm.vue';
import GenerationRow from '@/components/generations/GenerationRow.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Generation, GenerationDraft, GenerationOptions, SharedData } from '@/types';
import { Head, usePage, usePoll } from '@inertiajs/vue3';
import { ImageIcon } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    generations: Generation[];
    options: GenerationOptions | null;
    markupPercent: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generations', href: '/generations' }];

const page = usePage<SharedData>();
const flash = computed(() => page.props.flash);

// Live status: refresh only the history (and the balance, as holds settle) every 3s while anything is in flight.
const inFlight = computed(() => props.generations.some((g) => !g.is_terminal));
const { start, stop } = usePoll(3000, { only: ['generations', 'credits'] }, { autoStart: false });

watch(inFlight, (active) => (active ? start() : stop()), { immediate: true });

// History actions load the form; the user reviews the estimate and presses Generate.
const formSection = ref<HTMLElement | null>(null);
const formRef = ref<{ load: (draft: GenerationDraft) => void } | null>(null);
const loadDraft = (draft: GenerationDraft) => {
    formRef.value?.load(draft);
    formSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

const onEdit = (g: Generation) =>
    loadDraft({
        prompt: g.prompt,
        size: g.size,
        quality: g.quality,
        reference_images: g.reference_images,
        note: { tone: 'info', text: 'Loaded the prompt from a failed generation. Edit it if you like, then press Generate.' },
    });

const onReuse = (g: Generation) =>
    loadDraft({
        prompt: g.prompt,
        size: g.size,
        quality: g.quality,
        // The finished image goes first; Pixelforge accepts at most 4 reference images.
        reference_images: [g.image_url!, ...g.reference_images].slice(0, 4),
        note: {
            tone: 'charge',
            text: 'This makes a brand-new image, using the original as a reference image, so it is charged again as a new generation. The reference image adds 765 input tokens, and the estimate below includes them. The original image is not changed.',
        },
    });
</script>

<template>
    <Head title="Generations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4">
            <div
                v-if="flash.success"
                class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200"
            >
                {{ flash.success }}
            </div>
            <div
                v-if="flash.error"
                class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200"
            >
                {{ flash.error }}
            </div>

            <section ref="formSection" class="scroll-mt-4 rounded-xl border p-5">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-4">
                    <h1 class="text-lg font-semibold">Create Something Awesome:</h1>
                    <CreditBalance />
                </div>
                <GenerationForm ref="formRef" :options="options" :markup-percent="markupPercent" />
            </section>

            <section class="space-y-3">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-lg font-semibold">History</h2>
                    <span v-if="inFlight" class="text-xs text-muted-foreground">Updating live…</span>
                </div>

                <div
                    v-if="generations.length === 0"
                    class="flex flex-col items-center gap-2 rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
                >
                    <ImageIcon class="size-6" />
                    No generations yet. Your images will appear here.
                </div>

                <GenerationRow v-for="g in generations" :key="g.id" :generation="g" @edit="onEdit" @reuse="onReuse" />
            </section>
        </div>
    </AppLayout>
</template>
