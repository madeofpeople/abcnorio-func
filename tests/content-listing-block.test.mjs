import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import React from 'react';
import { transformSync } from '@babel/core';

const context = vm.createContext({ React });
const controls = Object.fromEntries([
    'InspectorControls', 'CheckboxControl', 'Notice', 'PanelBody', 'RangeControl',
    'SelectControl', 'Spinner', 'TextControl', 'ListingLayoutControls',
].map((name) => [name, function Control() {}]));
const Preview = () => null;
let registration;
let state;
let effect;
const requests = [];
const queryArgs = [];
const element = {
    useMemo: (callback) => callback(),
    useRef: (value) => ({ current: value }),
    useState: (initial) => [state ?? initial, (next) => { state = next; }],
    useEffect: (callback, [path]) => {
        if (effect?.path === path) return;
        effect?.cleanup?.();
        effect = { path, cleanup: callback() };
    },
};
function synthetic(values) {
    return new vm.SyntheticModule(Object.keys(values), function () {
        for (const [name, value] of Object.entries(values)) this.setExport(name, value);
    }, { context });
}
const manualOrder = new vm.SourceTextModule(
    readFileSync(new URL('../resources/js/query-post-manual-order.js', import.meta.url), 'utf8'), { context },
);
await manualOrder.link(() => synthetic(element));
await manualOrder.evaluate();
const block = new vm.SourceTextModule(transformSync(
    readFileSync(new URL('../resources/js/contentListingBlock.js', import.meta.url), 'utf8'),
    { babelrc: false, configFile: false, presets: ['@babel/preset-react'] },
).code, { context });
await block.link((name) => {
    if (name === './query-post-manual-order') return manualOrder;
    const values = {
        '@wordpress/blocks': { registerBlockType: (_name, settings) => { registration = settings; } },
        '@wordpress/block-editor': { ...controls, useBlockProps: () => ({}) },
        '@wordpress/components': controls,
        '@wordpress/element': element,
        '@wordpress/url': { addQueryArgs: (path, args) => {
            queryArgs.push(args);
            return `${path}?${new URLSearchParams(args)}`;
        } },
        '@wordpress/server-side-render': { default: Preview },
        '@wordpress/api-fetch': { default: ({ path }) => new Promise((resolve, reject) => {
            requests.push({ path, resolve, reject });
        }) },
        './blockHelpers': {
            parseCommaSeparatedValues: (value) => value.split(',').map((entry) => entry.trim()).filter(Boolean),
            stripHtmlTags: (value) => value.replace(/<[^>]+>/g, ''),
        },
        './listingLayout': { ...controls, listingLayout: () => ({ style: {}, variant: 'grid' }) },
        '../../src/Blocks/content-listing-query/block.json': {
            default: JSON.parse(readFileSync(new URL('../src/Blocks/content-listing-query/block.json', import.meta.url))),
        },
    };
    return synthetic(values[name]);
});
await block.evaluate();

function nodes(element) {
    return React.isValidElement(element)
        ? [element, ...React.Children.toArray(element.props.children).flatMap(nodes)] : [];
}
const flush = () => new Promise(setImmediate);
const dragEvent = () => ({ preventDefault() {}, dataTransfer: {} });

test('manual selection follows filters and count, and saves the displayed order for the preview', async () => {
    const attributes = { order: 'manual', listingPostTypes: ['event', 'article'], listingTagFilter: ['music'], listingCount: 24 };
    const render = () => nodes(registration.edit({
        attributes, setAttributes: (update) => Object.assign(attributes, update),
    }));
    let editor = render();
    assert.ok(editor.some((node) => node.type === controls.Spinner));
    assert.deepEqual([...queryArgs.at(-1).post_types], ['event', 'article']);
    assert.deepEqual([...queryArgs.at(-1).tags], ['music']);
    assert.equal(queryArgs.at(-1).count, 24);
    requests.at(-1).resolve([
        { id: 1, post_type: 'event', title: { rendered: 'Future event' }, date: '2020-01-01' },
        { id: 2, post_type: 'article', title: { rendered: 'Older article' }, date: '2000-01-01' },
        { id: 3, post_type: 'article', title: { rendered: 'Another article' }, date: '2001-01-01' },
    ]);
    await flush();
    editor = render();
    const items = editor.filter((node) => node.props.draggable);
    assert.equal(items.length, 3, 'Past publication dates must not remove matching items');
    items[0].props.onDragStart(dragEvent());
    items[2].props.onDrop(dragEvent());
    assert.deepEqual([...attributes.sortOrderPostIds], [2, 3, 1]);
    editor = render();
    const preview = editor.find((node) => node.type === Preview);
    assert.deepEqual([...preview.props.attributes.sortOrderPostIds], [2, 3, 1]);
    assert.deepEqual([...preview.props.attributes.listingTagFilter], ['music']);
    requests.at(-1).resolve([
        { id: 2, post_type: 'article', title: { rendered: 'Older article' } },
        { id: 3, post_type: 'article', title: { rendered: 'Another article' } },
        { id: 1, post_type: 'event', title: { rendered: 'Future event' } },
    ]);
    await flush();
    attributes.listingPostTypes = ['article'];
    attributes.listingTagFilter = ['different-tag'];
    attributes.listingCount = 1;
    editor = render();
    assert.equal(editor.filter((node) => node.props.draggable).length, 0, 'Do not show stale unfiltered items');
    assert.equal(queryArgs.at(-1).count, 6);
    assert.deepEqual([...queryArgs.at(-1).post_types], ['article']);
    assert.deepEqual([...queryArgs.at(-1).tags], ['different-tag']);
    const range = editor.find((node) => node.type === controls.RangeControl);
    assert.equal(range.props.value, 6);
    assert.equal(range.props.max, 24);
    requests.at(-1).reject(new Error('Cannot load filtered selection'));
    await flush();
    editor = render();
    assert.equal(editor.find((node) => node.type === controls.Notice).props.children, 'Cannot load filtered selection');
    assert.ok(!editor.some((node) => node.props.children === 'No matching items found'));
});

test('shared manual ordering preserves unshown saved values and works for collective slugs', () => {
    for (const [values, saved, limit, expected] of [
        [[1, 2, 3], [99, 2, 3], 2, [3, 2, 99]],
        [['one', 'two', 'three'], [], undefined, ['two', 'one', 'three']],
    ]) {
        let updated;
        const order = manualOrder.namespace.useQueryPostManualOrder({
            candidateRecords: values.map((value) => ({ value })),
            orderedValues: saved, valueSelector: (record) => record.value, limit,
            onOrderChange: (value) => { updated = value; },
        });
        order.handleDragStart(dragEvent(), order.draggableRecords[0].value);
        order.handleDrop(dragEvent(), 1);
        assert.deepEqual([...updated], expected);
    }
});
