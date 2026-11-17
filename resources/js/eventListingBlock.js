import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, RangeControl, SelectControl, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';
import ServerSideRender from '@wordpress/server-side-render';
import { QUERY_BLOCK_MAX_ITEM_COUNT, QUERY_BLOCK_MIN_ITEM_COUNT } from './blockHelpers';
import blockMetadata from '../../src/Blocks/event-listing-query/block.json';
import { listingLayout, ListingLayoutControls } from './listingLayout';

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
        const layout = listingLayout(attributes, blockMetadata.attributes);
        const blockProps = useBlockProps({ style: layout.style });
        const eventTypeOptions = useTaxonomyOptions( 'event_type' );
        const collectiveOptions = useTaxonomyOptions( 'collective_association' );
        const variant = layout.variant;
        const isPaged = variant === 'paged-grid';
        const showAllLink = Boolean( attributes.showAllLink ?? true );
        const effectiveShowAllLink = isPaged ? false : showAllLink;
        const showAllLinkLabel = String( attributes.showAllLinkLabel || 'View all' );
        const showAllLinkHref = String( attributes.showAllLinkHref || '/events/' );
        const itemCount = Math.max(
            QUERY_BLOCK_MIN_ITEM_COUNT,
            Math.min( QUERY_BLOCK_MAX_ITEM_COUNT, Number.parseInt( attributes.itemCount, 10 ) || 6 )
        );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Event Listing Options" initialOpen={ true }>
                        <SelectControl
                            label="Variant"
                            value={ variant }
                            options={ VARIANT_OPTIONS }
                            onChange={ ( value ) => setAttributes( { variant: value, gridColumns: layout.gridColumns } ) }
                        />
                        <ListingLayoutControls layout={layout} setAttributes={setAttributes} />
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