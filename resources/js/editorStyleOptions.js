import { addFilter } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';
import { unregisterBlockStyle } from '@wordpress/blocks';

addFilter( 'blocks.registerBlockType', 'abcnorio/disable-shadow-control', ( settings, name ) => ( {
    ...settings,
    supports: { ...settings.supports, shadow: false },
    ...( name === 'core/image' && Array.isArray( settings.styles ) ? {
        styles: settings.styles.filter( ( style ) => style.name !== 'rounded' ),
    } : {} ),
} ) );

domReady( () => {
    unregisterBlockStyle( 'core/image', 'rounded' );
} );
