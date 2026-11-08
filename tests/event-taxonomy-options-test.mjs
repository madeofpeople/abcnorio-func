import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

test( 'event taxonomy selector returns stable records and maps options', async () => {
    const source = await readFile( new URL( '../resources/js/eventListingBlock.js', import.meta.url ), 'utf8' );
    const hook = source.slice( source.indexOf( 'function useTaxonomyOptions(' ), source.indexOf( "\n    registerBlockType(" ) );
    const terms = [ { name: 'Music', slug: 'music' } ];
    let records = null;
    let memoTerms;
    let memoValue;
    const context = vm.createContext( {
        useSelect: ( callback ) => {
            const select = () => ( { getEntityRecords: () => records } );
            const first = callback( select );
            assert.equal( callback( select ), first );
            return first;
        },
        useMemo: ( callback, dependencies ) => {
            if ( ! memoValue || memoTerms !== dependencies[ 0 ] ) {
                memoTerms = dependencies[ 0 ];
                memoValue = callback();
            }
            return memoValue;
        },
    } );
    const useOptions = vm.runInContext( `${ hook }\nuseTaxonomyOptions`, context );
    const pending = useOptions( 'event_type' );
    assert.equal( pending.length, 1 );
    assert.equal( pending[ 0 ].label, 'All' );
    assert.equal( useOptions( 'event_type' ), pending );
    records = terms;
    const options = useOptions( 'event_type' );
    assert.equal( options.length, 2 );
    assert.equal( options[ 1 ].label, 'Music' );
    assert.equal( options[ 1 ].value, 'music' );
    assert.equal( useOptions( 'event_type' ), options );
} );
