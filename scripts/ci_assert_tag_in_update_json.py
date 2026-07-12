#!/usr/bin/env python3
from __future__ import annotations

import json
import os
from pathlib import Path

version = json.loads(Path('update.json').read_text()).get('*', {}).get('version')
tag = os.environ.get('CI_COMMIT_TAG', '')
if not version or tag != f'v{version}':
    raise SystemExit(f'ERROR: refusing release for {tag}; update.json declares {version!r}')
print(f'Releasing {tag} declared by update.json.')
