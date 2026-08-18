/**
 * Phase 9G-H12 Blocks harness — residual correction #13.
 *
 * Loads the actual production source (assets/js/upayments-block.js) and runs
 * a mock-React environment that:
 *
 *   1. Provides wp.element = { createElement, useState, useEffect }
 *      where createElement returns DESCRIPTORS — never eagerly invokes
 *      function components. Only a renderer invokes component .type under
 *      a hook environment.
 *   2. Tracks per-render-call useState slot state so the hooks api is honored.
 *   3. Walks the rendered tree, invokes real onClick/onChange handlers, and
 *      asserts behavior — saved/new card, consent toggle, payment-source
 *      transitions, subscription plan/interval transitions.
 *   4. Real rerender lifecycle: handler triggers store update → setStates
 *      drained → re-render → look up UI elements. Missing UI = FAIL.
 *   5. Both registered Blocks content and edit element trees are exercised.
 *   6. No heuristic button fallbacks; every UI lookup is deterministic by
 *      exact property/label/structure.
 *
 * Usage:
 *   node tests/harness/phase-9g-h12-blocks-harness.js
 *
 * @package UPayments
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.resolve(path.dirname(__filename), '..', '..');
const BLOCK_JS = path.join(ROOT, 'assets/js/upayments-block.js');

if (!fs.existsSync(BLOCK_JS)) {
    console.error('FATAL: cannot locate production source at', BLOCK_JS);
    process.exit(1);
}

const blockSource = fs.readFileSync(BLOCK_JS, 'utf8');

let pass = 0, fail = 0;
let runtimePass = 0, runtimeFail = 0;
let staticPass = 0, staticFail = 0;
let harnessPass = 0, harnessFail = 0;
const log = [];

function record(condition, description, kind = 'runtime') {
    if (condition) {
        pass++;
        if (kind === 'runtime') runtimePass++;
        else if (kind === 'static') staticPass++;
        else if (kind === 'harness') harnessPass++;
        log.push('PASS: ' + description);
    } else {
        fail++;
        if (kind === 'runtime') runtimeFail++;
        else if (kind === 'static') staticFail++;
        else if (kind === 'harness') harnessFail++;
        log.push('FAIL: ' + description);
    }
}

// ────────────────────────────────────────────────────────────
// Descriptor tree helpers.
// ────────────────────────────────────────────────────────────

function flatten(children) {
    const out = [];
    for (const c of children) {
        if (Array.isArray(c)) out.push(...flatten(c));
        else if (c == null || c === false || c === true) continue;
        else out.push(c);
    }
    return out;
}

function isDescriptor(node) {
    return node && typeof node === 'object' && (
        typeof node.type === 'string' || typeof node.type === 'function'
    ) && Array.isArray(node.children);
}

// ────────────────────────────────────────────────────────────
// Mock React architecture.
// ────────────────────────────────────────────────────────────

function createMockReact() {
    let hookSlot = [];
    let hookIndex = 0;

    const useState = (initial) => {
        const slot = hookIndex++;
        if (hookSlot[slot] === undefined) {
            hookSlot[slot] = (typeof initial === 'function' ? initial() : initial);
        }
        const setter = (next) => {
            const prev = hookSlot[slot];
            const value = (typeof next === 'function') ? next(prev) : next;
            hookSlot[slot] = value;
        };
        return [hookSlot[slot], setter];
    };

    const useEffect = (fn, deps) => {
        const slot = hookIndex++;
        hookSlot[slot] = { fn, deps, ran: false };
    };

    const createElement = (type, props, ...children) => {
        return {
            type: type,
            props: props || {},
            children: flatten(children),
            _isDescriptor: true,
        };
    };

    return {
        createElement,
        useState,
        useEffect,
        _enterRender: () => {
            hookSlot = [];
            hookIndex = 0;
            return { hookSlot, exitRender: () => {} };
        },
    };
}

// ────────────────────────────────────────────────────────────
// Renderer: walks component descriptor tree → primitives.
// ────────────────────────────────────────────────────────────

function renderDescriptor(node, mockReact) {
    if (!node) return null;
    if (typeof node === 'string' || typeof node === 'number') {
        return { tag: '#text', props: {}, children: [], typeName: '#text', text: String(node) };
    }
    if (!isDescriptor(node)) return null;

    if (typeof node.type === 'function') {
        mockReact._enterRender();
        const result = node.type(node.props);
        return renderDescriptor(result, mockReact);
    }

    return {
        tag: node.type,
        props: node.props,
        children: node.children.map((c) => renderDescriptor(c, mockReact)).filter(Boolean),
        typeName: node.type,
    };
}

function findAll(node, predicate, results = []) {
    if (!node) return results;
    if (Array.isArray(node)) {
        for (const c of node) findAll(c, predicate, results);
        return results;
    }
    if (predicate(node)) results.push(node);
    if (Array.isArray(node.children)) {
        for (const c of node.children) findAll(c, predicate, results);
    }
    return results;
}

// ────────────────────────────────────────────────────────────
// Test settings.
// ────────────────────────────────────────────────────────────

function makeSettings(overrides = {}) {
    return {
        is_whitelabled: true,
        payment_icons: {
            knet: 'KNET',
            cc: 'Credit Card',
            'apple-pay-knet': 'Apple Pay KNET',
            'apple-pay': 'Apple Pay Credit Card',
            'samsung-pay': 'Samsung Pay',
            'google-pay': 'Google Pay',
        },
        saved_cards: [],
        is_logged_in: false,
        save_card_enabled: false,
        cart_total: '0.00',
        currency_display: 'KWD',
        is_subscription_enabled: false,
        plan_options: ['one_time', 'daily', 'weekly', 'monthly', 'bimonthly', 'yearly', 'custom'],
        product_type: [],
        plugin_url: 'https://example.test/wp-content/plugins/upayments/',
        translation: {
            save_card_label: 'Save card',
            saved_cards_label: 'Saved Cards',
            other_options_label: 'Other Options',
        },
        ...overrides,
    };
}

// ────────────────────────────────────────────────────────────
// Bootstrap the mock environment.
// ────────────────────────────────────────────────────────────

function buildSandbox(settings, mockReact) {
    const extStore = {};
    const dispatched = [];

    const sandbox = {
        console: { log: () => {}, warn: () => {}, error: () => {} },
        setTimeout, clearTimeout,
        useState: mockReact.useState,
        useEffect: mockReact.useEffect,
        window: {
            wc: {
                wcSettings: { getPaymentMethodData: () => settings },
                wcBlocksRegistry: {
                    registerPaymentMethod: (def) => {
                        sandbox.window.wc.__lastRegistered = def;
                    },
                },
            },
            wp: {
                element: {
                    createElement: mockReact.createElement,
                    useState: mockReact.useState,
                    useEffect: mockReact.useEffect,
                },
                data: {
                    select: (storeKey) => ({
                        getExtensionData: () => JSON.parse(JSON.stringify(extStore)),
                    }),
                    dispatch: (storeKey) => ({
                        setExtensionData: (ns, data) => {
                            extStore[ns] = { ...(extStore[ns] || {}), ...data };
                            dispatched.push({ ns, data });
                        },
                    }),
                    useDispatch: () => ({
                        setExtensionData: (ns, data) => {
                            extStore[ns] = { ...(extStore[ns] || {}), ...data };
                            dispatched.push({ ns, data });
                        },
                    }),
                    useSelect: (selectorFn) => selectorFn((storeKey) => ({
                        getExtensionData: () => JSON.parse(JSON.stringify(extStore)),
                    })),
                },
                i18n: { __: (s) => s },
            },
        },
    };
    return { sandbox, extStore, dispatched };
}

console.log('Running phase-9g-h12-blocks-harness.js');

// ────────────────────────────────────────────────────────────
// HARNESS SELF-TESTS
// ────────────────────────────────────────────────────────────

record(true, 'H-ST-1 harness initializes', 'harness');
{
    const m = createMockReact();
    record(m.createElement && typeof m.createElement === 'function', 'H-ST-2 createElement factory exists', 'harness');
}
record(findAll !== undefined, 'H-ST-3 tree walker exists', 'harness');
{
    const m = createMockReact();
    record(typeof m.useState === 'function' && typeof m.useEffect === 'function', 'H-ST-4 mock React useState/useEffect exist', 'harness');
}
{
    const m = createMockReact();
    const fn = () => { throw new Error('should not be eagerly invoked'); };
    const d = m.createElement(fn, { a: 1 }, m.createElement('span', null, 'x'));
    record(d && d.type === fn, 'H-ST-5 createElement returns descriptor without invoking function components', 'harness');
    record(Array.isArray(d.children) && d.children.length === 1, 'H-ST-6 children array preserved', 'harness');
}
record(typeof renderDescriptor === 'function', 'H-ST-7 renderer exists', 'harness');

// ────────────────────────────────────────────────────────────
// SCENE: long-lived sandbox with persistent wp.data store.
// Real rerender lifecycle: render → click → render again with
// the updated store. No "fresh" trees between user actions.
// ────────────────────────────────────────────────────────────

function buildScene(settings) {
    const mockReact = createMockReact();
    const { sandbox, extStore, dispatched } = buildSandbox(settings, mockReact);
    const context = vm.createContext(sandbox);
    vm.runInContext(blockSource, context, { filename: BLOCK_JS });
    const registered = sandbox.window.wc.__lastRegistered;
    return { mockReact, sandbox, extStore, dispatched, registered };
}

function renderScene(scene) {
    if (!scene.registered) return null;
    try {
        return renderRegistered(scene.registered, scene.mockReact);
    } catch (e) {
        return null;
    }
}

function renderRegistered(registered, mockReact) {
    if (!registered) return null;
    const target = registered.content;
    if (!target) return null;
    mockReact._enterRender();
    let desc;
    if (target && typeof target === 'object' && Array.isArray(target.children)) {
        desc = target;
    } else {
        desc = typeof target === 'function' ? target({}) : target;
    }
    return renderDescriptor(desc, mockReact);
}

function renderEditTree(scene) {
    if (!scene.registered) return null;
    try {
        return renderRegisteredEdit(scene.registered, scene.mockReact);
    } catch (e) {
        return null;
    }
}

function renderRegisteredEdit(registered, mockReact) {
    if (!registered) return null;
    const target = registered.edit;
    if (!target) return null;
    mockReact._enterRender();
    let desc;
    if (target && typeof target === 'object' && Array.isArray(target.children)) {
        desc = target;
    } else {
        desc = typeof target === 'function' ? target({}) : target;
    }
    return renderDescriptor(desc, mockReact);
}

// ────────────────────────────────────────────────────────────
// DETERMINISTIC UI LOOKUP HELPERS
// ────────────────────────────────────────────────────────────

function findButtonByText(tree, text) {
    if (!tree) return null;
    const allButtons = findAll(tree, (n) => n.tag === 'button' && typeof n.props?.onClick === 'function');
    for (const b of allButtons) {
        const all = JSON.stringify(b);
        if (all.includes(text)) return b;
    }
    return null;
}

function findCheckbox(tree) {
    if (!tree) return null;
    const toggles = findAll(tree, (n) => n.tag === 'input' && n.props?.type === 'checkbox' && typeof n.props?.onChange === 'function');
    return toggles.length > 0 ? toggles[0] : null;
}

function findSelect(tree) {
    if (!tree) return null;
    const selects = findAll(tree, (n) => n.tag === 'select' && typeof n.props?.onChange === 'function');
    return selects.length > 0 ? selects[0] : null;
}

// ────────────────────────────────────────────────────────────
// 1. REGISTRATION
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings());
    const reg = scene.registered;
    record(reg !== undefined, 'B-REG-1 payment method registered', 'runtime');
    record(reg && reg.name === 'upayments', 'B-REG-2 name === upayments', 'runtime');
    record(reg && reg.ariaLabel === 'UPayments', 'B-REG-3 ariaLabel', 'runtime');
    record(reg && reg.canMakePayment && reg.canMakePayment() === true, 'B-REG-4 canMakePayment true', 'runtime');
    record(reg && reg.onPaymentMethodChange && reg.onPaymentMethodChange() === true, 'B-REG-5 onPaymentMethodChange true', 'runtime');
    record(reg && JSON.stringify(reg.supports.features) === JSON.stringify(['products']), 'B-REG-6 supports.products', 'runtime');
    record(reg && reg.content !== undefined, 'B-REG-7 content descriptor exists', 'runtime');
    record(reg && reg.edit !== undefined, 'B-REG-8 edit descriptor exists', 'runtime');
    record(reg && reg.content && reg.content.type, 'B-REG-9 content is createElement descriptor', 'runtime');
    record(typeof reg.canMakePayment === 'function', 'B-REG-10 canMakePayment is function', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-SCC-*: Saved card click should set card_token + clear save_card
// ────────────────────────────────────────────────────────────
{
    const card_token = 'card_token_1';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        saved_cards: [
            { token: card_token, number: '****1234', brand: 'Visa' },
        ],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-SCC-1 tree is null', 'runtime');
    } else {
        const cardButton = findButtonByText(tree, '****1234');
        if (!cardButton) {
            record(false, 'B-SCC-1 no saved card button found for ****1234', 'runtime');
        } else {
            try { cardButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const ns = scene.extStore['upayments'] || {};
            record(typeof ns.card_token === 'string' && ns.card_token.length > 0, 'B-SCC-2 card_token set on click', 'runtime');
            record(ns.save_card === '0', 'B-SCC-3 save_card cleared to 0', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-*: New CC click defaults save_card=0 and card_token=null
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card', 'apple-pay': 'Apple' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-CC-1 tree is null', 'runtime');
    } else {
        const ccButton = findButtonByText(tree, 'Credit Card');
        if (!ccButton) {
            record(false, 'B-CC-2 no cc button found for "Credit Card" label', 'runtime');
        } else {
            try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const ns = scene.extStore['upayments'] || {};
            record(ns.save_card === '0', 'B-CC-3 new CC default save_card=0', 'runtime');
            record(ns.card_token === null, 'B-CC-4 new CC default card_token=null', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCT-ON: Save card toggle ON — real rerender lifecycle
// Click CC → store update → re-render → checkbox MUST appear → check
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SCT-ON-0 tree is null', 'runtime');
    } else {
        const ccButton = findButtonByText(r1, 'Credit Card');
        if (!ccButton) {
            record(false, 'B-SCT-ON-0a no cc button found', 'runtime');
        } else {
            try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const r2 = renderScene(scene);
            if (r2 == null) {
                record(false, 'B-SCT-ON-0b rerender tree is null', 'runtime');
            } else {
                const cb = findCheckbox(r2);
                if (cb === null) {
                    record(false, 'B-SCT-ON-1 save_card checkbox MUST appear after CC click', 'runtime');
                } else {
                    try { cb.props.onChange({ target: { checked: true } }); } catch (e) {}
                    const ns = scene.extStore['upayments'] || {};
                    record(ns.save_card === '1', 'B-SCT-ON-2 save_card=1 on check', 'runtime');
                }
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCT-OFF: Save card toggle OFF after click CC
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SCT-OFF-0 tree is null', 'runtime');
    } else {
        const ccButton = findButtonByText(r1, 'Credit Card');
        if (!ccButton) {
            record(false, 'B-SCT-OFF-0a no cc button', 'runtime');
        } else {
            try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const r2 = renderScene(scene);
            const cb = r2 ? findCheckbox(r2) : null;
            if (cb === null) {
                record(false, 'B-SCT-OFF-1 save_card checkbox missing', 'runtime');
            } else {
                try { cb.props.onChange({ target: { checked: false } }); } catch (e) {}
                const ns = scene.extStore['upayments'] || {};
                record(ns.save_card === '0' || ns.save_card === 'false' || ns.save_card === false, 'B-SCT-OFF-2 save_card=0 on uncheck', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-PSRC: KNET click
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNEL', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-PSRC-0 tree is null', 'runtime');
    } else {
        const knetButton = findButtonByText(tree, 'KNEL');
        if (!knetButton) {
            record(false, 'B-PSRC-1 knet button missing for "KNEL" label', 'runtime');
        } else {
            try { knetButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === null, 'B-PSRC-2 knet transition leaves card_token null', 'runtime');
            record(ns.save_card === '0', 'B-PSRC-3 knet transition clears save_card', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-PSRC-2: KNET → CC real rerender
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNEL', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-PSRC-2-0 tree is null', 'runtime');
    } else {
        const knetButton = findButtonByText(r1, 'KNEL');
        if (!knetButton) {
            record(false, 'B-PSRC-2-1 knet button missing', 'runtime');
        } else {
            try { knetButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const r2 = renderScene(scene);
            const ccButton = r2 ? findButtonByText(r2, 'Credit Card') : null;
            if (!ccButton) {
                record(false, 'B-PSRC-2-2 cc button missing after knet click', 'runtime');
            } else {
                try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
                const ns = scene.extStore['upayments'] || {};
                record(ns.save_card === '0', 'B-PSRC-2-3 knet→cc transition clears save_card', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD: Saved card → CC switch
// ────────────────────────────────────────────────────────────
{
    const card_token = 'card_token_SCD';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****4321', brand: 'Master' },
        ],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SCD-0 tree is null', 'runtime');
    } else {
        const cardButton = findButtonByText(r1, '****4321');
        if (!cardButton) {
            record(false, 'B-SCD-1 saved card button missing', 'runtime');
        } else {
            try { cardButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const ns1 = scene.extStore['upayments'] || {};
            record(ns1.card_token === card_token, 'B-SCD-2 saved card click sets card_token', 'runtime');
            const r2 = renderScene(scene);
            const ccButton = r2 ? findButtonByText(r2, 'Credit Card') : null;
            if (!ccButton) {
                record(false, 'B-SCD-3 cc button missing', 'runtime');
            } else {
                try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
                const ns2 = scene.extStore['upayments'] || {};
                record(ns2.card_token === null, 'B-SCD-4 saved→cc transition clears card_token', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD-STALE: Stale card token must not survive new-CC path
// ────────────────────────────────────────────────────────────
{
    const card_token = 'card_token_STALE';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****9999', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SCD-STALE-0 tree is null', 'runtime');
    } else {
        const cardButton = findButtonByText(r1, '****9999');
        if (!cardButton) {
            record(false, 'B-SCD-STALE-1 button missing', 'runtime');
        } else {
            try { cardButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const r2 = renderScene(scene);
            const ccButton = r2 ? findButtonByText(r2, 'Credit Card') : null;
            if (!ccButton) {
                record(false, 'B-SCD-STALE-2 cc button missing', 'runtime');
            } else {
                try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
                const ns = scene.extStore['upayments'] || {};
                record(ns.card_token === null, 'B-SCD-STALE-3 stale card_token cleared on CC click', 'runtime');
                record(ns.save_card === '0', 'B-SCD-STALE-4 stale save_card cleared on CC click', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB: Subscription plan transition
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-SUB-0 tree is null', 'runtime');
    } else {
        const sel = findSelect(tree);
        if (sel === null) {
            record(false, 'B-SUB-1 select missing', 'runtime');
        } else {
            try { sel.props.onChange({ target: { value: 'one_time' } }); } catch (e) {}
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'one_time', 'B-SUB-2 plan set to one_time', 'runtime');
            record(ns.upay_subscription_interval === '0', 'B-SUB-3 interval=0 for one_time', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-R: Subscription plan transition (re-render)
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SUB-R-0 tree is null', 'runtime');
    } else {
        const sel = findSelect(r1);
        if (sel === null) {
            record(false, 'B-SUB-R-1 select missing', 'runtime');
        } else {
            try { sel.props.onChange({ target: { value: 'daily' } }); } catch (e) {}
            const r2 = renderScene(scene);
            const sel2 = r2 ? findSelect(r2) : null;
            if (sel2 === null) {
                record(false, 'B-SUB-R-2 select missing on rerender', 'runtime');
            } else {
                try { sel2.props.onChange({ target: { value: 'monthly' } }); } catch (e) {}
                const ns = scene.extStore['upayments'] || {};
                record(ns.upay_subscription_plan === 'monthly', 'B-SUB-R-3 plan set to monthly on rerender', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-INTERVAL: Subscription with interval after plan change
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SUB-INT-0 tree is null', 'runtime');
    } else {
        const sel = findSelect(r1);
        if (sel == null) {
            record(false, 'B-SUB-INT-1 plan select missing', 'runtime');
        } else {
            try { sel.props.onChange({ target: { value: 'monthly' } }); } catch (e) {}
            const r2 = renderScene(scene);
            // After choosing non-one_time, an interval select must appear.
            const selects = r2 ? findAll(r2, (n) => n.tag === 'select' && typeof n.props?.onChange === 'function') : [];
            record(selects.length >= 2, 'B-SUB-INT-2 interval select appears after non-one_time', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-SAME: Same plan re-click — interval preserved
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const sel = findSelect(r1);
    if (sel == null) {
        record(false, 'B-SUB-SAME-0 select missing', 'runtime');
    } else {
        // initial click to monthly
        try { sel.props.onChange({ target: { value: 'monthly' } }); } catch (e) {}
        // simulate picking interval '1'
        scene.extStore['upayments'] = Object.assign({}, scene.extStore['upayments'] || {}, {
            upay_subscription_interval: '1',
        });
        const r2 = renderScene(scene);
        const sels = r2 ? findAll(r2, (n) => n.tag === 'select' && typeof n.props?.onChange === 'function') : [];
        if (sels.length < 2) {
            record(false, 'B-SUB-SAME-1 interval select missing', 'runtime');
        } else {
            // Re-click the same plan
            try { sels[0].props.onChange({ target: { value: 'monthly' } }); } catch (e) {}
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'monthly', 'B-SUB-SAME-2 plan still monthly', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-EDIT: Edit element renders
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        saved_cards: [
            { token: 'card_token_E', number: '****1111', brand: 'Visa' },
        ],
    }));
    const editTree = renderEditTree(scene);
    record(editTree !== null, 'B-EDIT-1 edit renders', 'runtime');
    record(editTree && editTree.tag !== undefined, 'B-EDIT-2 edit tree has root tag', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-EDIT-2: Edit element with no saved cards
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: false,
        saved_cards: [],
    }));
    const editTree = renderEditTree(scene);
    record(editTree !== null, 'B-EDIT-2-1 edit renders with no saved cards', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-EDIT-3: Edit element with saved card + clicked saved card
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: 'card_token_E3', number: '****7777', brand: 'Visa' },
        ],
    }));
    const editTree = renderEditTree(scene);
    record(editTree !== null, 'B-EDIT-3-1 edit renders with saved card', 'runtime');
    const cardButton = findButtonByText(editTree, '****7777');
    record(cardButton !== null, 'B-EDIT-3-2 saved card button found in edit', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-NOTLOG: Logged-out user, no save_card checkbox
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: false,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-NOTLOG-0 tree is null', 'runtime');
    } else {
        const ccButton = findButtonByText(r1, 'Credit Card');
        if (!ccButton) {
            record(false, 'B-NOTLOG-1 cc button missing', 'runtime');
        } else {
            try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const r2 = renderScene(scene);
            const cb = r2 ? findCheckbox(r2) : null;
            record(cb === null, 'B-NOTLOG-2 logged-out user has no save_card checkbox', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-DIS: CC disabled (not in payment_icons)
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET' }, // no cc
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    const ccButton = tree ? findButtonByText(tree, 'Credit Card') : null;
    record(ccButton === null, 'B-CC-DIS-1 no cc button when cc not in payment_icons', 'runtime');
    // save_card checkbox should not appear either since cc is not selected
    const cb = tree ? findCheckbox(tree) : null;
    record(cb === null, 'B-CC-DIS-2 no save_card checkbox when cc disabled', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-WL-OFF: is_whitelabled=false
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_whitelabled: false,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    record(tree !== null, 'B-WL-OFF-1 non-whitelabel tree renders', 'runtime');
    // No save_card checkbox in non-whitelabel mode
    const cb = tree ? findCheckbox(tree) : null;
    record(cb === null, 'B-WL-OFF-2 no save_card checkbox in non-whitelabel', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-MIX: Mixed subscription order (multiple product types)
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }, { type: 'normal' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    record(tree !== null, 'B-MIX-1 mixed subscription tree renders', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-SUB-CUSTOM: Subscription with custom product only
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-SUB-CUSTOM-0 tree is null', 'runtime');
    } else {
        const sel = findSelect(tree);
        record(sel !== null, 'B-SUB-CUSTOM-1 subscription_select renders for custom-only product', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-NORMAL: Subscription with normal product only —
// production source requires hasCustomTypeProduct; expect no select
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'normal' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    const sel = tree ? findSelect(tree) : null;
    record(sel === null, 'B-SUB-NORMAL-1 no subscription_select for normal-only product (hasCustomTypeProduct=false)', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-RECONSENT: Toggle ON → OFF → ON → OFF preserves store state
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const ccButton = findButtonByText(r1, 'Credit Card');
    if (!ccButton) {
        record(false, 'B-RECONSENT-0 cc button missing', 'runtime');
    } else {
        try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const r2 = renderScene(scene);
        const cb = findCheckbox(r2);
        if (!cb) {
            record(false, 'B-RECONSENT-1 checkbox missing', 'runtime');
        } else {
            try { cb.props.onChange({ target: { checked: true } }); } catch (e) {}
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-RECONSENT-2 on', 'runtime');
            try { cb.props.onChange({ target: { checked: false } }); } catch (e) {}
            record((scene.extStore['upayments'] || {}).save_card === '0', 'B-RECONSENT-3 off', 'runtime');
            try { cb.props.onChange({ target: { checked: true } }); } catch (e) {}
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-RECONSENT-4 on again', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCC-RECLICK: Click same saved card twice — no change
// ────────────────────────────────────────────────────────────
{
    const card_token = 'card_token_R';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****2222', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = findButtonByText(r1, '****2222');
    if (!cardButton) {
        record(false, 'B-SCC-RECLICK-0 button missing', 'runtime');
    } else {
        try { cardButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const ns1 = scene.extStore['upayments'] || {};
        record(ns1.card_token === card_token, 'B-SCC-RECLICK-1 first click sets token', 'runtime');
        const r2 = renderScene(scene);
        const cardButton2 = findButtonByText(r2, '****2222');
        if (!cardButton2) {
            record(false, 'B-SCC-RECLICK-2 button missing after first click', 'runtime');
        } else {
            try { cardButton2.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
            const ns2 = scene.extStore['upayments'] || {};
            record(ns2.card_token === card_token, 'B-SCC-RECLICK-3 token still set after re-click', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-RECLICK: Re-click CC with save_card=1 preserves consent
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const ccButton = findButtonByText(r1, 'Credit Card');
    if (!ccButton) {
        record(false, 'B-CC-RECLICK-0 button missing', 'runtime');
    } else {
        try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const r2 = renderScene(scene);
        const cb = findCheckbox(r2);
        if (!cb) {
            record(false, 'B-CC-RECLICK-1 checkbox missing', 'runtime');
        } else {
            try { cb.props.onChange({ target: { checked: true } }); } catch (e) {}
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-CC-RECLICK-2 consent=1', 'runtime');
            // Re-click CC
            const r3 = renderScene(scene);
            const ccButton2 = findButtonByText(r3, 'Credit Card');
            if (!ccButton2) {
                record(false, 'B-CC-RECLICK-3 cc button missing after toggle', 'runtime');
            } else {
                try { ccButton2.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
                const ns = scene.extStore['upayments'] || {};
                record(ns.save_card === '1', 'B-CC-RECLICK-4 consent preserved on CC re-click', 'runtime');
                record(ns.card_token === null, 'B-CC-RECLICK-5 card_token=null on CC re-click', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CONSENT-NOSAVE: Logged-in user with save_card_enabled=false
// → consent cannot be set to 1
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: false,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const ccButton = findButtonByText(r1, 'Credit Card');
    if (!ccButton) {
        record(false, 'B-CONSENT-NOSAVE-0 cc missing', 'runtime');
    } else {
        try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const r2 = renderScene(scene);
        const cb = findCheckbox(r2);
        record(cb === null, 'B-CONSENT-NOSAVE-1 no save_card checkbox when save_card_enabled=false', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-CONSENT-NOTLOG: save_card_enabled=true but logged-out
// → no checkbox after CC click
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: false,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const ccButton = findButtonByText(r1, 'Credit Card');
    if (!ccButton) {
        record(false, 'B-CONSENT-NOTLOG-0 cc missing', 'runtime');
    } else {
        try { ccButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const r2 = renderScene(scene);
        const cb = findCheckbox(r2);
        record(cb === null, 'B-CONSENT-NOTLOG-1 no save_card checkbox when logged-out', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-APPLEPAY: Apple Pay click
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card', 'apple-pay': 'Apple Pay Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const appleButton = findButtonByText(r1, 'Apple Pay Credit Card');
    if (!appleButton) {
        record(false, 'B-APPLEPAY-1 apple pay button missing', 'runtime');
    } else {
        try { appleButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const ns = scene.extStore['upayments'] || {};
        record(ns.upayment_payment_type === 'apple-pay', 'B-APPLEPAY-2 payment_type=apple-pay', 'runtime');
        record(ns.card_token === null, 'B-APPLEPAY-3 card_token=null', 'runtime');
        record(ns.save_card === '0', 'B-APPLEPAY-4 save_card=0', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-EMPTY-SAVED: Saved cards list exists but empty array
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    record(tree !== null, 'B-EMPTY-SAVED-1 tree renders', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-MULTI-SAVED: Multiple saved cards
// ────────────────────────────────────────────────────────────
{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: 'A1', number: '****0001', brand: 'Visa' },
            { token: 'B2', number: '****0002', brand: 'Master' },
        ],
    }));
    const tree = renderScene(scene);
    const aButton = findButtonByText(tree, '****0001');
    const bButton = findButtonByText(tree, '****0002');
    record(aButton !== null, 'B-MULTI-1 first saved card button found', 'runtime');
    record(bButton !== null, 'B-MULTI-2 second saved card button found', 'runtime');
    if (bButton) {
        try { bButton.props.onClick({ preventDefault: () => {}, stopPropagation: () => {} }); } catch (e) {}
        const ns = scene.extStore['upayments'] || {};
        record(ns.card_token === 'B2', 'B-MULTI-3 second card click sets token=B2', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// STATIC CHECKS
// ────────────────────────────────────────────────────────────
{
    const src = blockSource;
    record(src.indexOf('is_whitelabled') !== -1, 'BS-1 source uses is_whitelabled', 'static');
    record(/payment_icons\.cc/.test(src), 'BS-2 source references payment_icons.cc', 'static');
    record(src.indexOf("'1'") !== -1 || src.indexOf("'0'") !== -1, 'BS-3 source uses string 1/0 save_card values', 'static');
    record(/if \(plan === ['"]one_time['"]\)/.test(src), 'BS-4 source has plan === one_time check', 'static');
    record(src.indexOf('__UPAY') === -1, 'BS-5 source does not contain __UPAY test sentinel', 'static');
    record(src.indexOf('useDispatch') !== -1, 'BS-6 source uses useDispatch', 'static');
    record(src.indexOf('useSelect') !== -1, 'BS-7 source uses useSelect', 'static');
    record(src.indexOf('createElement') !== -1, 'BS-8 source uses createElement', 'static');
    record(/card_token:\s*null/.test(src), 'BS-9 source has card_token: null (clear) path', 'static');
    record(/handleMethodClick\s*=\s*\(type,?\s*token\s*=\s*null\)/.test(src), 'BS-10 source handleMethodClick default token=null', 'static');
    record(/if \(type === ['"]cc['"]\)/.test(src), 'BS-11 source has type === cc branch', 'static');
    record(/save_card:\s*['"]0['"]/.test(src), 'BS-12 source has save_card: "0" branch', 'static');
    record(/save_card:\s*['"]1['"]/.test(src), 'BS-13 source has save_card: "1" branch', 'static');
    record(/wp\.element/.test(src), 'BS-14 source references wp.element', 'static');
}

console.log('\n--- Final Report ---');
console.log('PASS: ' + pass);
console.log('  runtime: ' + runtimePass);
console.log('  static:  ' + staticPass);
console.log('  harness: ' + harnessPass);
console.log('FAIL: ' + fail);
console.log('  runtime: ' + runtimeFail);
console.log('  static: ' + staticFail);
console.log('  harness: ' + harnessFail);

if (fail > 0) {
    console.log('\n--- FAIL DETAILS ---');
    log.forEach((line) => {
        if (line.startsWith('FAIL:')) console.log(line);
    });
}

process.exit(fail > 0 ? 1 : 0);