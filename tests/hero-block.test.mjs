import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { transformSync } from '@babel/core';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

let registration;
const context = vm.createContext({ React });
const InnerBlocks = () => null;
InnerBlocks.Content = () => 'saved inner blocks';
const ComponentShell = () => null;
const controls = Object.fromEntries([
    'BlockControls', 'InspectorControls', 'MediaPlaceholder', 'MediaUpload', 'MediaUploadCheck',
    'PanelBody', 'SelectControl', 'ToolbarButton', 'ToolbarGroup',
].map((name) => [name, function Control() {}]));
const exports = {
    ...controls,
    InnerBlocks,
    useBlockProps: (props) => ({ ...props, ref: 'block-ref', 'data-block': 'hero-id' }),
    registerBlockType: (name, settings) => { registration = settings; },
    default: ComponentShell,
};
const module = new vm.SourceTextModule(transformSync(
    readFileSync(new URL('../resources/js/heroBlock.js', import.meta.url), 'utf8'),
    { babelrc: false, configFile: false, presets: ['@babel/preset-react'] },
).code, { context });
await module.link(() => new vm.SyntheticModule(Object.keys(exports), function () {
    for (const [name, value] of Object.entries(exports)) this.setExport(name, value);
}, { context }));
await module.evaluate();

function find(element, type) {
    if (!React.isValidElement(element)) return undefined;
    if (element.type === type) return element;
    return React.Children.toArray(element.props.children).map((child) => find(child, type)).find(Boolean);
}

test('hero always uses the shared shell and saves only child blocks without presentation wrappers', () => {
    const attributes = { url: 'https://example.test/hero.jpg', id: 8, variant: 'thin' };
    const args = { attributes, setAttributes() {} };
    const saved = renderToStaticMarkup(React.createElement(registration.save));
    assert.equal(saved, 'saved inner blocks');

    const editor = registration.edit(args);
    const shell = find(editor, ComponentShell);
    assert.equal(shell.props.component, 'hero');
    assert.equal(shell.props.blockProps.className, 'abcnorio-hero thin');
    assert.equal(shell.props.blockProps.ref, 'block-ref');
    assert.equal(shell.props.blockProps['data-block'], 'hero-id');
    assert.equal(shell.props.bindings.image.props.style['--cover-bg-lg'], 'url("https://example.test/hero.jpg")');
    assert.equal(shell.props.slots.image, null);
    assert.equal(shell.props.slots.content.type, InnerBlocks);
    assert.ok(find(editor, controls.MediaUpload));
    assert.ok(find(editor, controls.SelectControl));
    assert.equal(renderToStaticMarkup(React.createElement(registration.save)), saved);
});

test('empty image keeps the actual media placeholder and its attribute callback', () => {
    let updated;
    const shell = find(registration.edit({
        attributes: { variant: 'unknown' },
        setAttributes(value) { updated = value; },
    }), ComponentShell);
    assert.equal(shell.props.blockProps.className, 'abcnorio-hero');
    assert.equal(shell.props.bindings.image.props.style['--cover-bg-xs'], 'none');
    assert.equal(shell.props.slots.image.type, controls.MediaPlaceholder);
    shell.props.slots.image.props.onSelect({ id: 12, url: 'image.jpg', alt: 'Description' });
    assert.equal(JSON.stringify(updated), JSON.stringify({ id: 12, url: 'image.jpg', alt: 'Description' }));
});
