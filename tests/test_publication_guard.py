#!/usr/bin/env python3
"""Exercise publication rejection in disposable repositories without a remote."""
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parents[1]


def main():
    with tempfile.TemporaryDirectory(prefix="kreaflow-publication-test-") as temporary:
        root = Path(temporary)
        (root / "tools").mkdir()
        shutil.copyfile(SOURCE / "tools/check_publication.py", root / "tools/check_publication.py")

        def git(*args, input=None):
            return subprocess.run(["git", "-C", str(root), *args], input=input, capture_output=True, text=True, check=True).stdout.strip()

        def check(expected, message=""):
            result = subprocess.run(["python3", str(root / "tools/check_publication.py")], capture_output=True, text=True)
            assert result.returncode == expected, result.stderr
            assert message in result.stderr, result.stderr

        git("init", "-b", "main")
        git("config", "user.name", "Synthetic Test")
        git("config", "user.email", "test@example.invalid")
        (root / "PUBLICATION.md").write_text("sanitized-history-v1\n")
        git("add", "tools/check_publication.py", "PUBLICATION.md")
        git("commit", "-m", "Synthetic public root")
        check(0)
        for name, content, category in [
            (".env", "SYNTHETIC_ONLY=1\n", "private artifact type"),
            ("config.txt", "endpoint=" + "https://" + "192." + "168.12.34/api\n", "private network endpoint"),
            ("export.zip", "synthetic archive\n", "private artifact type"),
        ]:
            (root / name).write_text(content)
            git("add", name)
            check(1, category)
            git("rm", "--cached", name)

        # Removing a private value later must not hide its earlier public commit.
        git("add", "config.txt")
        git("commit", "-m", "Synthetic historical privacy fixture")
        git("rm", "config.txt")
        git("commit", "-m", "Remove fixture from tip")
        check(1, "Historical config.txt: private network endpoint")

        # A second, unrelated root must remain forbidden even with a clean tip.
        blob = git("hash-object", "-w", "--stdin", input="synthetic history\n")
        tree = git("mktree", input="100644 blob " + blob + "\tprivate.txt\n")
        commit = git("commit-tree", tree, "-m", "Synthetic separate root")
        git("update-ref", "refs/heads/synthetic-private-history", commit)
        check(1, "multiple roots")
    print("Publication guard: clean source accepted; private artifacts, removed historical values and foreign roots rejected.")


if __name__ == "__main__":
    main()
