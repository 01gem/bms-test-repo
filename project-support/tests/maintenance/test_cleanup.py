"""Safety checks for deployment packaging and filesystem retention; uses temporary files only."""
import importlib.util
import json
import os
from pathlib import Path
import tempfile
from datetime import datetime, timedelta
import unittest
from unittest import mock
import zipfile

ROOT = Path(__file__).resolve().parents[3]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


package = load('deployment', 'project-support/scripts/build-deployment.py')
storage = load('storage', 'project-support/scripts/maintain-request-storage.py')


class CleanupSafety(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='barangay-cleanup-test-')
        self.root = Path(self.temp.name)
        self.logs = self.root / 'logs'
        self.backups = self.root / 'backups'
        self.logs.mkdir()
        self.backups.mkdir()
        self.now = datetime(2026, 10, 25, 12, 0)

    def tearDown(self):
        self.temp.cleanup()

    def backup(self, name, pinned=False, complete=True):
        path = self.backups / name
        path.mkdir()
        (path / 'application.sql').write_text('KEEP OR DELETE THIS TEST FIXTURE ONLY')
        if complete:
            (path / '.backup-complete.json').write_text(json.dumps({'kind': 'barangaylink-backup', 'complete': True}))
        if pinned:
            (path / '.keep').write_text('Explicitly preserved')
        return path

    def test_package_omits_tests_evidence_secrets_and_uploaded_documents(self):
        for name in ['tests/request-concurrency/run.php', 'docs/evidence.jpg', '.env', 'source-code.txt',
                     'generated_documents/existing.pdf', 'profile_img/residents/private.jpg',
                     'complaint_attachments/1/photo.png', 'api/.env.local', 'project-support/scripts/update-source-code.py']:
            self.assertFalse(package.include_file(name), name)
        for name in ['api/requests/_workflow.php', 'api/requests/_generation.php', 'api/requests/_dispatch.php',
                     'project-support/migrations/20261025_request_concurrency.sql', 'document_templates/template.docx',
                     'generated_documents/.htaccess', 'project-support/scripts/dispatch-request-notices.php', 'js/official.js']:
            self.assertTrue(package.include_file(name), name)

    def test_package_preview_writes_nothing_and_archive_contains_only_runtime(self):
        project = self.root / 'project'
        names = ['api/requests/_workflow.php', 'tests/worker.php', '.env', 'docs/report.md']
        for name in names:
            file = project / name
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_text(name)
        with mock.patch.object(package, 'ROOT', project), mock.patch.object(package.subprocess, 'check_output', return_value='\0'.join(names).encode()):
            self.assertEqual(package.build(), ['api/requests/_workflow.php'])
            self.assertFalse((self.root / 'runtime.zip').exists())
            package.build(self.root / 'runtime.zip')
            with zipfile.ZipFile(self.root / 'runtime.zip') as archive:
                self.assertEqual(archive.namelist(), ['api/requests/_workflow.php'])
            with self.assertRaises(ValueError):
                package.build(project / 'served.zip')
            with self.assertRaises(FileExistsError):
                package.build(self.root / 'runtime.zip')

    def test_package_rejects_traversal_and_linked_sources(self):
        for name in ['../.env', 'api/../../private.php', '/api/test.php', 'C:/api/test.php', 'api\\test.php']:
            self.assertFalse(package.include_file(name), name)
        project = self.root / 'project'
        (project / 'api').mkdir(parents=True)
        linked = project / 'api' / 'linked.php'
        linked.write_text('test')
        with mock.patch.object(package, 'ROOT', project), mock.patch.object(package.subprocess, 'check_output', return_value=b'api/linked.php'), mock.patch.object(Path, 'is_symlink', return_value=True):
            with self.assertRaises(ValueError):
                package.build()

    def test_backup_retention_preserves_newest_three_pinned_and_unknown(self):
        pinned = self.backup('20200101-000000', pinned=True)
        old = self.backup('20210101-000000')
        unknown = self.backup('20220101-000000', complete=False)
        newest = [self.backup(x) for x in ['20230101-000000', '20240101-000000', '20250101-000000']]
        actions = storage.maintain(self.logs, self.backups, now=self.now)
        self.assertEqual(actions, [('delete-backup', old)])
        self.assertTrue(all(p.exists() for p in [pinned, old, unknown] + newest))
        storage.maintain(self.logs, self.backups, apply=True, now=self.now)
        self.assertFalse(old.exists())
        self.assertTrue(all(p.exists() for p in [pinned, unknown] + newest))

    def test_recent_backups_and_incomplete_manifests_are_not_removed(self):
        recent = self.backup('20261001-000000')
        for name in ['20261002-000000', '20261003-000000', '20261004-000000']:
            self.backup(name)
        invalid = self.backup('20190101-000000')
        (invalid / '.backup-complete.json').write_text('{broken')
        self.assertEqual(storage.plan(self.logs, self.backups, now=self.now), [])
        (invalid / '.backup-complete.json').write_text('[]')
        self.assertEqual(storage.plan(self.logs, self.backups, now=self.now), [])
        self.assertTrue(recent.exists())

    def test_logs_only_cannot_remove_backups_and_rotation_preserves_bytes(self):
        for name in ['20200101-000000', '20210101-000000', '20220101-000000', '20230101-000000']:
            self.backup(name)
        active = self.logs / 'dispatcher-runs.jsonl'
        content = b'critical error history\n' * 30
        active.write_bytes(content)
        storage.maintain(self.logs, self.backups, apply=True, logs_only=True, now=self.now, max_bytes=10)
        self.assertEqual(len(list(self.backups.iterdir())), 4)
        self.assertFalse(active.exists())
        archives = list(self.logs.glob('dispatcher-runs.*.jsonl'))
        self.assertEqual(len(archives), 1)
        self.assertEqual(archives[0].read_bytes(), content)
        self.assertFalse((self.logs / '.storage-maintenance.lock').exists())

    def test_log_retention_keeps_latest_three_and_nonmatching_files(self):
        paths = []
        old_time = (self.now - timedelta(days=120)).timestamp()
        for day in range(1, 6):
            path = self.logs / f'dispatcher-runs.2025010{day}-000000-abcdef01.jsonl'
            path.write_text('history')
            os.utime(path, (old_time, old_time))
            paths.append(path)
        other = self.logs / 'unrelated-error.log'
        other.write_text('keep')
        actions = storage.plan(self.logs, self.backups, now=self.now, logs_only=True)
        self.assertEqual({p for action, p in actions}, set(paths[:2]))
        self.assertTrue(other.exists())

    def test_unsafe_roots_and_backup_links_fail_closed(self):
        with self.assertRaises(ValueError):
            storage.validate_root(storage.PROJECT)
        with self.assertRaises(ValueError):
            storage.validate_root(Path(storage.PROJECT.anchor))
        outside = self.root / 'outside'
        outside.mkdir()
        with self.assertRaises(ValueError):
            storage.contained(outside, self.backups)
        with mock.patch.object(storage, 'linked', return_value=True):
            with self.assertRaises(ValueError):
                storage.validate_root(self.backups)

    def test_active_lock_and_linked_backup_content_prevent_deletion(self):
        lock = self.logs / '.storage-maintenance.lock'
        lock.write_text('another maintenance process')
        self.assertEqual(storage.maintain(self.logs, self.backups, apply=True), [])
        self.assertTrue(lock.exists())
        lock.unlink()
        for name in ['20200101-000000', '20210101-000000', '20220101-000000', '20230101-000000']:
            self.backup(name)
        original = storage.linked
        def unsafe(path):
            return Path(path).name == 'application.sql' or original(path)
        with mock.patch.object(storage, 'linked', side_effect=unsafe):
            with self.assertRaises(ValueError):
                storage.maintain(self.logs, self.backups, apply=True, now=self.now)
        self.assertTrue((self.backups / '20200101-000000').exists())


if __name__ == '__main__':
    unittest.main(verbosity=2)
