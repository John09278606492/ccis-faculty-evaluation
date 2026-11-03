const test = require('node:test');
const assert = require('node:assert/strict');
const { campusLinks } = require('../../desktop-host/resources/server/network-links.cjs');

test('campus links exclude local-only and IPv6 addresses and put Wi-Fi first', () => {
    const interfaces = {
        Ethernet: [{ family: 'IPv4', address: '192.168.137.1', internal: false }],
        Loopback: [{ family: 'IPv4', address: '127.0.0.1', internal: true }],
        'Wi-Fi': [{ family: 'IPv4', address: '172.16.96.100', internal: false },
            { family: 'IPv6', address: 'fe80::1', internal: false }],
        Disconnected: [{ family: 'IPv4', address: '169.254.12.3', internal: false }],
    };
    assert.deepEqual(campusLinks(interfaces, 8020), [
        { name: 'Wi-Fi', url: 'http://172.16.96.100:8020' },
        { name: 'Ethernet', url: 'http://192.168.137.1:8020' },
    ]);
});

test('an address change replaces the old sharing link without retaining it', () => {
    const interfaces = { 'Wi-Fi': [{ family: 'IPv4', address: '172.16.98.136', internal: false }] };
    const before = campusLinks(interfaces, 8020);
    interfaces['Wi-Fi'][0].address = '172.16.96.100';
    const after = campusLinks(interfaces, 8020);
    assert.notDeepEqual(before, after);
    assert.equal(after[0].url, 'http://172.16.96.100:8020');
});
