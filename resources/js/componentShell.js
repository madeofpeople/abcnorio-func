import { cloneElement, createElement, useMemo } from '@wordpress/element';

const TAGS = new Set(['div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'img', 'a', 'strong', 'em', 'gallery-listing', 'button', 'nav', 'ul', 'li', 'figure', 'figcaption', 'article', 'time']);
const ATTRIBUTE_NAMES = { class: 'className', for: 'htmlFor', tabindex: 'tabIndex', srcset: 'srcSet', fetchpriority: 'fetchPriority', datetime: 'dateTime' };
const BOOLEAN_ATTRIBUTES = new Set(['disabled', 'hidden']);

export function compileComponentShell(html) {
    if (typeof html !== 'string' || html.trim() === '') {
        throw new Error('Component editor shell is missing. Rebuild component and editor assets.');
    }
    const document = new DOMParser().parseFromString(html, 'text/html');
    const roots = document.querySelectorAll('[data-editor-root]');
    if (roots.length !== 1) throw new Error('Component editor shell requires exactly one declared root.');
    const slots = new Set();
    const bindings = new Set();
    const repeats = new Set();

    function compile(node, names = { slots, bindings, repeats }) {
        if (node.nodeType === 1 && node.localName === 'template') {
            const name = node.getAttribute('data-editor-repeat');
            if (!name) {
                if (!node.hasAttribute('data-gallery-template')) throw new Error('Undeclared component shell template.');
                return () => null;
            }
            if (!/^[a-z][a-z0-9-]*$/.test(name) || names.repeats.has(name)) throw new Error(`Invalid or duplicate editor repeater: ${name}`);
            names.repeats.add(name);
            const local = { slots: new Set(), bindings: new Set(), repeats: new Set() };
            const children = [...node.content.childNodes].map((child) => compile(child, local));
            return (regions, values, key, items) => {
                if (!Array.isArray(items[name])) throw new Error(`Editor repeater must be an array: ${name}`);
                const seen = new Set();
                return items[name].map((item) => {
                    if (!item || !['string', 'number'].includes(typeof item.key) || seen.has(String(item.key))) {
                        throw new Error(`Editor repeater requires unique string/number keys: ${name}`);
                    }
                    seen.add(String(item.key));
                    assertNames(local.slots, item.slots || {}, 'slot');
                    assertNames(local.bindings, item.bindings || {}, 'binding');
                    assertNames(local.repeats, item.repeats || {}, 'repeater');
                    return children.map((child, index) => child(item.slots || {}, item.bindings || {}, `${key}.${item.key}.${index}`, item.repeats || {}));
                });
            };
        }
        if (node.nodeType === 3) {
            const text = node.textContent;
            const slot = text.trim().match(/^__EDITOR_SLOT_([A-Z][A-Z0-9_]*)__$/)?.[1]?.toLowerCase();
            if (!slot) {
                if (text.includes('__EDITOR_SLOT_')) throw new Error('Editor slot must occupy its own text node.');
                return () => text;
            }
            if (names.slots.has(slot)) throw new Error(`Duplicate editor slot: ${slot}`);
            names.slots.add(slot);
            return (regions) => regions[slot];
        }
        if (node.nodeType !== 1) return () => null;
        if (!TAGS.has(node.localName)) throw new Error(`Unsupported component shell element: ${node.localName}`);
        const props = {};
        const binding = node.getAttribute('data-editor-bind');
        if (binding !== null) {
            if (!/^[a-z][a-z0-9-]*$/.test(binding) || names.bindings.has(binding)) {
                throw new Error(`Invalid or duplicate editor binding: ${binding}`);
            }
            names.bindings.add(binding);
        }
        for (const { name, value } of node.attributes) {
            if (name.startsWith('on') || name === 'style') throw new Error(`Unsupported component shell attribute: ${name}`);
            if (name !== 'data-editor-root' && name !== 'data-editor-bind') {
                props[ATTRIBUTE_NAMES[name] || name] = BOOLEAN_ATTRIBUTES.has(name) ? true : value;
            }
        }
        const children = [...node.childNodes].map((child) => compile(child, names));
        return (regions, values, key = 'root', items = {}) => {
            const value = binding === null ? {} : values[binding];
            if (value.hidden) return null;
            const tag = value.tag || node.localName;
            if (!TAGS.has(tag)) throw new Error(`Unsupported bound component element: ${tag}`);
            const boundProps = { ...props, ...value.props, key };
            if (tag.includes('-')) {
                boundProps.class = boundProps.className;
                delete boundProps.className;
            }
            const content = Object.hasOwn(value, 'children')
                ? [value.children]
                : children.map((child, index) => child(regions, values, `${key}.${index}`, items));
            if (value.render) return value.render({ tag, props: boundProps, children: content });
            return tag === 'img' ? createElement(tag, boundProps) : createElement(tag, boundProps, ...content);
        };
    }

    const render = compile(roots[0]);
    function assertNames(expected, actual, label) {
        for (const name of expected) {
            if (!Object.hasOwn(actual, name)) throw new Error(`Missing editor ${label}: ${name}`);
        }
        for (const name of Object.keys(actual)) {
            if (!expected.has(name)) throw new Error(`Undeclared editor ${label}: ${name}`);
        }
    }
    return function Shell({ blockProps, slots: regions = {}, bindings: values = {}, repeats: items = {} }) {
        assertNames(slots, regions, 'slot');
        assertNames(bindings, values, 'binding');
        assertNames(repeats, items, 'repeater');
        const tree = render(regions, values, 'root', items);
        if (!tree) throw new Error('Component editor root cannot be hidden.');
        const className = [...new Set(`${tree.props.className || tree.props.class || ''} ${blockProps.className || ''}`.split(/\s+/).filter(Boolean))].join(' ');
        const props = { ...blockProps, [tree.type.includes('-') ? 'class' : 'className']: className };
        if (tree.type.includes('-')) delete props.className;
        return cloneElement(tree, props);
    };
}

export default function ComponentShell({ component, ...props }) {
    const html = window.abcnorioEditorShells?.[component];
    const Shell = useMemo(() => compileComponentShell(html), [html]);
    return createElement(Shell, props);
}
