const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const bundle = path.join(root, 'bundle');
const php = path.join(bundle, 'php/php.exe');
function helper(input) {
    return spawnSync(php, ['-c', path.join(bundle, 'php/php.ini'), '-d', `extension_dir=${path.join(bundle, 'php/ext')}`, path.join(root, 'setup.php')], { input: JSON.stringify(input), encoding: 'utf8', windowsHide: true });
}
test('desktop backup preserves required empty folders and validates its manifest', { skip: !fs.existsSync(php) }, () => {
    const folder = path.join(root, '.test-data', 'archive-unit-' + Date.now());
    const source = path.join(folder, 'source');
    fs.mkdirSync(path.join(source, 'mysql'), { recursive: true });
    fs.mkdirSync(path.join(source, 'app/student-db/no-files'), { recursive: true });
    fs.writeFileSync(path.join(source, 'mysql/auto.cnf'), 'test');
    fs.writeFileSync(path.join(source, 'instance.json'), '{}');
    fs.writeFileSync(path.join(source, '.env'), 'APP_KEY=test');
    const zip = path.join(folder, 'classroom.zip');
    const archived = helper({ action: 'archive', project: source, target: zip, sources: { mysql: path.join(source, 'mysql'), 'instance.json': path.join(source, 'instance.json'), 'application/.env': path.join(source, '.env'), 'application/storage/app': path.join(source, 'app') } });
    assert.equal(archived.status, 0, archived.stderr);
    const extracted = helper({ action: 'extract', project: source, source: zip, target: path.join(folder, 'restored') });
    assert.equal(extracted.status, 0, extracted.stderr);
    assert.ok(fs.statSync(path.join(folder, 'restored/application/storage/app/student-db/no-files')).isDirectory());
});
test('restore rejects a traversal entry before extracting', { skip: !fs.existsSync(php) }, () => {
    const folder = path.join(root, '.test-data', 'unsafe-unit-' + Date.now());
    fs.mkdirSync(folder, { recursive: true });
    const zip = path.join(folder, 'unsafe.zip');
    const made = spawnSync(php, ['-c', path.join(bundle, 'php/php.ini'), '-d', `extension_dir=${path.join(bundle, 'php/ext')}`, '-r', '$z=new ZipArchive;$z->open($argv[1],ZipArchive::CREATE);$z->addFromString("manifest.json",json_encode(["format"=>1,"mysqlSeries"=>"8.0"]));$z->addFromString("../escape.txt","bad");$z->close();', zip], { encoding: 'utf8', windowsHide: true });
    assert.equal(made.status, 0, made.stderr);
    const result = helper({ action: 'extract', project: folder, source: zip, target: path.join(folder, 'restored') });
    assert.notEqual(result.status, 0);
    assert.match(result.stderr + result.stdout, /Unsafe file/);
    assert.equal(fs.existsSync(path.join(folder, 'escape.txt')), false);
});
