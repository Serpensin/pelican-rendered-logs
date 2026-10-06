#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any

SEMVER_RE = re.compile(r'^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$')


def version_tag(version: str) -> str:
    match = SEMVER_RE.match(version.strip())
    if not match:
        raise SystemExit(f'ERROR: invalid plugin version: {version}')

    return f'v{match.group(1)}'


def load_release_tag() -> str:
    manifest = json.loads(Path('plugin.json').read_text())
    update_data: dict[str, Any] = json.loads(Path('update.json').read_text())
    version = manifest.get('version')
    if not isinstance(version, str):
        raise SystemExit('ERROR: plugin.json must declare a string version.')

    releases = update_data.get('releases')
    active = update_data.get('*')
    if not isinstance(releases, dict) or not isinstance(active, dict):
        raise SystemExit('ERROR: update.json must contain releases and * objects.')

    release = releases.get(version)
    if not isinstance(release, dict):
        raise SystemExit(f'ERROR: update.json.releases is missing version {version}.')

    if release.get('version') != version or active.get('version') != version:
        raise SystemExit(f'ERROR: plugin.json, update.json.releases and update.json.* must all declare {version}.')

    if release.get('download_url') != active.get('download_url'):
        raise SystemExit('ERROR: update.json.releases and update.json.* must use the same current download_url.')

    return version_tag(version)


def tag_exists(tag: str) -> bool:
    result = subprocess.run(['git', 'tag', '--list', tag], text=True, check=True, capture_output=True)
    return result.stdout.strip() == tag


def create_tag_with_git(tag: str, ref: str) -> int:
    name = os.environ.get('GITLAB_USER_NAME') or 'GitLab CI'
    email = os.environ.get('GITLAB_USER_EMAIL') or 'gitlab-ci@example.invalid'
    subprocess.run(['git', 'config', 'user.name', name], check=True)
    subprocess.run(['git', 'config', 'user.email', email], check=True)

    if not tag_exists(tag):
        subprocess.run(['git', 'tag', '-a', tag, ref, '-m', f'Version {tag}'], check=True)

    subprocess.run(['git', 'push', 'origin', tag], check=True)
    print(f'Created version tag {tag} at {ref} via git push')
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description='Create the tag for the current append-only update.json entry.')
    parser.add_argument('--dry-run', action='store_true', help='Report the tag that would be created without changing GitLab.')
    args = parser.parse_args()

    tag = load_release_tag()
    if tag_exists(tag):
        print(f'Tag {tag} already exists; nothing to do.')
        return 0

    if args.dry_run:
        print(f'Would create version tag {tag}.')
        return 0

    token = os.environ.get('RELEASE_TOKEN') or os.environ.get('CI_JOB_TOKEN')
    if not token:
        print('ERROR: Set RELEASE_TOKEN (preferred) or allow CI_JOB_TOKEN for tag creation.')
        return 1

    project_id = os.environ['CI_PROJECT_ID']
    api = os.environ['CI_API_V4_URL'].rstrip('/')
    ref = os.environ['CI_COMMIT_SHA']
    url = f'{api}/projects/{project_id}/repository/tags'
    data = urllib.parse.urlencode({
        'tag_name': tag,
        'ref': ref,
        'message': f'Version {tag}',
    }).encode()
    headers = {
        'User-Agent': 'pelican-rendered-logs-ci',
        'Content-Type': 'application/x-www-form-urlencoded',
    }
    if os.environ.get('RELEASE_TOKEN'):
        headers['PRIVATE-TOKEN'] = token
    else:
        headers['JOB-TOKEN'] = token

    request = urllib.request.Request(url, data=data, method='POST', headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            print(response.read().decode('utf-8', 'replace'))
            print(f'Created version tag {tag} at {ref}')
            return 0
    except urllib.error.HTTPError as error:
        body = error.read().decode('utf-8', 'replace')
        if error.code == 400 and 'already exists' in body.lower():
            print(f'Tag {tag} already exists; nothing to do.')
            return 0
        if 'JOB-TOKEN' in headers and error.code in {401, 403, 405}:
            print(f'Repository Tags API rejected CI_JOB_TOKEN with HTTP {error.code}; trying git push fallback.')
            try:
                return create_tag_with_git(tag, ref)
            except subprocess.CalledProcessError as git_error:
                print(f'ERROR: git push fallback failed with exit code {git_error.returncode}')
                print('Enable CI job-token repository pushes for this project or set a masked RELEASE_TOKEN variable with API/write_repository access.')
                return git_error.returncode or 1
        print(f'ERROR: failed to create tag {tag}: HTTP {error.code}')
        print(body)
        if 'JOB-TOKEN' in headers:
            print('If CI_JOB_TOKEN is not allowed to create repository tags on this GitLab instance, enable CI job-token repository pushes for this project or set a masked RELEASE_TOKEN variable with API/write_repository access.')
        return 1


if __name__ == '__main__':
    raise SystemExit(main())
