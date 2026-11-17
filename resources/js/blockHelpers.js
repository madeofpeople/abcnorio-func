export const QUERY_BLOCK_MIN_ITEM_COUNT = 1;
export const QUERY_BLOCK_MAX_ITEM_COUNT = 12;

export function parseCommaSeparatedValues(value) {
    return String(value || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
}

export function stripHtmlTags(value) {
    return String(value || '').replace(/<[^>]+>/g, '').trim();
}