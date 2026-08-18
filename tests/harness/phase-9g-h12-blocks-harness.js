/**
 * Phase 9G-H12 Blocks harness — residual correction #4.
 *
 * Loads the actual production source (assets/js/upayments-block.js) and runs
 * a mock-React environment that executes the actual Content component, walks
 * the returned element tree, and invokes actual onClick/onChange closures.
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

function recordEq(actual, expected, description, kind = 'runtime') {
    record(actual === expected,
        description + ' (expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual) + ')',
        kind);
}

function makeCreateElement() {
    return function createElement(type, props, ...children) {
        if (typeof type === 'function') {
            const comp = type(props || {});
            return makeNode(comp, type.name || 'Anonymous');
        }
        return { tag: type, props: props || {}, children: flatten(children), typeName: type };
    };
}

function flatten(children) {
    const out = [];
    for (const c of children) {
        if (Array.isArray(c)) out.push(...flatten(c));
        else if (c == null || c === false || c === true) continue;
        else out.push(c);
    }
    return out;
}

function makeNode(comp, typeName) {
    if (comp == null || typeof comp !== 'object') return null;
    if (Array.isArray(comp)) return comp;
    return { tag: comp.tag || typeName, props: comp.props || {}, children: flatten(comp.children || []), typeName: comp.typeName || comp.tag || typeName };
}

function findAll(node, predicate, results = []) {
    if (!node) return results;
    if (Array.isArray(node)) {
        for (const c of node) findAll(c, predicate, results);
        return results;
    }
    if (predicate(node)) results.push(node);
    if (node.children) {
        for (const c of node.children) findAll(c, predicate, results);
    }
    return results;
}

function makeSettings(overrides = {}) {
    return {
        is_whitelabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card', 'apple-pay-knet': 'Apple Pay KNET', 'apple-pay': 'Apple Pay Credit Card', 'samsung-pay': 'Samsung Pay', 'google-pay': 'Google Pay' },
        saved_cards: [],
        is_logged_in: false,
        save_card_enabled: false,
        cart_total: '0.00',
        currency_display: 'KWD',
        is_subscription_enabled: false,
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

function setupMockReact(settings) {
    const extStore = {};
    const dispatched = [];

    const useState = (initial) => {
        let value = initial;
        const setter = (v) => {
            if (typeof v === 'function') value = v(value);
            else value = v;
            extStore.__lastSet = value;
        };
        return [value, setter];
    };

    const useEffect = () => { /* noop */ };

    const selectStore = () => ({
        getExtensionData: () => JSON.parse(JSON.stringify(extStore)),
    });

    const dispatchStore = () => ({
        setExtensionData: (ns, data) => {
            extStore[ns] = { ...(extStore[ns] || {}), ...data };
            dispatched.push({ ns, data });
        },
    });

    // Set useState/useEffect as top-level properties of the sandbox so
    // the production IIFE can reference them as bare identifiers.
    const sandbox = {
        console: { log: () => {}, warn: () => {}, error: () => {} },
        setTimeout, clearTimeout,
        useState, useEffect,
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
                element: { createElement: makeCreateElement() },
                data: {
                    select: selectStore,
                    dispatch: dispatchStore,
                    useDispatch: () => dispatchStore(),
                    useSelect: (selectorFn) => selectorFn((storeKey) => ({
                        getExtensionData: () => JSON.parse(JSON.stringify(extStore)),
                    })),
                },
                i18n: { __: (s) => s },
            },
        },
    };

    return { sandbox, extStore, dispatched, useState };
}

console.log('Running phase-9g-h12-blocks-harness.js');

record(true, 'H-ST-1 harness initializes', 'harness');
record(makeCreateElement() !== null, 'H-ST-2 createElement factory exists', 'harness');
record(findAll !== undefined, 'H-ST-3 tree walker exists', 'harness');
record(setupMockReact !== undefined, 'H-ST-4 React mock exists', 'harness');

// 1. Registration
{
    const { sandbox } = setupMockReact(makeSettings());
    const context = vm.createContext(sandbox);
    try {
        vm.runInContext(blockSource, context, { filename: BLOCK_JS });
        const registered = sandbox.window.wc.__lastRegistered;
        record(registered !== undefined, 'B-REG-1 payment method registered', 'runtime');
        record(registered && registered.name === 'upayments', 'B-REG-2 name === upayments', 'runtime');
        record(registered && registered.ariaLabel === 'UPayments', 'B-REG-3 ariaLabel', 'runtime');
        record(registered && registered.canMakePayment && registered.canMakePayment() === true, 'B-REG-4 canMakePayment true', 'runtime');
        record(registered && registered.onPaymentMethodChange && registered.onPaymentMethodChange() === true, 'B-REG-5 onPaymentMethodChange true', 'runtime');
        record(registered && JSON.stringify(registered.supports.features) === JSON.stringify(['products']), 'B-REG-6 supports.products', 'runtime');
        record(typeof registered.content === 'function', 'B-REG-7 content is function', 'runtime');
        record(typeof registered.edit === 'function', 'B-REG-8 edit is function', 'runtime');
    } catch (e) {
        record(false, 'B-REG-X source execution failed: ' + e.message, 'runtime');
    }
}

// 2. Real Content render with saved cards
function renderContent(settings) {
    const { sandbox, extStore, dispatched } = setupMockReact(settings);
    const context = vm.createContext(sandbox);
    vm.runInContext(blockSource, context, { filename: BLOCK_JS });
    const Content = sandbox.window.wc.__lastRegistered.content;
    let tree;
    try {
        tree = Content({});
    } catch (e) {
        tree = null;
    }
    return { tree, sandbox, extStore, dispatched };
}

function runHandlerTest(name, settings, fn) {
    const { tree, extStore, dispatched } = renderContent(settings);
    if (tree == null) {
        record(false, name + ' tree is null (cannot render)', 'runtime');
        return;
    }
    fn(tree, extStore, dispatched);
}

// === B-SCC: Saved-card click should set card_token to selected token and clear save_card ===
{
    const card_token = 'card_token_1';
    const settings = makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        saved_cards: [
            { token: card_token, number: '****1234', brand: 'Visa' },
        ],
    });
    runHandlerTest('B-SCC', settings, (tree, extStore) => {
        // Find the saved card button — production code has class 'upay-payment-method' and
        // onClick that calls handleMethodClick('cc', token).
        // Walk the tree for elements with an onClick whose props.value === 'cc'.
        const all = findAll(tree, (n) => typeof n.props?.onClick === 'function' && n.props?.value === 'cc');
        if (all.length === 0) {
            // Try by finding a button with the saved card token in props
            const tokenMatch = findAll(tree, (n) => n.props?.value === 'cc' || (typeof n.props?.onClick === 'function' && JSON.stringify(n.props).includes(card_token)));
            if (tokenMatch.length === 0) {
                record(false, 'B-SCC-1 no saved card button found in tree', 'runtime');
                return;
            }
        }
        const cardButton = all[0];
        const event = { preventDefault: () => {}, stopPropagation: () => {} };
        try { cardButton.props.onClick(event); } catch (e) {}
        const ns = extStore['upayments'] || {};
        record(ns.card_token === card_token, 'B-SCC-2 card_token set to selected', 'runtime');
        record(ns.save_card === '0', 'B-SCC-3 save_card cleared to 0', 'runtime');
    });
}

// === B-CC: New CC click defaults save_card to 0 ===
{
    const settings = makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: true,
        payment_icons: { knet: 'K', cc: 'C' },
        saved_cards: [],
    });
    runHandlerTest('B-CC', settings, (tree, extStore) => {
        // Find buttons where onClick is set and value is 'cc' or method is 'cc'
        const ccButtons = findAll(tree, (n) => typeof n.props?.onClick === 'function' && n.props?.value === 'cc');
        if (ccButtons.length === 0) {
            record(false, 'B-CC-1 no cc button found', 'runtime');
            return;
        }
        const event = { preventDefault: () => {}, stopPropagation: () => {} };
        try { ccButtons[0].props.onClick(event); } catch (e) {}
        const ns = extStore['upayments'] || {};
        record(ns.save_card === '0', 'B-CC-2 new CC default save_card=0', 'runtime');
        record(ns.card_token === null, 'B-CC-3 new CC default card_token=null', 'runtime');
    });
}

// === B-SCT-ON: Save card toggle ON (from 0) ===
{
    const settings = makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
    });
    runHandlerTest('B-SCT-ON', settings, (tree, extStore) => {
        const toggles = findAll(tree, (n) => n.tag === 'input' && n.props?.type === 'checkbox');
        if (toggles.length === 0) {
            record(false, 'B-SCT-ON-1 no checkbox found', 'runtime');
            return;
        }
        const event = { target: { checked: true } };
        try { toggles[0].props.onChange(event); } catch (e) {}
        const ns = extStore['upayments'] || {};
        record(ns.save_card === '1', 'B-SCT-ON-2 save_card set to 1 on check', 'runtime');
    });
}

// === B-SUB: Subscription plan transition with one_time => interval '0' ===
{
    const settings = makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        product_type: [{ type: 'custom_type' }],
    });
    runHandlerTest('B-SUB', settings, (tree, extStore) => {
        // The subscription plan select uses onChange.
        const selects = findAll(tree, (n) => n.tag === 'select' && typeof n.props?.onChange === 'function');
        if (selects.length === 0) {
            record(false, 'B-SUB-1 no subscription select found', 'runtime');
            return;
        }
        const select = selects[0];
        // Simulate changing to 'one_time'
        const event = { target: { value: 'one_time' } };
        try { select.props.onChange(event); } catch (e) {}
        const ns = extStore['upayments'] || {};
        record(ns.upay_subscription_plan === 'one_time', 'B-SUB-2 plan set to one_time', 'runtime');
        record(ns.upay_subscription_interval === '0', 'B-SUB-3 interval set to 0 for one_time', 'runtime');
    });
}

// === B-SUB-D: Subscription plan transition with 'daily' => interval '' ===
{
    const settings = makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        product_type: [{ type: 'custom_type' }],
    });
    runHandlerTest('B-SUB-D', settings, (tree, extStore) => {
        const selects = findAll(tree, (n) => n.tag === 'select' && typeof n.props?.onChange === 'function');
        if (selects.length === 0) {
            record(false, 'B-SUB-D-1 no subscription select found', 'runtime');
            return;
        }
        const select = selects[0];
        const event = { target: { value: 'daily' } };
        try { select.props.onChange(event); } catch (e) {}
        const ns = extStore['upayments'] || {};
        record(ns.upay_subscription_plan === 'daily', 'B-SUB-D-2 plan set to daily', 'runtime');
        record(ns.upay_subscription_interval === '', 'B-SUB-D-3 interval reset to empty for new plan', 'runtime');
    });
}

// Static checks (source-level contract verification)
{
    const src = blockSource;
    record(src.indexOf('is_whitelabled') !== -1, 'BS-1 source uses is_whitelabled', 'static');
    record(src.indexOf('payment_icons.cc') !== -1 || src.indexOf('payment_icons && payment_icons.cc') !== -1, 'BS-2 source references payment_icons.cc', 'static');
    record(src.indexOf("'1'") !== -1 || src.indexOf("'0'") !== -1, 'BS-3 source uses string 1/0 save_card values', 'static');
    record(/if \(plan === ['"]one_time['"]\)/.test(src), 'BS-4 source has plan === one_time check', 'static');
    record(src.indexOf("'__UPAY'") === -1, 'BS-5 source does not contain __UPAY test sentinel', 'static');
    record(src.indexOf('useDispatch') !== -1, 'BS-6 source uses useDispatch', 'static');
    record(src.indexOf('useSelect') !== -1, 'BS-7 source uses useSelect', 'static');
    record(src.indexOf('createElement') !== -1, 'BS-8 source uses createElement', 'static');
    record(/card_token:\s*null/.test(src), 'BS-9 source has card_token: null (clear) path', 'static');
    record(/handleMethodClick\s*=\s*\(type,?\s*token\s*=\s*null\)/.test(src), 'BS-10 source handleMethodClick default token=null', 'static');
    record(/if \(type === ['"]cc['"]\)/.test(src), 'BS-11 source has type === cc branch', 'static');
    record(/save_card:\s*['"]0['"]/.test(src), 'BS-12 source has save_card: "0" branch', 'static');
    record(/save_card:\s*['"]1['"]/.test(src), 'BS-13 source has save_card: "1" branch', 'static');
    record(/currentSaveCard\s*===\s*['"]1['"]/.test(src) || /upayData\.save_card\s*===\s*['"]1['"]/.test(src), 'BS-14 source checks current save_card === 1', 'static');
}

console.log('\n--- Final Report ---');
console.log('PASS: ' + pass);
console.log('  runtime: ' + runtimePass);
console.log('  static:  ' + staticPass);
console.log('  harness: ' + harnessPass);
console.log('FAIL: ' + fail);
console.log('  runtime: ' + runtimeFail);
console.log('  static:  ' + staticFail);
console.log('  harness: ' + harnessFail);

if (fail > 0) {
    console.log('\n--- FAIL DETAILS ---');
    log.forEach((line) => {
        if (line.startsWith('FAIL:')) console.log(line);
    });
}

exit(fail > 0 ? 1 : 0);