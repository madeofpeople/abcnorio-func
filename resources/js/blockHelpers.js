export const QUERY_BLOCK_MIN_ITEM_COUNT = 1;
export const QUERY_BLOCK_MAX_ITEM_COUNT = 12;

export function normalizeSlugToken(value) {
    return String(value || '')
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-+/g, '-')
        .replace(/^-+|-+$/g, '');
}

export function normalizeUniqueNumberIds(values) {
    if (!Array.isArray(values)) {
        return [];
    }

    const normalized = [];
    const seen = new Set();

    values.forEach((value) => {
        const id = Number.parseInt(value, 10);
        if (!Number.isFinite(id) || id < 1 || seen.has(id)) {
            return;
        }

        seen.add(id);
        normalized.push(id);
    });

    return normalized;
}

export function parseCommaSeparatedValues(value) {
    return String(value || '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
}

export function stripHtmlTags(value) {
    return String(value || '').replace(/<[^>]+>/g, '').trim();
}