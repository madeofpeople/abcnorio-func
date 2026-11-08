import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

test( 'Group defaults and transforms use flow without changing other layouts', async () => {
    const filters = {};
    const hooks = new vm.SyntheticModule( [ 'addFilter' ], function () {
        this.setExport( 'addFilter', ( hook, namespace, callback ) => { filters[ hook ] = callback; } );
    } );
    const source = await readFile( new URL( '../resources/js/groupLayout.js', import.meta.url ), 'utf8' );
    const module = new vm.SourceTextModule( source );
    await module.link( () => hooks );
    await module.evaluate();

    const variation = {
        name: 'group',
        isDefault: true,
        attributes: { layout: { type: 'constrained' } },
    };
    const row = { name: 'group-row', attributes: { layout: { type: 'flex' } } };
    const settings = { variations: [ variation, row ] };
    const register = filters[ 'blocks.registerBlockType' ];
    const updated = register( settings, 'core/group', null );
    assert.equal( updated.variations[ 0 ].attributes.layout.type, 'default' );
    assert.equal( updated.variations[ 0 ].isDefault, true );
    assert.equal( updated.variations[ 1 ], row );
    assert.equal( variation.attributes.layout.type, 'constrained' );
    assert.equal( register( settings, 'core/columns', null ), settings );
    assert.equal( register( settings, 'core/group', {} ), settings );
    const missing = {};
    assert.equal( register( missing, 'core/group', null ), missing );

    const transform = filters[ 'blocks.switchToBlockType.transformedBlock' ];
    const group = { name: 'core/group', attributes: { layout: { type: 'constrained' } } };
    assert.equal( transform( group ).attributes.layout.type, 'default' );
    assert.equal( group.attributes.layout.type, 'constrained' );
    for ( const block of [
        { name: 'core/group', attributes: { layout: { type: 'flex' } } },
        { name: 'core/group', attributes: { layout: { type: 'constrained', contentSize: '30rem' } } },
        { name: 'core/columns', attributes: { layout: { type: 'constrained' } } },
        { name: 'core/group', attributes: {} },
    ] ) {
        assert.equal( transform( block ), block );
    }
} );
