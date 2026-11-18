import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { transformSync } from '@babel/core';

let registration;
let state;
const Shell = () => null;
const Control = () => null;
const module = new vm.SourceTextModule(transformSync(
    readFileSync(new URL('../resources/js/publicFormBlock.js', import.meta.url), 'utf8'),
    { babelrc: false, configFile: false, presets: ['@babel/preset-react'] },
).code, { context: vm.createContext({ React }) });
await module.link((name) => {
    const values = name === './componentShell' ? { default: Shell } : {
        registerBlockType: (_name, settings) => { registration = settings; },
        InspectorControls: Control, PanelBody: Control, SelectControl: Control, Notice: Control, Spinner: Control,
        useBlockProps: () => ({}), useEffect() {}, useState: () => [state.shift(), () => {}],
        default: () => { throw new Error('No network requests in unit test.'); },
    };
    return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [key, value] of Object.entries(values)) this.setExport(key, value);
    }, { context: module.context });
});
await module.evaluate();

function nodes(element) {
    return React.isValidElement(element)
        ? [element, ...React.Children.toArray(element.props.children).flatMap(nodes)]
        : [];
}

test('public form states use one inert shell without changing saved selections', () => {
    const forms = [{ slug: 'contact', title: 'Contact <us>', submit_label: 'Send' }];
    for (const [available, error, loading, slug, message] of [
        [[], '', true, 'contact', 'Loading public forms...'],
        [forms, '', false, '', 'Select a configured ACF public form.'],
        [[], '', false, '', 'No public forms are configured.'],
        [[], 'Unavailable', false, 'contact', 'Unavailable'],
        [forms, '', false, 'deleted', 'The selected form is no longer available. Choose another form in the block settings.'],
        [forms, '', false, 'contact', 'Configured form selected.'],
    ]) {
        state = [available, error, loading];
        const changes = [];
        const editor = registration.edit({ attributes: { formSlug: slug }, setAttributes: (value) => changes.push(value) });
        const elements = nodes(editor);
        const shell = elements.find((node) => node.type === Shell);
        assert.equal(shell.props.component, 'public-form');
        assert.equal(shell.props.bindings.status.children, message);
        assert.equal(shell.props.bindings.slug.children, slug);
        assert.equal(shell.props.bindings.submit.hidden, slug !== 'contact' || available.length === 0);
        assert.equal(elements.filter((node) => typeof node.type === 'string').length, 1, 'Only Gutenberg wrapper remains in JSX');
        assert.equal(changes.length, 0, 'Loading or missing forms must not clear the saved slug');
        if (!loading) {
            const selector = elements.find((node) => node.props.label === 'Configured ACF form');
            assert.ok(selector.props.options.some((option) => option.value === slug));
            selector.props.onChange('contact');
            assert.equal(changes[0].formSlug, 'contact');
        }
    }
    assert.equal(registration.save(), null);
});
