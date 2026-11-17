<?php

namespace abcnorio\CustomFunc\Blocks;

final class ListingLayout
{
    public static function apply(\DOMElement $listing, array $attributes): void
    {
        $type = \WP_Block_Type_Registry::get_instance()->get_registered('abcnorio/' . $listing->tagName);
        if (!$type) {
            throw new \RuntimeException('Components System Error: Listing block schema is not registered.');
        }
        foreach (['', 'Xs', 'Sm', 'Md', 'Lg'] as $suffix) {
            $key = 'slidesPerPage' . $suffix;
            $value = $attributes[$key] ?? null;
            $count = is_numeric($value) && is_finite((float) $value) ? (float) $value : $type->attributes[$key]['default'];
            $listing->setAttribute(
                'data-slides-per-page' . ($suffix === '' ? '' : '-' . strtolower($suffix)),
                (string) min(6, max(1, $count))
            );
        }

        $variant = $attributes['variant'] ?? 'grid';
        $columns = $attributes['gridColumns'] ?? '';
        if (!in_array($columns, ['grid-3', 'grid-4'], true)) {
            $columns = in_array($variant, ['grid-3', 'grid-4'], true) ? $variant : 'grid-4';
        }
        HtmlFragmentSupport::removeClass($listing, 'grid-3');
        HtmlFragmentSupport::removeClass($listing, 'grid-4');
        if ($variant !== 'slider') {
            HtmlFragmentSupport::addClass($listing, $columns);
        }
    }
}
