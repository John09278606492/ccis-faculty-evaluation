const { test } = require('node:test');
const assert = require('node:assert/strict');
const { inside, validatePort, setEnv, Runtime } = require('../runtime.cjs');
test('ports reserve room for all companion services', () => {
    for (const port of [0, 80, 65500, NaN, 18080.5, '18080']) assert.throws(() => validatePort(port));
    assert.equal(validatePort(18080), 18080);
});
test('environment values cannot insert another configuration line', () => {
    const result = setEnv('APP_KEY=\r\nDB_PORT=3306\r\n', { APP_KEY: 'abc\nDB_PASSWORD=injected', DB_PORT: 18081 });
    assert.equal(result.split('\n').filter(line => line.startsWith('DB_PASSWORD=')).length, 0);
    assert.match(result, /DB_PORT="18081"/);
});
test('data containment rejects parents and siblings', () => {
    assert.equal(inside('C:/app/data', 'C:/app/data/file.zip'), true);
    for (const target of ['C:/app/data', 'C:/app/data-other/file.zip', 'C:/app/data/../other']) assert.equal(inside('C:/app/data', target), false);
});
test('only one management operation can run at a time', async () => {
    const runtime = Object.create(Runtime.prototype); runtime.operation = true;
    await assert.rejects(runtime.exclusive(() => {}), /still running/);
});
