<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Estimate, GenerationOptions } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { Loader2, Plus, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    options: GenerationOptions | null;
    markupPercent: string;
}>();

const MAX_PROMPT = 4000;
const MAX_REFS = 4;

const form = useForm({
    prompt: '',
    size: props.options?.sizes[0] ?? '1024x1024',
    quality: props.options?.qualities.includes('medium') ? 'medium' : (props.options?.qualities[0] ?? 'medium'),
    reference_images: [] as string[],
});

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);
const estimateError = ref<string | null>(null);
let controller: AbortController | null = null;

const filledRefs = computed(() => form.reference_images.filter((u) => u.trim() !== ''));

// Pricing math lives on the server; this only asks for a quote.
const fetchEstimate = useDebounceFn(async () => {
    if (!props.options) return;

    controller?.abort();
    controller = new AbortController();
    estimating.value = true;

    try {
        const { data } = await axios.post<Estimate>(
            route('generations.estimate'),
            { prompt: form.prompt, size: form.size, quality: form.quality, reference_images: filledRefs.value },
            { signal: controller.signal },
        );
        estimate.value = data;
        estimateError.value = null;
    } catch (e) {
        if (axios.isCancel(e)) return;
        estimate.value = null;
        estimateError.value = axios.isAxiosError(e) && e.response?.status === 422 ? null : 'Could not get a price estimate right now.';
    } finally {
        estimating.value = false;
    }
}, 300);

watch(() => [form.prompt, form.size, form.quality, filledRefs.value.length], fetchEstimate, { immediate: true });

const addRef = () => form.reference_images.length < MAX_REFS && form.reference_images.push('');
const removeRef = (i: number) => form.reference_images.splice(i, 1);

const refErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, msg]) => msg),
);

const submit = () => {
    form.transform((data) => ({ ...data, reference_images: filledRefs.value })).post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('prompt', 'reference_images');
        },
    });
};

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm';
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span class="text-xs text-muted-foreground" :class="{ 'text-red-600': form.prompt.length > MAX_PROMPT }">
                    {{ form.prompt.length.toLocaleString() }} / {{ MAX_PROMPT.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                v-model="form.prompt"
                rows="4"
                required
                :maxlength="MAX_PROMPT"
                placeholder="Describe, in detail, the image you wish to generate"
                class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm"
            />
            <InputError :message="form.errors.prompt" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="size">Size</Label>
                <select id="size" v-model="form.size" :class="selectClass" :disabled="!options">
                    <option v-for="s in options?.sizes ?? []" :key="s" :value="s">{{ s.replace('x', ' × ') }}</option>
                </select>
                <InputError :message="form.errors.size" />
            </div>
            <div class="grid gap-2">
                <Label for="quality">Quality</Label>
                <select id="quality" v-model="form.quality" :class="selectClass" :disabled="!options">
                    <option v-for="q in options?.qualities ?? []" :key="q" :value="q" class="capitalize">{{ q }}</option>
                </select>
                <InputError :message="form.errors.quality" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label
                >Reference images <span class="font-normal text-muted-foreground">(optional, up to {{ MAX_REFS }})</span></Label
            >
            <div v-for="(_, i) in form.reference_images" :key="i" class="flex gap-2">
                <Input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/reference.png" />
                <Button type="button" variant="ghost" size="icon" :aria-label="`Remove reference image ${i + 1}`" @click="removeRef(i)">
                    <X />
                </Button>
            </div>
            <div>
                <Button v-if="form.reference_images.length < MAX_REFS" type="button" variant="outline" size="sm" @click="addRef">
                    <Plus /> Add reference image
                </Button>
            </div>
            <InputError v-for="(msg, i) in refErrors" :key="i" :message="msg" />
        </div>

        <div class="rounded-lg border bg-muted/40 p-4" data-testid="estimate">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="text-sm text-muted-foreground">Estimated cost</div>
                    <div class="flex items-center gap-2 text-2xl font-semibold tabular-nums">
                        <template v-if="estimate">
                            {{
                                estimate.display.min === estimate.display.max
                                    ? estimate.display.max
                                    : `${estimate.display.min} – ${estimate.display.max}`
                            }}
                        </template>
                        <span v-else-if="!options || estimateError" class="text-base font-normal text-red-600">
                            {{ estimateError ?? 'Pricing is unavailable right now.' }}
                        </span>
                        <span v-else class="text-muted-foreground">—</span>
                        <Loader2 v-if="estimating" class="size-4 animate-spin text-muted-foreground" />
                    </div>
                    <p class="mt-1 max-w-md text-xs text-muted-foreground">
                        You pay Pixelforge's token cost plus a {{ markupPercent }}% service fee. The final amount depends on tokens used and is always
                        within this range. Failed generations are free.
                    </p>
                </div>
                <Button type="submit" size="lg" :disabled="form.processing || !options || !form.prompt.trim()">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    Generate
                </Button>
            </div>
        </div>
    </form>
</template>
