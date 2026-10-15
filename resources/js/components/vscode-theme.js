/**
 * VS Code's Dark Modern and Light Modern editor colours for CodeMirror, so the
 * workspace looks like the editor students will use as programmers.
 */
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language'
import { EditorView } from '@codemirror/view'
import { tags as t } from '@lezer/highlight'

const palettes = {
    dark: {
        background: '#1f1f1f', foreground: '#cccccc', gutter: '#6e7681', gutterActive: '#cccccc',
        activeLine: '#2a2d2e80', selection: '#264f78', cursor: '#aeafad', matching: '#0064001a',
        panel: '#252526', border: '#3c3c3c',
        keyword: '#569cd6', control: '#c586c0', string: '#ce9178', number: '#b5cea8', comment: '#6a9955',
        function: '#dcdcaa', variable: '#9cdcfe', type: '#4ec9b0', tag: '#569cd6', attribute: '#9cdcfe',
        property: '#9cdcfe', operator: '#d4d4d4', constant: '#4fc1ff', invalid: '#f44747', heading: '#569cd6',
    },
    light: {
        background: '#ffffff', foreground: '#3b3b3b', gutter: '#6e7681', gutterActive: '#171184',
        activeLine: '#f2f2f2', selection: '#add6ff', cursor: '#000000', matching: '#0064001a',
        panel: '#f8f8f8', border: '#e5e5e5',
        keyword: '#0000ff', control: '#af00db', string: '#a31515', number: '#098658', comment: '#008000',
        function: '#795e26', variable: '#001080', type: '#267f99', tag: '#800000', attribute: '#e50000',
        property: '#001080', operator: '#000000', constant: '#0070c1', invalid: '#cd3131', heading: '#800000',
    },
}

function build(mode) {
    const c = palettes[mode]

    return [
        EditorView.theme({
            '&': { color: c.foreground, backgroundColor: c.background },
            '.cm-content': { caretColor: c.cursor },
            '.cm-cursor, .cm-dropCursor': { borderLeftColor: c.cursor, borderLeftWidth: '2px' },
            '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: c.selection },
            '.cm-activeLine': { backgroundColor: c.activeLine },
            '.cm-gutters': { backgroundColor: c.background, color: c.gutter, border: 'none' },
            '.cm-activeLineGutter': { backgroundColor: 'transparent', color: c.gutterActive },
            '.cm-matchingBracket': { backgroundColor: c.matching, outline: `1px solid ${c.gutter}` },
            '.cm-foldPlaceholder': { backgroundColor: 'transparent', border: 'none', color: c.gutter },
            '.cm-tooltip': { backgroundColor: c.panel, border: `1px solid ${c.border}`, color: c.foreground },
            '.cm-tooltip-autocomplete > ul > li[aria-selected]': { backgroundColor: mode === 'dark' ? '#04395e' : '#0060c0', color: '#ffffff' },
            '.cm-panels': { backgroundColor: c.panel, color: c.foreground },
            '.cm-searchMatch': { backgroundColor: mode === 'dark' ? '#623315' : '#f8c9ab' },
        }, { dark: mode === 'dark' }),
        syntaxHighlighting(HighlightStyle.define([
            { tag: [t.keyword, t.modifier, t.definitionKeyword, t.moduleKeyword, t.operatorKeyword, t.self, t.null, t.bool], color: c.keyword },
            { tag: [t.controlKeyword], color: c.control },
            { tag: [t.string, t.special(t.string), t.regexp, t.character], color: c.string },
            { tag: [t.number, t.integer, t.float], color: c.number },
            { tag: [t.comment, t.lineComment, t.blockComment, t.docComment], color: c.comment, fontStyle: 'italic' },
            { tag: [t.function(t.variableName), t.function(t.propertyName), t.macroName], color: c.function },
            { tag: [t.variableName, t.special(t.variableName)], color: c.variable },
            { tag: [t.propertyName], color: c.property },
            { tag: [t.typeName, t.className, t.namespace, t.standard(t.typeName)], color: c.type },
            { tag: [t.tagName, t.angleBracket], color: c.tag },
            { tag: [t.attributeName], color: c.attribute },
            { tag: [t.attributeValue], color: c.string },
            { tag: [t.operator, t.punctuation, t.separator, t.bracket], color: c.operator },
            { tag: [t.constant(t.variableName), t.atom, t.unit], color: c.constant },
            { tag: [t.heading], color: c.heading, fontWeight: 'bold' },
            { tag: [t.invalid], color: c.invalid },
        ])),
    ]
}

export const vscodeDark = build('dark')
export const vscodeLight = build('light')
