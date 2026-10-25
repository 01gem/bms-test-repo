"""Conservative log/backup retention. Default is a preview; --logs-only never deletes backups."""
import argparse
from datetime import datetime, timedelta
import json
import os
from pathlib import Path
import re
import shutil
import stat
import uuid

PROJECT = Path(__file__).resolve().parent.parent
LOG_ROOT = Path('C:/laragon/var/BarangayLink/Capstone-System-COPY/logs')
BACKUP_ROOT = Path('C:/laragon/backups/BarangayLink')
BACKUP_NAME = re.compile(r'^\d{8}-\d{6}$')
LOG_NAME = re.compile(r'^dispatcher-runs\.\d{8}-\d{6}-[0-9a-f]{8}\.jsonl$')


def linked(path):
    info = path.lstat()
    return path.is_symlink() or bool(getattr(info, 'st_file_attributes', 0) & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 1024))


def validate_root(path):
    path = Path(path).absolute()
    if path == Path(path.anchor) or path.resolve() == PROJECT.resolve() or PROJECT.resolve().is_relative_to(path.resolve()):
        raise ValueError('Refusing a filesystem/project ancestor as a maintenance root.')
    if path.exists() and linked(path):
        raise ValueError('Maintenance roots cannot be links or junctions.')
    return path


def contained(path, root):
    path, root = Path(path), Path(root)
    if path.parent.resolve() != root.resolve() or linked(path):
        raise ValueError('Maintenance target escaped its named root or is linked.')


def managed_backup(path):
    if not path.is_dir() or linked(path) or not BACKUP_NAME.fullmatch(path.name):
        return False
    marker = path / '.backup-complete.json'
    if not marker.is_file() or linked(marker):
        return False
    try:
        data = json.loads(marker.read_text(encoding='utf-8-sig'))
        datetime.strptime(path.name, '%Y%m%d-%H%M%S')
        return isinstance(data, dict) and data.get('kind') == 'barangaylink-backup' and data.get('complete') is True
    except (ValueError, OSError):
        return False


def plan(log_root, backup_root, now=None, max_bytes=5 * 1024 * 1024, retention_days=90, keep=3, logs_only=False):
    log_root, backup_root = validate_root(log_root), validate_root(backup_root)
    now = now or datetime.now()
    cutoff = now - timedelta(days=retention_days)
    actions = []
    active = log_root / 'dispatcher-runs.jsonl'
    if active.is_file() and not linked(active) and active.stat().st_size >= max_bytes:
        actions.append(('rotate-log', active))
    archives = sorted((p for p in log_root.iterdir() if p.is_file() and not linked(p) and LOG_NAME.fullmatch(p.name)), key=lambda p: p.name, reverse=True) if log_root.exists() else []
    for path in archives[keep:]:
        if datetime.fromtimestamp(path.stat().st_mtime) < cutoff:
            actions.append(('delete-log', path))
    if not logs_only and backup_root.exists():
        backups = sorted((p for p in backup_root.iterdir() if managed_backup(p)), key=lambda p: p.name, reverse=True)
        for path in backups[keep:]:
            if not (path / '.keep').exists() and datetime.strptime(path.name, '%Y%m%d-%H%M%S') < cutoff:
                actions.append(('delete-backup', path))
    return actions


def reject_linked_tree(path):
    if linked(path):
        raise ValueError('Refusing to remove a linked backup tree.')
    for entry in path.iterdir():
        if linked(entry):
            raise ValueError('Backup contains a link/junction; manual review required.')
        if entry.is_dir():
            reject_linked_tree(entry)


def maintain(log_root=LOG_ROOT, backup_root=BACKUP_ROOT, apply=False, logs_only=False, now=None, **options):
    log_root, backup_root = validate_root(log_root), validate_root(backup_root)
    now = now or datetime.now()
    if not apply:
        actions = plan(log_root, backup_root, now=now, logs_only=logs_only, **options)
        for action, path in actions:
            print('Would ' + action + ': ' + str(path))
        print(f'Preview: {len(actions)} eligible actions; no files changed.')
        return actions
    # One maintenance writer at a time; the scheduler also ignores overlapping runs.
    log_root.mkdir(parents=True, exist_ok=True)
    lock = log_root / '.storage-maintenance.lock'
    try:
        descriptor = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY)
    except FileExistsError:
        print('Maintenance already locked; no files changed. Review a stale lock manually.')
        return []
    try:
        os.close(descriptor)
        actions = plan(log_root, backup_root, now=now, logs_only=logs_only, **options)
        for action, path in actions:
            root = backup_root if action == 'delete-backup' else log_root
            contained(path, root)
            if action == 'rotate-log':
                destination = root / f'dispatcher-runs.{now:%Y%m%d-%H%M%S}-{uuid.uuid4().hex[:8]}.jsonl'
                if destination.exists():
                    raise ValueError('Refusing to overwrite a log archive.')
                path.rename(destination)
            elif action == 'delete-backup':
                if not managed_backup(path) or (path / '.keep').exists():
                    continue
                reject_linked_tree(path)
                shutil.rmtree(path)
            else:
                path.unlink()
            print(action + ': ' + str(path))
        print(f'Applied {len(actions)} eligible maintenance actions.')
        return actions
    finally:
        lock.unlink()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--logs-only', action='store_true')
    parser.add_argument('--log-root', type=Path, default=LOG_ROOT)
    parser.add_argument('--backup-root', type=Path, default=BACKUP_ROOT)
    args = parser.parse_args()
    maintain(args.log_root, args.backup_root, args.apply, args.logs_only)
