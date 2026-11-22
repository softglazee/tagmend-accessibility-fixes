"""Audits a built plugin ZIP against the plugin folder of this repository.

Usage: python3 tools/audit-zip.py <zip> [--against <other.zip>]

Checks that
  - every entry name uses forward slashes and sits under tagmend-accessibility-fixes/;
  - no entry is a dotfile, a Markdown, *.dist, shell, archive or JSON file, or lies in a
    development folder;
  - the ZIP holds exactly the files git tracks in tagmend-accessibility-fixes/, except the two
    that .distignore leaves out (.distignore and .gitignore), and each file's bytes equal the
    file in the working tree;
  - with --against: both ZIPs hold the same file names with the same bytes (SHA-256 per file),
    so a rebuilt ZIP can be compared with a released one although timestamps differ.
Prints one line per check and exits 1 on any failure.
"""

import hashlib
import subprocess
import sys
import zipfile
from pathlib import Path

SLUG = "tagmend-accessibility-fixes"
ROOT = Path(__file__).resolve().parent.parent
LEFT_OUT = {".distignore", ".gitignore"}
REFUSED_DIRS = {"tests", "docs", "tools", "release", "node_modules", "vendor", "dev", "runs"}
REFUSED_SUFFIXES = (".md", ".dist", ".sh", ".zip", ".gz", ".tgz", ".tar", ".7z", ".rar", ".bz2", ".phar", ".json", ".log", ".map")


def files_of(path):
    with zipfile.ZipFile(path) as archive:
        bad = archive.testzip()
        if bad is not None:
            sys.exit("FAIL  {}: CRC error in {}".format(path, bad))
        return {info.filename: archive.read(info) for info in archive.infolist() if not info.filename.endswith("/")}, [i.filename for i in archive.infolist()]


def main(argv):
    if len(argv) not in (1, 3) or (len(argv) == 3 and argv[1] != "--against"):
        sys.exit(__doc__)
    failures = []
    files, names = files_of(argv[0])
    print("ZIP   {}: {} entries, {} files".format(argv[0], len(names), len(files)))

    for name in names:
        rel = name[len(SLUG) + 1:] if name.startswith(SLUG + "/") else None
        if "\\" in name or rel is None:
            failures.append("entry outside {}/ or with a backslash: {}".format(SLUG, name))
            continue
        parts = [p for p in rel.split("/") if p]
        if any(p.startswith(".") for p in parts):
            failures.append("dotfile or dot-folder: " + name)
        if not name.endswith("/"):
            if any(p.lower() in REFUSED_DIRS for p in parts[:-1]):
                failures.append("development folder: " + name)
            if parts and parts[-1].lower().endswith(REFUSED_SUFFIXES):
                failures.append("refused file type: " + name)
    print("{}  entry names".format("FAIL" if failures else "PASS"))

    tracked = subprocess.run(
        ["git", "-C", str(ROOT), "ls-files", "-z", "--", SLUG + "/"],
        check=True, capture_output=True,
    ).stdout.decode("utf-8").split("\0")
    expected = {}
    for rel in filter(None, tracked):
        inner = rel[len(SLUG) + 1:]
        if inner in LEFT_OUT:
            continue
        expected[rel] = (ROOT / rel).read_bytes()
    missing = sorted(set(expected) - set(files))
    extra = sorted(set(files) - set(expected))
    changed = sorted(n for n in set(files) & set(expected) if files[n] != expected[n])
    for label, items in (("missing from the ZIP", missing), ("not tracked in the plugin folder", extra), ("bytes differ from the working tree", changed)):
        for item in items:
            failures.append("{}: {}".format(label, item))
    print("{}  same {} files as the plugin folder (tracked, minus .distignore and .gitignore), same bytes".format(
        "FAIL" if missing or extra or changed else "PASS", len(expected)))

    if len(argv) == 3:
        other, _ = files_of(argv[2])
        only_a = sorted(set(files) - set(other))
        only_b = sorted(set(other) - set(files))
        differ = sorted(n for n in set(files) & set(other) if hashlib.sha256(files[n]).digest() != hashlib.sha256(other[n]).digest())
        for label, items in (("only in " + argv[0], only_a), ("only in " + argv[2], only_b), ("content differs", differ)):
            for item in items:
                failures.append("{}: {}".format(label, item))
        print("{}  same file names and SHA-256 per file as {} ({} files)".format(
            "FAIL" if only_a or only_b or differ else "PASS", argv[2], len(other)))

    with open(argv[0], "rb") as handle:
        print("SHA256 {}".format(hashlib.sha256(handle.read()).hexdigest()))
    if failures:
        print("\n".join("FAIL  " + f for f in failures))
        sys.exit(1)
    print("ZIP audit passed")


if __name__ == "__main__":
    main(sys.argv[1:])
