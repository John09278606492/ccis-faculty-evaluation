import assert from 'node:assert/strict'
import vm from 'node:vm'
import { buildDocument } from '../resources/js/components/code-runner.js'

const html = buildDocument('<html><head></head><body></body></html>', [], 'qa')
const source = html.match(/<script>([\s\S]*?)<\/script>/)[1]
const sandbox = {
    console: { log() {}, info() {}, warn() {}, error() {}, debug() {} },
    parent: { postMessage() {} },
    document: { addEventListener() {} },
    addEventListener() {},
}
sandbox.window = sandbox
for (const name of ['localStorage', 'sessionStorage']) {
    Object.defineProperty(sandbox, name, {
        configurable: true,
        get() { throw new Error('Storage unavailable in sandbox') },
    })
}
vm.runInNewContext(source, sandbox)
for (const name of ['localStorage', 'sessionStorage']) {
    const storage = sandbox[name]
    assert.equal(storage.length, 0)
    storage.setItem('answer', 42)
    storage.setItem('__proto__', 'safe')
    assert.equal(storage.getItem('answer'), '42')
    assert.equal(storage.length, 2)
    assert.equal(storage.key(0), 'answer')
    storage.removeItem('answer')
    assert.equal(storage.getItem('answer'), null)
    storage.clear()
    assert.equal(storage.length, 0)
    assert.equal(storage.key(0), null)
}
console.log('Generated preview script: sandbox storage operations passed.')
