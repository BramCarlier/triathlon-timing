"""Exercise Forge's actual Composer variable formats without a live database."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


SCRIPT = Path(__file__).resolve().parents[2] / 'deploy' / 'forge-deploy.sh'


class ForgeDeployTest(unittest.TestCase):
    def run_deploy(self, composer_format, fail_build=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            release = root / 'release'
            release.mkdir()
            bin_dir = root / 'bin'
            bin_dir.mkdir()
            log = root / 'commands.log'
            for executable in ['php8.4', 'composer', 'corepack', 'node']:
                stub = bin_dir / executable
                stub.write_text(
                    '#!/usr/bin/env bash\n'
                    'printf "%s|%s|%s\\n" "$PWD" "${0##*/}" "$*" >> "$TIMING_TEST_LOG"\n'
                    'if [[ "${0##*/}" == corepack && "$*" == "yarn build" '
                    '&& "${TIMING_TEST_FAIL:-}" == 1 ]]; then exit 17; fi\n'
                )
                stub.chmod(0o755)
            env = {
                **os.environ,
                'PATH': f'{bin_dir}:{os.environ["PATH"]}',
                'FORGE_PHP': 'php8.4',
                'FORGE_COMPOSER': composer_format.format(path=bin_dir / 'composer'),
                'FORGE_SITE_PATH': str(root / 'inactive-site'),
                'TIMING_TEST_LOG': str(log),
                'TIMING_TEST_FAIL': '1' if fail_build else '',
            }
            result = subprocess.run(
                ['bash', str(SCRIPT), str(release)], env=env,
                capture_output=True, text=True, check=False,
            )
            commands = log.read_text().splitlines()
            self.assertTrue(all(line.startswith(f'{release}|') for line in commands))
            return result, commands, str(bin_dir / 'composer')

    def test_composer_path_and_forge_command(self):
        for value in ['{path}', 'php8.4 {path}']:
            with self.subTest(value=value):
                result, commands, composer = self.run_deploy(value)
                self.assertEqual(result.returncode, 0, result.stderr)
                installs = [line for line in commands if 'install --no-dev' in line]
                self.assertEqual(len(installs), 1)
                self.assertIn(f'|php8.4|{composer} install --no-dev', installs[0])
                self.assertTrue(any('|corepack|yarn install --immutable' in x for x in commands))
                self.assertTrue(any('|php8.4|artisan migrate --force' in x for x in commands))

    def test_failed_build_stops_before_install_and_migration(self):
        result, commands, _ = self.run_deploy('php8.4 {path}', fail_build=True)
        self.assertEqual(result.returncode, 17)
        self.assertFalse(any('install --no-dev' in x or 'artisan migrate' in x for x in commands))


if __name__ == '__main__':
    unittest.main()
