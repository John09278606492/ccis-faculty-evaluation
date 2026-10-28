"""Build a private Windows evaluation transfer, with no live database replacement.

Requires Python 3, Git, the installed Composer vendor directory and Windows PHP/Node
runtime folders. The ZIP is private; source updates remain separate from campus data.
"""
import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def build(args):
    php_dir, node_dir = args.php_dir.resolve(), args.node_dir.resolve()
    node_exe = node_dir/'node.exe' if (node_dir/'node.exe').is_file() else node_dir/'bin/node.exe'
    for required in [php_dir/'php.exe', node_exe, ROOT/'vendor/autoload.php']:
        if not required.is_file():
            raise RuntimeError('Missing transfer prerequisite: '+str(required))
    if subprocess.check_output(['git', 'status', '--porcelain'], cwd=ROOT).strip():
        raise RuntimeError('Commit the reviewed application source first so the snapshot records an exact revision.')
    stamp = dt.datetime.now(dt.timezone(dt.timedelta(hours=8))).strftime('%Y%m%d-%H%M%S')
    output = args.output.resolve() if args.output else ROOT/'output'/('campus-transfer-'+stamp)
    output.mkdir(parents=True, exist_ok=False)
    if os.name == 'nt':
        owner = subprocess.check_output(['whoami'], text=True).strip()
        subprocess.run(['icacls', str(output), '/inheritance:r', '/grant:r', owner+':(OI)(CI)F',
                        '*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F'], check=True, stdout=subprocess.DEVNULL)
    project = output/'CampusEvaluation'
    project.mkdir()
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    files = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')
    for name in filter(None, files):
        if name.startswith(('bootstrap/cache/', 'public/storage/', 'storage/')):
            continue
        source = ROOT/name
        if source.is_symlink() or not source.resolve().is_relative_to(ROOT):
            raise RuntimeError('Unexpected link outside the source checkout: '+name)
        target = project/name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(source, target)
    shutil.copytree(ROOT/'vendor', project/'vendor')
    for name in ['bootstrap/cache', 'storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views']:
        (project/name).mkdir(parents=True, exist_ok=True)
    for disk in ['private', 'public']:
        shutil.copytree(ROOT/'storage/app'/disk, project/'storage/app'/disk)
    shutil.copytree(php_dir, project/'runtime/php')
    (project/'runtime/php/conf.d').mkdir(exist_ok=True)
    (project/'runtime/node').mkdir(parents=True)
    shutil.copy2(node_exe, project/'runtime/node/node.exe')
    for license_name in ['LICENSE', 'LICENSE.txt', 'LICENSE.md']:
        if (node_dir/license_name).exists():
            shutil.copy2(node_dir/license_name, project/'runtime/node'/license_name)
    ca = Path('C:/laragon/etc/ssl/cacert.pem')
    if not ca.is_file():
        ca = php_dir/'cacert.pem'
    if not ca.is_file():
        raise RuntimeError('Provide cacert.pem in the PHP folder for portable TLS trust.')
    shutil.copy2(ca, project/'runtime/php/cacert.pem')
    ini_source = php_dir/'php.ini'
    ini = ini_source.read_text(encoding='utf-8-sig')
    settings = {'extension_dir': '"${CCIS_PHP_RUNTIME_DIR}/ext"', 'error_log': '', 'session.save_path': '',
                'sys_temp_dir': '', 'upload_tmp_dir': '', 'curl.cainfo': '"${CCIS_PHP_RUNTIME_DIR}/cacert.pem"',
                'openssl.cafile': '"${CCIS_PHP_RUNTIME_DIR}/cacert.pem"'}
    for key, value in settings.items():
        ini, count = re.subn(r'^\s*'+re.escape(key)+r'\s*=.*$', lambda _: key+' = '+value, ini, flags=re.M)
        if not count:
            ini += '\n'+key+' = '+value+'\n'
    (project/'runtime/php/php.ini').write_text(ini, encoding='utf-8')
    env = os.environ.copy()
    status = ROOT/'storage/app/campus-server.json'
    if status.is_file():
        state = json.loads(status.read_text())
        cache = ROOT/'storage/framework/cache'/('host-'+str(state['port'])+'-config.php')
        if state.get('state') == 'running' and cache.is_file():
            env['APP_CONFIG_CACHE'] = str(cache)
    if args.config_cache:
        env['APP_CONFIG_CACHE'] = str(args.config_cache.resolve())
    subprocess.run([str(php_dir/'php.exe'), str(ROOT/'scripts/export-campus-transfer-state.php'), str(project)], cwd=ROOT, env=env, check=True)
    metadata_file = project/'snapshot-metadata.json'
    metadata = json.loads(metadata_file.read_text())
    metadata_file.unlink()
    shutil.copy2(ROOT/'docs/CAMPUS-SERVER-TRANSFER.md', project/'START-HERE.md')
    shutil.copy2(ROOT/'campus-updates.example.json', project/'campus-updates.json')
    entries = {p.relative_to(project).as_posix(): {'bytes':p.stat().st_size, 'sha256':digest(p)}
               for p in sorted(project.rglob('*')) if p.is_file()}
    manifest = {'version':1, 'revision':revision, 'branch':subprocess.check_output(['git','branch','--show-current'],cwd=ROOT,text=True).strip(),
                **metadata, 'files':entries}
    (project/'TRANSFER-MANIFEST.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    portable_env = os.environ.copy()
    portable_env.update(CCIS_PHP_RUNTIME_DIR=str(project/'runtime/php'), PHPRC=str(project/'runtime/php'), PHP_INI_SCAN_DIR=str(project/'runtime/php/conf.d'))
    subprocess.run([str(project/'runtime/php/php.exe'), str(project/'scripts/verify-campus-transfer.php')], cwd=project, env=portable_env, check=True)
    archive = output/('PRIVATE-CampusEvaluation-'+stamp+'.zip')
    with zipfile.ZipFile(archive, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=5) as zipped:
        for path in sorted(project.rglob('*')):
            zipped.write(path, path.relative_to(output).as_posix())
    with zipfile.ZipFile(archive) as zipped:
        broken = zipped.testzip()
        if broken:
            raise RuntimeError('Transfer ZIP integrity check failed: '+broken)
    receipt = {'archive':str(archive), 'sha256':digest(archive), 'bytes':archive.stat().st_size,
               'created_at':metadata['created_at'], 'revision':revision, 'students':metadata['students'], 'records':metadata['records']}
    (output/'transfer-receipt.json').write_text(json.dumps(receipt,indent=2),encoding='utf-8')
    print(json.dumps({'archive':str(archive),'bytes':receipt['bytes'],'students':metadata['students'],'revision':revision}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php-dir', type=Path, required=True)
    parser.add_argument('--node-dir', type=Path, required=True)
    parser.add_argument('--output', type=Path)
    parser.add_argument('--config-cache', type=Path)
    build(parser.parse_args())
