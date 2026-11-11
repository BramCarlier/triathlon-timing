"""Exercise the nightly backup script with stubbed mysqldump and rclone."""
import gzip
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'deploy' / 'backup-database.sh'


class BackupDatabaseTest(unittest.TestCase):
    def run_backup(self, env_text, remote=''):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        root = Path(directory.name)
        app = root / 'app'
        app.mkdir()
        (app / '.env').write_text(env_text)
        bin_dir = root / 'bin'
        bin_dir.mkdir()
        log = root / 'commands.log'
        (bin_dir / 'mysqldump').write_text(
            '#!/usr/bin/env bash\n'
            'printf "mysqldump|%s\\n" "$*" >> "$BACKUP_TEST_LOG"\n'
            'cat "${1#--defaults-extra-file=}" >> "$BACKUP_TEST_LOG"\n'
            'echo "CREATE TABLE races (id int);"\n'
        )
        (bin_dir / 'rclone').write_text(
            '#!/usr/bin/env bash\nprintf "rclone|%s\\n" "$*" >> "$BACKUP_TEST_LOG"\n'
        )
        for stub in bin_dir.iterdir():
            stub.chmod(0o755)
        backups = root / 'backups'
        env = {
            **os.environ,
            'PATH': f'{bin_dir}:{os.environ["PATH"]}',
            'BACKUP_DIR': str(backups),
            'BACKUP_REMOTE': remote,
            'BACKUP_TEST_LOG': str(log),
        }
        result = subprocess.run(['bash', str(SCRIPT), str(app)], env=env, capture_output=True, text=True, check=False)
        commands = log.read_text().splitlines() if log.exists() else []
        return result, commands, backups

    def test_dump_is_compressed_and_password_stays_out_of_arguments(self):
        result, commands, backups = self.run_backup(
            'DB_CONNECTION=mysql\nDB_DATABASE=triathlon_timing\nDB_USERNAME=timing\nDB_PASSWORD="s3cret $(rm -rf /)"\n',
            remote='b2:club/triathlon',
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        dumps = list(backups.glob('triathlon_timing-*.sql.gz'))
        self.assertEqual(len(dumps), 1)
        self.assertIn(b'CREATE TABLE races', gzip.decompress(dumps[0].read_bytes()))
        dump_call = next(line for line in commands if line.startswith('mysqldump|'))
        self.assertNotIn('s3cret', dump_call)
        self.assertIn('--single-transaction', dump_call)
        self.assertIn('password=s3cret $(rm -rf /)', commands)
        self.assertTrue(any(line.startswith('rclone|copy') and 'b2:club/triathlon/' in line for line in commands))
        self.assertTrue(any(line.startswith('rclone|delete --min-age 30d') for line in commands))

    def test_without_a_remote_it_warns_that_the_copy_is_not_off_server(self):
        result, commands, _ = self.run_backup('DB_CONNECTION=mysql\nDB_DATABASE=db\nDB_USERNAME=u\nDB_PASSWORD=p\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('only on this server', result.stderr)
        self.assertFalse(any(line.startswith('rclone|') for line in commands))

    def test_refuses_other_databases(self):
        result, _, _ = self.run_backup('DB_CONNECTION=sqlite\nDB_DATABASE=/tmp/x.sqlite\n')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Only MySQL', result.stderr)


if __name__ == '__main__':
    unittest.main()
