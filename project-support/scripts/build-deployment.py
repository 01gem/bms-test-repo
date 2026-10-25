"""Build a runtime-only ZIP from current Git-listed files. Preview unless --output is supplied."""
import argparse
from pathlib import Path, PurePosixPath
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent
RUNTIME_ROOTS = {'api', 'css', 'database', 'document_templates', 'images', 'js', 'pages', 'scripts'}
ROOT_FILES = {'db.php', 'index.php', 'logout.php', 'LICENSE'}
RUNTIME_SCRIPTS = {'dispatch-request-notices.php', 'cleanup-request-artifacts.php', 'maintain-request-storage.py', 'run-pickup-dispatcher.ps1'}
SCAFFOLD_ROOTS = {'generated_documents', 'profile_img', 'complaint_attachments'}


def include_file(name):
    parts = PurePosixPath(name).parts
    if not parts or '..' in parts or PurePosixPath(name).is_absolute() or '\\' in name or ':' in name:
        return False
    if any(p.startswith('.env') or p == '__pycache__' or p.startswith('~$') for p in parts):
        return False
    if parts[0] in SCAFFOLD_ROOTS:
        return parts[-1] in {'.htaccess', 'README.txt'}
    if len(parts) == 1:
        return name in ROOT_FILES
    if parts[0] not in RUNTIME_ROOTS:
        return False
    if parts[0] == 'scripts':
        return len(parts) == 2 and parts[-1] in RUNTIME_SCRIPTS
    if parts[0] == 'document_templates':
        return parts[-1].endswith('.docx') or parts[-1] in {'.htaccess', 'README.txt'}
    return not name.endswith(('.log', '.jsonl', '.pyc', '.zip'))


def build(output=None, list_files=False):
    names = subprocess.check_output(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=ROOT).decode().split('\0')
    files = sorted({name for name in names if name and include_file(name) and (ROOT / name).is_file()})
    for name in files:
        if (ROOT / name).is_symlink() or not (ROOT / name).resolve().is_relative_to(ROOT.resolve()):
            raise ValueError('Refusing linked/outside deployment source: ' + name)
    if output is not None:
        output = Path(output).resolve()
        if output.is_relative_to(ROOT.resolve()):
            raise ValueError('Choose an output outside the served project directory.')
        if output.suffix.lower() != '.zip':
            raise ValueError('Deployment output must end in .zip.')
        output.parent.mkdir(parents=True, exist_ok=True)
        # Exclusive creation protects an existing package from accidental replacement.
        with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
            for name in files:
                archive.write(ROOT / name, name)
    if list_files:
        print('\n'.join(files))
    print(f'{"Built" if output else "Preview"}: {len(files)} runtime files; tests, evidence, .env files, uploads and source snapshot excluded.')
    return files


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, help='New ZIP path outside the served project; omission previews only.')
    parser.add_argument('--list', action='store_true', help='List selected files.')
    args = parser.parse_args()
    build(args.output, args.list)
