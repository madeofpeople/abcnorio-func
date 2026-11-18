import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

test( 'editor disables shadow support and unregisters only the rounded image style', async () => {
    let filter;
    let ready;
    const removed = [];
    const hooks = new vm.SyntheticModule( [ 'addFilter' ], function () {
        this.setExport( 'addFilter', ( hook, namespace, callback ) => {
            assert.equal( hook, 'blocks.registerBlockType' );
            filter = callback;
        } );
    } );
    const domReady = new vm.SyntheticModule( [ 'default' ], function () {
        this.setExport( 'default', ( callback ) => { ready = callback; } );
    } );
    const blocks = new vm.SyntheticModule( [ 'unregisterBlockStyle' ], function () {
        this.setExport( 'unregisterBlockStyle', ( ...args ) => removed.push( args ) );
    } );
    const source = await readFile( new URL( '../resources/js/editorStyleOptions.js', import.meta.url ), 'utf8' );
    const module = new vm.SourceTextModule( source );
    const imports = { '@wordpress/hooks': hooks, '@wordpress/dom-ready': domReady, '@wordpress/blocks': blocks };
    await module.link( ( name ) => imports[ name ] );
    await module.evaluate();

    const settings = { supports: { shadow: true, color: { text: true } }, styles: [ { name: 'default' } ] };
    const updated = filter( settings );
    assert.equal( updated.supports.shadow, false );
    assert.equal( updated.supports.color, settings.supports.color );
    assert.equal( updated.styles, settings.styles );
    assert.equal( settings.supports.shadow, true );
    assert.equal( filter( {} ).supports.shadow, false );
    const imageSettings = { styles: [ { name: 'default' }, { name: 'rounded' }, { name: 'custom' } ] };
    assert.deepEqual( Array.from( filter( imageSettings, 'core/image' ).styles, ( style ) => style.name ), [ 'default', 'custom' ] );
    assert.equal( imageSettings.styles.length, 3 );
    assert.equal( filter( imageSettings, 'core/button' ).styles, imageSettings.styles );
    assert.equal( removed.length, 0 );
    ready();
    assert.deepEqual( removed, [ [ 'core/image', 'rounded' ] ] );
} );
