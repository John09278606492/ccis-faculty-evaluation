// Run after building, against the actual packaged resources and Electron Node runtime.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const asar = require('@electron/asar');
const root = path.resolve(__dirname, '..');
const packaged = path.join(root, 'dist/win-unpacked');
for (const file of ['runtime.cjs', 'main.cjs', 'preload.cjs']) {
    assert.equal(asar.extractFile(path.join(packaged, 'resources/app.asar'), file).toString(), fs.readFileSync(path.join(root, file), 'utf8'), `${file} must match release source`);
}
const { Runtime } = require('../runtime.cjs');
const data = process.argv[2];
assert.ok(data, 'Pass the restored smoke-test data folder.');
const bundle = path.join(packaged, 'resources/classroom');
const runtime = new Runtime(bundle, path.resolve(data), path.join(packaged, 'CCIS Classroom.exe'));
(async () => {
    try {
        await runtime.start(19380);
        assert.equal((await fetch(runtime.snapshot().url + '/login')).status, 200);
        const health = JSON.parse(await runtime.helper('health'));
        assert.equal(health.users, 1);
        assert.match(fs.readFileSync(path.join(runtime.project, 'storage/app/student-db/web/phpmyadmin/config.inc.php'), 'utf8'), /\['port'\] = '19382'/);
        assert.equal(fs.readFileSync(path.join(runtime.project, 'storage/app/private/transfer-evidence.txt'), 'utf8'), 'Uploaded records survived transfer');
        assert.equal((await fetch(runtime.databaseLink(), { redirect: 'manual' })).status, 302);
        console.log('PASS: packaged source integrity, Electron gateway, bundled PHP/MySQL, restored records, database manager and changed student database port.');
    } finally {
        await runtime.stopServices();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
