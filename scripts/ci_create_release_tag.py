#!/usr/bin/env python3
from __future__ import annotations

import os
import urllib.error
import urllib.parse
import urllib.request

tag = os.environ.get("RELEASE_TAG", "").strip()
if not tag:
    print("No release tag required.")
    raise SystemExit(0)
token = os.environ.get("RELEASE_TOKEN")
if not token:
    raise SystemExit("ERROR: RELEASE_TOKEN is required to create release tags.")
url = f"{os.environ['CI_API_V4_URL'].rstrip('/')}/projects/{os.environ['CI_PROJECT_ID']}/repository/tags"
data = urllib.parse.urlencode({'tag_name': tag, 'ref': os.environ['CI_COMMIT_SHA'], 'message': f'Release {tag}'}).encode()
request = urllib.request.Request(url, data=data, method='POST', headers={
    'PRIVATE-TOKEN': token,
    'Content-Type': 'application/x-www-form-urlencoded',
    'User-Agent': 'pelican-rendered-logs-ci',
})
try:
    with urllib.request.urlopen(request, timeout=60) as response:
        print(response.read().decode('utf-8', 'replace'))
except urllib.error.HTTPError as error:
    body = error.read().decode('utf-8', 'replace')
    if error.code == 400 and 'already exists' in body.lower():
        print(f'{tag} already exists.')
    else:
        raise SystemExit(f'ERROR: unable to create {tag}: HTTP {error.code}: {body}')
