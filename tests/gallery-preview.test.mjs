import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../resources/js/galleryPreview.js', import.meta.url), 'utf8');
const { getGallerySlideOffsets, showGalleryPage, createGallerySliderPreview } = await import(
    `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`
);

test('slider offsets clamp variable-width slides and page steps to the viewport', () => {
    assert.deepEqual(getGallerySlideOffsets([0, 300, 620, 950], 600, 1250, 'item'), [0, 300, 620, 650]);
    assert.deepEqual(getGallerySlideOffsets([0, 300, 620, 950], 600, 1250, 'page'), [0, 300, 650]);
    assert.deepEqual(getGallerySlideOffsets([0, 900], 600, 1800, 'page'), [0, 900, 1200]);
    assert.deepEqual(getGallerySlideOffsets([0, 300], 800, 600, 'item'), [0]);
    assert.deepEqual(getGallerySlideOffsets([], 800, 0, 'page'), [0]);
});

function fixture(reducedMotion = false) {
    const timers = new Map();
    let timerId = 0;
    const items = Array.from({ length: 18 }, (_, index) => {
        const classes = new Set();
        return {
            hidden: index >= 8,
            attributes: {},
            classes,
            classList: {
                add: (...names) => names.forEach((name) => classes.add(name)),
                remove: (...names) => names.forEach((name) => classes.delete(name)),
            },
            toggleAttribute(name, hidden) { this.hidden = hidden; },
            setAttribute(name, value) { this.attributes[name] = value; },
        };
    });
    const view = {
        matchMedia: () => ({ matches: reducedMotion }),
        setTimeout(callback) { timers.set(++timerId, callback); return timerId; },
        clearTimeout: (id) => timers.delete(id),
    };
    return {
        items,
        gallery: { dataset: {}, ownerDocument: { defaultView: view }, querySelectorAll: () => items },
        timers,
        tick() {
            const callbacks = [...timers.values()];
            timers.clear();
            callbacks.forEach((callback) => callback());
        },
        visible() { return items.flatMap((item, index) => item.hidden ? [] : [index]); },
    };
}

test('paged grid animates out, swaps only visibility, then clears entering classes', () => {
    const f = fixture();
    const originalItems = [...f.items];
    const cleanup = showGalleryPage(f.gallery, 2, 8, 1, true);
    assert.equal(f.gallery.dataset.slide, 'next');
    assert.equal(f.items[0].classes.has('is-leaving'), true);
    assert.deepEqual(f.visible(), [0, 1, 2, 3, 4, 5, 6, 7]);
    f.tick();
    assert.deepEqual(f.visible(), [8, 9, 10, 11, 12, 13, 14, 15]);
    assert.equal(f.items[8].classes.has('is-entering'), true);
    assert.equal(f.items[0].attributes['aria-hidden'], 'true');
    assert.equal(f.items[8].attributes['aria-hidden'], 'false');
    f.tick();
    assert.equal(f.items.every((item) => item.classes.size === 0), true);
    assert.deepEqual(f.items, originalItems);
    cleanup();
});

test('reduced motion, selected images, last pages and variant changes skip animation', () => {
    for (const [reduced, animate] of [[true, true], [false, false]]) {
        const f = fixture(reduced);
        showGalleryPage(f.gallery, 3, 8, 1, animate);
        assert.deepEqual(f.visible(), [16, 17]);
        assert.equal(f.timers.size, 0);
        showGalleryPage(f.gallery, null, 8, 3, true);
        assert.equal(f.visible().length, 18);
    }
});

test('cancelled page changes cannot hide newly selected images later', () => {
    const f = fixture();
    const cancel = showGalleryPage(f.gallery, 2, 8, 1, true);
    cancel();
    showGalleryPage(f.gallery, 1, 8, 2, false);
    f.tick();
    assert.deepEqual(f.visible(), [0, 1, 2, 3, 4, 5, 6, 7]);
    assert.equal(f.items.every((item) => item.classes.size === 0), true);
    assert.equal(f.gallery.dataset.slide, undefined);
});

test('slider scrolls existing blocks, reveals selections and releases observers/listeners', () => {
    const listeners = new Set();
    let disconnected = false;
    let navigation;
    const viewport = {
        clientWidth: 600, scrollLeft: 0,
        getBoundingClientRect: () => ({ left: -16, right: 584 }),
        addEventListener: (name) => listeners.add(name),
        removeEventListener: (name) => listeners.delete(name),
        scrollTo({ left }) { this.scrollLeft = left; },
    };
    const slides = [0, 300, 620, 950].map((left, index) => ({
        dataset: { block: `image-${index}` },
        getBoundingClientRect: () => ({ left: left - viewport.scrollLeft, right: left + 300 - viewport.scrollLeft }),
    }));
    const track = {
        parentElement: viewport, querySelectorAll: () => slides,
        addEventListener: (name) => listeners.add(name),
        removeEventListener: (name) => listeners.delete(name),
    };
    const gallery = {
        querySelector: () => track,
        ownerDocument: { defaultView: {
            ResizeObserver: class { observe() {} disconnect() { disconnected = true; } },
            cancelAnimationFrame() {},
            matchMedia: () => ({ matches: false }),
        } },
    };
    const preview = createGallerySliderPreview(gallery, 'item', (state) => { navigation = state; });
    assert.deepEqual(navigation, { index: 0, count: 4 });
    preview.goTo(1);
    assert.equal(viewport.scrollLeft, 300);
    preview.goTo(99);
    assert.equal(viewport.scrollLeft, 666);
    preview.reveal('image-0');
    assert.equal(viewport.scrollLeft, 16);
    preview.destroy();
    assert.equal(disconnected, true);
    assert.equal(listeners.size, 0);
    assert.equal(track.querySelectorAll(), slides);
});
