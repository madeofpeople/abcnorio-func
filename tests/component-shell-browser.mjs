import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { transformSync } from '@babel/core';
import { chromium } from 'playwright';

const require = createRequire(import.meta.url);
const root = fileURLToPath(new URL('../', import.meta.url));
if (!process.env.ABCNORIO_WP_SCRIPTS) {
    throw new Error('Set ABCNORIO_WP_SCRIPTS to a public WordPress script dependency JSON file.');
}
const dependencies = JSON.parse(readFileSync(process.env.ABCNORIO_WP_SCRIPTS, 'utf8'));
const pointer = `${root}resources/vendor/components/dev-current.json`;
const generation = existsSync(pointer) ? JSON.parse(readFileSync(pointer, 'utf8')) : 'dist';
const dist = process.env.ABCNORIO_COMPONENTS_DIST_DIR || `${root}resources/vendor/components/${generation}`;
const compile = (name) => transformSync(readFileSync(`${root}resources/js/${name}.js`, 'utf8'), {
    babelrc: false,
    configFile: false,
    presets: [require.resolve('@babel/preset-react')],
    plugins: [require.resolve('@babel/plugin-transform-modules-commonjs')],
}).code;
const components = ['hero', 'announcement-tout', 'gallery', 'newsletter-signup', 'public-form', 'press-item'];
const shells = Object.fromEntries(components.map((name) => [name, readFileSync(`${dist}/${name}/editor.html`, 'utf8')]));
const schemas = Object.fromEntries(components.map((name) => [
    `abcnorio/${name === 'public-form' ? 'forms' : name}`, JSON.parse(readFileSync(`${root}src/Blocks/${name}/block.json`, 'utf8')),
]));
const announcementFixtures = {
    default: readFileSync(`${dist}/announcement-tout.html`, 'utf8'),
    secondary: readFileSync(`${dist}/announcement-tout/secondary.html`, 'utf8'),
};
const browser = await chromium.launch({
    ...(process.env.ABCNORIO_TEST_BROWSER ? { executablePath: process.env.ABCNORIO_TEST_BROWSER } : {}),
    headless: true,
});
try {
    const page = await browser.newPage();
    await page.goto(new URL(dependencies[0].src).origin);
    for (const dependency of dependencies) await page.addScriptTag({ url: dependency.src });
    for (const file of ['styles/gallery.css', 'styles/editor/gallery.css']) {
        await page.addStyleTag({ content: readFileSync(`${dist}/${file}`, 'utf8') });
    }
    const contracts = await page.evaluate(({ shells, schemas, announcementFixtures, adapter, hero, announcement, gallery, galleryPreview, imageSizes, newsletter, publicForm, press, dates }) => {
        const packages = {
            '@wordpress/element': wp.element,
            '@wordpress/blocks': wp.blocks,
            '@wordpress/block-editor': wp.blockEditor,
            '@wordpress/components': wp.components,
            '@wordpress/data': wp.data,
            '@wordpress/hooks': wp.hooks,
            '@wordpress/compose': wp.compose,
            '../../src/Blocks/gallery/image-sizes.json': imageSizes,
            '@wordpress/api-fetch': ({ path }) => {
                const state = window.componentShellTest;
                if (path === '/abcnorio/v1/forms') {
                    return new Promise((resolve, reject) => {
                        state.formRequest = { resolve, reject };
                    });
                }
                if (path !== '/abcnorio/v1/mailchimp/list-data') throw new Error('Unexpected preview request.');
                state.newsletterRequests = (state.newsletterRequests || 0) + 1;
                return state.newsletterError
                    ? Promise.reject(new Error('Synthetic newsletter loading error'))
                    : new Promise((resolve) => { state.resolveNewsletter = resolve; });
            },
        };
        const load = (code) => {
            const exports = {};
            new Function('require', 'exports', 'React', code)((name) => {
                if (!packages[name]) throw new Error(`Unknown browser-test dependency: ${name}`);
                return packages[name];
            }, exports, wp.element);
            return exports;
        };
        const shell = load(adapter);
        packages['abcnorio-webcomponents/util/dates'] = load(dates);
        packages['./componentShell'] = shell;
        window.abcnorioEditorShells = shells;
        wp.blockLibrary.registerCoreBlocks();
        const register = wp.blocks.registerBlockType;
        packages['@wordpress/blocks'] = {
            ...wp.blocks,
            registerBlockType: (name, settings) => register(name, { ...schemas[name], ...settings }),
        };
        load(hero);
        load(announcement);
        packages['./galleryPreview'] = load(galleryPreview);
        load(gallery);
        load(newsletter);
        load(publicForm);
        load(press);
        const h = wp.element.createElement;
        const render = (html, props) => shell.compileComponentShell(html)(props);
        const repeater = '<div data-editor-root><template data-editor-repeat="pages"><button data-editor-bind="button"></button></template></div>';
        let rejected = 0;
        const bad = [
            () => shell.compileComponentShell(''),
            () => shell.compileComponentShell('<div></div>'),
            () => shell.compileComponentShell(shells.hero.replace('__EDITOR_SLOT_IMAGE__', '__EDITOR_SLOT_IMAGE__<span>__EDITOR_SLOT_IMAGE__</span>')),
            () => shell.compileComponentShell(shells.hero.replace('__EDITOR_SLOT_IMAGE__', 'Prefix __EDITOR_SLOT_IMAGE__')),
            () => shell.compileComponentShell(shells.hero.replace('__EDITOR_SLOT_IMAGE__', '<iframe></iframe>__EDITOR_SLOT_IMAGE__')),
            () => shell.compileComponentShell(shells.hero.replace('data-editor-root', 'onclick="bad" data-editor-root')),
            () => shell.compileComponentShell(shells.hero.replace('data-editor-root', 'style="color:red" data-editor-root')),
            () => render(shells.hero, { blockProps: {}, slots: { content: null }, bindings: { image: {} } }),
            () => render(shells.hero, { blockProps: {}, slots: { content: null, image: null }, bindings: {} }),
            () => render(shells.hero, { blockProps: {}, slots: { content: null, image: null, unknown: null }, bindings: { image: {} } }),
            () => render(repeater, { blockProps: {} }),
            () => render(repeater, { blockProps: {}, repeats: { pages: null } }),
            () => render(repeater, { blockProps: {}, repeats: { pages: [{ key: 'one' }] } }),
            () => render(repeater, { blockProps: {}, repeats: { pages: [{ bindings: { button: {} } }] } }),
            () => render(repeater, { blockProps: {}, repeats: { pages: [{ key: 1, bindings: { button: {} } }, { key: '1', bindings: { button: {} } }] } }),
            () => render(repeater, { blockProps: {}, repeats: { pages: [{ key: 1, bindings: { button: {}, extra: {} } }] } }),
            () => shell.compileComponentShell(repeater.replace('</template>', '</template><template data-editor-repeat="pages"></template>')),
        ];
        for (const attempt of bad) {
            let error;
            try { attempt(); } catch (caught) { error = caught; }
            if (!error) throw new Error('Malformed component contract was accepted.');
            rejected++;
        }
        for (let level = 1; level <= 6; level++) {
            const tree = render(shells['announcement-tout'], {
                blockProps: {},
                bindings: {
                    toast: { tag: `h${level}`, children: '<literal toast>' },
                    title: { children: 'Title' },
                },
                slots: { content: h('p', null, 'Child') },
            });
            const rendered = wp.element.renderToString(tree);
            const heading = new DOMParser().parseFromString(rendered, 'text/html').querySelector('.announcement-tout__toast');
            if (heading?.localName !== `h${level}` || heading.textContent !== '<literal toast>' || heading.children.length !== 0) {
                throw new Error(`Heading binding/escaping failed for level ${level}.`);
            }
        }
        for (const [variant, toast, title, level] of [
            ['default', 'Announcement toast', 'Announcement Title', 2],
            ['secondary', 'Secondary announcement', 'Secondary Title', 4],
        ]) {
            const tree = render(shells['announcement-tout'], {
                blockProps: { className: variant === 'secondary' ? 'announcement-tout--secondary' : '' },
                bindings: {
                    toast: { tag: `h${level}`, children: toast },
                    title: { children: title },
                },
                slots: { content: null },
            });
            const actual = new DOMParser().parseFromString(wp.element.renderToString(tree), 'text/html').querySelector('.announcement-tout');
            const expected = new DOMParser().parseFromString(announcementFixtures[variant], 'text/html').querySelector('.announcement-tout');
            if (!actual.isEqualNode(expected)) throw new Error(`Announcement ${variant} frontend/editor shell mismatch.`);
        }
        function Registry() {
            window.componentShellTest.registry = wp.data.useRegistry();
            return null;
        }
        function App() {
            const [blocks, setBlocks] = wp.element.useState([
                wp.blocks.createBlock('abcnorio/hero', {}, [wp.blocks.createBlock('core/paragraph', { content: 'Hero child' })]),
                wp.blocks.createBlock('abcnorio/announcement-tout', { toast: 'Toast', title: 'Title' }, [
                    wp.blocks.createBlock('core/paragraph', { content: 'Announcement child' }),
                ]),
            ]);
            return h(wp.components.SlotFillProvider, null,
                h(wp.blockEditor.BlockEditorProvider, {
                    value: blocks, onInput: setBlocks, onChange: setBlocks,
                    settings: { hasFixedToolbar: true },
                }, h(Registry), h(wp.blockEditor.WritingFlow, null,
                    h('div', { className: 'block-editor-iframe__body editor-styles-wrapper' }, h(wp.blockEditor.BlockList)))));
        }
        const host = document.createElement('main');
        document.body.replaceChildren(host);
        window.componentShellTest = { root: wp.element.createRoot(host) };
        componentShellTest.root.render(h(App));
        return { rejected };
    }, {
        shells, schemas, announcementFixtures,
        adapter: compile('componentShell'), hero: compile('heroBlock'), announcement: compile('announcementToutBlock'),
        gallery: compile('galleryBlock'), galleryPreview: compile('galleryPreview'),
        newsletter: compile('newsletterSignupBlock'),
        publicForm: compile('publicFormBlock'),
        press: compile('pressItemBlock'),
        dates: transformSync(readFileSync(require.resolve('abcnorio-webcomponents/util/dates'), 'utf8'), {
            babelrc: false, configFile: false, plugins: [require.resolve('@babel/plugin-transform-modules-commonjs')],
        }).code,
        imageSizes: JSON.parse(readFileSync(`${root}src/Blocks/gallery/image-sizes.json`, 'utf8')),
    });
    assert.equal(contracts.rejected, 17);
    await page.waitForSelector('.announcement-tout .block-editor-block-list__layout');
    const paragraph = page.locator('.announcement-tout [contenteditable="true"]').first();
    await paragraph.fill('Editable announcement survives');
    await paragraph.press('End');
    await paragraph.press('Enter');
    await page.keyboard.type('Second child');
    await page.evaluate(() => {
        const state = componentShellTest;
        const store = state.registry.select('core/block-editor');
        state.before = {
            hero: document.querySelector('.abcnorio-hero'),
            announcement: document.querySelector('.announcement-tout'),
            input: document.querySelector('.announcement-tout [contenteditable="true"]'),
        };
        const [hero, announcement] = store.getBlocks();
        state.registry.dispatch('core/block-editor').updateBlockAttributes(hero.clientId, { variant: 'thin', id: 21, url: 'https://example.test/background.jpg' });
        state.registry.dispatch('core/block-editor').updateBlockAttributes(announcement.clientId, {
            variant: 'secondary', toastHeadingLevel: 6, toast: 'Changed toast', title: '',
        });
    });
    await page.waitForSelector('.announcement-tout h6');
    const result = await page.evaluate(() => {
        const state = componentShellTest;
        const blocks = state.registry.select('core/block-editor').getBlocks();
        const serialized = wp.blocks.serialize(blocks);
        const parsed = wp.blocks.parse(serialized);
        const current = document.querySelector('.announcement-tout');
        const invalid = (blocks) => blocks.some((block) => !block.isValid || invalid(block.innerBlocks));
        return {
            sameHero: state.before.hero === document.querySelector('.abcnorio-hero'),
            sameAnnouncement: state.before.announcement === current,
            sameChild: state.before.input === current.querySelector('[contenteditable="true"]'),
            text: blocks[1].innerBlocks.map((block) => block.attributes.content),
            heading: current.querySelector('h6')?.textContent,
            titleHidden: !current.querySelector('.announcement-tout__title'),
            variant: current.classList.contains('announcement-tout--secondary'),
            imageStyle: document.querySelector('.abcnorio-hero__image-wrapper').style.getPropertyValue('--cover-bg-lg'),
            validReload: !invalid(parsed),
            noSavedShells: !serialized.includes('announcement-tout__content') && !serialized.includes('abcnorio-hero__content'),
            noMarkers: !document.body.textContent.includes('__EDITOR_SLOT_'),
            shadowRoots: [...document.querySelectorAll('*')].filter((node) => node.shadowRoot).length,
        };
    });
    assert.deepEqual(result, {
        sameHero: true, sameAnnouncement: true, sameChild: true,
        text: ['Editable announcement survives', 'Second child'],
        heading: 'Changed toast', titleHidden: true, variant: true,
        imageStyle: 'url("https://example.test/background.jpg")',
        validReload: true, noSavedShells: true, noMarkers: true, shadowRoots: 0,
    });
    await page.evaluate(() => {
        const state = componentShellTest;
        const announcement = state.registry.select('core/block-editor').getBlocks()[1];
        state.registry.dispatch('core/block-editor').updateBlockAttributes(announcement.clientId, {
            toast: '', title: 'Restored title', toastHeadingLevel: 1,
        });
    });
    await page.waitForSelector('.announcement-tout__title');
    assert.equal(await page.locator('.announcement-tout__toast').count(), 0);
    assert.equal(await page.locator('.announcement-tout__title').innerText(), 'Restored title');
    await page.evaluate(() => {
        const dispatch = componentShellTest.registry.dispatch('core/block-editor');
        const url = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="600" height="400" fill="gray"/></svg>');
        const images = Array.from({ length: 10 }, (_, index) => wp.blocks.createBlock('core/image', {
            url, alt: `Image ${index + 1}`, caption: `Caption ${index + 1}`,
        }));
        const gallery = wp.blocks.createBlock('abcnorio/gallery', { variant: 'slider' }, images);
        componentShellTest.galleryId = gallery.clientId;
        dispatch.insertBlocks(gallery);
    });
    await page.waitForSelector('gallery-listing .images > [data-type="core/image"]');
    const galleryChecks = await page.evaluate(async () => {
        const state = componentShellTest;
        const dispatch = state.registry.dispatch('core/block-editor');
        const store = state.registry.select('core/block-editor');
        const settle = () => new Promise((resolve) => setTimeout(resolve, 300));
        const root = document.querySelector('gallery-listing');
        const track = root.querySelector('.images');
        const firstImage = track.querySelector('[data-type="core/image"]');
        const original = JSON.stringify(store.getBlock(state.galleryId).innerBlocks.map((block) => block.attributes));
        for (const variant of ['paged-grid', 'masonry', 'captioned', 'default', 'slider']) {
            dispatch.updateBlockAttributes(state.galleryId, { variant });
            await settle();
            if (root !== document.querySelector('gallery-listing') || track !== root.querySelector('.images') || firstImage !== track.querySelector('[data-type="core/image"]')) {
                throw new Error(`Gallery ${variant} remounted editable DOM.`);
            }
            if (root.dataset.variant !== variant || root.dataset.navigationMode !== 'page') throw new Error('Gallery bindings failed.');
            if (!root.classList.contains('gallery') || !root.classList.contains(`gallery--${variant}`) || root.classList.contains('blaze-slider') !== !['masonry', 'paged-grid'].includes(variant)) {
                throw new Error(`Gallery ${variant} custom-element classes failed: ${root.outerHTML.slice(0, 250)}`);
            }
            if (variant === 'paged-grid') {
                const buttons = root.querySelectorAll('.gallery-pagination li button');
                if (buttons.length !== 4) throw new Error('Gallery page template did not repeat.');
                buttons[2].click();
                await settle();
                if (track.querySelectorAll('[data-type="core/image"]:not([hidden])').length !== 2) throw new Error('Gallery paging failed.');
                const gallery = store.getBlock(state.galleryId);
                dispatch.selectBlock(gallery.innerBlocks[0].clientId);
                await settle();
                if (track.querySelectorAll('[data-type="core/image"]:not([hidden])').length !== 8) throw new Error('Selected image not revealed.');
            }
        }
        const gallery = store.getBlock(state.galleryId);
        if (JSON.stringify(gallery.innerBlocks.map((block) => block.attributes)) !== original) throw new Error('Variant/navigation changed saved image attributes.');
        dispatch.moveBlockToPosition(gallery.innerBlocks[9].clientId, state.galleryId, state.galleryId, 0);
        await settle();
        const reordered = store.getBlock(state.galleryId);
        if (reordered.innerBlocks[0].attributes.alt !== 'Image 10') throw new Error('Image reorder failed.');
        const serialized = wp.blocks.serialize(reordered);
        const parsed = wp.blocks.parse(serialized)[0];
        if (!parsed.isValid || parsed.innerBlocks.some((block) => !block.isValid)) throw new Error('Gallery child-only save is invalid.');
        if (serialized.includes('gallery-listing') || serialized.includes('gallery-item') || serialized.includes('blaze-')) throw new Error('Gallery saved presentation wrappers.');
        const legacy = serialized.replace(/^<!-- wp:abcnorio\/gallery(?: (.*?))? -->/, (_comment, json) => `<!-- wp:abcnorio/gallery ${JSON.stringify({ ...JSON.parse(json || '{}'), navigationMode: 'item' })} -->`);
        const legacyParsed = wp.blocks.parse(legacy)[0];
        if (!legacyParsed.isValid || JSON.stringify(legacyParsed.innerBlocks.map((block) => block.attributes)) !== JSON.stringify(parsed.innerBlocks.map((block) => block.attributes))) {
            throw new Error('Removing the obsolete navigation setting invalidated existing gallery content.');
        }
        dispatch.updateBlockAttributes(state.galleryId, { variant: 'paged-grid' });
        await settle();
        dispatch.removeBlocks(reordered.innerBlocks.slice(7).map((block) => block.clientId));
        await settle();
        if (root.querySelector('.gallery-pagination')) throw new Error('Removing images left stale page navigation.');
        dispatch.insertBlocks(wp.blocks.createBlock('core/image', { url: reordered.innerBlocks[0].attributes.url, alt: 'Added image' }), undefined, state.galleryId);
        dispatch.insertBlocks(wp.blocks.createBlock('core/image', { url: reordered.innerBlocks[0].attributes.url, alt: 'Another added image' }), undefined, state.galleryId);
        await settle();
        if (root.querySelectorAll('.gallery-pagination li button').length !== 4) throw new Error('Adding images did not rebuild repeated page navigation.');
        return { count: parsed.innerBlocks.length, reordered: parsed.innerBlocks[0].attributes.alt, templates: root.querySelectorAll('template').length };
    });
    assert.deepEqual(galleryChecks, { count: 10, reordered: 'Image 10', templates: 0 });
    console.log('Gallery shared shell checks passed: all variants retain real core/image DOM, template paging, selected-image reveal, unchanged media attributes, reorder and valid child-only reload.');
    await page.evaluate(() => {
        const block = wp.blocks.createBlock('abcnorio/newsletter-signup', { fieldTags: ['EMAIL', 'NAME'], groupIds: ['interests'] });
        componentShellTest.newsletterId = block.clientId;
        componentShellTest.registry.dispatch('core/block-editor').insertBlocks(block);
    });
    await page.waitForFunction(() => typeof componentShellTest.resolveNewsletter === 'function');
    const placeholder = page.locator('[data-newsletter-preview]').first();
    assert.equal(await placeholder.locator('button').isDisabled(), true);
    assert.equal(await placeholder.locator('form,input,select,[data-newsletter-signup]').count(), 0);
    await page.evaluate(() => {
        const state = componentShellTest;
        state.newsletterRoot = document.querySelector('[data-newsletter-preview]');
        state.resolveNewsletter({
            merge_fields: [{ tag: 'EMAIL', name: 'Email address', required: true }, { tag: 'NAME', name: 'Name' }],
            interest_groups: [{ id: 'interests', title: 'Interest groups', type: 'checkboxes' }],
        });
        state.registry.dispatch('core/block-editor').updateBlockAttributes(state.newsletterId, {
            header: 'Updated newsletter', subHeader: 'Updated subtitle', submitText: 'Join',
            showRequiredIndicator: false, requiredIndicatorText: 'Required',
        });
    });
    await page.waitForFunction(() => document.querySelector('[data-newsletter-bind="header"]').textContent === 'Updated newsletter');
    const newsletterChecks = await page.evaluate(() => {
        const state = componentShellTest;
        const root = document.querySelector('[data-newsletter-preview]');
        const block = state.registry.select('core/block-editor').getBlock(state.newsletterId);
        const serialized = wp.blocks.serialize(block);
        const reloaded = wp.blocks.parse(serialized)[0];
        if (root !== state.newsletterRoot || root.querySelector('button').textContent !== 'Join' || !root.querySelector('button').disabled) throw new Error('Newsletter lost stable/inert bindings.');
        if (root.querySelector('[data-newsletter-bind="required-star"],[data-newsletter-bind="required-text"]')) throw new Error('Newsletter required indicator did not hide.');
        if (root.querySelectorAll('.abcnorio-newsletter-signup__field').length !== 1 || root.querySelector('.abcnorio-newsletter-signup__group')) throw new Error('Placeholder should not recreate selected form fields/groups.');
        if (!reloaded.isValid || serialized.includes('abcnorio-newsletter-signup__')) throw new Error('Newsletter save changed from attribute-only storage.');
        return { fields: [...reloaded.attributes.fieldTags], groups: [...reloaded.attributes.groupIds], requests: state.newsletterRequests };
    });
    assert.deepEqual(newsletterChecks, { fields: ['EMAIL', 'NAME'], groups: ['interests'], requests: 1 });
    await page.evaluate(() => {
        componentShellTest.newsletterError = true;
        componentShellTest.registry.dispatch('core/block-editor').insertBlocks(wp.blocks.createBlock('abcnorio/newsletter-signup'));
    });
    await page.getByText('Synthetic newsletter loading error', { exact: true }).waitFor();
    assert.equal(await page.locator('[data-newsletter-preview]').count(), 2);
    assert.equal(await page.locator('[data-newsletter-preview] button:enabled').count(), 0);
    console.log('Newsletter placeholder checks passed: disabled/no real form during loading/success/error, stable text bindings, preserved field/group settings and valid attribute-only reload; no real list or submission requests.');
    await page.evaluate(() => {
        const block = wp.blocks.createBlock('abcnorio/forms', { formSlug: 'contact' });
        componentShellTest.formId = block.clientId;
        componentShellTest.registry.dispatch('core/block-editor').insertBlocks(block);
    });
    await page.waitForFunction(() => !!componentShellTest.formRequest);
    const formPlaceholder = page.locator('.abcnorio-form__placeholder').first();
    assert.equal(await formPlaceholder.locator('[role="status"]').innerText(), 'Loading public forms...');
    await page.evaluate(() => {
        componentShellTest.formRoot = document.querySelector('.abcnorio-form__placeholder');
        componentShellTest.formRequest.resolve([{ slug: 'contact', title: 'Contact <us>', submit_label: 'Send' }]);
    });
    await page.waitForFunction(() => document.querySelector('.abcnorio-form__placeholder h3')?.textContent === 'Form: Contact <us>');
    assert.equal(await formPlaceholder.locator('h3').innerHTML(), 'Form: Contact &lt;us&gt;');
    assert.equal(await formPlaceholder.locator('button').isDisabled(), true);
    assert.equal(await formPlaceholder.locator('form,input,select,textarea,[data-submit-action],[data-generic-form]').count(), 0);
    await page.evaluate(() => {
        const state = componentShellTest;
        const dispatch = state.registry.dispatch('core/block-editor');
        const block = state.registry.select('core/block-editor').getBlock(state.formId);
        const serialized = wp.blocks.serialize(block);
        const parsed = wp.blocks.parse(serialized)[0];
        if (!parsed.isValid || parsed.attributes.formSlug !== 'contact' || serialized.includes('abcnorio-form__')) {
            throw new Error('Public form attribute-only persistence changed.');
        }
        dispatch.updateBlockAttributes(state.formId, { formSlug: 'deleted' });
    });
    await page.waitForFunction(() => document.querySelector('.abcnorio-form__placeholder [role="status"]')?.textContent.includes('no longer available'));
    assert.equal(await formPlaceholder.locator('button').count(), 0);
    assert.equal(await page.evaluate(() => componentShellTest.formRoot === document.querySelector('.abcnorio-form__placeholder')), true);
    for (const scenario of ['empty', 'error', 'invalid']) {
        await page.evaluate(() => {
            const state = componentShellTest;
            state.formRequest = null;
            const block = wp.blocks.createBlock('abcnorio/forms', { formSlug: '' });
            state.registry.dispatch('core/block-editor').insertBlocks(block);
        });
        await page.waitForFunction(() => !!componentShellTest.formRequest);
        await page.evaluate((scenario) => {
            const request = componentShellTest.formRequest;
            if (scenario === 'error') request.reject(new Error('Synthetic public forms request failed'));
            else request.resolve(scenario === 'invalid' ? {} : []);
        }, scenario);
        const expected = {
            empty: 'No public forms are configured.',
            error: 'Synthetic public forms request failed',
            invalid: 'Could not load public forms: invalid response.',
        }[scenario];
        await page.waitForFunction((text) => [...document.querySelectorAll('.abcnorio-form__placeholder [role="status"]')].some((node) => node.textContent === text), expected);
    }
    console.log('Public form placeholder passed: loading/selection/missing/empty/error/invalid-response states, escaped title, stable DOM, disabled button, no fields/runtime and unchanged attribute-only save/reload; synthetic API only.');
    await page.evaluate(() => {
        const block = wp.blocks.createBlock('abcnorio/press-item', {
            date: '2026-09-10', title: 'Press title', source: 'News & Arts',
            sourceUrl: 'https://example.test/?a=1&b=2',
        });
        componentShellTest.pressId = block.clientId;
        componentShellTest.registry.dispatch('core/block-editor').insertBlocks(block);
    });
    await page.waitForSelector('article.press-item h3[contenteditable="true"]');
    const pressTitle = page.locator('article.press-item h3[contenteditable="true"]');
    await pressTitle.fill('Edited press title');
    await page.evaluate(() => {
        const state = componentShellTest;
        state.pressRoot = document.querySelector('article.press-item');
        state.pressTitle = state.pressRoot.querySelector('h3');
        state.pressSource = state.pressRoot.querySelector('.source');
        if (state.pressRoot.querySelector('time').textContent !== 'Thu, Sept 10, 2026') throw new Error('Editor must use component date formatter.');
        if (state.pressSource.textContent !== 'News & Arts') throw new Error('Source must preserve literal text.');
        state.registry.dispatch('core/block-editor').updateBlockAttributes(state.pressId, { date: '2026-02-31', fallbackDate: 'Winter 2026' });
    });
    await page.waitForFunction(() => document.querySelector('article.press-item time')?.textContent === 'Winter 2026');
    await page.locator('article.press-item .source[contenteditable="true"]').fill('Updated source');
    await page.evaluate(() => componentShellTest.registry.dispatch('core/block-editor').updateBlockAttributes(componentShellTest.pressId, {
        date: '2026-06-06', sourceUrl: 'https://example.test/"quoted"', fallbackDate: '',
    }));
    await page.waitForFunction(() => document.querySelector('article.press-item time')?.textContent === 'Sat, Jun 6, 2026');
    const pressResult = await page.evaluate(() => {
        const state = componentShellTest;
        const root = document.querySelector('article.press-item');
        const block = state.registry.select('core/block-editor').getBlock(state.pressId);
        const serialized = wp.blocks.serialize(block);
        const parsed = wp.blocks.parse(serialized)[0];
        if (root !== state.pressRoot || root.querySelector('h3') !== state.pressTitle || root.querySelector('.source') !== state.pressSource) throw new Error('Press editing regions remounted.');
        if (root.querySelector('a').getAttribute('href') !== 'https://example.test/"quoted"') throw new Error('Source URL escaping changed the URL.');
        if (!parsed.isValid || serialized.includes('<article') || serialized.includes('<time')) throw new Error('Press attribute-only save changed.');
        return { title: parsed.attributes.title, source: parsed.attributes.source, date: parsed.attributes.date };
    });
    assert.deepEqual(pressResult, { title: 'Edited press title', source: 'Updated source', date: '2026-06-06' });
    console.log('Press shared shell passed: real inline title/source editing, safe link roundtrip, shared dates/invalid-date fallback, stable article/RichText DOM and attribute-only save/reload.');
    const coreChecks = await page.evaluate(() => {
        const create = wp.blocks.createBlock;
        const paragraph = () => create('core/paragraph', { content: 'Core <strong>formatted</strong> content' });
        const samples = [
            create('core/group', { className: 'dark', layout: { type: 'default' } }, [paragraph()]),
            create('core/group', { layout: { type: 'flex', flexWrap: 'nowrap' } }, [paragraph()]),
            create('core/buttons', {}, [create('core/button', { text: 'Read more', url: '/example/' })]),
            ...[1, 2, 3, 4, 5, 6].map((level) => create('core/heading', {
                level, content: 'Heading', anchor: `core-heading-${level}`,
            })),
            paragraph(),
            create('core/image', {
                url: 'https://example.test/image.jpg', alt: 'Core image',
                caption: 'Core caption', href: '/image-link/', linkDestination: 'custom',
            }),
            create('core/quote', { citation: 'Source' }, [paragraph()]),
            create('core/list', { ordered: true, start: 5, reversed: true }, [
                create('core/list-item', { content: 'First item' }),
                create('core/list-item', { content: 'Second item' }),
            ]),
            create('core/html', { content: '<div data-core-roundtrip="true">Saved HTML</div>' }),
            create('core/embed', {
                url: 'https://www.youtube.com/watch?v=abcdefghijk', type: 'video',
                providerNameSlug: 'youtube', responsive: true,
            }),
            create('core/embed', {
                url: 'https://my.matterport.com/show/?m=example', type: 'rich',
                providerNameSlug: 'matterport',
            }),
        ];
        const invalid = (blocks) => blocks.some((block) => !block.isValid || invalid(block.innerBlocks));
        for (const sample of samples) {
            const serialized = wp.blocks.serialize(sample);
            const parsed = wp.blocks.parse(serialized);
            if (parsed.length !== 1 || invalid(parsed) || wp.blocks.serialize(parsed) !== serialized) {
                throw new Error(`Core save contract did not roundtrip: ${sample.name}`);
            }
            if (serialized.includes('data-editor-') || serialized.includes('__EDITOR_SLOT_')) throw new Error('Shared shell declarations leaked into core saves.');
        }
        return { count: samples.length, names: [...new Set(samples.map((block) => block.name))] };
    });
    assert.equal(coreChecks.count, 16);
    assert.deepEqual(coreChecks.names, [
        'core/group', 'core/buttons', 'core/heading', 'core/paragraph',
        'core/image', 'core/quote', 'core/list', 'core/html', 'core/embed',
    ]);
    console.log('Core contracts passed: 16 native Gutenberg save/parse/reserialize cases, including all heading levels, group layouts, nested buttons/list items, linked captioned image, quote, HTML and YouTube/Matterport embeds.');
    console.log('Shared shell browser checks passed: 17 rejected contracts, all six heading levels, escaping, real Gutenberg typing/insertion, optional bindings, stable DOM/refs, hero media styles and valid child-only save/reload.');
} finally {
    await browser.close();
}
