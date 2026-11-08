import { addFilter } from '@wordpress/hooks';

addFilter( 'blocks.registerBlockType', 'abcnorio/group-flow-variation', ( settings, name, deprecated ) => {
    if ( name !== 'core/group' || deprecated || ! Array.isArray( settings.variations ) ) {
        return settings;
    }

    return {
        ...settings,
        variations: settings.variations.map( ( variation ) => variation.name === 'group'
            ? { ...variation, attributes: { ...variation.attributes, layout: { type: 'default' } } }
            : variation
        ),
    };
} );

addFilter( 'blocks.switchToBlockType.transformedBlock', 'abcnorio/group-flow-layout', ( block ) => {
    const layout = block.attributes.layout;
    if ( block.name === 'core/group' && layout?.type === 'constrained' && Object.keys( layout ).length === 1 ) {
        return {
            ...block,
            attributes: { ...block.attributes, layout: { type: 'default' } },
        };
    }
    return block;
} );
