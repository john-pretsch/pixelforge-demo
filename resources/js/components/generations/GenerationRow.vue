<script setup lang="ts">
import StatusBadge from '@/components/generations/StatusBadge.vue';
import type { Generation } from '@/types';
import { AlertTriangle, ImageOff, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const imageFailed = ref(false);
const showImage = computed(() => props.generation.image_url && !props.generation.image_expired && !imageFailed.value);

const estimateText = computed(() => {
    const e = props.generation.estimate;
    return e.display_min === e.display_max ? e.display_max : `${e.display_min} – ${e.display_max}`;
});

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
