// Real Windows integration test: bundled binaries only, two independent data folders.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const { Runtime } = require('../runtime.cjs');
const root = path.resolve(__dirname, '..');
const base = path.join(root, '.test-data', 'run-' + Date.now());
const source = new Runtime(path.join(root, 'bundle'), path.join(base, "First laptop's classroom"));
const destination = new Runtime(path.join(root, 'bundle'), path.join(base, 'Second laptop'));
async function main() {
    console.log('1. Clean install using bundled PHP/MySQL (no Laragon paths).');
    await source.setup({ name: 'Installer QA', email: 'installer.qa@ccis.test', password: 'Installer-QA-2026!', port: 19280 });
    console.log('2. Start gateway, student server and database manager.');
    await source.start();
    const response = await fetch(source.snapshot().url + '/login');
    assert.equal(response.status, 200);
    assert.match(await response.text(), /Sign in/);
    assert.equal(JSON.parse(await source.helper('health')).users, 1);
    const boot = "require 'vendor/autoload.php';$a=require 'bootstrap/app.php';$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$p=app(App\\Services\\StudentDatabase\\StudentDbServer::class)->connect();";
    await source.run(source.php, ['-r', boot + "$p->exec('CREATE DATABASE stu99_transfer');$p->exec('CREATE TABLE stu99_transfer.evidence (message VARCHAR(80))');$p->exec(\"INSERT INTO stu99_transfer.evidence VALUES ('Student workspace survived transfer')\");"]);
    fs.writeFileSync(path.join(source.project, 'storage/app/private/transfer-evidence.txt'), 'Uploaded records survived transfer');
    const signon = await fetch(source.databaseLink(), { redirect: 'manual' });
    assert.equal(signon.status, 302);
    console.log('3. Clean stop and full portable backup.');
    const backup = path.join(base, 'transfer.zip');
    await source.backup(backup);
    assert.ok(fs.statSync(backup).size > 1000);
    console.log('4. Restore on a second data folder and verify retained admin.');
    await destination.restore(backup);
    await destination.start(19380);
    assert.equal((await fetch(destination.snapshot().url + '/login')).status, 200);
    assert.equal(JSON.parse(await destination.helper('health')).users, 1);
    assert.match(await destination.run(destination.php, ['-r', boot + "echo $p->query('SELECT message FROM stu99_transfer.evidence')->fetchColumn();"]), /survived transfer/);
    assert.equal(fs.readFileSync(path.join(destination.project, 'storage/app/private/transfer-evidence.txt'), 'utf8'), 'Uploaded records survived transfer');
    assert.match(fs.readFileSync(path.join(destination.project, 'storage/app/student-db/web/phpmyadmin/config.inc.php'), 'utf8'), /\['port'\] = '19382'/);
    await destination.stop();
    console.log('PASS: clean install, gateway, database sign-on, backup, restore and clean shutdown.');
    console.log('Evidence folder: ' + base);
}
main().catch(async error => {
    console.error(error);
    for (const runtime of [source, destination]) {
        if (Object.keys(runtime.children).length) await runtime.stopServices().catch(e => console.error('Shutdown:', e.message));
    }
    process.exitCode = 1;
});
