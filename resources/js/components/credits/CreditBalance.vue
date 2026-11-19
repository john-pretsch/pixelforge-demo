<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Coins, Loader2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();
const credits = computed(() => page.props.credits);
const buying = ref(false);

// Fake purchase: no payment. The server only accepts its own fixed packs.
const buy = (pack: string) => {
    router.post(
        route('credits.purchase'),
        { pack },
        {
            preserveScroll: true,
            onStart: () => (buying.value = true),
            onFinish: () => (buying.value = false),
        },
    );
};

const packLabel = (pack: string) => `$${pack.replace(/(\.\d\d)\d*$/, '$1')}`;
</script>

<template>
    <div v-if="credits" class="flex items-center gap-3">
        <div class="text-right">
            <div class="text-xs text-muted-foreground">Credits</div>
            <div class="font-semibold tabular-nums" data-testid="credit-balance" :title="credits.balance">{{ credits.display }}</div>
        </div>
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button variant="outline" size="sm" :disabled="buying">
                    <Loader2 v-if="buying" class="animate-spin" />
                    <Coins v-else />
                    Buy credits
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuLabel class="text-xs font-normal text-muted-foreground">Demo purchase: no payment is taken</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem v-for="pack in credits.packs" :key="pack" @select="buy(pack)">Add {{ packLabel(pack) }}</DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
