/**
 * Compare exact decimal money strings ("0.021900000") without floats:
 * both are scaled to integer nanodollars (9dp) as BigInt.
 */
export function toNanos(amount: string): bigint {
    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace(/^[-+]/, '').split('.');
    const nanos = BigInt(whole || '0') * 1_000_000_000n + BigInt((fraction + '000000000').slice(0, 9));
    return negative ? -nanos : nanos;
}

export const isGreaterThan = (a: string, b: string): boolean => toNanos(a) > toNanos(b);
