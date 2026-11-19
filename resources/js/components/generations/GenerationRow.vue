<script setup lang="ts">
import StatusBadge from '@/components/generations/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import type { Generation } from '@/types';
import { router } from '@inertiajs/vue3';
import { AlertTriangle, ImageOff, ImagePlus, Loader2, Pencil, RotateCcw } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

// Both only load the form above; nothing is submitted (or charged) until the user presses Generate.
const emit = defineEmits<{ edit: [generation: Generation]; reuse: [generation: Generation] }>();

// Resubmits as a NEW generation (fresh estimate, credit check and hold); this failed row stays as is.
const retrying = ref(false);
const retry = () =>
    router.post(
        route('generations.retry', props.generation.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (retrying.value = true),
            onFinish: () => (retrying.value = false),
        },
    );

const imageFailed = ref(false);
const showImage = computed(() => props.generation.image_url && !props.generation.image_expired && !imageFailed.value);

const estimateText = computed(() => {
    const e = props.generation.estimate;
    return e.display_min === e.display_max ? e.display_max : `${e.display_min} – ${e.display_max}`;
});

const canReuse = computed(() => props.generation.status === 'succeeded' && !!props.generation.image_url && !props.generation.image_expired);

const created = computed(() => new Date(props.generation.created_at).toLocaleString());
</script>

<template>
    <article class="flex gap-4 rounded-xl border p-4">
        <div class="flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted sm:size-36">
            <a v-if="showImage" :href="generation.image_url!" target="_blank" rel="noopener">
                <img
                    :src="generation.image_url!"
                    :alt="generation.prompt"
                    class="size-full object-cover"
                    loading="lazy"
                    @error="imageFailed = true"
                />
            </a>
            <div v-else-if="generation.status === 'succeeded'" class="flex flex-col items-center gap-1 p-2 text-center text-xs text-muted-foreground">
                <ImageOff class="size-5" />
                Image link expired
            </div>
            <AlertTriangle v-else-if="generation.status === 'failed'" class="size-6 text-red-500" />
            <Loader2 v-else class="size-6 animate-spin text-muted-foreground" />
        </div>

        <div class="min-w-0 flex-1 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <StatusBadge :generation="generation" />
                <span class="text-xs text-muted-foreground"
                    >{{ generation.size.replace('x', ' × ') }} · <span class="capitalize">{{ generation.quality }}</span></span
                >
                <span v-if="generation.reference_images.length" class="text-xs text-muted-foreground">
                    · {{ generation.reference_images.length }} reference image{{ generation.reference_images.length > 1 ? 's' : '' }}
                </span>
                <span class="ml-auto text-xs text-muted-foreground">{{ created }}</span>
            </div>

            <p class="line-clamp-3 text-sm">{{ generation.prompt }}</p>

            <p v-if="generation.notice" class="text-xs text-amber-700 dark:text-amber-400">{{ generation.notice }}</p>
            <p v-if="generation.error" class="text-xs text-red-600 dark:text-red-400">{{ generation.error.message }}</p>

            <div v-if="generation.status === 'failed' || generation.status === 'succeeded'" class="flex flex-wrap gap-2">
                <template v-if="generation.status === 'failed'">
                    <Button v-if="generation.can_retry" size="sm" variant="outline" :disabled="retrying" data-testid="retry" @click="retry">
                        <Loader2 v-if="retrying" class="animate-spin" />
                        <RotateCcw v-else />
                        Retry
                    </Button>
                    <Button size="sm" variant="outline" data-testid="edit" @click="emit('edit', generation)">
                        <Pencil />
                        Edit
                    </Button>
                </template>
                <Button
                    v-else
                    size="sm"
                    variant="outline"
                    :disabled="!canReuse"
                    :title="
                        canReuse
                            ? 'Use this image as a reference for a new image'
                            : 'The image link has expired, so it can no longer be used as a reference'
                    "
                    data-testid="reuse"
                    @click="emit('reuse', generation)"
                >
                    <ImagePlus />
                    Retry with this image
                </Button>
            </div>

            <dl class="flex flex-wrap gap-x-6 gap-y-1 text-xs">
                <div>
                    <dt class="inline text-muted-foreground">Estimated:</dt>
                    <dd class="ml-1 inline tabular-nums">{{ estimateText }}</dd>
                </div>
                <div>
                    <dt class="inline text-muted-foreground">Charged:</dt>
                    <dd class="ml-1 inline font-medium tabular-nums">
                        <template v-if="generation.status === 'succeeded'">{{ generation.price_display }}</template>
                        <template v-else-if="generation.status === 'failed'"
                            >$0.00 <span class="font-normal text-muted-foreground">(not charged)</span></template
                        >
                        <span v-else class="font-normal text-muted-foreground">pending</span>
                    </dd>
                </div>
                <div v-if="generation.usage" class="text-muted-foreground">{{ generation.usage.total_tokens.toLocaleString() }} tokens</div>
            </dl>
        </div>
    </article>
</template>
