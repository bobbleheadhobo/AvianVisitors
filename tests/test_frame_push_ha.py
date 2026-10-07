"""frame/esphome/push-to-ha.sh against a stand-in Home Assistant.

A fake ssh runs the remote half locally under a strict POSIX shell (dash
when present, like the SSH add-on's BusyBox ash), with --remote-dir pointing
at a temporary /config/esphome. ESPHome validation is skipped (--no-check).
"""
import os
import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
ESPHOME = ROOT / "frame" / "esphome"
SCRIPT = ESPHOME / "push-to-ha.sh"
POSIX_SH = shutil.which("dash") or "/bin/sh"

FAKE_SSH = """#!/bin/bash
args=()
while [ $# -gt 0 ]; do
  case $1 in
    -p|-o) shift 2 ;;
    -O) exit 0 ;;
    *) args+=("$1"); shift ;;
  esac
done
echo "${args[0]}" >> "$FAKE_SSH_LOG"
exec %s -c "${args[*]:1}"
""" % POSIX_SH


class PushToHaTests(unittest.TestCase):
    def setUp(self):
        self.tmp = pathlib.Path(tempfile.mkdtemp(prefix="push-ha-"))
        self.addCleanup(shutil.rmtree, self.tmp)
        bin_dir = self.tmp / "bin"
        bin_dir.mkdir()
        ssh = bin_dir / "ssh"
        ssh.write_text(FAKE_SSH)
        ssh.chmod(0o755)
        # The fake ssh runs the remote half on this machine: never let it
        # reach this machine's real sudo. The sudo test puts its own first.
        no_sudo = bin_dir / "sudo"
        no_sudo.write_text("#!/bin/sh\nexit 1\n")
        no_sudo.chmod(0o755)
        self.ha = self.tmp / "config" / "esphome"
        self.ha.mkdir(parents=True)
        (self.ha / "secrets.yaml").write_text('wifi_ssid: "x"\nwifi_password: "y"\nota_password: "z"\n')
        self.conf = self.tmp / "ha-push.conf"
        self.env = dict(os.environ, PATH=f"{bin_dir}:{os.environ['PATH']}",
                        BIRDFRAME_HA_CONF=str(self.conf), FAKE_SSH_LOG=str(self.tmp / "ssh.log"))

    def push(self, *args):
        return subprocess.run([str(SCRIPT), "--remote-dir", str(self.ha), "--no-check", *args],
                              env=self.env, text=True, capture_output=True, timeout=60)

    def test_copies_verifies_and_remembers(self):
        result = self.push("--host", "192.168.0.20")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("copied and verified", result.stdout)
        for rel in ("birdframe-usb.yaml", "birdframe/common.yaml", "birdframe/birdframe.h"):
            self.assertEqual((self.ha / rel).read_bytes(), (ESPHOME / rel).read_bytes(), rel)
        self.assertIn("birdframe_api_key", result.stdout)          # missing secret reported
        self.assertNotIn("birdframe_api_key", (self.ha / "secrets.yaml").read_text())
        self.assertIn("host=192.168.0.20", self.conf.read_text())
        again = self.push()                                         # host from the saved file
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertEqual(len(list((self.ha / ".birdframe-backup").iterdir())), 1)

    def test_dry_run_changes_nothing(self):
        result = self.push("--host", "ha.local", "--dry-run")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("dry run", result.stdout)
        self.assertEqual(sorted(p.name for p in self.ha.iterdir()), ["secrets.yaml"])
        self.assertFalse(self.conf.exists())

    def test_old_flat_layout_removed_only_when_ours(self):
        (self.ha / "birdframe.h").write_bytes((ESPHOME / "birdframe" / "birdframe.h").read_bytes())
        (self.ha / "common.yaml").write_text("substitutions:\n  someone_else: true\n")
        result = self.push("--host", "ha.local")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse((self.ha / "birdframe.h").exists())
        self.assertIn("someone_else", (self.ha / "common.yaml").read_text())
        backups = list((self.ha / ".birdframe-backup").iterdir())
        self.assertTrue((backups[0] / "birdframe.h").exists())

    def test_keeps_five_backups(self):
        for _ in range(7):
            self.assertEqual(self.push("--host", "ha.local").returncode, 0)
        self.assertEqual(len(list((self.ha / ".birdframe-backup").iterdir())), 5)

    def test_rejects_option_like_and_unsafe_values(self):
        for args in (["--host", "--dry-run"], ["--host"], ["--host", "-oProxyCommand=id"],
                     ["--host", "x;id"], ["--user", "a b", "--host", "h"], ["--port", "22x", "--host", "h"],
                     ["--build", "both", "--host", "h"]):
            result = self.push(*args)
            self.assertEqual(result.returncode, 2, args)
        self.assertFalse((self.tmp / "ssh.log").exists())           # never connected

    def test_remembers_the_folder(self):
        self.assertEqual(self.push("--host", "ha.local").returncode, 0)
        self.assertIn(f"remote_dir={self.ha}", self.conf.read_text())
        # A later run without --remote-dir uses the saved folder.
        result = subprocess.run([str(SCRIPT), "--no-check", "--dry-run"], env=self.env,
                                text=True, capture_output=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn(str(self.ha), result.stdout)

    def test_no_esphome_folder_found(self):
        # Neither /homeassistant/esphome nor /config/esphome exists here.
        if pathlib.Path("/homeassistant/esphome").is_dir() or pathlib.Path("/config/esphome").is_dir():
            self.skipTest("a real ESPHome folder exists on this machine")
        result = subprocess.run([str(SCRIPT), "--host", "ha.local", "--no-check"], env=self.env,
                                text=True, capture_output=True, timeout=60)
        self.assertEqual(result.returncode, 1)
        self.assertIn("no ESPHome folder", result.stderr)
        self.assertFalse(self.conf.exists())

    def test_unwritable_folder_changes_nothing(self):
        if os.geteuid() == 0:
            self.skipTest("root can write anywhere")
        self.ha.chmod(0o555)
        self.addCleanup(self.ha.chmod, 0o755)
        result = self.push("--host", "ha.local")
        self.assertEqual(result.returncode, 1)
        self.assertIn("cannot write", result.stderr)
        self.assertIn("password-free sudo", result.stderr)
        self.assertFalse((self.ha / "birdframe").exists())

    def test_staging_leaves_nothing_behind(self):
        (self.ha / ".birdframe-incoming-123").mkdir()          # from an interrupted run
        self.assertEqual(self.push("--host", "ha.local").returncode, 0)
        self.assertEqual(list(self.ha.glob(".birdframe-incoming-*")), [])

    def test_gitignore_in_a_git_folder(self):
        (self.ha / ".git").mkdir()
        (self.ha / ".gitignore").write_text("/.esphome/\n/secrets.yaml")  # no final newline
        dry = self.push("--host", "ha.local", "--dry-run")
        self.assertIn(".gitignore", dry.stdout)
        self.assertEqual((self.ha / ".gitignore").read_text(), "/.esphome/\n/secrets.yaml")
        for _ in range(2):                                     # added once, on its own line
            self.assertEqual(self.push("--host", "ha.local").returncode, 0)
        self.assertEqual((self.ha / ".gitignore").read_text(),
                         "/.esphome/\n/secrets.yaml\n/.birdframe-backup/\n")

    def test_no_gitignore_outside_git(self):
        self.assertEqual(self.push("--host", "ha.local").returncode, 0)
        self.assertFalse((self.ha / ".gitignore").exists())

    def test_uses_sudo_when_the_folder_is_roots(self):
        # Advanced SSH & Web Terminal with a non-root user: the folder is not
        # writable, but password-free sudo is. The stand-in sudo opens the
        # folder only while it runs a command, as root would.
        if os.geteuid() == 0:
            self.skipTest("root can write anywhere")
        sudo_bin = self.tmp / "sudo-bin"
        sudo_bin.mkdir()
        sudo = sudo_bin / "sudo"
        sudo.write_text(
            '#!/bin/bash\n[ "$1" = -n ] && shift\necho "$*" >> "$FAKE_SUDO_LOG"\n'
            'chmod u+w "$FAKE_ROOT_DIR"; "$@"; rc=$?; chmod u-w "$FAKE_ROOT_DIR"; exit $rc\n')
        sudo.chmod(0o755)
        self.env.update(PATH=f"{sudo_bin}:{self.env['PATH']}", FAKE_ROOT_DIR=str(self.ha),
                        FAKE_SUDO_LOG=str(self.tmp / "sudo.log"))
        self.ha.chmod(0o555)
        self.addCleanup(self.ha.chmod, 0o755)
        result = self.push("--host", "ha.local", "--user", "joey")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("using sudo", result.stdout)
        self.assertIn("copied and verified", result.stdout)
        self.assertTrue((self.ha / "birdframe" / "common.yaml").exists())
        log = (self.tmp / "sudo.log").read_text()
        self.assertIn("tar -C", log)
        self.assertIn("sh -s", log)

    def test_rejects_unsafe_saved_host(self):
        self.conf.write_text("host=-oProxyCommand=id\n")
        result = self.push("--dry-run")
        self.assertEqual(result.returncode, 2)
        self.assertIn("bad --host", result.stderr)


if __name__ == "__main__":
    unittest.main()
