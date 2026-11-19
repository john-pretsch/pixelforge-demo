import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: { success: string | null; error: string | null };
    credits: Credits | null;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    is_admin: boolean;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface TokenUsage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens: number;
}

export interface Generation {
    id: number;
    status: GenerationStatus;
    status_label: string;
    is_terminal: boolean;
    /** Our label only; null for generations created before titles existed. */
    title: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimate: { min: string; max: string; display_min: string; display_max: string };
    price: string | null;
    price_display: string | null;
    usage: TokenUsage | null;
    notice: string | null;
    error: { code: string | null; message: string | null } | null;
    /** Failed because Pixelforge stayed busy: can be resubmitted as a new generation. */
    can_retry: boolean;
    image_url: string | null;
    image_expired: boolean;
    image_url_expires_at: string | null;
    created_at: string;
    completed_at: string | null;
}

export interface GenerationOptions {
    sizes: string[];
    qualities: string[];
}

export interface Estimate {
    price_min: string;
    price_max: string;
    display: { min: string; max: string };
    tokens: { min: TokenUsage; max: TokenUsage };
}

export interface Credits {
    /** Exact decimal string (9dp); holds for in-flight generations already subtracted. */
    balance: string;
    display: string;
    packs: string[];
}

export interface Amount {
    value: string;
    display: string;
}

export interface ReportFigures {
    generations: number;
    charged: Amount;
    provider_cost: Amount;
    profit: Amount;
    margin_percent: string | null;
}

export interface ReportReconciliation {
    generations: number;
    cost: Amount;
    difference: Amount;
    matches: boolean;
}

export interface ReportUserRow extends ReportFigures {
    user_id: number;
    name: string;
    email: string;
}

export interface ReportDay extends ReportFigures {
    date: string;
    pixelforge: ReportReconciliation | null;
    users: ReportUserRow[];
}

export interface ProfitReport {
    from: string;
    to: string;
    totals: ReportFigures & { pixelforge: ReportReconciliation | null };
    days: ReportDay[];
    users: ReportUserRow[];
    usage_available: boolean;
}

/** Form contents loaded from a history item (Edit / Retry with this image). Nothing is submitted until Generate. */
export interface GenerationDraft {
    title: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    /** Shown above the form, e.g. the "charged as a new image" note. */
    note: { tone: 'info' | 'charge'; text: string } | null;
}
