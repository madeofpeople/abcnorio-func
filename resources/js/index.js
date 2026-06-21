import { registerPlugin } from '@wordpress/plugins';
import { useEffect } from '@wordpress/element';
import { subscribe, select, dispatch } from '@wordpress/data';
import './contentListingBlock';
import './eventListingBlock';

const LINK_SELECTORS = [
    '.editor-post-preview',
    '.editor-post-preview__button-external',
    '.editor-post-publish-panel__postpublish-buttons a',
    '.components-snackbar__content a',
    'a.editor-post-publish-button__view-post',
].join( ',' );

const SIDEBAR_SCOPE_TAXONOMY_KEYS = [ 'sidebar_scope', 'sidebar-scopes' ];
const SIDEBAR_SCOPE_POST_TYPES = [ 'event', 'collective', 'article', 'page', 'sidebar' ];

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

function SidebarScopeSingleSelectGuard() {
    useEffect( () => {
        let isApplying = false;
        let lastSelectionKey = '';

        const unsubscribe = subscribe( () => {
            if ( isApplying ) {
                return;
            }

            const editor = select( 'core/editor' );
            if ( ! editor ) {
                return;
            }

            const postType = editor.getCurrentPostType?.();
            if ( ! SIDEBAR_SCOPE_POST_TYPES.includes( postType ) ) {
                return;
            }

            const rawSelection = SIDEBAR_SCOPE_TAXONOMY_KEYS
                .map( ( key ) => editor.getEditedPostAttribute?.( key ) )
                .find( ( value ) => Array.isArray( value ) );

            if ( ! Array.isArray( rawSelection ) || rawSelection.length <= 1 ) {
                return;
            }

            const normalizedSelection = [ ...new Set( rawSelection.map( ( value ) => Number( value ) ).filter( ( value ) => Number.isInteger( value ) && value > 0 ) ) ];
            if ( normalizedSelection.length <= 1 ) {
                return;
            }

            const selectionKey = normalizedSelection.join( ',' );
            if ( selectionKey === lastSelectionKey ) {
                return;
            }

            lastSelectionKey = selectionKey;
            const keepTermId = normalizedSelection[ normalizedSelection.length - 1 ];
            const updates = SIDEBAR_SCOPE_TAXONOMY_KEYS.reduce( ( acc, key ) => {
                acc[ key ] = [ keepTermId ];
                return acc;
            }, {} );

            isApplying = true;
            dispatch( 'core/editor' ).editPost( updates );
            dispatch( 'core/notices' ).createNotice(
                'warning',
                'Only one Sidebar Scope can be selected. Kept your latest selection.',
                {
                    id: 'abcnorio-sidebar-scope-single-select',
                    type: 'snackbar',
                    isDismissible: true,
                }
            );

            window.requestAnimationFrame( () => {
                isApplying = false;
            } );
        } );

        return () => {
            unsubscribe();
        };
    }, [] );

    return null;
}

registerPlugin( 'abcnorio-sidebar-scope-single-select', { render: SidebarScopeSingleSelectGuard } );
