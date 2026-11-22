"""Create a direct-deploy ZIP without private runtime, test, or repository files."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile
import hashlib
import sys

ROOT = Path(__file__).resolve().parent.parent
DEST = ROOT / 'dist' / 'AZHottie_Studio_Full_Deploy.zip'
EXCLUDED_DIRS = {'.git', '.github', 'tests', 'scripts', 'node_modules', 'vendor', 'dist', 'uploads', 'cache', 'tmp', '__pycache__'}
EXCLUDED_ROOT = {'.gitignore', '.gitattributes', 'SECURITY.md'}
EXCLUDED_SUFFIXES = {'.pem', '.p12', '.key', '.sqlite', '.sqlite3', '.db', '.bak', '.log', '.tmp', '.mbox', '.zip'}


def files_for_release():
    for path in sorted(ROOT.rglob('*')):
        if not path.is_file():
            continue
        rel = path.relative_to(ROOT)
        if any(part in EXCLUDED_DIRS for part in rel.parts) or rel.name in EXCLUDED_ROOT:
            continue
        if rel.parts[0] == 'storage' and rel.as_posix() != 'storage/.htaccess':
            continue
        if rel.name.startswith('.env') or rel.suffix in EXCLUDED_SUFFIXES or rel.name.endswith('.sql.gz'):
            continue
        if path.is_symlink():
            raise ValueError(f'Symlink excluded from deploy: {rel}')
        yield path, rel


def main():
    files = list(files_for_release())
    expected = {'index.php', 'agent-chat-api.php', 'live-director.php', 'storage/.htaccess'}
    actual = {str(rel) for _, rel in files}
    missing = expected - actual
    if missing:
        raise RuntimeError(f'Missing critical release files: {sorted(missing)}')
    DEST.parent.mkdir(exist_ok=True)
    with ZipFile(DEST, 'w', ZIP_DEFLATED, compresslevel=9) as archive:
        for file, rel in files:
            archive.write(file, 'azhottie-studio/' + rel.as_posix())
    with ZipFile(DEST) as archive:
        assert archive.testzip() is None
        assert len(archive.namelist()) == len(files)
    digest = hashlib.sha256(DEST.read_bytes()).hexdigest()
    print(f'Built {DEST.name}: {len(files)} files, {DEST.stat().st_size} bytes; sha256 {digest}')


if __name__ == '__main__':
    main()
