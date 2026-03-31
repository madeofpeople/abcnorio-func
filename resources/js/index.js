import { registerPlugin } from '@wordpress/plugins';
import { useEffect } from '@wordpress/element';

const LINK_SELECTORS = [
    '.editor-post-preview',
    '.editor-post-preview__button-external',
    '.editor-post-publish-panel__postpublish-buttons a',
    '.components-snackbar__content a',
    'a.editor-post-publish-button__view-post',
].join( ',' );

function enforceNewTabLinks() {
    document.querySelectorAll( LINK_SELECTORS ).forEach( ( link ) => {
        if ( ! ( link instanceof HTMLAnchorElement ) ) return;
        link.setAttribute( 'target', '_blank' );
        link.setAttribute( 'rel', 'noopener noreferrer' );
    } );
}

function OpenLinksInNewTab() {
    useEffect( () => {
        enforceNewTabLinks();

        const observer = new MutationObserver( () => {
            enforceNewTabLinks();
        } );

        observer.observe( document.body, { childList: true, subtree: true } );

        return () => observer.disconnect();
    }, [] );

    return null;
}
registerPlugin( 'abcnorio-open-links-new-tab', { render: OpenLinksInNewTab } );
