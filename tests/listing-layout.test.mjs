import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';
import { transformSync } from '@babel/core';

const source = readFileSync(new URL('../resources/js/listingLayout.js', import.meta.url), 'utf8');
const context = vm.createContext({
    React: { createElement: (type, props) => ({ type, props }) },
});
const module = new vm.SourceTextModule(transformSync(source, {
    babelrc: false,
    configFile: false,
    presets: ['@babel/preset-react'],
}).code, { context });
await module.link(() => new vm.SyntheticModule(['SelectControl'], function () {
    this.setExport('SelectControl', function SelectControl() {});
}, { context }));
await module.evaluate();
const { listingLayout, ListingLayoutControls, normalizeSlideCount } = module.namespace;

for (const [component, defaults] of [
    ['event-listing', [1, 2, 3, 4, 3.5]],
    ['content-listing', [1, 2, 3, 3, 4]],
]) {
    const schema = JSON.parse(readFileSync(new URL(`../src/Blocks/${component}-query/block.json`, import.meta.url))).attributes;
    test(`${component} restores saved defaults, legacy grids and preview sizing`, () => {
        const layout = listingLayout({}, schema);
        assert.deepEqual(Array.from(Object.values(layout.slides)), defaults);
        assert.equal(layout.gridColumns, 'grid-4');
        assert.equal(listingLayout({ variant: 'grid-3', gridColumns: '' }, schema).gridColumns, 'grid-3');
        assert.equal(listingLayout({ variant: 'grid-3', gridColumns: 'grid-4' }, schema).gridColumns, 'grid-4');
        const fractional = listingLayout({ variant: 'slider', slidesPerPageXs: 2.333 }, schema);
        assert.equal(fractional.style['--listing-preview-slides-xs'], 2.333);
        assert.equal(fractional.slides.slidesPerPageXs, 2.333);
    });

    test(`${component} exposes all breakpoint controls and preserves fractional selections`, () => {
        const layout = listingLayout({ variant: 'slider', slidesPerPageXs: 2.333 }, schema);
        let changed;
        const controls = ListingLayoutControls({ layout, setAttributes: (value) => { changed = value; } });
        assert.equal(controls.length, 5);
        assert.match(controls[0].props.label, /under 425px/);
        assert.match(controls[1].props.label, /425px and up/);
        assert.equal(controls[1].props.value, '2.333');
        assert.ok(controls[4].props.options.some((option) => option.value === '3.5'));
        controls[1].props.onChange('4.333');
        assert.equal(changed.slidesPerPageXs, 4.333);
        const custom = ListingLayoutControls({
            layout: listingLayout({ variant: 'slider', slidesPerPage: 1.75 }, schema),
            setAttributes() {},
        });
        assert.ok(custom[0].props.options.some((option) => option.value === '1.75'));
        const grid = ListingLayoutControls({ layout: listingLayout({ variant: 'grid-3' }, schema), setAttributes: (value) => { changed = value; } });
        grid.props.onChange('grid-4');
        assert.equal(changed.variant, 'grid');
        assert.equal(changed.gridColumns, 'grid-4');
    });
}

test('slide count guards clamp extremes without rounding valid fractions', () => {
    assert.equal(normalizeSlideCount(2.333, 1), 2.333);
    assert.equal(normalizeSlideCount(0, 3), 1);
    assert.equal(normalizeSlideCount(20, 3), 6);
    for (const value of [undefined, null, '', 'bad', Infinity]) {
        assert.equal(normalizeSlideCount(value, 3.5), 3.5);
    }
});
