<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { isGreaterThan } from '@/lib/money';
import type { Estimate, GenerationDraft, GenerationOptions, SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';
import { ImageOff, Loader2, Plus, X } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    options: GenerationOptions | null;
    markupPercent: string;
}>();

const MAX_TITLE = 25;

const MAX_PROMPT = 4000;
const MAX_REFS = 4;

const form = useForm({
    title: '',
    prompt: '',
    size: props.options?.sizes[0] ?? '1024x1024',
    quality: props.options?.qualities.includes('medium') ? 'medium' : (props.options?.qualities[0] ?? 'medium'),
    reference_images: [] as string[],
});

// Preview of the server's default (Generation::titleFromPrompt): first five words, cut to 25 with "…".
const defaultTitle = computed(() => {
    const title = form.prompt.trim().split(/\s+/).filter(Boolean).slice(0, 5).join(' ');
    return [...title].length > MAX_TITLE
        ? [...title]
              .slice(0, MAX_TITLE - 1)
              .join('')
              .trimEnd() + '…'
        : title;
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

// Thumbnails for the reference image URLs, numbered to match their input. A URL that fails to load
// (typo, expired link, not an image) shows a "No preview" tile; that's a hint only, the server still validates.
const previews = computed(() =>
    form.reference_images
        .map((url, index) => ({ url: url.trim(), index }))
        .filter((p) => {
            try {
                return ['http:', 'https:'].includes(new URL(p.url).protocol);
            } catch {
                return false;
            }
        }),
);
const brokenPreviews = ref(new Set<string>());
const markBroken = (url: string) => (brokenPreviews.value = new Set(brokenPreviews.value).add(url));

const addRef = () => form.reference_images.length < MAX_REFS && form.reference_images.push('');
const removeRef = (i: number) => form.reference_images.splice(i, 1);

const refErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('reference_images'))
        .map(([, msg]) => msg),
);

// Advisory only: the server re-checks the balance under a lock and holds the upper bound.
const page = usePage<SharedData>();
const credits = computed(() => page.props.credits);
const cannotAfford = computed(() => !!estimate.value && !!credits.value && isGreaterThan(estimate.value.price_max, credits.value.balance));

// The textarea's maxlength silently truncates a paste that doesn't fit: tell the user.
const truncatedBy = ref(0);
const onPromptPaste = (e: ClipboardEvent) => {
    const el = e.target as HTMLTextAreaElement;
    const pasted = e.clipboardData?.getData('text') ?? '';
    const room = MAX_PROMPT - (el.value.length - (el.selectionEnd - el.selectionStart));
    truncatedBy.value = Math.max(0, pasted.length - room);
};
// Any later edit (other than the paste itself) dismisses the notice.
const onPromptInput = (e: Event) => {
    if ((e as InputEvent).inputType !== 'insertFromPaste') truncatedBy.value = 0;
};

// Loaded from the history ("Edit" on a failed item, "Retry with this image" on a succeeded one).
// Nothing is submitted until the user presses Generate, so they always see the estimate first.

const draftNote = ref<GenerationDraft['note']>(null);
const promptEl = ref<HTMLTextAreaElement | null>(null);

const load = (draft: GenerationDraft) => {
    form.clearErrors();
    form.title = draft.title ?? '';
    form.prompt = draft.prompt;
    if (props.options?.sizes.includes(draft.size)) form.size = draft.size;
    if (props.options?.qualities.includes(draft.quality)) form.quality = draft.quality;
    form.reference_images = draft.reference_images.slice(0, MAX_REFS);
    draftNote.value = draft.note;
    truncatedBy.value = 0;
    promptEl.value?.focus();
};

defineExpose({ load });

const submit = () => {
    form.transform((data) => ({ ...data, reference_images: filledRefs.value })).post(route('generations.store'), {
        preserveScroll: true,
        onSuccess: () => {
            truncatedBy.value = 0;
            draftNote.value = null;
            form.reset('title', 'prompt', 'reference_images');
        },
    });
};

const selectClass =
    'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm';
</script>

<template>
    <form class="space-y-5" @submit.prevent="submit">
        <div
            v-if="draftNote"
            class="flex items-start gap-3 rounded-lg border p-3 text-sm"
            :class="
                draftNote.tone === 'charge'
                    ? 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200'
                    : 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-200'
            "
            role="status"
            data-testid="draft-note"
        >
            <p class="flex-1">{{ draftNote.text }}</p>
            <button type="button" class="opacity-70 hover:opacity-100" aria-label="Dismiss" @click="draftNote = null"><X class="size-4" /></button>
        </div>

        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="title">Title</Label>
                <span class="text-xs text-muted-foreground">{{ form.title.length }} / {{ MAX_TITLE }}</span>
            </div>
            <Input
                id="title"
                v-model="form.title"
                :maxlength="MAX_TITLE"
                :placeholder="defaultTitle || 'Optional: defaults to the first five words of the prompt'"
                autocomplete="off"
            />
            <p class="text-xs text-muted-foreground">
                Optional, just for your history. Leave it blank to use the first five words of the prompt. It isn't sent to Pixelforge.
            </p>
            <InputError :message="form.errors.title" />
        </div>

        <div class="grid gap-2">
            <div class="flex items-baseline justify-between">
                <Label for="prompt">Prompt</Label>
                <span class="text-xs text-muted-foreground" :class="{ 'text-red-600': form.prompt.length > MAX_PROMPT }">
                    {{ form.prompt.length.toLocaleString() }} / {{ MAX_PROMPT.toLocaleString() }}
                </span>
            </div>
            <textarea
                id="prompt"
                ref="promptEl"
                v-model="form.prompt"
                rows="4"
                required
                :maxlength="MAX_PROMPT"
                placeholder="Describe, in detail, the image you wish to generate"
                class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 md:text-sm"
                @paste="onPromptPaste"
                @input="onPromptInput"
            />
            <p v-if="truncatedBy > 0" class="text-sm text-amber-700 dark:text-amber-400" role="status" data-testid="prompt-truncated">
                Your paste was cut to fit the {{ MAX_PROMPT.toLocaleString() }}-character limit: the last {{ truncatedBy.toLocaleString() }}
                {{ truncatedBy === 1 ? 'character was' : 'characters were' }} not added.
            </p>
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
            <div v-if="previews.length" class="flex flex-wrap gap-2" data-testid="reference-thumbnails">
                <a
                    v-for="p in previews"
                    :key="`${p.index}-${p.url}`"
                    :href="p.url"
                    target="_blank"
                    rel="noopener"
                    class="relative flex size-20 items-center justify-center overflow-hidden rounded-md border bg-muted"
                    :title="p.url"
                >
                    <img
                        v-if="!brokenPreviews.has(p.url)"
                        :src="p.url"
                        :alt="`Reference image ${p.index + 1}`"
                        class="size-full object-cover"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        @error="markBroken(p.url)"
                    />
                    <span v-else class="flex flex-col items-center gap-1 p-1 text-center text-[10px] leading-tight text-muted-foreground">
                        <ImageOff class="size-4" />
                        No preview
                    </span>
                    <span class="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] font-medium text-white">{{ p.index + 1 }}</span>
                </a>
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
                    <p v-if="cannotAfford && estimate && credits" class="mt-2 text-sm text-red-600" data-testid="insufficient-credits">
                        Not enough credits: this can cost up to {{ estimate.display.max }} and you have {{ credits.display }}. Buy credits to
                        continue.
                    </p>
                    <InputError v-else class="mt-2" :message="form.errors.credits" />
                </div>
                <Button type="submit" size="lg" :disabled="form.processing || !options || !form.prompt.trim() || cannotAfford">
                    <Loader2 v-if="form.processing" class="animate-spin" />
                    Generate
                </Button>
            </div>
        </div>
    </form>
</template>
