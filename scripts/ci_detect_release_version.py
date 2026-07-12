#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import subprocess
from pathlib import Path
from typing import Any

SEMVER = re.compile(r"^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$")


def tag(value: str) -> str | None:
    match = SEMVER.match(value.strip())
    return f"v{match.group(1)}" if match else None


def versions(value: Any) -> set[str]:
    found: set[str] = set()
    if isinstance(value, dict):
        for key, child in value.items():
            if normalized := tag(str(key)):
                found.add(normalized)
            if key == "version" and isinstance(child, str):
                if normalized := tag(child):
                    found.add(normalized)
            else:
                found.update(versions(child))
    elif isinstance(value, list):
        for child in value:
            found.update(versions(child))
    return found


wanted = versions(json.loads(Path("update.json").read_text()))
existing = set(subprocess.run(["git", "tag", "--list"], text=True, check=True, capture_output=True).stdout.splitlines())
missing = sorted(wanted - existing)
Path("release.env").write_text("\n".join([
    f"SHOULD_CREATE_TAG={'true' if missing else 'false'}",
    f"RELEASE_TAG={missing[0] if len(missing) == 1 else ''}",
    "",
]))
print(f"Missing release tags: {', '.join(missing) or '(none)'}")
if len(missing) > 1:
    raise SystemExit("ERROR: update.json contains multiple untagged versions")
