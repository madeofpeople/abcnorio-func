import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import ServerSideRender from '@wordpress/server-side-render';

const DATE_FILTER_OPTIONS = [
    { label: 'Upcoming', value: 'upcoming' },
    { label: 'Past', value: 'past' },
    { label: 'All time', value: 'alltime' },
];

const ORDER_OPTIONS = [
    { label: 'Descending', value: 'desc' },
    { label: 'Ascending', value: 'asc' },
];

function useTaxonomyOptions( taxonomy ) {
    return useSelect(
        ( select ) => {
            const coreStore = select( 'core' );
            if ( ! coreStore ) return [ { label: 'All', value: '' } ];

            const terms =
                coreStore.getEntityRecords( 'taxonomy', taxonomy, {
                    per_page: -1,
                    hide_empty: false,
                    orderby: 'name',
                    order: 'asc',
                } ) || [];

            return [
                { label: 'All', value: '' },
                ...terms.map( ( term ) => ( {
                    label: term?.name || '',
                    value: term?.slug || '',
                } ) ),
            ];
        },
        [ taxonomy ]
    );
}

    registerBlockType( 'abcnorio/event-listing', {
    edit( { attributes, setAttributes } ) {
        const blockProps = useBlockProps();
        const eventTypeOptions = useTaxonomyOptions( 'event_type' );
        const collectiveOptions = useTaxonomyOptions( 'collective_association' );

        return (
            <div { ...blockProps }>
                <InspectorControls>
                    <PanelBody title="Event Listing Options" initialOpen={ true }>
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
                        <RangeControl
                            label="Item Count"
                            value={ attributes.itemCount }
                            min={ 1 }
                            max={ 50 }
                            onChange={ ( value ) =>
                                setAttributes( { itemCount: value ?? 6 } )
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