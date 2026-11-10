import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import { QUERY_BLOCK_MAX_ITEM_COUNT, QUERY_BLOCK_MIN_ITEM_COUNT } from './blockHelpers';

const DATE_FILTER_OPTIONS = [
    { label: 'Upcoming', value: 'upcoming' },
    { label: 'Past', value: 'past' },
    { label: 'All time', value: 'alltime' },
];

const ORDER_OPTIONS = [
    { label: 'Descending', value: 'desc' },
    { label: 'Ascending', value: 'asc' },
];

const VARIANT_OPTIONS = [
    { label: 'Grid', value: 'grid' },
    { label: 'Slider', value: 'slider' },
    { label: 'Paged Grid', value: 'paged-grid' },
];

const SLIDE_BREAKPOINTS = [
    { key: 'slidesPerPage', label: 'Base (under 450px)', defaultValue: 1 },
    { key: 'slidesPerPageXs', label: 'XS (450px and up)', defaultValue: 2 },
    { key: 'slidesPerPageSm', label: 'SM (720px and up)', defaultValue: 3 },
    { key: 'slidesPerPageMd', label: 'MD (1024px and up)', defaultValue: 4 },
    { key: 'slidesPerPageLg', label: 'LG (1250px and up)', defaultValue: 3.5 },
];

const SLIDE_OPTIONS = [
    { label: '1.333', value: '1.333' },
    { label: '2', value: '2' },
    { label: '2.333', value: '2.333' },
    { label: '3', value: '3' },
    { label: '3.333', value: '3.333' },
    { label: '4', value: '4' },
    { label: '4.333', value: '4.333' },
    { label: '5', value: '5' },
    { label: '6', value: '6' },
];

function normalizeSlidesPerPage(value, fallback) {
    const parsed = Number.parseFloat(value);
    const count = Number.isFinite(parsed) ? parsed : fallback;
    return Math.max(1, Math.min(6, count));
}

function getSlideOptions() {
    return SLIDE_OPTIONS;
}

function useTaxonomyOptions( taxonomy ) {
    const terms = useSelect(
        ( select ) => select( 'core' ).getEntityRecords( 'taxonomy', taxonomy, {
            per_page: -1,
            hide_empty: false,
            orderby: 'name',
            order: 'asc',
        } ),
        [ taxonomy ]
    );

    return useMemo( () => [
        { label: 'All', value: '' },
        ...( terms || [] ).map( ( term ) => ( {
            label: term?.name || '',
            value: term?.slug || '',
        } ) ),
    ], [ terms ] );
}

    registerBlockType( 'abcnorio/event-listing', {
    edit( { attributes, setAttributes } ) {
        const blockProps = useBlockProps();
        const eventTypeOptions = useTaxonomyOptions( 'event_type' );
        const collectiveOptions = useTaxonomyOptions( 'collective_association' );
        const variantValue = String( attributes.variant || 'grid' ).toLowerCase();
        const variant = variantValue === 'slider' || variantValue === 'paged-grid' ? variantValue : 'grid';
        const isPaged = variant === 'paged-grid';
        const showAllLink = Boolean( attributes.showAllLink ?? true );
        const effectiveShowAllLink = isPaged ? false : showAllLink;
        const showAllLinkLabel = String( attributes.showAllLinkLabel || 'View all' );
        const showAllLinkHref = String( attributes.showAllLinkHref || '/events/' );
        const itemCount = Math.max(
            QUERY_BLOCK_MIN_ITEM_COUNT,
            Math.min( QUERY_BLOCK_MAX_ITEM_COUNT, Number.parseInt( attributes.itemCount, 10 ) || 6 )
        );
        const slidesPerPage = Object.fromEntries(
            SLIDE_BREAKPOINTS.map(({ key, defaultValue }) => [
                key,
                normalizeSlidesPerPage(attributes[key], defaultValue),
            ])
        );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Event Listing Options" initialOpen={ true }>
                        <SelectControl
                            label="Variant"
                            value={ variant }
                            options={ VARIANT_OPTIONS }
                            onChange={ ( value ) => setAttributes( { variant: value } ) }
                        />
                        {variant === 'slider' && SLIDE_BREAKPOINTS.map(({ key, label, defaultValue }) => (
                            <SelectControl
                                key={key}
                                label={`Items per page — ${label}`}
                                value={String(slidesPerPage[key])}
                                options={getSlideOptions()}
                                onChange={(value) => setAttributes({
                                    [key]: normalizeSlidesPerPage(value, slidesPerPage[key]),
                                })}
                            />
                        ))}
                        <TextControl
                            label="Title"
                            value={ attributes.title || '' }
                            onChange={ ( value ) =>
                                setAttributes( { title: value } )
                            }
                        />
                        <SelectControl
                            label="Date Range"
                            value={ attributes.dateFilter }
                            options={ DATE_FILTER_OPTIONS }
                            onChange={ ( value ) =>
                                setAttributes( { dateFilter: value } )
                            }
                        />
                        <SelectControl
                            label="Collective Association"
                            value={ attributes.collectiveAssociation }
                            options={ collectiveOptions }
                            onChange={ ( value ) =>
                                setAttributes( { collectiveAssociation: value } )
                            }
                        />
                        <SelectControl
                            label="Event Type"
                            value={ attributes.eventType }
                            options={ eventTypeOptions }
                            onChange={ ( value ) =>
                                setAttributes( { eventType: value } )
                            }
                        />
                        <SelectControl
                            label="Sort Order"
                            value={ attributes.order }
                            options={ ORDER_OPTIONS }
                            onChange={ ( value ) =>
                                setAttributes( { order: value } )
                            }
                        />
                        <CheckboxControl
                            label="Show View All Button"
                            checked={ effectiveShowAllLink }
                            disabled={ isPaged }
                            help={ isPaged ? 'Paged Grid does not render a View All button.' : undefined }
                            onChange={ ( value ) =>
                                setAttributes( { showAllLink: Boolean( value ) } )
                            }
                        />
                        {effectiveShowAllLink && (
                            <>
                                <TextControl
                                    label="View All Button Text"
                                    value={ showAllLinkLabel }
                                    onChange={ ( value ) =>
                                        setAttributes( { showAllLinkLabel: value } )
                                    }
                                />
                                <TextControl
                                    label="View All Button URL"
                                    value={ showAllLinkHref }
                                    onChange={ ( value ) =>
                                        setAttributes( { showAllLinkHref: value } )
                                    }
                                />
                            </>
                        )}
                        <RangeControl
                            label={ isPaged ? 'Items Per Page' : 'Item Count' }
                            value={ itemCount }
                            min={ QUERY_BLOCK_MIN_ITEM_COUNT }
                            max={ QUERY_BLOCK_MAX_ITEM_COUNT }
                            onChange={ ( value ) =>
                                setAttributes( { itemCount: value ?? itemCount } )
                            }
                        />
                    </PanelBody>
                </InspectorControls>
                <ServerSideRender
                    block="abcnorio/event-listing"
                    attributes={ attributes }
                />
            </div>
        );
    },
    save() {
        return null;
    },
} );