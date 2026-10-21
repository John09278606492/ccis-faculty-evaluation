/**
 * Extra JavaScript completions.
 *
 * @codemirror/lang-javascript only knows the language keywords, so a student
 * writing `docum` gets nothing - the browser globals they actually use
 * (document, console, the DOM methods) are missing. This adds them.
 */
import { javascriptLanguage } from '@codemirror/lang-javascript'
import { htmlLanguage } from '@codemirror/lang-html'


/** The browser globals and the DOM members students reach for most. */
const GLOBALS = {
    document: 'The page the script runs in',
    window: 'The browser window',
    console: 'console.log(), console.error(), …',
    navigator: 'Information about the browser',
    location: 'The address of the page',
    history: 'The pages visited in this tab',
    localStorage: 'Data kept in the browser between visits',
    sessionStorage: 'Data kept until the tab is closed',
    fetch: 'fetch(url) - ask a server for something',
    setTimeout: 'setTimeout(fn, ms) - run fn later',
    setInterval: 'setInterval(fn, ms) - run fn repeatedly',
    clearTimeout: 'Cancel a setTimeout',
    clearInterval: 'Cancel a setInterval',
    requestAnimationFrame: 'Run fn before the next repaint',
    alert: 'Show a message',
    confirm: 'Ask a yes/no question',
    prompt: 'Ask for a line of text',
    JSON: 'JSON.parse() and JSON.stringify()',
    Math: 'Math.round(), Math.floor(), Math.random(), …',
    Date: 'The current date and time',
    Array: 'The Array constructor and its methods',
    Object: 'The Object constructor and its methods',
    String: 'The String constructor and its methods',
    Number: 'The Number constructor and its methods',
    Boolean: 'The Boolean constructor',
    RegExp: 'Regular expressions',
    Error: 'Error, TypeError, RangeError, …',
    Promise: 'Promises',
    Map: 'A key/value collection',
    Set: 'A collection of unique values',
    Symbol: 'Symbols',
    Proxy: 'A proxy for another object',
    Reflect: 'Reflection helpers',
    parseInt: 'Turn a string into a whole number',
    parseFloat: 'Turn a string into a decimal number',
    isNaN: 'Is this not a number?',
    encodeURIComponent: 'Escape a value for a URL',
    decodeURIComponent: 'Undo encodeURIComponent()',
    structuredClone: 'Copy a value deeply',
    queueMicrotask: 'Run fn after the current code',
}

const DOCUMENT_MEMBERS = {
    getElementById: 'Find an element by its id',
    querySelector: 'Find the first element that matches a CSS selector',
    querySelectorAll: 'Find every element that matches a CSS selector',
    createElement: 'Make a new element',
    createTextNode: 'Make a new text node',
    createDocumentFragment: 'Make a fragment to hold nodes',
    body: 'The <body> element',
    head: 'The <head> element',
    title: 'The page title',
    cookie: 'The cookies of this page',
    readyState: 'How far the page has loaded',
    addEventListener: 'Listen for something happening',
    removeEventListener: 'Stop listening',
    dispatchEvent: 'Send an event by hand',
    write: 'Write into the page',
    open: 'Open a new window or document',
    close: 'Close the document',
    forms: 'The forms on the page',
    links: 'The links on the page',
    images: 'The images on the page',
    activeElement: 'The element that has the focus',
    hasFocus: 'Does this element have the focus?',
    elementFromPoint: 'The element at a point on the page',
    getSelection: 'The text the user has selected',
    execCommand: 'Run a command on the selection',
}

const ELEMENT_MEMBERS = {
    textContent: 'The text inside the element',
    innerHTML: 'The markup inside the element',
    innerText: 'The visible text inside the element',
    value: 'The value of a form field',
    style: 'The inline styles of the element',
    classList: 'add(), remove(), toggle(), contains()',
    className: 'The classes of the element',
    id: 'The id of the element',
    tagName: 'The tag name, in upper case',
    children: 'The child elements',
    childNodes: 'The child nodes, including text',
    parentElement: 'The element that contains this one',
    nextElementSibling: 'The next element in the same parent',
    previousElementSibling: 'The element before this one',
    firstElementChild: 'The first child element',
    lastElementChild: 'The last child element',
    closest: 'Find the nearest ancestor that matches a selector',
    matches: 'Does this element match the selector?',
    append: 'Add a child',
    prepend: 'Add a child at the start',
    remove: 'Remove the element from its parent',
    replaceWith: 'Put something in the element\'s place',
    insertAdjacentHTML: 'Insert HTML relative to the element',
    setAttribute: 'Set an attribute',
    getAttribute: 'Read an attribute',
    hasAttribute: 'Is the attribute there?',
    removeAttribute: 'Remove an attribute',
    addEventListener: 'Listen for something happening on the element',
    removeEventListener: 'Stop listening',
    dispatchEvent: 'Send an event by hand',
    click: 'Click the element',
    focus: 'Give the element the focus',
    blur: 'Take the focus away',
    scrollIntoView: 'Scroll the element into view',
    getBoundingClientRect: 'The element\'s position and size',
    getAttributeNames: 'All the attribute names',
    dataset: 'The data-* attributes',
    offsetWidth: 'The width of the element',
    offsetHeight: 'The height of the element',
    clientWidth: 'The width of the visible part',
    clientHeight: 'The height of the visible part',
    scrollTop: 'How far the element is scrolled down',
    scrollLeft: 'How far the element is scrolled across',
    querySelector: 'Find the first element inside that matches a selector',
    querySelectorAll: 'Find every element inside that matches a selector',
}

const ARRAY_MEMBERS = {
    push: 'Add to the end',
    pop: 'Take off the end',
    shift: 'Take off the start',
    unshift: 'Add to the start',
    slice: 'A part of the array',
    splice: 'Remove or replace items',
    concat: 'Join two arrays',
    join: 'Turn the array into text',
    indexOf: 'Where an item is',
    includes: 'Is the item in the array?',
    find: 'The first item that matches',
    findIndex: 'Where the first matching item is',
    filter: 'The items that match',
    map: 'Turn each item into something',
    forEach: 'Do something with each item',
    reduce: 'Fold the array into one value',
    some: 'Is any item like this?',
    every: 'Are all the items like this?',
    sort: 'Put the items in order',
    reverse: 'Turn the array around',
    flat: 'Flatten nested arrays',
    flatMap: 'Map, then flatten',
    fill: 'Fill the array with a value',
    keys: 'The indexes',
    values: 'The items',
    entries: 'The index and item pairs',
    at: 'The item at a position',
}

/** What the student is typing, to decide which members to offer. */
function wordBefore(context) {
    const line = context.state.doc.lineAt(context.pos)
    const before = line.text.slice(0, context.pos - line.from)
    const match = before.match(/[\w$]*$/)
    return match ? match[0] : ''
}

/** The object the student is typing a member of, if any. */
function memberOwner(context) {
    const line = context.state.doc.lineAt(context.pos)
    const before = line.text.slice(0, context.pos - line.from)
    const match = before.match(/([\w$]+)\.([\w$]*)$/)
    return match ? match[1] : null
}

/**
 * @param {import('@codemirror/autocomplete').CompletionContext} context
 * @returns {import('@codemirror/autocomplete').CompletionResult | null}
 */
export function javascriptGlobals(context) {
    const owner = memberOwner(context)
    const typed = wordBefore(context)
    const from = context.pos - typed.length

    if (owner) {
        let members = null
        if (owner === 'document') {
            members = DOCUMENT_MEMBERS
        } else if (owner === 'console') {
            members = {
                log: 'Print a message',
                info: 'Print a message',
                warn: 'Print a warning',
                error: 'Print an error',
                debug: 'Print a message for debugging',
                table: 'Print a table',
                time: 'Start a timer',
                timeEnd: 'Stop a timer',
                clear: 'Clear the console',
            }
        } else if (owner === 'window') {
            members = { ...GLOBALS, ...DOCUMENT_MEMBERS }
        } else if (owner === 'localStorage' || owner === 'sessionStorage') {
            members = {
                getItem: 'Read a saved value',
                setItem: 'Save a value',
                removeItem: 'Remove a saved value',
                clear: 'Remove every saved value',
                key: 'The name of a saved value',
                length: 'How many values are saved',
            }
        } else if (owner === 'Math') {
            members = {
                abs: 'The absolute value',
                ceil: 'Round up',
                floor: 'Round down',
                round: 'Round to the nearest',
                min: 'The smallest',
                max: 'The largest',
                random: 'A random number',
                sqrt: 'The square root',
                pow: 'Raise to a power',
                sign: 'Is the number negative?',
                trunc: 'Drop the decimals',
            }
        } else if (owner === 'JSON') {
            members = { parse: 'Turn text into a value', stringify: 'Turn a value into text' }
        }

        if (members) {
            const options = Object.entries(members)
                .filter(([name]) => name.toLowerCase().startsWith(typed.toLowerCase()))
                .map(([name, detail]) => ({ label: name, detail, type: 'method' }))

            return options.length ? { from, to: context.pos, options } : null
        }

        // An element: the members students use on one.
        const elementOptions = Object.entries(ELEMENT_MEMBERS)
            .filter(([name]) => name.toLowerCase().startsWith(typed.toLowerCase()))
            .map(([name, detail]) => ({ label: name, detail, type: 'method' }))

        return elementOptions.length ? { from, to: context.pos, options: elementOptions } : null
    }

    const options = Object.entries(GLOBALS)
        .filter(([name]) => name.toLowerCase().startsWith(typed.toLowerCase()))
        .map(([name, detail]) => ({ label: name, detail, type: 'variable' }))

    return options.length ? { from, to: context.pos, options } : null
}

/**
 * Completions for the members of an element, once the student has an element
 * in a variable: `box.textContent` and the like.
 */
export function elementMembers(context) {
    const owner = memberOwner(context)
    if (!owner) {
        return null
    }
    const typed = wordBefore(context)
    const options = Object.entries(ELEMENT_MEMBERS)
        .filter(([name]) => name.toLowerCase().startsWith(typed.toLowerCase()))
        .map(([name, detail]) => ({ label: name, detail, type: 'method' }))

    return options.length ? options : null
}

/** Completions for the methods of an array. */
export function arrayMembers(context) {
    const owner = memberOwner(context)
    if (!owner) {
        return null
    }
    const typed = wordBefore(context)
    const from = context.pos - typed.length
    const options = Object.entries(ARRAY_MEMBERS)
        .filter(([name]) => name.toLowerCase().startsWith(typed.toLowerCase()))
        .map(([name, detail]) => ({ label: name, detail, type: 'method' }))

    return options.length ? { from, to: context.pos, options } : null
}

/**
 * The browser globals, added to the JavaScript language.
 *
 * @codemirror/lang-javascript's own source only knows the language keywords,
 * so a student writing `docum` was offered nothing.
 */
export const javascriptCompletion = javascriptLanguage.data.of({
    autocomplete: javascriptGlobals,
})

/**
 * The same globals, for the JavaScript inside an HTML file's <script> block.
 */
export const htmlCompletion = htmlLanguage.data.of({
    autocomplete: javascriptGlobals,
})
