#!/usr/bin/env python3
"""Translate Apache .htaccess security rules into a Juggler route config.

This is the CMS-compatibility bridge: WordPress/Drupal/Laravel ship .htaccess
files that deny access to dotfiles/config, set security headers and rewrite
unknown paths to a front controller (index.php). Juggler replaces scattered
per-directory .htaccess files with one validated, hot-reloadable policy in
routes.json — the rules themselves keep working for CMS users.

Supported directives (the security-relevant subset real CMS files use):
  RewriteEngine On / RewriteRule ^(.*)$ index.php [L]   -> cms_fallback_path
  <FilesMatch "..."> Require all denied </FilesMatch>   -> deny_substrings
  <Files "..."> Deny from all </Files>                  -> deny_substrings
  Header always set X-... "value"                       -> response_headers
  Deny from all (plain, inside <Directory>)             -> deny_substrings: []

Usage:
    python htaccess_import.py /path/to/.htaccess /api > routes_patch.json
    python grpc_client.py routes_patch.json
"""
import json
import re
import sys

FILES_MATCH_RE = re.compile(r"<FilesMatch\s+[\"']?([^\"'>]+)[\"']?>", re.I)
FILES_RE = re.compile(r"<Files\s+[\"']?([^\"'>]+)[\"']?>", re.I)
HEADER_RE = re.compile(
    r"Header\s+(?:always\s+)?set\s+([A-Za-z0-9-]+)\s+[\"']?([^\"'\r\n]+)[\"']?", re.I
)
REWRITE_RE = re.compile(r"RewriteRule\s+\^.*\$\s+([^\s\[\]]+)", re.I)


def clean_pattern(pattern: str) -> list[str]:
    """Turn a FilesMatch regex into plain deny substrings.

    "^(wp-config\\.php|\\.htaccess|\\.git)" -> ["wp-config.php",
    ".htaccess", ".git"]. Escapes are kept as literals (`wp-config.php`);
    regex metacharacters that carry no literal meaning are dropped.
    """
    out = []
    for alt in pattern.split("|"):
        s = alt.strip()
        s = re.sub(r"^\^", "", s)
        s = re.sub(r"\$$", "", s)
        s = s.replace("(?", "").replace(")", "").replace("(", "")
        s = s.replace("\\.", ".").replace("\\-", "-")
        s = s.strip("[]^")
        s = s.strip()
        if s and s not in out:
            out.append(s)
    return out


def translate(htaccess_text: str) -> dict:
    security: dict = {"deny_substrings": [], "response_headers": []}
    fallback = None

    in_files_block = False
    files_pattern = ""
    for raw_line in htaccess_text.splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        m = FILES_MATCH_RE.search(line)
        if m:
            in_files_block, files_pattern = True, m.group(1)
            continue
        m = FILES_RE.search(line)
        if m:
            in_files_block, files_pattern = True, m.group(1)
            continue
        if line.lower().startswith("</files"):
            in_files_block = False
            continue

        # Require all denied / Deny from all inside a <Files> block:
        # translate the pattern into substrings that must never be served.
        if in_files_block and re.search(r"(require all denied|deny from all)", line, re.I):
            for alt in clean_pattern(files_pattern):
                if alt not in security["deny_substrings"]:
                    security["deny_substrings"].append(alt)
            continue

        m = HEADER_RE.search(line)
        if m:
            key, value = m.group(1), m.group(2).strip()
            if (key, value) not in security["response_headers"]:
                security["response_headers"].append((key, value))
            continue

        m = REWRITE_RE.search(line)
        if m and "RewriteEngine on" not in line.lower():
            target = m.group(1).strip()
            if not target.startswith(("http", "/")):
                fallback = "/" + target.lstrip("/")

    result = {"security": security}
    if fallback:
        result["cms_fallback_path"] = fallback
    return result


def main() -> None:
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(1)
    htaccess_path, route_prefix = sys.argv[1], sys.argv[2]
    # Git Bash / MSYS mangles leading-slash args into Windows paths; restore
    # anything that is not an existing file path to a leading slash.
    if route_prefix.startswith("C:/Program Files/Git/"):
        route_prefix = route_prefix[len("C:/Program Files/Git") :]
    with open(htaccess_path, encoding="utf-8") as f:
        text = f.read()

    translation = translate(text)
    route_cfg: dict = {"upstream_addrs": [], "security": translation["security"]}
    if "cms_fallback_path" in translation:
        route_cfg["security"]["cms_fallback_path"] = translation["cms_fallback_path"]

    out = {route_prefix: route_cfg}
    json.dump(out, sys.stdout, indent=2)
    print()


if __name__ == "__main__":
    main()
