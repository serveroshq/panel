/**
 * Shared formatting helpers for durations and sizes.
 *
 * Durations are floored, never rounded up: a label must not claim a unit of
 * time has passed while the clock is still inside the previous one.
 */

export type TimeUnits = {
    minutes: number;
    hours: number;
    days: number;
};

/** Whole minutes, hours and days in a duration given in milliseconds. */
export function timeUnits(ms: number): TimeUnits {
    const minutes = Math.max(0, Math.floor(ms / 60_000));

    return {
        minutes,
        hours: Math.floor(minutes / 60),
        days: Math.floor(minutes / 1_440),
    };
}

/** An m:ss countdown from a remaining duration in milliseconds. */
export function countdown(ms: number): string {
    const seconds = Math.max(0, Math.floor(ms / 1_000));

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/** A fixed-decimal number with trailing zeroes and a bare decimal point removed. */
export function fixed(value: number, digits = 1): string {
    const text = value.toFixed(digits);

    return text.includes('.') ? text.replace(/\.?0+$/, '') : text;
}
