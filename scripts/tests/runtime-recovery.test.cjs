const test = require('node:test');
const assert = require('node:assert/strict');
const { RestartPolicy } = require('../../desktop-host/resources/server/restart-policy.cjs');
test('restarts use bounded exponential backoff and then require operator attention', () => {
    const policy = new RestartPolicy();
    policy.started(100);
    assert.deepEqual([1,2,3,4,5].map(() => policy.failed(200).delayMs), [1000,2000,4000,8000,16000]);
    assert.equal(policy.failed(200).exhausted, true);
});
test('a recovered worker gets a fresh budget after a stable interval', () => {
    const policy = new RestartPolicy();
    policy.started(0);
    policy.failed(100);
    policy.started(1000);
    assert.equal(policy.failed(62000).delayMs, 1000);
});
