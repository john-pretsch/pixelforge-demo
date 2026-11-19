<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Gauge, Radio, ShieldCheck } from 'lucide-vue-next';

// Backdrop: a grid of "pixels" that glow on staggered, deterministic delays.
// 8 columns on phones, 16 from sm up; cells are square, so 16×12 cells cover any viewport.
const pixels = Array.from({ length: 16 * 12 }, (_, i) => {
    const seed = (i * 9301 + 49297) % 233280;
    const r = seed / 233280;
    return { delay: `${(r * 6).toFixed(2)}s`, duration: `${(3 + r * 4).toFixed(2)}s`, hue: r > 0.66 ? 'pink' : r > 0.33 ? 'violet' : 'cyan' };
});

const features = [
    { icon: Gauge, title: 'Live price estimates', text: 'See the cost range before you hit generate.' },
    { icon: Radio, title: 'Real-time status', text: 'Queued, processing, done: updates as it happens.' },
    { icon: ShieldCheck, title: 'Failures are free', text: 'Charged to the nanodollar, only on success.' },
];
</script>

<template>
    <Head title="Demo" />

    <div class="relative flex min-h-screen flex-col overflow-hidden bg-[#07070c] text-white">
        <!-- Pixel backdrop -->
        <div
            class="pointer-events-none absolute inset-0 grid grid-cols-8 content-start opacity-60 [mask-image:radial-gradient(ellipse_at_center,black_20%,transparent_75%)] sm:grid-cols-[repeat(16,minmax(0,1fr))]"
            aria-hidden="true"
        >
            <span
                v-for="(p, i) in pixels"
                :key="i"
                class="pixel m-[3px] aspect-square rounded-[3px]"
                :class="`pixel-${p.hue}`"
                :style="{ animationDelay: p.delay, animationDuration: p.duration }"
            />
        </div>

        <!-- Nav -->
        <header class="relative z-10 flex items-center justify-between px-4 py-5 sm:px-8">
            <div class="flex items-center gap-2 font-semibold tracking-tight">
                <span class="grid size-7 grid-cols-2 gap-0.5 rounded-md bg-white/5 p-1" aria-hidden="true">
                    <span class="rounded-[2px] bg-cyan-400" />
                    <span class="rounded-[2px] bg-violet-400" />
                    <span class="rounded-[2px] bg-pink-400" />
                    <span class="rounded-[2px] bg-white/80" />
                </span>
                Pixel-Forge Studio
            </div>
            <nav class="flex items-center gap-2 text-sm">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('generations.index')"
                    class="rounded-full bg-white px-4 py-1.5 font-medium text-black transition hover:bg-white/90"
                >
                    Open the studio
                </Link>
                <template v-else>
                    <Link :href="route('login')" class="rounded-full px-4 py-1.5 text-white/80 transition hover:text-white">Log in</Link>
                    <Link :href="route('register')" class="rounded-full bg-white px-4 py-1.5 font-medium text-black transition hover:bg-white/90">
                        Sign up
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <main class="relative z-10 flex flex-1 flex-col items-center justify-center px-4 pb-16 text-center">
            <span
                class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-white/70 backdrop-blur"
            >
                <span class="size-1.5 animate-pulse rounded-full bg-emerald-400" />
                Demo
            </span>

            <h1 class="wordmark pb-2 text-6xl font-semibold leading-[0.95] tracking-tighter sm:text-8xl md:text-9xl">
                Pixel-Forge
                <span class="block text-4xl tracking-tight sm:text-6xl md:text-7xl">Studio</span>
            </h1>

            <p class="mt-6 max-w-xl text-lg text-white/70 sm:text-xl">
                Type a prompt. Get pixels. Pay exactly what it cost, and nothing when it fails.
            </p>

            <div class="mt-10 flex flex-wrap items-center justify-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('generations.index')"
                    class="cta rounded-full px-7 py-3 text-base font-semibold text-black transition hover:scale-[1.03]"
                >
                    Start forging →
                </Link>
                <template v-else>
                    <Link
                        :href="route('register')"
                        class="cta rounded-full px-7 py-3 text-base font-semibold text-black transition hover:scale-[1.03]"
                    >
                        Start forging →
                    </Link>
                    <Link
                        :href="route('login')"
                        class="rounded-full border border-white/20 px-7 py-3 text-base font-medium text-white/90 backdrop-blur transition hover:border-white/40 hover:bg-white/5"
                    >
                        I have an account
                    </Link>
                </template>
            </div>

            <ul class="mt-16 grid w-full max-w-4xl gap-3 text-left sm:grid-cols-3">
                <li v-for="f in features" :key="f.title" class="rounded-2xl border border-white/10 bg-white/[0.03] p-5 backdrop-blur">
                    <component :is="f.icon" class="mb-3 size-5 text-cyan-300" />
                    <div class="font-medium">{{ f.title }}</div>
                    <div class="mt-1 text-sm text-white/60">{{ f.text }}</div>
                </li>
            </ul>
        </main>

        <footer class="relative z-10 px-4 pb-6 text-center text-xs text-white/40">
            A demo app. Images come from the simulated Pixelforge API, and credits are play money.
        </footer>
    </div>
</template>

<style scoped>
.wordmark {
    background: linear-gradient(90deg, #67e8f9, #a78bfa, #f472b6, #67e8f9);
    background-size: 300% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: shimmer 8s linear infinite;
}

.cta {
    background: linear-gradient(90deg, #67e8f9, #a78bfa, #f472b6);
    box-shadow: 0 0 40px -8px rgba(167, 139, 250, 0.7);
}

.pixel {
    animation-name: glow;
    animation-iteration-count: infinite;
    animation-timing-function: ease-in-out;
    background: rgba(255, 255, 255, 0.03);
}

.pixel-cyan {
    --c: rgba(103, 232, 249, 0.55);
}
.pixel-violet {
    --c: rgba(167, 139, 250, 0.55);
}
.pixel-pink {
    --c: rgba(244, 114, 182, 0.55);
}

@keyframes glow {
    0%,
    70%,
    100% {
        background: rgba(255, 255, 255, 0.03);
    }
    80% {
        background: var(--c);
        box-shadow: 0 0 12px var(--c);
    }
}

@keyframes shimmer {
    to {
        background-position: 300% 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .pixel,
    .wordmark {
        animation: none;
    }
}
</style>
