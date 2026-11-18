import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { transformSync } from '@babel/core';
import React from 'react';

let registration;
const ComponentShell = () => null;
const controls = Object.fromEntries([
    'InspectorControls', 'PanelBody', 'TextControl', 'ToggleControl', 'CheckboxControl', 'Spinner', 'Notice',
].map((name) => [name, function Control() {}]));
const listData = {
    merge_fields: [{ tag: 'EMAIL', name: 'Email', required: true }, { tag: 'NAME', name: 'Name' }],
    interest_groups: [{ id: 'group-one', title: 'Interests', type: 'checkboxes' }],
};
const exports = {
    ...controls,
    registerBlockType: (_name, settings) => { registration = settings; },
    useBlockProps: () => ({ className: 'wp-block-abcnorio-newsletter-signup', ref: 'block-ref' }),
    useState: (value) => [value && typeof value === 'object' ? listData : value, () => {}],
    useMemo: (callback) => callback(),
    useEffect() {},
};
const context = vm.createContext({ React });
const module = new vm.SourceTextModule(transformSync(
    readFileSync(new URL('../resources/js/newsletterSignupBlock.js', import.meta.url), 'utf8'),
    { babelrc: false, configFile: false, presets: ['@babel/preset-react'] },
).code, { context });
await module.link((specifier) => {
    const values = specifier === './componentShell'
        ? { default: ComponentShell }
        : specifier === '@wordpress/api-fetch'
            ? { default: () => { throw new Error('Unit test must not make network requests.'); } }
            : exports;
    return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [name, value] of Object.entries(values)) this.setExport(name, value);
    }, { context });
});
await module.evaluate();

function elements(element) {
    if (!React.isValidElement(element)) return [];
    return [element, ...React.Children.toArray(element.props.children).flatMap(elements)];
}

test('newsletter uses only the shared inert placeholder while preserving field/group controls', () => {
    const changes = [];
    const editor = registration.edit({
        attributes: { header: 'News', subHeader: 'Updates', submitText: 'Join', fieldTags: ['EMAIL', 'NAME'], groupIds: ['group-one'] },
        setAttributes: (value) => changes.push(value),
    });
    const nodes = elements(editor);
    const shell = nodes.find((node) => node.type === ComponentShell);
    assert.equal(shell.props.component, 'newsletter-signup');
    assert.equal(shell.props.bindings.header.children, 'News');
    assert.equal(shell.props.bindings['sub-header'].children, 'Updates');
    assert.equal(shell.props.bindings.submit.children, 'Join');
    assert.equal(shell.props.slots, undefined);
    assert.equal(shell.props.repeats, undefined);
    assert.equal(editor.ref, 'block-ref');
    assert.equal(nodes.filter((node) => typeof node.type === 'string').length, 2, 'Only Gutenberg wrapper and inspector help text remain in JSX');
    const choices = nodes.filter((node) => node.type === controls.CheckboxControl);
    assert.equal(choices.length, 3);
    assert.equal(choices[0].props.disabled, true);
    assert.equal(choices[1].props.checked, true);
    choices[1].props.onChange(false);
    assert.deepEqual([...changes[0].fieldTags], ['EMAIL']);
    choices[2].props.onChange(false);
    assert.deepEqual([...changes[1].groupIds], []);
    assert.equal(registration.save(), null);
});

test('newsletter placeholder retains default labels and optional visibility bindings', () => {
    const shell = elements(registration.edit({
        attributes: { showRequiredIndicator: false }, setAttributes() {},
    })).find((node) => node.type === ComponentShell);
    assert.equal(shell.props.bindings.header.children, 'Newsletter Signup');
    assert.equal(shell.props.bindings.submit.children, 'Subscribe');
    assert.equal(shell.props.bindings['sub-header'].hidden, true);
    assert.equal(shell.props.bindings['required-star'].hidden, true);
    assert.equal(shell.props.bindings['required-text'].hidden, true);
});
