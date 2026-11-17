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
