#!/usr/bin/env python3
"""Verify lang/zh-CN.json against other locales: keys, JSON, placeholders."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLACEHOLDER = re.compile(r":[A-Za-z][A-Za-z0-9_]*")
HTML = re.compile(r"</?[A-Za-z][^>]*>")


def load(name: str) -> dict[str, str]:
    path = ROOT / "lang" / name
    raw = path.read_text(encoding="utf-8")
    data = json.loads(raw)
    if not isinstance(data, dict):
        raise SystemExit(f"{path} is not a JSON object")
    for k, v in data.items():
        if not isinstance(k, str) or not isinstance(v, str):
            raise SystemExit(f"{path} has a non-string entry at {k!r}")
        if v.strip() == "":
            raise SystemExit(f"{path} has an empty translation for {k!r}")
    return data


def main() -> int:
    es = load("es.json")
    zh = load("zh-CN.json")
    errors: list[str] = []

    missing = sorted(set(es) - set(zh))
    extra = sorted(set(zh) - set(es))
    if missing:
        errors.append(f"zh-CN missing {len(missing)} keys, first: {missing[:8]}")
    if extra:
        errors.append(f"zh-CN has {len(extra)} unexpected keys, first: {extra[:8]}")

    placeholder_mismatches = []
    html_mismatches = []
    for key in es:
        if key not in zh:
            continue
        src = set(PLACEHOLDER.findall(key))
        dst = set(PLACEHOLDER.findall(zh[key]))
        if src != dst:
            placeholder_mismatches.append((key, sorted(src), sorted(dst), zh[key]))
        if HTML.findall(key) and HTML.findall(key) != HTML.findall(zh[key]):
            html_mismatches.append((key, zh[key]))

    if placeholder_mismatches:
        errors.append(f"{len(placeholder_mismatches)} placeholder mismatches, first:")
        for key, src, dst, val in placeholder_mismatches[:8]:
            errors.append(f"  key={key!r} expected={src} got={dst} zh={val!r}")
    if html_mismatches:
        errors.append(f"{len(html_mismatches)} HTML mismatches, first: {html_mismatches[0]}")

    spots = {
        "Updates": "更新",
        "Welcome": "欢迎",
        "Account": "账户",
        "API keys": "API 密钥",
        "Something went wrong. Please try again.": "出了点问题。请再试一次。",
    }
    print(f"keys es={len(es)} zh-CN={len(zh)}")
    print("spot checks:")
    for key, expected in spots.items():
        if key not in zh:
            errors.append(f"spot check missing key {key!r}")
            continue
        print(f"  {key!r} => {zh[key]!r}")
        if zh[key] != expected:
            errors.append(f"spot check failed for {key!r}: {zh[key]!r} != {expected!r}")

    for key in (
        "To update from here, run serveros-panel upgrade && serveros-panel schedule on the server once.",
        "The update didn’t finish. See /var/log/serveros-panel-update.log on the server.",
    ):
        if key in zh:
            if "serveros-panel" in key and "serveros-panel" not in zh[key]:
                errors.append(f"command missing in translation of {key!r}")
            if "/var/log/serveros-panel-update.log" in key and "/var/log/serveros-panel-update.log" not in zh[key]:
                errors.append(f"path missing in translation of {key!r}")

    if errors:
        print("FAIL")
        for item in errors:
            print(item)
        return 1
    print("OK")
    return 0


if __name__ == "__main__":
    sys.exit(main())
