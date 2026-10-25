"""Refresh the local text snapshot without credentials, runtime cookies, or binary assets."""
from datetime import datetime, timezone, timedelta
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[2]
names = subprocess.check_output(
    ['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], cwd=root
).decode('utf-8').split('\0')
extensions = {'.php', '.js', '.css', '.sql', '.md', '.txt', '.json', '.py', '.htaccess'}
stamp = datetime.now(timezone(timedelta(hours=8))).strftime('%Y-%m-%d %I:%M:%S %p')
parts = [f'Last saved: {stamp} PHT (UTC+08:00)\n',
         'Source snapshot excludes credentials, runtime session configuration, and binary assets.\n']
for name in sorted(set(filter(None, names)), key=str.casefold):
    path = root / name
    if (name == 'source-code.txt' or name.startswith(('.env', '.vscode/'))
            or name.endswith('/server.json') or not path.is_file()):
        continue
    if path.suffix.lower() not in extensions and path.name not in {'.gitignore', '.htaccess', 'LICENSE'}:
        continue
    text = path.read_text(encoding='utf-8-sig')
    parts.append('\n\n' + '=' * 80 + '\n\nFILE: ' + name.replace('/', '\\')
                 + '\n\n' + '=' * 80 + '\n\n' + text.rstrip() + '\n')
(root / 'source-code.txt').write_text(''.join(parts), encoding='utf-8')
print(f'Updated {root / "source-code.txt"} ({len(parts) - 2} files)')
