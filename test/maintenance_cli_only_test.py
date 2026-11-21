#!/usr/bin/env python3
# Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License,
# or (at your option) any later version.

"""Verify maintenance HTTP rejection without executing maintenance operations."""

import http.client
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parent.parent
SCRIPTS = sorted((ROOT / "scripts").glob("*.php"))
GUARD = """if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit('This maintenance script is available only from the command line.');
}"""


def main():
    # Never request an unguarded maintenance script, including when testing a regression.
    # Strip only leading PHP comments/whitespace; the guard must be the first statement.
    for script in SCRIPTS:
        source = script.read_text()
        executable = re.sub(r"\A<\?php\s*(?:(?:/\*.*?\*/|//[^\n]*\n)\s*)*", "", source, flags=re.S)
        if not executable.startswith(GUARD):
            raise AssertionError(f"{script.name}: missing pre-bootstrap CLI guard; HTTP test aborted")

    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        port = probe.getsockname()[1]

    # Keep every temporary artifact inside the module and always stop the server.
    with tempfile.TemporaryDirectory(prefix="maintenance-http-", dir=ROOT / "test") as temp:
        with (Path(temp) / "server.log").open("w+") as log:
            server = subprocess.Popen(
                ["php", "-S", f"127.0.0.1:{port}", "-t", str(ROOT)],
                cwd=ROOT, stdout=log, stderr=subprocess.STDOUT,
            )
            try:
                deadline = time.monotonic() + 5
                while True:
                    if server.poll() is not None:
                        raise AssertionError("PHP test server exited before readiness")
                    try:
                        with socket.create_connection(("127.0.0.1", port), timeout=0.2):
                            break
                    except OSError:
                        if time.monotonic() >= deadline:
                            raise AssertionError("PHP test server did not become ready")
                        time.sleep(0.05)

                checks = 0
                for script in SCRIPTS:
                    for method in ("GET", "POST"):
                        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=3)
                        try:
                            connection.request(
                                method, f"/scripts/{script.name}?action=run&confirm=yes",
                                body="action=run&confirm=yes" if method == "POST" else None,
                                headers={"Content-Type": "application/x-www-form-urlencoded"},
                            )
                            response = connection.getresponse()
                            body = response.read().decode()
                            assert response.status == 403, (script.name, method, response.status)
                            assert body == "This maintenance script is available only from the command line.", (script.name, method, body)
                            checks += 1
                        finally:
                            connection.close()
                print(f"PASS: {checks} HTTP denials across {len(SCRIPTS)} maintenance scripts")
            finally:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=3)


if __name__ == "__main__":
    main()
