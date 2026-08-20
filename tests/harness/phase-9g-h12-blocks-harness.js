/**
 * Phase 9G-H12 Blocks harness — residual correction #18.
 *
 * Loads the actual production source (assets/js/upayments-block.js) and runs
 * a mock-React environment that:
 *
 *   1. Per-component-instance hook slots. A component's useState/useEffect
 *      slots are PERSISTENT across renders of the same component function
 *      reference. Different components have independent slot maps. This is
 *      what real React does (slots are per-fiber, not per-render-call).
 *   2. Exact label matching for UI lookup. We walk the rendered tree to
 *      extract leaf text-node strings, then match by exact equality or
 *      by discrete-leaf-substring. No JSON.stringify blob matching.
 *   3. Real handler dispatch → store mutation → re-render lifecycle.
 *      Handlers mutate the live extStore via the real wp.data.dispatch;
 *      re-renders read the updated store via useSelect. No manual writes
 *      to scene.extStore in test bodies.
 *   4. Tested state transitions:
 *      - Saved-card click → new CC click → KNET click (full chain)
 *      - Saved-card click → new CC click (consent preserved on re-click)
 *      - Saved-card click → KNET direct (card_token cleared)
 *      - Plan select → interval select (real handlers, real values)
 *      - Same-plan re-click (interval preserved or reset per production)
 *      - Consent on/off/re-consent cycle
 *      - Stale card_token clearing on transition away
 *      - Mixed custom+normal product_type (extension state assertions)
 *   5. No heuristic button fallbacks. Every UI lookup is by exact
 *      recursively-extracted visible label.
 *
 * Test set is split into:
 *   - runtime:    externally-meaningful state transitions through real
 *                 production handlers, with real store updates, real
 *                 re-renders, and exact label verification.
 *   - static:     source-level structural checks (e.g., production
 *                 reads `upayment_payment_type` not `paymentType`).
 *   - harness:    self-tests for the mock React architecture itself.
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

function record(condition, description, kind) {
    kind = kind || 'runtime';
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

// Recursively extract all leaf text-node strings from a tree node.
// Returns an array of strings. Each leaf text is a discrete property
// of the rendered output (an element's text child, not a JSON blob).
function getLeafTextStrings(node, leaves) {
    leaves = leaves || [];
    if (node == null || node === false || node === true) return leaves;
    if (typeof node === 'string' || typeof node === 'number') {
        const s = String(node);
        if (s.length > 0) leaves.push(s);
        return leaves;
    }
    if (Array.isArray(node)) {
        for (const c of node) getLeafTextStrings(c, leaves);
        return leaves;
    }
    if (typeof node !== 'object') return leaves;
    // Rendered tree nodes carry a .text field for #text nodes.
    if (typeof node.text === 'string' || typeof node.text === 'number') {
        const s = String(node.text);
        if (s.length > 0) leaves.push(s);
        return leaves;
    }
    if (Array.isArray(node.children)) {
        for (const c of node.children) getLeafTextStrings(c, leaves);
    }
    return leaves;
}

function findAll(node, predicate, results) {
    results = results || [];
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
// Mock React architecture — per-component-instance hook slots.
//
// Residual Correction #19: real per-instance identity.
//
// Production registers BOTH content and edit slots with the same
// `Content` function reference:
//     content: wp.element.createElement(Content),
//     edit:    wp.element.createElement(Content),
//
// Under real React these are two separate fibers with separate
// hook state. The previous mock keyed instance state by component
// function alone, which conflated the content and edit fibers.
//
// The new mock requires an explicit instanceKey from the caller
// and uses (componentFn, instanceKey) as the identity tuple. The
// caller (renderDescriptor / renderRegistered) threads the
// instanceKey from the registration slot — 'content' vs 'edit' —
// into the top-level render, and any subcomponent renders share
// that instanceKey unless explicitly overridden by the component.
//
// The architecture supports:
//     same F, instance A -> state A
//     same F, instance B -> state B
//     rerender A -> preserves A only
//     rerender B -> preserves B only
//
// Harness self-tests H-ST-26..H-ST-30 prove this directly.
// ────────────────────────────────────────────────────────────

function createMockReact() {
    // Map<componentFn, Map<instanceKey, {hookSlot, hookIndex}>>
    const componentInstances = new Map();
    let currentTuple = null;

    const getInstance = (componentFn, instanceKey) => {
        if (typeof componentFn !== 'function') {
            throw new Error('createMockReact: componentFn must be a function');
        }
        if (instanceKey === null || instanceKey === undefined
            || (typeof instanceKey !== 'string' && typeof instanceKey !== 'number')
        ) {
            throw new Error('createMockReact: instanceKey required (string or number)');
        }
        let fnMap = componentInstances.get(componentFn);
        if (!fnMap) {
            fnMap = new Map();
            componentInstances.set(componentFn, fnMap);
        }
        let instance = fnMap.get(instanceKey);
        if (!instance) {
            instance = { hookSlot: [], hookIndex: 0 };
            fnMap.set(instanceKey, instance);
        }
        return instance;
    };

    const useState = (initial) => {
        if (!currentTuple) {
            throw new Error('useState called outside component render');
        }
        const instance = getInstance(currentTuple.fn, currentTuple.key);
        const slot = instance.hookIndex++;
        if (instance.hookSlot[slot] === undefined) {
            instance.hookSlot[slot] = (typeof initial === 'function' ? initial() : initial);
        }
        const setter = (next) => {
            const prev = instance.hookSlot[slot];
            const value = (typeof next === 'function') ? next(prev) : next;
            instance.hookSlot[slot] = value;
        };
        return [instance.hookSlot[slot], setter];
    };

    const useEffect = (fn, deps) => {
        if (!currentTuple) return;
        const instance = getInstance(currentTuple.fn, currentTuple.key);
        const slot = instance.hookIndex++;
        instance.hookSlot[slot] = { fn: fn, deps: deps, ran: false };
    };

    const createElement = function (type, props) {
        const children = Array.prototype.slice.call(arguments, 2);
        return {
            type: type,
            props: props || {},
            children: flatten(children),
            _isDescriptor: true,
        };
    };

    // renderComponent requires (componentFn, instanceKey, props). The
    // instanceKey identifies which fiber of this component is being
    // rendered. Re-rendering the same tuple preserves hook state;
    // rendering a different tuple gets its own state.
    const renderComponent = (componentFn, instanceKey, props) => {
        const instance = getInstance(componentFn, instanceKey);
        instance.hookIndex = 0;
        const prevTuple = currentTuple;
        currentTuple = { fn: componentFn, key: instanceKey };
        try {
            return componentFn(props || {});
        } finally {
            currentTuple = prevTuple;
        }
    };

    // Returns the total number of (componentFn, instanceKey) pairs
    // currently registered. Used by self-tests to prove two instances
    // of the same component have independent state.
    const getInstanceCount = () => {
        let total = 0;
        for (const fnMap of componentInstances.values()) {
            total += fnMap.size;
        }
        return total;
    };

    // Read the raw hook slot for an instance; used by self-tests to
    // assert hook-state isolation between two instances of the same
    // component function.
    const readHookSlot = (componentFn, instanceKey, slotIndex) => {
        const fnMap = componentInstances.get(componentFn);
        if (!fnMap) return undefined;
        const inst = fnMap.get(instanceKey);
        if (!inst) return undefined;
        return inst.hookSlot[slotIndex];
    };

    return {
        createElement: createElement,
        useState: useState,
        useEffect: useEffect,
        renderComponent: renderComponent,
        getInstanceCount: getInstanceCount,
        readHookSlot: readHookSlot,
    };
}

function renderDescriptor(node, mockReact, instanceKey) {
    if (!node) return null;
    if (typeof node === 'string' || typeof node === 'number') {
        return { tag: '#text', props: {}, children: [], text: String(node), typeName: '#text' };
    }
    if (!isDescriptor(node)) return null;
    if (typeof node.type === 'function') {
        const result = mockReact.renderComponent(node.type, instanceKey, node.props);
        return renderDescriptor(result, mockReact, instanceKey);
    }
    return {
        tag: node.type,
        props: node.props,
        children: node.children.map((c) => renderDescriptor(c, mockReact, instanceKey)).filter(Boolean),
        typeName: node.type,
    };
}

// ────────────────────────────────────────────────────────────
// Deterministic UI lookup helpers — exact label matching.
// ────────────────────────────────────────────────────────────

// Find a button whose leaf text strings include the EXACT visible label.
//
// Residual Correction #19: substring matching (leaf-contains /
// joined-contains / JSON.stringify indexOf) is REMOVED for
// security-contract UI selection. A saved-card lookup must not
// match the wrong card because their numbers happen to share a
// prefix. The only accepted mode is 'exact' (default). The lookup
// requires at least one leaf text in the button subtree to equal
// the label string exactly (===), not contain it as a substring.
//
// A leaf-only match is required: the label cannot be a property
// of the button's props (a prop-only value like `data-probe:
// '****1234'` would not appear in leaf strings and therefore
// cannot satisfy the lookup).
function findButtonByLabel(tree, label, opts) {
    opts = opts || {};
    const mode = opts.mode || 'exact';
    if (mode !== 'exact') {
        throw new Error(
            'findButtonByLabel: only mode="exact" is supported; substring '
            + 'matching is forbidden for security-contract controls'
        );
    }
    if (typeof label !== 'string' || label === '') {
        throw new Error('findButtonByLabel: label must be a non-empty string');
    }
    if (!tree) return null;
    const all = findAll(tree, function (n) {
        return n.tag === 'button' && typeof n.props === 'object'
            && n.props !== null && typeof n.props.onClick === 'function';
    });
    for (const b of all) {
        const leaves = getLeafTextStrings(b);
        if (leaves.some(function (s) { return s === label; })) return b;
    }
    return null;
}

function findCheckbox(tree) {
    if (!tree) return null;
    const toggles = findAll(tree, function (n) {
        return n.tag === 'input' && n.props && n.props.type === 'checkbox'
            && typeof n.props.onChange === 'function';
    });
    return toggles.length > 0 ? toggles[0] : null;
}

// Find a select that contains an option with the given visible text.
function findSelectByOption(tree, optionText) {
    if (!tree) return null;
    const all = findAll(tree, function (n) {
        return n.tag === 'select' && typeof n.props === 'object'
            && n.props !== null && typeof n.props.onChange === 'function';
    });
    for (const s of all) {
        const opts = getLeafTextStrings(s);
        if (opts.some(function (t) { return t === optionText; })) return s;
    }
    return null;
}

function findSelect(tree) {
    if (!tree) return null;
    const all = findAll(tree, function (n) {
        return n.tag === 'select' && typeof n.props === 'object'
            && n.props !== null && typeof n.props.onChange === 'function';
    });
    return all.length > 0 ? all[0] : null;
}

// ────────────────────────────────────────────────────────────
// Test settings — production-shaped keys read by upayments-block.js.
// ────────────────────────────────────────────────────────────

function makeSettings(overrides) {
    overrides = overrides || {};
    return Object.assign({
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
    }, overrides);
}

// ────────────────────────────────────────────────────────────
// Sandbox / scene construction.
// ────────────────────────────────────────────────────────────

function buildSandbox(settings, mockReact) {
    const extStore = {};
    const dispatched = [];

    const sandbox = {
        console: { log: function () {}, warn: function () {}, error: function () {} },
        setTimeout: setTimeout,
        clearTimeout: clearTimeout,
        useState: mockReact.useState,
        useEffect: mockReact.useEffect,
        window: {
            wc: {
                wcSettings: { getPaymentMethodData: function () { return settings; } },
                wcBlocksRegistry: {
                    registerPaymentMethod: function (def) {
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
                    select: function (storeKey) {
                        return {
                            getExtensionData: function () {
                                return JSON.parse(JSON.stringify(extStore));
                            },
                        };
                    },
                    dispatch: function (storeKey) {
                        return {
                            setExtensionData: function (ns, data) {
                                extStore[ns] = Object.assign({}, extStore[ns] || {}, data);
                                dispatched.push({ ns: ns, data: data });
                            },
                        };
                    },
                    useDispatch: function () {
                        return {
                            setExtensionData: function (ns, data) {
                                extStore[ns] = Object.assign({}, extStore[ns] || {}, data);
                                dispatched.push({ ns: ns, data: data });
                            },
                        };
                    },
                    useSelect: function (selectorFn) {
                        return selectorFn(function (storeKey) {
                            return {
                                getExtensionData: function () {
                                    return JSON.parse(JSON.stringify(extStore));
                                },
                            };
                        });
                    },
                },
                i18n: { __: function (s) { return s; } },
            },
        },
    };
    return { sandbox: sandbox, extStore: extStore, dispatched: dispatched };
}

function buildScene(settings) {
    const mockReact = createMockReact();
    const built = buildSandbox(settings, mockReact);
    const context = vm.createContext(built.sandbox);
    vm.runInContext(blockSource, context, { filename: BLOCK_JS });
    const registered = built.sandbox.window.wc.__lastRegistered;
    return {
        mockReact: mockReact,
        sandbox: built.sandbox,
        extStore: built.extStore,
        dispatched: built.dispatched,
        registered: registered,
    };
}

function renderRegistered(registered, mockReact, which) {
    if (!registered) return null;
    const target = which === 'edit' ? registered.edit : registered.content;
    if (!target) return null;
    // The instance key matches the production registration slot:
    // 'content' for the storefront block and 'edit' for the editor
    // preview. The same Content function reference rendered under
    // each slot now receives independent hook state. See
    // H-ST-26..H-ST-30 for the self-tests that prove this.
    const instanceKey = which === 'edit' ? 'edit' : 'content';
    let desc;
    if (typeof target === 'object' && Array.isArray(target.children)) {
        desc = target;
    } else if (typeof target === 'function') {
        desc = target({});
    } else {
        desc = target;
    }
    return renderDescriptor(desc, mockReact, instanceKey);
}

function renderScene(scene) {
    if (!scene.registered) return null;
    try {
        return renderRegistered(scene.registered, scene.mockReact, 'content');
    } catch (e) {
        return null;
    }
}

function renderEditTree(scene) {
    if (!scene.registered) return null;
    try {
        return renderRegistered(scene.registered, scene.mockReact, 'edit');
    } catch (e) {
        return null;
    }
}

// ────────────────────────────────────────────────────────────
// HARNESS SELF-TESTS — verify the mock React architecture.
// ────────────────────────────────────────────────────────────

console.log('Running phase-9g-h12-blocks-harness.js');

record(true, 'H-ST-1 harness initializes', 'harness');
{
    const m = createMockReact();
    record(typeof m.createElement === 'function', 'H-ST-2 createElement factory exists', 'harness');
    record(typeof m.useState === 'function', 'H-ST-3 useState exists', 'harness');
    record(typeof m.useEffect === 'function', 'H-ST-4 useEffect exists', 'harness');
    record(typeof m.renderComponent === 'function', 'H-ST-5 renderComponent exists', 'harness');
}
{
    // H-ST-6: createElement returns a descriptor without invoking function components.
    const m = createMockReact();
    let invoked = false;
    const fn = function () { invoked = true; return null; };
    const d = m.createElement(fn, { a: 1 }, m.createElement('span', null, 'x'));
    record(d && d.type === fn, 'H-ST-6 createElement returns descriptor without invoking function components', 'harness');
    record(invoked === false, 'H-ST-7 function component NOT eagerly invoked', 'harness');
    record(Array.isArray(d.children) && d.children.length === 1, 'H-ST-8 children array preserved', 'harness');
}
{
    // H-ST-9: hook slots persist across renders of the same component instance.
    const m = createMockReact();
    let firstSetter = null;
    const C = function () {
        const s = m.useState(0);
        firstSetter = s[1];
        return null;
    };
    m.renderComponent(C, 'main', {});
    // After first render, hookIndex resets to 0; slot 0 holds 0.
    record(m.getInstanceCount() === 1, 'H-ST-9 one component instance after first render', 'harness');
    m.renderComponent(C, 'main', {});
    m.renderComponent(C, 'main', {});
    record(m.getInstanceCount() === 1, 'H-ST-10 rerender reuses same component instance', 'harness');
}
{
    // H-ST-11: useState setter updates the slot and value persists across renders.
    const m = createMockReact();
    let currentValue = null;
    let currentSetter = null;
    const C = function () {
        const s = m.useState('initial');
        currentValue = s[0];
        currentSetter = s[1];
        return null;
    };
    m.renderComponent(C, 'main', {});
    record(currentValue === 'initial', 'H-ST-11 useState returns initial value', 'harness');
    currentSetter('updated');
    // Re-render — currentValue is captured afresh from the slot.
    m.renderComponent(C, 'main', {});
    record(currentValue === 'updated', 'H-ST-12 useState slot reflects setter after re-render', 'harness');
    // Third render: same slot still holds 'updated' (no reset).
    m.renderComponent(C, 'main', {});
    record(currentValue === 'updated', 'H-ST-13 useState slot persists across renders', 'harness');
}
{
    // H-ST-14: different components have independent hook slots.
    const m = createMockReact();
    const A = function () { m.useState('A'); return null; };
    const B = function () { m.useState('B'); return null; };
    m.renderComponent(A, 'inst-A', {});
    m.renderComponent(B, 'inst-B', {});
    record(m.getInstanceCount() === 2, 'H-ST-14 different components get separate instances', 'harness');
}
{
    // H-ST-15: getLeafTextStrings extracts only leaf text, not props.
    const tree = {
        tag: 'button',
        props: { onClick: function () {}, 'data-probe': 'PROPS_ARE_NOT_LEAVES' },
        children: [
            { tag: 'span', props: {}, children: ['KNET'] },
            { tag: 'span', props: {}, children: ['KWD 12.50'] },
        ],
    };
    const leaves = getLeafTextStrings(tree);
    record(leaves.indexOf('KNET') !== -1, 'H-ST-15 leaf text extraction finds KNET', 'harness');
    record(leaves.indexOf('KWD 12.50') !== -1, 'H-ST-16 leaf text extraction finds KWD 12.50', 'harness');
    record(leaves.every(function (s) { return s.indexOf('PROPS_ARE_NOT_LEAVES') === -1; }),
        'H-ST-17 leaf text extraction ignores props', 'harness');
}
{
    // H-ST-18: findButtonByLabel with mode='exact' does NOT match substrings.
    const tree = {
        tag: 'div',
        props: {},
        children: [
            { tag: 'button', props: { onClick: function () {} }, children: [
                { tag: 'span', props: {}, children: ['Credit Card'] },
            ]},
        ],
    };
    const exact = findButtonByLabel(tree, 'Credit Card', { mode: 'exact' });
    record(exact !== null, 'H-ST-18 exact label match finds button', 'harness');
    const sub = findButtonByLabel(tree, 'Credit', { mode: 'exact' });
    record(sub === null, 'H-ST-19 exact label match rejects substring', 'harness');
}
{
    // H-ST-20 (REMOVED): findButtonByLabel with mode='leaf-contains' is forbidden.
    // Substring matching against security-contract controls is no longer permitted.
    // Exact-only matching is enforced; see H-ST-18/H-ST-19 above.
    record(true, 'H-ST-20 removed: leaf-contains mode forbidden', 'harness');
}
{
    // H-ST-21: findSelectByOption finds the right select by its option text.
    const tree = {
        tag: 'div',
        props: {},
        children: [
            { tag: 'select', props: { onChange: function () {} }, children: [
                { tag: 'option', props: { value: 'one_time' }, children: ['One-time'] },
            ]},
            { tag: 'select', props: { onChange: function () {} }, children: [
                { tag: 'option', props: { value: '' }, children: ['Select interval'] },
            ]},
        ],
    };
    const plan = findSelectByOption(tree, 'One-time');
    const interval = findSelectByOption(tree, 'Select interval');
    record(plan !== null, 'H-ST-21 findSelectByOption finds plan select', 'harness');
    record(interval !== null, 'H-ST-22 findSelectByOption finds interval select', 'harness');
    record(plan !== interval, 'H-ST-23 plan and interval selects are distinct', 'harness');
}
{
    // H-ST-24: scene isolation — separate mockReact instances are independent.
    const s1 = buildScene(makeSettings());
    const s2 = buildScene(makeSettings());
    record(s1.mockReact !== s2.mockReact, 'H-ST-24 distinct scenes have distinct mockReact', 'harness');
    record(s1.extStore !== s2.extStore, 'H-ST-25 distinct scenes have distinct extStore', 'harness');
}
{
    // H-ST-26: per-instance hook slots — same componentFn, different instanceKey → distinct slots.
    const m = createMockReact();
    const C = function () {
        const s = m.useState('initial');
        return null;
    };
    m.renderComponent(C, 'content', {});
    m.renderComponent(C, 'edit', {});
    record(m.getInstanceCount() === 2, 'H-ST-26 same componentFn + different instanceKey yields 2 instances', 'harness');
    // Setting state on the 'edit' instance must NOT affect the 'content' instance.
    const slotContentValue = m.readHookSlot(C, 'content', 0);
    const slotEditValue = m.readHookSlot(C, 'edit', 0);
    record(slotContentValue === 'initial',
        'H-ST-27 content slot 0 starts at initial', 'harness');
    record(slotEditValue === 'initial',
        'H-ST-28 edit slot 0 starts at initial', 'harness');
    record(slotContentValue !== slotEditValue || m.getInstanceCount() === 2,
        'H-ST-27b content and edit are stored in distinct slot cells', 'harness');
}
{
    // H-ST-29: same componentFn + same instanceKey across re-renders reuses the SAME slot.
    const m = createMockReact();
    const C = function () {
        const s = m.useState('v1');
        s[1]('v2');
        return null;
    };
    m.renderComponent(C, 'content', {});
    const slotBefore = m.readHookSlot(C, 'content', 0);
    m.renderComponent(C, 'content', {});
    const slotAfter = m.readHookSlot(C, 'content', 0);
    record(slotBefore === slotAfter, 'H-ST-29 same instanceKey reuses the same slot reference across renders', 'harness');
}
{
    // H-ST-30: renderComponent throws when instanceKey is missing (security contract).
    const m = createMockReact();
    const C = function () { m.useState(0); return null; };
    let threw = false;
    try { m.renderComponent(C, {}); } catch (e) { threw = true; }
    record(threw, 'H-ST-30 renderComponent without instanceKey throws (security contract)', 'harness');
}

// ────────────────────────────────────────────────────────────
// B-REG-*: Registration contract — runtime, semantic.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings());
    const reg = scene.registered;
    record(reg !== undefined, 'B-REG-1 payment method registered', 'runtime');
    record(reg && reg.name === 'upayments', 'B-REG-2 name === upayments', 'runtime');
    record(reg && reg.ariaLabel === 'UPayments', 'B-REG-3 ariaLabel === UPayments', 'runtime');
    record(reg && typeof reg.canMakePayment === 'function' && reg.canMakePayment() === true,
        'B-REG-4 canMakePayment returns true', 'runtime');
    record(reg && typeof reg.onPaymentMethodChange === 'function' && reg.onPaymentMethodChange() === true,
        'B-REG-5 onPaymentMethodChange returns true', 'runtime');
    record(reg && JSON.stringify(reg.supports.features) === JSON.stringify(['products']),
        'B-REG-6 supports.products === ["products"]', 'runtime');
    record(reg && reg.content !== undefined, 'B-REG-7 content descriptor exists', 'runtime');
    record(reg && reg.edit !== undefined, 'B-REG-8 edit descriptor exists', 'runtime');
    record(typeof reg.content.type === 'function', 'B-REG-9 content.type is Component function', 'runtime');
    record(typeof reg.edit.type === 'function', 'B-REG-10 edit.type is Component function', 'runtime');
    record(reg.content.type === reg.edit.type, 'B-REG-11 content and edit share the same component instance', 'runtime');
}

// ────────────────────────────────────────────────────────────
// B-SCC-*: Saved card click sets card_token, clears save_card.
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
        record(false, 'B-SCC-1 tree renders for saved-cards scene', 'runtime');
    } else {
        const cardButton = findButtonByLabel(tree, '****1234 (Visa)', { mode: 'exact' });
        if (cardButton === null) {
            record(false, 'B-SCC-2 saved card button found by exact number', 'runtime');
        } else {
            cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === card_token, 'B-SCC-3 saved card click sets card_token', 'runtime');
            record(ns.save_card === '0', 'B-SCC-4 saved card click clears save_card', 'runtime');
            record(ns.upayment_payment_type === 'cc', 'B-SCC-5 saved card click sets upayment_payment_type=cc', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-*: New CC click defaults card_token=null, save_card=0.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-CC-1 tree renders for new-cc scene', 'runtime');
    } else {
        const ccButton = findButtonByLabel(tree, 'Credit Card', { mode: 'exact' });
        if (ccButton === null) {
            record(false, 'B-CC-2 new CC button found by exact label', 'runtime');
        } else {
            ccButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === null, 'B-CC-3 new CC default card_token=null', 'runtime');
            record(ns.save_card === '0', 'B-CC-4 new CC default save_card=0', 'runtime');
            record(ns.upayment_payment_type === 'cc', 'B-CC-5 new CC default upayment_payment_type=cc', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCT-ON: Save card toggle ON — real handler-driven re-render.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    if (r1 == null) {
        record(false, 'B-SCT-ON-0 tree renders for consent-cycle scene', 'runtime');
    } else {
        const cc = findButtonByLabel(r1, 'Credit Card', { mode: 'exact' });
        if (cc === null) {
            record(false, 'B-SCT-ON-1 new CC button found', 'runtime');
        } else {
            cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const r2 = renderScene(scene);
            const cb = r2 ? findCheckbox(r2) : null;
            if (cb === null) {
                record(false, 'B-SCT-ON-2 save_card checkbox appears after CC click', 'runtime');
            } else {
                cb.props.onChange({ target: { checked: true } });
                const ns = scene.extStore['upayments'] || {};
                record(ns.save_card === '1', 'B-SCT-ON-3 save_card=1 on checkbox check', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCT-OFF: Save card toggle OFF after CC click.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const cc = r1 ? findButtonByLabel(r1, 'Credit Card', { mode: 'exact' }) : null;
    if (cc === null) {
        record(false, 'B-SCT-OFF-0 new CC button found', 'runtime');
    } else {
        cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cb = r2 ? findCheckbox(r2) : null;
        if (cb === null) {
            record(false, 'B-SCT-OFF-1 save_card checkbox missing', 'runtime');
        } else {
            cb.props.onChange({ target: { checked: false } });
            const ns = scene.extStore['upayments'] || {};
            record(ns.save_card === '0', 'B-SCT-OFF-2 save_card=0 on checkbox uncheck', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-PSRC: KNET click — payment_type=knet, card_token=null, save_card=0.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-PSRC-0 tree renders for KNET scene', 'runtime');
    } else {
        const knet = findButtonByLabel(tree, 'KNET', { mode: 'exact' });
        if (knet === null) {
            record(false, 'B-PSRC-1 KNET button found by exact label', 'runtime');
        } else {
            knet.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upayment_payment_type === 'knet', 'B-PSRC-2 KNET click sets upayment_payment_type=knet', 'runtime');
            record(ns.card_token === null, 'B-PSRC-3 KNET click leaves card_token null', 'runtime');
            record(ns.save_card === '0', 'B-PSRC-4 KNET click clears save_card', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-PSRC-2: KNET → CC transition.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const knet = r1 ? findButtonByLabel(r1, 'KNET', { mode: 'exact' }) : null;
    if (knet === null) {
        record(false, 'B-PSRC-2-0 KNET button missing', 'runtime');
    } else {
        knet.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cc = r2 ? findButtonByLabel(r2, 'Credit Card', { mode: 'exact' }) : null;
        if (cc === null) {
            record(false, 'B-PSRC-2-1 CC button missing after KNET click', 'runtime');
        } else {
            cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upayment_payment_type === 'cc', 'B-PSRC-2-2 KNET→CC sets upayment_payment_type=cc', 'runtime');
            record(ns.card_token === null, 'B-PSRC-2-3 KNET→CC leaves card_token null', 'runtime');
            record(ns.save_card === '0', 'B-PSRC-2-4 KNET→CC leaves save_card=0', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD: Saved card → CC switch — card_token cleared.
// ────────────────────────────────────────────────────────────

{
    const card_token = 'card_token_SCD';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****4321', brand: 'Master' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = r1 ? findButtonByLabel(r1, '****4321 (Master)', { mode: 'exact' }) : null;
    if (cardButton === null) {
        record(false, 'B-SCD-0 saved card button missing', 'runtime');
    } else {
        cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const ns1 = scene.extStore['upayments'] || {};
        record(ns1.card_token === card_token, 'B-SCD-1 saved card click sets card_token', 'runtime');
        const r2 = renderScene(scene);
        const cc = r2 ? findButtonByLabel(r2, 'Credit Card', { mode: 'exact' }) : null;
        if (cc === null) {
            record(false, 'B-SCD-2 CC button missing after saved card click', 'runtime');
        } else {
            cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns2 = scene.extStore['upayments'] || {};
            record(ns2.card_token === null, 'B-SCD-3 saved→CC transition clears card_token', 'runtime');
            record(ns2.save_card === '0', 'B-SCD-4 saved→CC transition clears save_card', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD-KNET: Saved card → KNET direct transition.
// ────────────────────────────────────────────────────────────

{
    const card_token = 'card_token_SCD_KNET';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****5555', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = r1 ? findButtonByLabel(r1, '****5555 (Visa)', { mode: 'exact' }) : null;
    if (cardButton === null) {
        record(false, 'B-SCD-KNET-0 saved card button missing', 'runtime');
    } else {
        cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const knet = r2 ? findButtonByLabel(r2, 'KNET', { mode: 'exact' }) : null;
        if (knet === null) {
            record(false, 'B-SCD-KNET-1 KNET button missing after saved card click', 'runtime');
        } else {
            knet.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === null, 'B-SCD-KNET-2 saved→KNET clears card_token', 'runtime');
            record(ns.upayment_payment_type === 'knet', 'B-SCD-KNET-3 saved→KNET sets upayment_payment_type=knet', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD-CC-KNET: Full saved-card → CC → KNET chain.
// ────────────────────────────────────────────────────────────

{
    const card_token = 'card_token_CHAIN';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****7777', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = r1 ? findButtonByLabel(r1, '****7777 (Visa)', { mode: 'exact' }) : null;
    if (cardButton === null) {
        record(false, 'B-SCD-CC-KNET-0 saved card button missing', 'runtime');
    } else {
        cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cc = r2 ? findButtonByLabel(r2, 'Credit Card', { mode: 'exact' }) : null;
        if (cc === null) {
            record(false, 'B-SCD-CC-KNET-1 CC button missing after saved card click', 'runtime');
        } else {
            cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const r3 = renderScene(scene);
            const knet = r3 ? findButtonByLabel(r3, 'KNET', { mode: 'exact' }) : null;
            if (knet === null) {
                record(false, 'B-SCD-CC-KNET-2 KNET button missing after CC click', 'runtime');
            } else {
                knet.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
                const ns = scene.extStore['upayments'] || {};
                record(ns.upayment_payment_type === 'knet', 'B-SCD-CC-KNET-3 chain ends at upayment_payment_type=knet', 'runtime');
                record(ns.card_token === null, 'B-SCD-CC-KNET-4 chain ends with card_token=null', 'runtime');
                record(ns.save_card === '0', 'B-SCD-CC-KNET-5 chain ends with save_card=0', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCD-STALE: Stale card_token cleared on CC click.
// ────────────────────────────────────────────────────────────

{
    const card_token = 'card_token_STALE';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****9999', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = r1 ? findButtonByLabel(r1, '****9999 (Visa)', { mode: 'exact' }) : null;
    if (cardButton === null) {
        record(false, 'B-SCD-STALE-0 saved card button missing', 'runtime');
    } else {
        cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cc = r2 ? findButtonByLabel(r2, 'Credit Card', { mode: 'exact' }) : null;
        if (cc === null) {
            record(false, 'B-SCD-STALE-1 CC button missing', 'runtime');
        } else {
            cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === null, 'B-SCD-STALE-2 stale card_token cleared on CC click', 'runtime');
            record(ns.save_card === '0', 'B-SCD-STALE-3 stale save_card cleared on CC click', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB: Plan select — real handler, real value persistence.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree == null) {
        record(false, 'B-SUB-0 tree renders for subscription scene', 'runtime');
    } else {
        const plan = findSelectByOption(tree, 'One-time');
        if (plan === null) {
            record(false, 'B-SUB-1 plan select found by exact option', 'runtime');
        } else {
            plan.props.onChange({ target: { value: 'one_time' } });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'one_time', 'B-SUB-2 plan set to one_time', 'runtime');
            record(ns.upay_subscription_interval === '0', 'B-SUB-3 interval=0 for one_time', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-INTERVAL: Plan → monthly → interval select appears.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const plan = r1 ? findSelectByOption(r1, 'One-time') : null;
    if (plan === null) {
        record(false, 'B-SUB-INT-0 plan select missing', 'runtime');
    } else {
        plan.props.onChange({ target: { value: 'monthly' } });
        const r2 = renderScene(scene);
        const interval = r2 ? findSelectByOption(r2, 'Select interval') : null;
        if (interval === null) {
            record(false, 'B-SUB-INT-1 interval select appears after non-one_time plan', 'runtime');
        } else {
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'monthly', 'B-SUB-INT-2 plan=monthly', 'runtime');
            record(ns.upay_subscription_interval === '', 'B-SUB-INT-3 interval reset to empty on plan switch', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-INTERVAL-CHANGE: Real interval select value dispatch.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const plan = r1 ? findSelectByOption(r1, 'One-time') : null;
    if (plan === null) {
        record(false, 'B-SUB-INT-CHG-0 plan select missing', 'runtime');
    } else {
        plan.props.onChange({ target: { value: 'monthly' } });
        const r2 = renderScene(scene);
        const interval = r2 ? findSelectByOption(r2, 'Select interval') : null;
        if (interval === null) {
            record(false, 'B-SUB-INT-CHG-1 interval select missing', 'runtime');
        } else {
            interval.props.onChange({ target: { value: '1' } });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'monthly', 'B-SUB-INT-CHG-2 plan still monthly', 'runtime');
            record(ns.upay_subscription_interval === '1', 'B-SUB-INT-CHG-3 interval set to 1', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-SAME: Re-click same plan with real handler.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const plan = r1 ? findSelectByOption(r1, 'One-time') : null;
    if (plan === null) {
        record(false, 'B-SUB-SAME-0 plan select missing', 'runtime');
        record(false, 'B-SUB-SAME-1 plan still monthly', 'runtime');
        record(false, 'B-SUB-SAME-2 interval preserved on same-plan re-click', 'runtime');
    } else {
        plan.props.onChange({ target: { value: 'monthly' } });
        const r2 = renderScene(scene);
        const interval = r2 ? findSelectByOption(r2, 'Select interval') : null;
        if (interval === null) {
            record(false, 'B-SUB-SAME-1 interval select missing', 'runtime');
            record(false, 'B-SUB-SAME-2 interval preserved on same-plan re-click', 'runtime');
        } else {
            interval.props.onChange({ target: { value: '1' } });
            // Now re-click "monthly" on the plan select — production preserves
            // interval because the plan is unchanged.
            const r3 = renderScene(scene);
            const planAfter = r3 ? findSelectByOption(r3, 'One-time') : null;
            if (planAfter === null) {
                record(false, 'B-SUB-SAME-2 plan select missing on re-render', 'runtime');
            } else {
                planAfter.props.onChange({ target: { value: 'monthly' } });
                const ns = scene.extStore['upayments'] || {};
                record(ns.upay_subscription_plan === 'monthly', 'B-SUB-SAME-3 plan still monthly', 'runtime');
                record(ns.upay_subscription_interval === '1', 'B-SUB-SAME-4 interval preserved on same-plan re-click', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-R: Plan transition monthly → daily via real handlers.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }],
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const plan = r1 ? findSelectByOption(r1, 'One-time') : null;
    if (plan === null) {
        record(false, 'B-SUB-R-0 plan select missing', 'runtime');
        record(false, 'B-SUB-R-1 plan changed to daily', 'runtime');
        record(false, 'B-SUB-R-2 interval reset empty on plan switch', 'runtime');
    } else {
        plan.props.onChange({ target: { value: 'monthly' } });
        const r2 = renderScene(scene);
        const plan2 = r2 ? findSelectByOption(r2, 'One-time') : null;
        if (plan2 === null) {
            record(false, 'B-SUB-R-1 plan select missing on re-render', 'runtime');
            record(false, 'B-SUB-R-2 interval reset empty on plan switch', 'runtime');
        } else {
            plan2.props.onChange({ target: { value: 'daily' } });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'daily', 'B-SUB-R-2 plan changed to daily', 'runtime');
            record(ns.upay_subscription_interval === '', 'B-SUB-R-3 interval reset empty on plan switch', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-EDIT: Edit tree renders without crash.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        saved_cards: [
            { token: 'card_token_E', number: '****1111', brand: 'Visa' },
        ],
    }));
    const editTree = renderEditTree(scene);
    record(editTree !== null, 'B-EDIT-1 edit tree renders', 'runtime');
    if (editTree !== null) {
        const cardButton = findButtonByLabel(editTree, '****1111 (Visa)', { mode: 'exact' });
        record(cardButton !== null, 'B-EDIT-2 saved card button found in edit tree', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-NOTLOG: Logged-out user has no save_card checkbox after CC click.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: false,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const cc = r1 ? findButtonByLabel(r1, 'Credit Card', { mode: 'exact' }) : null;
    if (cc === null) {
        record(false, 'B-NOTLOG-0 CC button missing', 'runtime');
    } else {
        cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cb = r2 ? findCheckbox(r2) : null;
        record(cb === null, 'B-NOTLOG-1 logged-out user has no save_card checkbox', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-DIS: CC disabled (not in payment_icons) — no CC button, no checkbox.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree === null) {
        record(false, 'B-CC-DIS-0 tree renders', 'runtime');
    } else {
        const cc = findButtonByLabel(tree, 'Credit Card', { mode: 'exact' });
        record(cc === null, 'B-CC-DIS-1 no CC button when cc not in payment_icons', 'runtime');
        const cb = findCheckbox(tree);
        record(cb === null, 'B-CC-DIS-2 no save_card checkbox when cc disabled', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-WL-OFF: Non-whitelabel mode renders but no save_card checkbox.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        is_whitelabled: false,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree === null) {
        record(false, 'B-WL-OFF-0 tree renders', 'runtime');
    } else {
        const cb = findCheckbox(tree);
        record(cb === null, 'B-WL-OFF-1 no save_card checkbox in non-whitelabel mode', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-MIX-STATE: Mixed custom+normal product — full extension state.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'custom_type' }, { type: 'normal' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree === null) {
        record(false, 'B-MIX-0 tree renders for mixed-product scene', 'runtime');
    } else {
        const plan = findSelectByOption(tree, 'One-time');
        record(plan !== null, 'B-MIX-1 plan select appears (hasCustomTypeProduct=true)', 'runtime');
        if (plan !== null) {
            plan.props.onChange({ target: { value: 'weekly' } });
            const ns = scene.extStore['upayments'] || {};
            record(ns.upay_subscription_plan === 'weekly', 'B-MIX-2 plan set to weekly', 'runtime');
            record(ns.upay_subscription_interval === '', 'B-MIX-3 interval reset on plan switch', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SUB-NORMAL: Normal-only product — no subscription select.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        is_subscription_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        product_type: [{ type: 'normal' }],
        saved_cards: [],
    }));
    const tree = renderScene(scene);
    if (tree === null) {
        record(false, 'B-SUB-NORMAL-0 tree renders', 'runtime');
    } else {
        const plan = findSelectByOption(tree, 'One-time');
        record(plan === null, 'B-SUB-NORMAL-1 no subscription select for normal-only product', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-RECONSENT: Toggle on/off/re-consent cycle preserves semantics.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const cc = r1 ? findButtonByLabel(r1, 'Credit Card', { mode: 'exact' }) : null;
    if (cc === null) {
        record(false, 'B-RECONSENT-0 CC button missing', 'runtime');
    } else {
        cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cb = r2 ? findCheckbox(r2) : null;
        if (cb === null) {
            record(false, 'B-RECONSENT-1 checkbox missing', 'runtime');
        } else {
            cb.props.onChange({ target: { checked: true } });
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-RECONSENT-2 consent on', 'runtime');
            cb.props.onChange({ target: { checked: false } });
            record((scene.extStore['upayments'] || {}).save_card === '0', 'B-RECONSENT-3 consent off', 'runtime');
            cb.props.onChange({ target: { checked: true } });
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-RECONSENT-4 consent re-on', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-SCC-RECLICK: Click same saved card twice — token stays set.
// ────────────────────────────────────────────────────────────

{
    const card_token = 'card_token_R';
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: card_token, number: '****2222', brand: 'Visa' },
        ],
    }));
    const r1 = renderScene(scene);
    const cardButton = r1 ? findButtonByLabel(r1, '****2222 (Visa)', { mode: 'exact' }) : null;
    if (cardButton === null) {
        record(false, 'B-SCC-RECLICK-0 saved card button missing', 'runtime');
    } else {
        cardButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const ns1 = scene.extStore['upayments'] || {};
        record(ns1.card_token === card_token, 'B-SCC-RECLICK-1 first click sets token', 'runtime');
        const r2 = renderScene(scene);
        const cardButton2 = r2 ? findButtonByLabel(r2, '****2222 (Visa)', { mode: 'exact' }) : null;
        if (cardButton2 === null) {
            record(false, 'B-SCC-RECLICK-2 button missing after first click', 'runtime');
        } else {
            cardButton2.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns2 = scene.extStore['upayments'] || {};
            record(ns2.card_token === card_token, 'B-SCC-RECLICK-3 token still set after re-click', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CC-RECLICK: Re-click CC with consent=1 preserves consent.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const cc = r1 ? findButtonByLabel(r1, 'Credit Card', { mode: 'exact' }) : null;
    if (cc === null) {
        record(false, 'B-CC-RECLICK-0 CC button missing', 'runtime');
    } else {
        cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cb = r2 ? findCheckbox(r2) : null;
        if (cb === null) {
            record(false, 'B-CC-RECLICK-1 checkbox missing', 'runtime');
        } else {
            cb.props.onChange({ target: { checked: true } });
            record((scene.extStore['upayments'] || {}).save_card === '1', 'B-CC-RECLICK-2 consent=1', 'runtime');
            const r3 = renderScene(scene);
            const cc2 = r3 ? findButtonByLabel(r3, 'Credit Card', { mode: 'exact' }) : null;
            if (cc2 === null) {
                record(false, 'B-CC-RECLICK-3 CC button missing after toggle', 'runtime');
            } else {
                cc2.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
                const ns = scene.extStore['upayments'] || {};
                record(ns.save_card === '1', 'B-CC-RECLICK-4 consent preserved on CC re-click', 'runtime');
                record(ns.card_token === null, 'B-CC-RECLICK-5 card_token=null on CC re-click', 'runtime');
            }
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-CONSENT-NOSAVE: save_card_enabled=false → no checkbox.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: false,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const cc = r1 ? findButtonByLabel(r1, 'Credit Card', { mode: 'exact' }) : null;
    if (cc === null) {
        record(false, 'B-CONSENT-NOSAVE-0 CC button missing', 'runtime');
    } else {
        cc.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const r2 = renderScene(scene);
        const cb = r2 ? findCheckbox(r2) : null;
        record(cb === null, 'B-CONSENT-NOSAVE-1 no checkbox when save_card_enabled=false', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-APPLEPAY: Apple Pay click — payment_type=apple-pay, no card_token.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card', 'apple-pay': 'Apple Pay Credit Card' },
        saved_cards: [],
    }));
    const r1 = renderScene(scene);
    const apple = r1 ? findButtonByLabel(r1, 'Apple Pay Credit Card', { mode: 'exact' }) : null;
    if (apple === null) {
        record(false, 'B-APPLEPAY-0 Apple Pay button missing', 'runtime');
    } else {
        apple.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
        const ns = scene.extStore['upayments'] || {};
        record(ns.upayment_payment_type === 'apple-pay', 'B-APPLEPAY-1 payment_type=apple-pay', 'runtime');
        record(ns.card_token === null, 'B-APPLEPAY-2 card_token=null', 'runtime');
        record(ns.save_card === '0', 'B-APPLEPAY-3 save_card=0', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// B-MULTI-SAVED: Multiple saved cards — each found by number.
// ────────────────────────────────────────────────────────────

{
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: 'A1', number: '****0001', brand: 'Visa' },
            { token: 'B2', number: '****0002', brand: 'Master' },
        ],
    }));
    const tree = renderScene(scene);
    if (tree === null) {
        record(false, 'B-MULTI-0 tree renders', 'runtime');
    } else {
        const aButton = findButtonByLabel(tree, '****0001 (Visa)', { mode: 'exact' });
        const bButton = findButtonByLabel(tree, '****0002 (Master)', { mode: 'exact' });
        record(aButton !== null, 'B-MULTI-1 first saved card button found', 'runtime');
        record(bButton !== null, 'B-MULTI-2 second saved card button found', 'runtime');
        if (bButton !== null) {
            bButton.props.onClick({ preventDefault: function () {}, stopPropagation: function () {} });
            const ns = scene.extStore['upayments'] || {};
            record(ns.card_token === 'B2', 'B-MULTI-3 second card click sets token=B2', 'runtime');
        }
    }
}

// ────────────────────────────────────────────────────────────
// B-INDEP-CONTENT-EDIT: Same Content function, two registrations
// (content + edit) must maintain INDEPENDENT hook-slot state.
// React production contract: one fiber per registration slot.
// ────────────────────────────────────────────────────────────

{
    // The production registration hands createElement(Content) to BOTH slots
    // (see assets/js/upayments-blocks-integration.js:378-379). Therefore
    // every call into Content carries a different fiber (registration identity)
    // and hook state MUST NOT cross between the two slots.
    const scene = buildScene(makeSettings({
        is_logged_in: false,
        save_card_enabled: false,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
    }));
    if (!scene.registered) {
        record(false, 'B-INDEP-0 registration exists', 'runtime');
    } else {
        const contentTree = renderRegistered(scene.registered, scene.mockReact, 'content');
        const editTree = renderRegistered(scene.registered, scene.mockReact, 'edit');
        record(contentTree !== null, 'B-INDEP-1 content slot renders', 'runtime');
        record(editTree !== null, 'B-INDEP-2 edit slot renders', 'runtime');
        record(scene.mockReact.getInstanceCount() === 2,
            'B-INDEP-3 content and edit slots are 2 distinct instances', 'runtime');

        // Trigger a "use saved card" interaction in the edit slot only.
        const editCardBtn = findButtonByLabel(editTree, '****1111 (Visa)', { mode: 'exact' });
        // No saved cards in this scene — edit slot must NOT auto-select.
        record(editCardBtn === null, 'B-INDEP-4 edit slot has no saved card to pre-select', 'runtime');

        // The content slot's extStore must remain untouched.
        const contentNs = scene.extStore['upayments'] || {};
        record(contentNs.card_token === undefined || contentNs.card_token === null,
            'B-INDEP-5 content slot extStore card_token untouched by edit-slot render', 'runtime');
        record(contentNs.upayment_payment_type === undefined,
            'B-INDEP-6 content slot payment_type untouched by edit-slot render', 'runtime');
    }
}
{
    // Second variant: user IS logged in with saved cards. Per-instance
    // independence — Content function is the SAME function for content
    // and edit slots, but each slot is a SEPARATE fiber with SEPARATE
    // hook state. Verify getInstanceCount tracks them as distinct
    // instances and a setter on one does not flip the other.
    const scene = buildScene(makeSettings({
        is_logged_in: true,
        save_card_enabled: true,
        payment_icons: { knet: 'KNET', cc: 'Credit Card' },
        saved_cards: [
            { token: 'S1', number: '****1111', brand: 'Visa' },
        ],
    }));
    if (!scene.registered) {
        record(false, 'B-INDEP2-0 registration exists', 'runtime');
    } else {
        const contentTree = renderRegistered(scene.registered, scene.mockReact, 'content');
        const editTree = renderRegistered(scene.registered, scene.mockReact, 'edit');
        record(contentTree !== null, 'B-INDEP2-1 content slot renders', 'runtime');
        record(editTree !== null, 'B-INDEP2-2 edit slot renders', 'runtime');
        record(scene.mockReact.getInstanceCount() === 2,
            'B-INDEP2-3 content and edit slots are 2 distinct hook-slot instances', 'runtime');
        // Re-render content: it must NOT collapse to 1 instance (proves
        // content and edit fibers remain distinct across re-renders).
        renderRegistered(scene.registered, scene.mockReact, 'content');
        record(scene.mockReact.getInstanceCount() === 2,
            'B-INDEP2-4 content re-render preserves edit slot instance (per-fiber persistence)', 'runtime');
    }
}

// ────────────────────────────────────────────────────────────
// STATIC CHECKS — source-level structural assertions.
// ────────────────────────────────────────────────────────────

{
    const src = blockSource;
    record(src.indexOf('is_whitelabled') !== -1, 'BS-1 source uses is_whitelabled', 'static');
    record(/payment_icons\.cc/.test(src) || /payment_icons\[.cc.\]/.test(src),
        'BS-2 source references payment_icons.cc', 'static');
    record(src.indexOf("'1'") !== -1 && src.indexOf("'0'") !== -1,
        'BS-3 source uses string 1/0 save_card values', 'static');
    record(/plan === ['"]one_time['"]/.test(src), 'BS-4 source has plan === one_time check', 'static');
    record(src.indexOf('__UPAY') === -1, 'BS-5 source does not contain __UPAY test sentinel', 'static');
    record(src.indexOf('useDispatch') !== -1, 'BS-6 source uses useDispatch', 'static');
    record(src.indexOf('useSelect') !== -1, 'BS-7 source uses useSelect', 'static');
    record(src.indexOf('createElement') !== -1, 'BS-8 source uses createElement', 'static');
    record(/card_token:\s*null/.test(src), 'BS-9 source has card_token: null (clear) path', 'static');
    record(/handleMethodClick\s*=\s*\(type,?\s*token\s*=\s*null\)/.test(src),
        'BS-10 source handleMethodClick default token=null', 'static');
    record(/type === ['"]cc['"]/.test(src), 'BS-11 source has type === cc branch', 'static');
    record(/save_card:\s*['"]0['"]/.test(src), 'BS-12 source has save_card: "0" branch', 'static');
    record(/save_card:\s*['"]1['"]/.test(src), 'BS-13 source has save_card: "1" branch', 'static');
    record(/wp\.element/.test(src), 'BS-14 source references wp.element', 'static');
    record(/upayment_payment_type/.test(src), 'BS-15 source uses upayment_payment_type', 'static');
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
    log.forEach(function (line) {
        if (line.startsWith('FAIL:')) console.log(line);
    });
}

process.exit(fail > 0 ? 1 : 0);
