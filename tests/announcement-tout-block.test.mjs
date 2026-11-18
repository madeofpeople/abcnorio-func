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
const controls = Object.fromEntries(['InspectorControls', 'PanelBody', 'SelectControl', 'TextControl']
    .map((name) => [name, function Control() {}]));
const exports = {
    ...controls,
    InnerBlocks,
    useBlockProps: (props) => ({ ...props, ref: 'block-ref' }),
    registerBlockType: (name, settings) => { registration = settings; },
    default: ComponentShell,
};
const module = new vm.SourceTextModule(transformSync(
    readFileSync(new URL('../resources/js/announcementToutBlock.js', import.meta.url), 'utf8'),
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

test('announcement shares its shell, retains controls, all heading levels and child-only save', () => {
    for (let level = 1; level <= 6; level++) {
        const editor = registration.edit({
            attributes: { variant: 'secondary', toastHeadingLevel: level, toast: 'Toast <text>', title: 'Title' },
            setAttributes() {},
        });
        const shell = find(editor, ComponentShell);
        assert.equal(shell.props.component, 'announcement-tout');
        assert.equal(shell.props.blockProps.ref, 'block-ref');
        assert.equal(shell.props.blockProps.className, 'announcement-tout announcement-tout--secondary');
        assert.equal(shell.props.bindings.toast.tag, `h${level}`);
        assert.equal(shell.props.bindings.toast.children, 'Toast <text>');
        assert.equal(shell.props.bindings.toast.hidden, false);
        assert.equal(shell.props.bindings.title.children, 'Title');
        assert.equal(shell.props.slots.content.type, InnerBlocks);
        assert.equal(shell.props.slots.content.props.templateLock, false);
        assert.ok(find(editor, controls.TextControl));
        assert.ok(find(editor, controls.SelectControl));
    }
    assert.equal(renderToStaticMarkup(React.createElement(registration.save)), 'saved inner blocks');
});

test('announcement handles empty optional fields and invalid heading levels without fallback markup', () => {
    const shell = find(registration.edit({
        attributes: { toastHeadingLevel: 99 },
        setAttributes() {},
    }), ComponentShell);
    assert.equal(shell.props.blockProps.className, 'announcement-tout');
    assert.equal(shell.props.bindings.toast.hidden, true);
    assert.equal(shell.props.bindings.toast.tag, 'h2');
    assert.equal(shell.props.bindings.title.hidden, true);
    assert.equal(shell.props.slots.content.type, InnerBlocks);
});
