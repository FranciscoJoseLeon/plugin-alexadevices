#!/usr/bin/env python3
"""
Monitors upstream Alexa API library (aioamazondevices) releases and API-level
changes so the Jeedom alexadevices plugin stays compatible with Amazon API updates.
"""

import json
import os
import re
import urllib.request
from pathlib import Path

PYPI_URL = "https://pypi.org/pypi/aioamazondevices/json"
GH_RELEASE_URL = "https://api.github.com/repos/chemelli74/aioamazondevices/releases/latest"
GH_COMMITS_URL = "https://api.github.com/repos/chemelli74/aioamazondevices/commits?per_page=8"

ROOT_DIR = Path(__file__).resolve().parent.parent
REQ_FILE = ROOT_DIR / "resources" / "requirements.txt"
STATE_FILE = ROOT_DIR / "tools" / ".api_state.json"
REPORT_FILE = ROOT_DIR / "tools" / "last_api_report.md"


def fetch_json(url: str) -> dict | list:
    headers = {
        "User-Agent": "Jeedom-AlexaDevices-Watcher/1.0",
        "Accept": "application/vnd.github.v3+json",
    }
    token = os.environ.get("GITHUB_TOKEN")
    if token and "api.github.com" in url:
        headers["Authorization"] = f"token {token}"
    req = urllib.request.Request(url, headers=headers)
    with urllib.request.urlopen(req, timeout=15) as resp:
        return json.loads(resp.read().decode("utf-8"))


def get_pinned_version() -> str:
    if not REQ_FILE.exists():
        return ""
    for line in REQ_FILE.read_text(encoding="utf-8").splitlines():
        if line.strip().startswith("aioamazondevices"):
            m = re.search(r"[>=~]=?([0-9.]+)", line)
            if m:
                return m.group(1)
    return ""


def load_state() -> dict:
    if STATE_FILE.exists():
        try:
            return json.loads(STATE_FILE.read_text(encoding="utf-8"))
        except Exception:
            return {}
    return {}


def save_state(state: dict):
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    STATE_FILE.write_text(json.dumps(state, indent=2), encoding="utf-8")


def set_gh_output(name: str, value: str):
    out = os.environ.get("GITHUB_OUTPUT")
    if out:
        with open(out, "a", encoding="utf-8") as f:
            f.write(f"{name}={value}\n")


def main():
    state = load_state()
    pinned = get_pinned_version()
    changes_detected = False
    version_changed = False
    report = []

    latest_ver = pinned
    try:
        pypi = fetch_json(PYPI_URL)
        latest_ver = pypi["info"]["version"]
        if latest_ver and latest_ver != pinned:
            version_changed = True
            changes_detected = True
            report.append(f"### Nueva versión de `aioamazondevices` (Alexa API): `{latest_ver}` (actual: `{pinned}`)")
            # Update requirements.txt for PR
            if REQ_FILE.exists():
                lines = REQ_FILE.read_text(encoding="utf-8").splitlines()
                new_lines = [
                    f"aioamazondevices>={latest_ver}" if l.strip().startswith("aioamazondevices") else l
                    for l in lines
                ]
                REQ_FILE.write_text("\n".join(new_lines) + "\n", encoding="utf-8")
    except Exception as e:
        print(f"Error checking PyPI: {e}")

    try:
        rel = fetch_json(GH_RELEASE_URL)
        tag = rel.get("tag_name", "")
        if tag and tag != state.get("last_release_tag"):
            changes_detected = True
            state["last_release_tag"] = tag
            body = (rel.get("body") or "").strip()
            report.append(f"### Release Notes `{tag}`\n{body[:1500]}")
    except Exception as e:
        print(f"Error checking GitHub release: {e}")

    try:
        commits = fetch_json(GH_COMMITS_URL)
        last_sha = state.get("last_commit_sha")
        new_commits = []
        for c in commits:
            if c["sha"] == last_sha:
                break
            msg = c["commit"]["message"].splitlines()[0]
            new_commits.append(f"- [`{c['sha'][:7]}`]({c['html_url']}) {msg}")
        if commits:
            state["last_commit_sha"] = commits[0]["sha"]
        if new_commits and last_sha:
            changes_detected = True
            report.append("### Commits recientes en la API:\n" + "\n".join(new_commits))
    except Exception as e:
        print(f"Error checking GitHub commits: {e}")

    state["last_version"] = latest_ver
    save_state(state)

    if changes_detected:
        REPORT_FILE.write_text("\n\n".join(report) + "\n", encoding="utf-8")
        print("Changes detected in Alexa API / aioamazondevices.")
    else:
        print("No new Alexa API changes.")

    set_gh_output("changes_detected", "true" if changes_detected else "false")
    set_gh_output("version_changed", "true" if version_changed else "false")
    set_gh_output("api_version", latest_ver or "unknown")


if __name__ == "__main__":
    main()
