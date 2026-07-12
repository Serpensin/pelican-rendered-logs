#!/usr/bin/env python3
from __future__ import annotations

import shutil
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_ID = 'serpensin-rendered-logs'
DIST = ROOT / 'dist'
DIST.mkdir(exist_ok=True)
RUNTIME_FILES = [Path('plugin.json'), Path('LICENSE')]
RUNTIME_DIRS = [Path('config'), Path('database'), Path('lang'), Path('routes'), Path('src')]

with tempfile.TemporaryDirectory(prefix='pelican-rendered-logs-package-') as tmp:
    root = Path(tmp) / PLUGIN_ID
    for rel in RUNTIME_FILES:
        destination = root / rel
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / rel, destination)
    for directory in RUNTIME_DIRS:
        for source in sorted((ROOT / directory).rglob('*')):
            if source.is_file():
                destination = root / source.relative_to(ROOT)
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(source, destination)
    output = DIST / f'{PLUGIN_ID}.zip'
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        for source in sorted(root.rglob('*')):
            if source.is_file():
                archive.write(source, source.relative_to(Path(tmp)))
print(output)
