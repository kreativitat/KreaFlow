#!/usr/bin/env python3
"""Reject accidental private artifacts/history without printing matched content."""
from pathlib import Path
import io
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
BLOCKED_PARTS = {".codex-deploy", ".codex-backups", "private", "secrets", "node_modules", "artifacts"}
BLOCKED_SUFFIXES = {".zip", ".gz", ".tgz", ".7z", ".rar", ".log", ".pem", ".key", ".p12", ".pfx", ".sqlite", ".sqlite3", ".db", ".xlsx", ".xls", ".csv", ".har", ".pdf"}
PATTERNS = {
    "local account path": re.compile(r"/(?:Users|home)/[A-Za-z0-9_.-]+/"),
    "private network endpoint": re.compile(r"https?://(?:10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+|100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.\d+\.\d+)(?=[:/\s]|$)"),
    "tailnet hostname": re.compile(r"[a-z0-9.-]+\.ts\.net\b", re.I),
    "company tax mapping": re.compile(r"(?:storeNIF|store_nif|tax_id)\s*['\"]?\s*(?:=>|:|=)\s*['\"](?:PT)?\d{9}['\"]", re.I),
    "credential-bearing URL": re.compile(r"https?://[^\s/@:]+:[^\s/@]+@", re.I),
    "private key": re.compile(r"-----BEGIN (?:[A-Z0-9]+ )?PRIVATE KEY-----"),
}


def git(*args):
    return subprocess.check_output(["git", "-C", str(ROOT), *args], text=True).strip()


def private_path(name):
    path = Path(name)
    return (BLOCKED_PARTS.intersection(path.parts) or path.suffix.lower() in BLOCKED_SUFFIXES
            or path.name == ".env" or path.name.startswith(".env.") and path.name != ".env.example"
            or name.startswith("bin/") or path.name.lower() in {"conf.php", "credentials.json", "id_rsa", "id_ed25519"})


def inspect_content(name, data, errors, historical=False):
    prefix = "Historical " if historical else ""
    try:
        content = data.decode("utf-8")
    except UnicodeError:
        if Path(name).suffix.lower() not in {".png", ".gif", ".jpg", ".jpeg", ".ico", ".woff", ".woff2", ".ttf"}:
            errors.append(f"{prefix}{name}: unreviewed binary type")
        return
    if name == "tools/check_publication.py":
        return  # Detection patterns are not installation values; Gitleaks still scans this file.
    for category, pattern in PATTERNS.items():
        if pattern.search(content):
            errors.append(f"{prefix}{name}: {category}")
    if Path(name).suffix.lower() == ".sql" and re.search(r"\bINSERT\s+INTO\s+\w*(?:facture|paiement|bank|user|societe)\b", content, re.I):
        errors.append(f"{prefix}{name}: business data in SQL")


def main():
    errors = []
    paths = git("ls-files", "-z").split("\0")
    for name in filter(None, paths):
        path = Path(name)
        if private_path(name):
            errors.append(f"{name}: private artifact type")
            continue
        absolute = ROOT / path
        if absolute.is_symlink():
            errors.append(f"{name}: symlink needs publication review")
            continue
        inspect_content(name, absolute.read_bytes(), errors)

    # A private-history merge introduces a second root. Verify all fetched refs,
    # including pull-request heads, rather than inspecting the current tree only.
    roots = git("rev-list", "--all", "--max-parents=0").splitlines()
    if roots:
        if len(set(roots)) != 1:
            errors.append("Git history: multiple roots; private history must not be imported")
        for root in set(roots):
            result = subprocess.run(["git", "-C", str(ROOT), "show", root + ":PUBLICATION.md"], capture_output=True, text=True)
            if result.returncode or "sanitized-history-v1" not in result.stdout:
                errors.append("Git history: root lacks the sanitized-publication boundary")
        historical_paths = git("log", "--all", "--format=", "--name-only", "-z").split("\0")
        for name in {name.strip("\n") for name in historical_paths if name.strip("\n")}:
            if private_path(name):
                errors.append(f"Historical {name}: private artifact type")
        objects = {}
        for line in git("-c", "core.quotepath=false", "rev-list", "--objects", "--all").splitlines():
            parts = line.split(" ", 1)
            if len(parts) == 2:
                objects[parts[0]] = parts[1]
        if objects:
            requests = "\n".join(objects) + "\n"
            types = subprocess.check_output(["git", "-C", str(ROOT), "cat-file", "--batch-check=%(objectname) %(objecttype) %(objectsize)"], input=requests, text=True)
            blobs = []
            for line in types.splitlines():
                oid, kind, size = line.split()
                if kind == "blob":
                    if int(size) > 12 * 1024 * 1024:
                        errors.append(f"Historical {objects[oid]}: oversized blob needs publication review")
                    else:
                        blobs.append(oid)
            data = subprocess.check_output(["git", "-C", str(ROOT), "cat-file", "--batch"], input=("\n".join(blobs) + "\n").encode()) if blobs else b""
            stream = io.BytesIO(data)
            for oid in blobs:
                _, _, size = stream.readline().split()
                content = stream.read(int(size))
                stream.read(1)
                inspect_content(objects[oid], content, errors, historical=True)
    for error in errors:
        print(error, file=sys.stderr)
    if errors:
        return 1
    print(f"Publication checks passed for {len(list(filter(None, paths)))} tracked files.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
