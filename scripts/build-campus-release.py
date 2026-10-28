"""Build a code-only CI release. Never include campus databases, keys or uploads."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent
DENY = re.compile(r'^(?:storage|runtime|output|work|\.git|\.campus-releases)/|^bootstrap/cache/|^public/storage(?:/|$)|(?:^|/)\.env(?:$|\.(?!example$))|\.sqlite(?:$|-)|(?:^|/)auth\.json$', re.I)


def digest(file):
    with file.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def build(output, sha):
    if not re.fullmatch('[a-f0-9]{40}', sha):
        raise ValueError('A full commit SHA is required.')
    if subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip() != sha:
        raise ValueError('Release identity must match the checked-out commit.')
    policy = json.loads((ROOT/'campus-release-policy.json').read_text())
    output.mkdir(parents=True, exist_ok=False)
    names = set(filter(None, subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).decode().split('\0')))
    for directory in ['vendor', 'public']:
        for file in (ROOT/directory).rglob('*'):
            relative = file.relative_to(ROOT).as_posix()
            if not DENY.search(relative) and file.is_file():
                names.add(relative)
    entries = {}
    seen = set()
    for name in sorted(names):
        file = ROOT/name
        if DENY.search(name) or not file.is_file():
            continue
        if not file.resolve().is_relative_to(ROOT):
            raise ValueError('A release file points outside the build checkout.')
        if name.lower() in seen:
            raise ValueError('Release paths collide on a Windows server.')
        seen.add(name.lower())
        entries[name] = {'bytes':file.stat().st_size, 'sha256':digest(file)}
    schema = {p.relative_to(ROOT/'database/migrations').as_posix():hashlib.sha256(p.read_bytes().replace(b'\r\n', b'\n')).hexdigest()
              for p in sorted((ROOT/'database/migrations').rglob('*')) if p.is_file()}
    signature = hashlib.sha256(json.dumps(schema, separators=(',', ':'), ensure_ascii=False).encode()).hexdigest()
    metadata = {'sha':sha, 'protocol':policy['protocol'], 'backwardCompatible':policy['backwardCompatible'], 'schemaSignature':signature}
    manifest = {**metadata, 'files':entries}
    archive = output/'campus-code.zip'
    with zipfile.ZipFile(archive, 'x', zipfile.ZIP_DEFLATED, compresslevel=6) as zipped:
        for name in entries:
            zipped.write(ROOT/name, name)
        zipped.writestr('campus-release.json', json.dumps(manifest, separators=(',', ':')))
    (output/'campus-update.json').write_text(json.dumps({**metadata, 'archiveSha256':digest(archive)}, indent=2))
    print(json.dumps({'sha':sha, 'files':len(entries), 'bytes':archive.stat().st_size}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--sha', required=True)
    args = parser.parse_args()
    build(args.output.resolve(), args.sha)
