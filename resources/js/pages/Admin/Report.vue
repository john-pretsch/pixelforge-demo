<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { Amount, BreadcrumbItem, ProfitReport, ReportReconciliation } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

const props = defineProps<{
    report: ProfitReport;
    markupPercent: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Admin report', href: '/admin/report' }];

const range = reactive({ from: props.report.from, to: props.report.to });
const apply = () => router.get(route('admin.report'), { ...range }, { preserveScroll: true });

const expanded = ref<Set<string>>(new Set());
const toggle = (date: string) => {
    const next = new Set(expanded.value);
    if (next.has(date)) next.delete(date);
    else next.add(date);
    expanded.value = next;
};

const margin = (m: string | null) => (m === null ? '—' : `${m}%`);
const exact = (a: Amount) => `$${a.value}`;
const diffTitle = (p: ReportReconciliation) =>
    p.matches ? `Matches Pixelforge exactly: $${p.cost.value}` : `Ours − Pixelforge = ${p.difference.value} USD; Pixelforge counted ${p.generations}`;
</script>

<template>
    <Head title="Admin report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-lg font-semibold">Profit report</h1>
                    <p class="max-w-2xl text-sm text-muted-foreground">
                        Succeeded generations, bucketed by the UTC day they completed (the same way Pixelforge bills). Charged = what users paid
                        (provider cost + {{ markupPercent }}%). Provider cost is checked against Pixelforge's <code>GET /v1/usage</code> to the
                        nanodollar.
                    </p>
                </div>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="apply">
                    <div class="grid gap-1">
                        <Label for="from" class="text-xs">From (UTC)</Label>
                        <Input id="from" v-model="range.from" type="date" class="w-40" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="to" class="text-xs">To (UTC)</Label>
                        <Input id="to" v-model="range.to" type="date" class="w-40" />
                    </div>
                    <Button type="submit" variant="outline">Apply</Button>
                </form>
            </div>

            <div
                v-if="!report.usage_available"
                class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
            >
                Pixelforge usage is unavailable right now, so provider costs can't be reconciled. Our own figures are shown.
            </div>

            <!-- Summary -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-testid="report-totals">
                <div class="rounded-xl border p-4">
                    <div class="text-xs text-muted-foreground">Charged to users</div>
                    <div class="text-2xl font-semibold tabular-nums" :title="exact(report.totals.charged)">{{ report.totals.charged.display }}</div>
                    <div class="text-xs text-muted-foreground">{{ report.totals.generations }} generations</div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-xs text-muted-foreground">Provider cost</div>
                    <div class="text-2xl font-semibold tabular-nums" :title="exact(report.totals.provider_cost)">
                        {{ report.totals.provider_cost.display }}
                    </div>
                    <div v-if="report.totals.pixelforge" class="flex items-center gap-1 text-xs" :title="diffTitle(report.totals.pixelforge)">
                        <template v-if="report.totals.pixelforge.matches">
                            <CheckCircle2 class="size-3.5 text-green-600" />
                            <span class="text-green-700 dark:text-green-400">Matches Pixelforge</span>
                        </template>
                        <template v-else>
                            <AlertTriangle class="size-3.5 text-amber-600" />
                            <span class="text-amber-700 dark:text-amber-400">Pixelforge: {{ report.totals.pixelforge.cost.display }}</span>
                        </template>
                    </div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-xs text-muted-foreground">Profit</div>
                    <div class="text-2xl font-semibold tabular-nums" :title="exact(report.totals.profit)">{{ report.totals.profit.display }}</div>
                </div>
                <div class="rounded-xl border p-4">
                    <div class="text-xs text-muted-foreground">Margin</div>
                    <div class="text-2xl font-semibold tabular-nums">{{ margin(report.totals.margin_percent) }}</div>
                </div>
            </div>

            <!-- Per day -->
            <section class="space-y-2">
                <h2 class="font-semibold">By day</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="p-3">Day (UTC)</th>
                                <th class="p-3 text-right">Generations</th>
                                <th class="p-3 text-right">Charged</th>
                                <th class="p-3 text-right">Provider cost</th>
                                <th class="p-3 text-right">Pixelforge /v1/usage</th>
                                <th class="p-3 text-right">Profit</th>
                                <th class="p-3 text-right">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="report.days.length === 0">
                                <td colspan="7" class="p-6 text-center text-muted-foreground">No billed generations in this range.</td>
                            </tr>
                            <template v-for="day in report.days" :key="day.date">
                                <tr class="cursor-pointer border-t hover:bg-muted/30" :data-testid="`day-${day.date}`" @click="toggle(day.date)">
                                    <td class="p-3 font-medium">
                                        <span class="inline-flex items-center gap-1">
                                            <component
                                                :is="expanded.has(day.date) ? ChevronDown : ChevronRight"
                                                class="size-4 text-muted-foreground"
                                            />
                                            {{ day.date }}
                                        </span>
                                    </td>
                                    <td class="p-3 text-right tabular-nums">{{ day.generations }}</td>
                                    <td class="p-3 text-right tabular-nums" :title="exact(day.charged)">{{ day.charged.display }}</td>
                                    <td class="p-3 text-right tabular-nums" :title="exact(day.provider_cost)">{{ day.provider_cost.display }}</td>
                                    <td class="p-3 text-right tabular-nums">
                                        <span v-if="day.pixelforge" class="inline-flex items-center gap-1" :title="diffTitle(day.pixelforge)">
                                            {{ day.pixelforge.cost.display }}
                                            <CheckCircle2 v-if="day.pixelforge.matches" class="size-4 text-green-600" aria-label="matches" />
                                            <AlertTriangle v-else class="size-4 text-amber-600" aria-label="mismatch" />
                                        </span>
                                        <span v-else class="text-muted-foreground">—</span>
                                    </td>
                                    <td class="p-3 text-right tabular-nums" :title="exact(day.profit)">{{ day.profit.display }}</td>
                                    <td class="p-3 text-right tabular-nums">{{ margin(day.margin_percent) }}</td>
                                </tr>
                                <template v-if="expanded.has(day.date)">
                                    <tr v-for="u in day.users" :key="`${day.date}-${u.user_id}`" class="border-t bg-muted/20 text-xs">
                                        <td class="p-2 pl-9">
                                            {{ u.name }} <span class="text-muted-foreground">{{ u.email }}</span>
                                        </td>
                                        <td class="p-2 text-right tabular-nums">{{ u.generations }}</td>
                                        <td class="p-2 text-right tabular-nums" :title="exact(u.charged)">{{ u.charged.display }}</td>
                                        <td class="p-2 text-right tabular-nums" :title="exact(u.provider_cost)">{{ u.provider_cost.display }}</td>
                                        <td class="p-2"></td>
                                        <td class="p-2 text-right tabular-nums" :title="exact(u.profit)">{{ u.profit.display }}</td>
                                        <td class="p-2 text-right tabular-nums">{{ margin(u.margin_percent) }}</td>
                                    </tr>
                                    <tr v-if="day.users.length === 0" class="border-t bg-muted/20 text-xs">
                                        <td colspan="7" class="p-2 pl-9 text-amber-700 dark:text-amber-400">
                                            Pixelforge billed generations on this day that we have no succeeded record of.
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Per user -->
            <section class="space-y-2">
                <h2 class="font-semibold">By user</h2>
                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="p-3">User</th>
                                <th class="p-3 text-right">Generations</th>
                                <th class="p-3 text-right">Charged</th>
                                <th class="p-3 text-right">Provider cost</th>
                                <th class="p-3 text-right">Profit</th>
                                <th class="p-3 text-right">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="report.users.length === 0">
                                <td colspan="6" class="p-6 text-center text-muted-foreground">No billed generations in this range.</td>
                            </tr>
                            <tr v-for="u in report.users" :key="u.user_id" class="border-t">
                                <td class="p-3">
                                    <div class="font-medium">{{ u.name }}</div>
                                    <div class="text-xs text-muted-foreground">{{ u.email }}</div>
                                </td>
                                <td class="p-3 text-right tabular-nums">{{ u.generations }}</td>
                                <td class="p-3 text-right tabular-nums" :title="exact(u.charged)">{{ u.charged.display }}</td>
                                <td class="p-3 text-right tabular-nums" :title="exact(u.provider_cost)">{{ u.provider_cost.display }}</td>
                                <td class="p-3 text-right tabular-nums" :title="exact(u.profit)">{{ u.profit.display }}</td>
                                <td class="p-3 text-right tabular-nums">{{ margin(u.margin_percent) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
