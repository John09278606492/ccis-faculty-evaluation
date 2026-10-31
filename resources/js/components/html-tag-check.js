/**
 * Marks HTML tags that are never closed.
 *
 * A tag left open (a "</form" without its ">") swallows everything after it,
 * so a <script> that follows shows up as plain text in the preview instead of
 * running. The editor marks the tag, so the mistake is seen while typing.
 *
 * @codemirror/lang-html has no matchTags in this version, so the check reads
 * the syntax tree: a close tag the parser could not finish has no ">" child.
 */
import { syntaxTree } from '@codemirror/language'
import { Decoration, ViewPlugin } from '@codemirror/view'
import { RangeSetBuilder } from '@codemirror/state'

/**
 * The close tags the parser could not finish, with the name of the tag.
 *
 * @returns {Array<{ from: number, to: number, name: string }>}
 */
export function unclosedTagsIn(view) {
    const found = []
    const tree = syntaxTree(view.state)

    tree.iterate({
        enter(node) {
            if (node.name !== 'CloseTag') {
                return
            }
            // The ">" the parser could not read.
            if (node.node.getChild('EndTag')) {
                return
            }
            // The tag name is in the tag's own text: "</form..." -> "form".
            const name = view.state.doc.sliceString(node.from, node.to)
                .match(/^<\/([a-zA-Z][a-zA-Z0-9-]*)/)?.[1]
            if (! name) {
                return
            }
            found.push({ from: node.from, to: node.to, name })
        },
    })

    return found
}

/**
 * The extension that marks the unclosed tags in an HTML file.
 */
export function unclosedTags() {
    return ViewPlugin.fromClass(
        class {
            constructor(view) {
                this.decorations = this.build(view)
            }

            update(update) {
                if (update.docChanged || update.viewportChanged) {
                    this.decorations = this.build(update.view)
                }
            }

            build(view) {
                const builder = new RangeSetBuilder()
                for (const tag of unclosedTagsIn(view)) {
                    builder.add(tag.from, tag.to, Decoration.mark({
                        class: 'cq-tag-unclosed',
                        attributes: {
                            title: `This </${tag.name}> tag is not closed. Add a ">" after it, otherwise everything after it is treated as part of the tag and your code will not run.`,
                        },
                    }))
                }

                return builder.finish()
            }
        },
        {
            decorations: (plugin) => plugin.decorations,
        }
    )
}
