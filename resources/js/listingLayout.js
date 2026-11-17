import { SelectControl } from '@wordpress/components';

const BREAKPOINTS = [
    ['slidesPerPage', 'Base (under 425px)', '--listing-preview-slides'],
    ['slidesPerPageXs', 'XS (425px and up)', '--listing-preview-slides-xs'],
    ['slidesPerPageSm', 'SM (720px and up)', '--listing-preview-slides-sm'],
    ['slidesPerPageMd', 'MD (1024px and up)', '--listing-preview-slides-md'],
    ['slidesPerPageLg', 'LG (1250px and up)', '--listing-preview-slides-lg'],
];
const SLIDE_COUNTS = [1, 1.333, 2, 2.333, 3, 3.333, 3.5, 4, 4.333, 5, 6];

export function normalizeSlideCount(value, fallback) {
    const parsed = Number(value);
    return Math.max(1, Math.min(6, value == null || value === '' || !Number.isFinite(parsed) ? fallback : parsed));
}

export function listingLayout(attributes, schema) {
    const variant = attributes.variant === 'slider' ? 'slider'
        : attributes.variant === 'paged-grid' ? 'paged-grid' : 'grid';
    const gridColumns = ['grid-3', 'grid-4'].includes(attributes.gridColumns)
        ? attributes.gridColumns
        : ['grid-3', 'grid-4'].includes(attributes.variant) ? attributes.variant : 'grid-4';
    const slides = Object.fromEntries(BREAKPOINTS.map(([key]) => [
        key, normalizeSlideCount(attributes[key], schema[key].default),
    ]));
    const style = {
        '--listing-preview-columns': gridColumns === 'grid-3' ? 3 : 4,
        ...Object.fromEntries(BREAKPOINTS.map(([key, , property]) => [property, slides[key]])),
    };
    return { variant, gridColumns, slides, style };
}

export function ListingLayoutControls({ layout, setAttributes }) {
    if (layout.variant !== 'slider') {
        return (
            <SelectControl
                label="Grid columns"
                value={layout.gridColumns}
                options={[
                    { label: '3 Col Grid', value: 'grid-3' },
                    { label: '4 Col Grid', value: 'grid-4' },
                ]}
                onChange={(gridColumns) => setAttributes({ variant: layout.variant, gridColumns })}
            />
        );
    }

    return BREAKPOINTS.map(([key, label]) => {
        const value = layout.slides[key];
        const options = [...new Set([...SLIDE_COUNTS, value])]
            .sort((left, right) => left - right)
            .map((count) => ({ label: String(count), value: String(count) }));
        return (
            <SelectControl
                key={key}
                label={`Items per page - ${label}`}
                value={String(value)}
                options={options}
                onChange={(next) => setAttributes({ [key]: normalizeSlideCount(next, value) })}
            />
        );
    });
}
