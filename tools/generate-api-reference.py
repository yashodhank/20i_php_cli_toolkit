#!/usr/bin/env python3
"""Generate docs/api-reference.md from docs/20i.apib.

Dev tooling only (not a runtime dependency): parses the API Blueprint,
classifies every endpoint (category, safety, toolkit status), and emits a
reference designed for both humans and AI agents.

Usage:  python3 tools/generate-api-reference.py
"""

import re
import sys
from collections import OrderedDict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
APIB = ROOT / "docs" / "20i.apib"
OUT = ROOT / "docs" / "api-reference.md"

# ---------------------------------------------------------------- parsing


def parse_apib(path):
    """Yield endpoint dicts: path, verb, title, action, desc, params,
    attrs, response_sample."""
    lines = path.read_text(errors="replace").splitlines()
    endpoints = []
    resource = None  # (title, path)
    i = 0
    n = len(lines)
    while i < n:
        l = lines[i]
        m = re.match(r"^#{2,4} (.+?) \[(/[^\]]*)\]\s*$", l)
        if m:
            resource = (m.group(1).strip(), m.group(2))
            i += 1
            continue
        v = re.match(r"^#{3,4} (.*?)\s*\[(GET|POST|PUT|DELETE|PATCH)\]\s*$", l)
        if v and resource:
            action, verb = v.group(1).strip(), v.group(2)
            # collect until next action/resource header
            j = i + 1
            block = []
            while j < n:
                nl = lines[j]
                if re.match(r"^#{2,4} .+?\[(/|GET|POST|PUT|DELETE|PATCH)", nl):
                    break
                block.append(nl)
                j += 1
            endpoints.append(parse_block(resource, action, verb, block))
            i = j
            continue
        i += 1
    # dedupe path+verb keeping first
    seen, out = set(), []
    for e in endpoints:
        k = (e["path"], e["verb"])
        if k in seen:
            continue
        seen.add(k)
        out.append(e)
    return out


def parse_block(resource, action, verb, block):
    desc_lines = []
    for l in block:
        s = l.strip()
        if s.startswith("```") or s.startswith("+ Parameters") \
                or s.startswith("+ Attributes") or s.startswith("+ Request") \
                or s.startswith("+ Response"):
            break
        desc_lines.append(l)
    desc = clean_desc("\n".join(desc_lines))

    params = extract_list_section(block, "+ Parameters")
    attrs = extract_list_section(block, "+ Attributes")
    resp = extract_response_sample(block)
    return {
        "title": resource[0],
        "path": resource[1],
        "verb": verb,
        "action": action,
        "desc": desc,
        "params": params,
        "attrs": attrs,
        "resp": resp,
    }


def clean_desc(text):
    text = re.sub(r"\n{2,}", "\n\n", text.strip())
    paras = [p.strip().replace("\n", " ") for p in text.split("\n\n") if p.strip()]
    keep = []
    for p in paras:
        if p.startswith("```") or p.startswith("<?php"):
            break
        keep.append(p)
        if sum(len(x) for x in keep) > 700:
            break
    out = "\n\n".join(keep)
    return out[:900] + ("…" if len(out) > 900 else "")


def extract_list_section(block, header):
    items, active, indent = [], False, None
    for l in block:
        if l.strip().startswith(header):
            active = True
            continue
        if active:
            if re.match(r"^\s*\+ (Parameters|Attributes|Request|Response)", l):
                break
            m = re.match(r"^\s*[+-] `?([\w.$\[\]{}-]+)`?\s*:?\s*(.*)$", l)
            if m and l.strip():
                name = m.group(1)
                rest = m.group(2).strip()
                rest = re.sub(r"\s+", " ", rest)
                items.append((name, rest[:180]))
            if len(items) >= 14:
                items.append(("…", "(more attributes in docs/20i.apib)"))
                break
    return items


def extract_response_sample(block):
    """First non-empty JSON-ish body under a Response section."""
    active, body = False, []
    for l in block:
        if re.match(r"^\s*\+ Response", l):
            active = True
            continue
        if active:
            s = l.strip()
            if re.match(r"^\s*\+ (Request|Parameters|Attributes)", l):
                break
            if s and s not in ("+ Body", "Body"):
                if s.startswith("+ "):
                    continue
                body.append(s)
            if len(body) >= 8:
                body.append("…")
                break
    sample = " ".join(body).strip()
    sample = re.sub(r"\s+", " ", sample)
    if not sample or sample in ("{}", "[]"):
        return sample or ""
    return sample[:260] + ("…" if len(sample) > 260 else "")


# ------------------------------------------------------------ classification

TOOLKIT_IMPLEMENTED = {
    ("/package", "GET"): "all commands (package/domain resolution)",
    ("/package/{packageId}/names", "GET"): "attach/detach/move preflight",
    ("/package/{packageId}/names", "POST"): "attach-domain-to-package, detach, move",
    ("/package/{packageId}/domain/{domainId}/dns", "GET"): "dump-records (called as /package/{id}/dns)",
    ("/package/{packageId}/domain/{domainId}/dns", "POST"): "add/delete/replace-records (called as /package/{id}/dns/{domain})",
    ("/package/{packageId}/allMailForwarders", "GET"): "list/delete/update-forward",
    ("/package/{packageId}/email/{domain}", "POST"): "create/delete/update-forward",
}

TOOLKIT_PROBED = {
    ("/reseller", "GET"), ("/reseller/{resellerId}/accountBalance", "GET"),
    ("/reseller/{resellerId}/packageCount", "GET"),
    ("/reseller/{resellerId}/packageTypes", "GET"),
    ("/reseller/{resellerId}/serviceChangeData", "GET"),
    ("/reseller/{resellerId}/cloudProviders", "GET"),
    ("/reseller/{resellerId}/timelineStorage", "GET"),
    ("/reseller/{resellerId}/virtualNameserver", "GET"),
    ("/reseller/{resellerId}/nominetBrand", "GET"),
    ("/reseller/{resellerId}/cloudServerProductData", "GET"),
    ("/domain", "GET"), ("/domain-period", "GET"), ("/domainPremiumType", "GET"),
    ("/domain-search/{prefix_or_name}", "GET"),
    ("/package/{packageId}/domain/{domainId}/servicePrice", "GET"),
    ("/package/{packageId}/email/{domain}", "GET"),
    ("/package/{packageId}/email/{domain}/forwarder", "GET"),
    ("/package/{packageId}/email/{domain}/mailForwarders", "GET"),
}

BILLING_PAT = re.compile(
    r"/(add(?!StackUser)\w*|renew\w*|upgrade\w+|\w*TurboCredits|\w*Addon)$")
DESTRUCTIVE_PAT = re.compile(
    r"(delete|remove|rebuild|restoreSnapshot|reinstall|splitPackage|"
    r"cancelTransfer|StagingRemoveClone|handleBulkDeleteWeb|resetPassword)",
    re.I)
SIDE_EFFECT_GET = {
    "/reseller/{resellerId}/resetPassword",
    "/reseller/{resellerId}/passwordResetEmail",
}
DOC_ARTIFACT_GETS = {
    "/reseller/{resellerId}/deleteWeb",
    "/reseller/{resellerId}/updatePackage",
    "/reseller/{resellerId}/splitPackage",
    "/reseller/{resellerId}/renewVPS",
}


def safety(path, verb):
    if verb == "GET":
        if path in SIDE_EFFECT_GET:
            return "⚠️ side-effect GET (sends email — treat as a write)"
        if path in DOC_ARTIFACT_GETS:
            return "⚠️ documented GET is likely a doc artifact — do not assume safe"
        return "🟢 read-only"
    if BILLING_PAT.search(path):
        return "💰 billing (charges account balance)"
    if DESTRUCTIVE_PAT.search(path):
        return "🔴 destructive"
    if path.endswith("/userStatus"):
        return "🔴 destructive (suspends/reactivates service)"
    if "/timelineBackup" in path and "takeSnapshot" in path:
        return "🟡 write (snapshot creation — safe)"
    return "🟡 write (config change — verify reversibility before use)"


def toolkit_status(path, verb):
    if (path, verb) in TOOLKIT_IMPLEMENTED:
        return "✅ implemented — " + TOOLKIT_IMPLEMENTED[(path, verb)]
    if (path, verb) in TOOLKIT_PROBED:
        return "🔍 verified live (read-only audit probe)"
    return ""


CATEGORIES = OrderedDict([
    ("Reseller: account, settings & users", dict(
        why="Identity, wallet, branding, product catalog and StackCP "
            "sub-user administration for the reseller account itself.",
        how="Discover your resellerId with `GET /reseller` (first element's "
            "`id`). Balance gates every order/renewal.",
    )),
    ("Reseller: package lifecycle", dict(
        why="Create, resize, split and delete hosting packages.",
        how="`deleteWeb` is the most dangerous endpoint in the API — it "
            "destroys hosting packages. Always resolve and double-check ids "
            "against `GET /package` first; prefer dry-run tooling.",
    )),
    ("Reseller: orders & renewals (billing)", dict(
        why="Everything that spends the prepaid account balance: domains, "
            "packages, VPS, cloud, MSSQL, mailboxes, SSL, add-ons.",
        how="Check `GET /reseller/{id}/accountBalance` first — calls fail on "
            "insufficient funds. `*Pre` variants return price quotes without "
            "charging. Never automate without an explicit confirm gate.",
    )),
    ("Domain registry & commerce", dict(
        why="Availability search, purchasable periods, premium pricing, and "
            "the account-wide registration ledger.",
        how="All read-only. Standard TLD prices are NOT exposed by the API "
            "(panel pricing tables only); `servicePrice` returns premium "
            "prices in GBP wholesale, `null` meaning standard-table price.",
    )),
    ("Domains: transfers", dict(
        why="EPP/auth codes, transfer locks, IPS tags, initiating and "
            "tracking transfers in/out.",
        how="Transfers are multi-day, registry-mediated processes; treat "
            "every POST here as irreversible once the registry accepts it.",
    )),
    ("Domains: registrant, privacy & config", dict(
        why="WHOIS contacts, privacy, opt-outs, per-domain limits and "
            "status on domains attached to packages.",
        how="Contact changes can trigger registrar verification emails and "
            "60-day transfer locks on some TLDs — read the apib notes per "
            "endpoint before writing.",
    )),
    ("DNS", dict(
        why="Zone reads, record diffs, preset configurations (Google/"
            "Office365), DNSSEC and nameserver delegation.",
        how="This toolkit already covers record CRUD: reads via "
            "`GET /package/{id}/dns`, writes as one atomic diff "
            "(`{conflictPolicy, insertPolicy, new:{TYPE:[…]}, delete:[refs]}`) "
            "where delete refs come from each record's `fields.ref` "
            "(never present on SOA). Zone GETs answer only for zone roots. "
            "StackDNS publication can lag 30+ minutes — verify against "
            "authoritative NS, and treat 'pending' as success.",
    )),
    ("Email", dict(
        why="Mailboxes, forwarders, autoresponders, DKIM/DMARC, spam "
            "policy, stats and webmail for each mail domain.",
        how="The path segment is the bare domain (officially `{domain}` "
            "since the 2026-08 blueprint). One POST endpoint manages every "
            "object type; forward deletes use a FLAT id array "
            "`{\"delete\":[\"f<id>\"]}` — the nested variant is silently "
            "ignored. Always verify writes by re-listing "
            "(`allMailForwarders`); a null response is a swallowed 404, "
            "never an empty success.",
    )),
    ("Packages: core", dict(
        why="Package inventory, per-package detail, limits, name/subdomain "
            "mapping, welcome emails, activation status.",
        how="`GET /package` is the master resolver (one call, all packages "
            "with `names[]`). `POST /package/{id}/names` is atomic "
            "add/rem/chg: add is idempotent, removing the last name is "
            "forbidden, removing the primary requires `chg` set to a "
            "surviving name.",
    )),
    ("Web: files, FTP & SSH", dict(
        why="Doc roots, file permissions, FTP users/credentials, SSH "
            "keys/IPs, directory indexing.",
        how="Credentials endpoints return secrets — never log responses.",
    )),
    ("Web: SSL", dict(
        why="Certificate inventory, free SSL issue, external cert install, "
            "force-HTTPS.",
        how="`freeSSL`/`forceSSL` are the routine pair; external install "
            "expects PEM material in the payload (secret-handling care).",
    )),
    ("Web: PHP & runtime", dict(
        why="PHP versions and per-package PHP configuration.",
        how="Read allowed values first (`allowedPhpConfiguration`, "
            "`availablePhpVersions`) — writes validate against them.",
    )),
    ("Web: databases", dict(
        why="MySQL/MSSQL databases and users on a hosting package.",
        how="Removal endpoints are destructive; password endpoints return/"
            "set secrets.",
    )),
    ("Web: CDN & caching", dict(
        why="CDN features, security headers, cache purge/report, "
            "StackCache.",
        how="Feature writes are per-domain; bulk variants exist under "
            "/hosting. Purges are safe but rate-limited in practice.",
    )),
    ("Web: security", dict(
        why="IP/country blocking, hotlink prevention, password protection, "
            "malware scan/report.",
        how="Malware scan POST starts a scan (async); report GET reads "
            "results.",
    )),
    ("Web: applications & builders", dict(
        why="One-click app installs, installed-software inventory, "
            "reinstall, Easy Builder instances.",
        how="`reinstall` overwrites site code — destructive. Easy Builder "
            "SSO endpoints mint login URLs (treat as secrets).",
    )),
    ("Web: WordPress management", dict(
        why="The largest single feature block: WP users, roles, plugins, "
            "themes, settings, staging, checksum, search-replace, update, "
            "StackCache integration.",
        how="Most reads are safe; `wordpressSearchReplace`, `wordpressUpdate` "
            "and staging clone/remove mutate the site and database — snapshot "
            "via timelineBackup takeSnapshot first.",
    )),
    ("Web: backups & restore (Timeline)", dict(
        why="Snapshot listing, on-demand snapshots and restores for web "
            "files, databases and mailboxes.",
        how="`takeSnapshot` is safe and a smart precondition before any "
            "destructive change; `restoreSnapshot` overwrites live data — "
            "gate behind explicit human confirmation.",
    )),
    ("Web: stats & operations", dict(
        why="Bandwidth/disk/usage stats, logs, sitemap, maintenance mode, "
            "redirects, subdomains, scheduled tasks, Windows app pools.",
        how="Mostly read + low-risk config writes; `tasks` POST edits cron.",
    )),
    ("VPS & managed VPS", dict(
        why="Self-managed and managed VPS lifecycle: power, rebuild, VNC, "
            "reverse DNS, backups, names, packages on managed VPS.",
        how="`rebuild` wipes the server; `stop`/`reboot` interrupt service. "
            "Managed-VPS `deleteWeb` destroys hosted packages.",
    )),
    ("Standalone services & bulk ops", dict(
        why="Website Turbo, standalone MSSQL, premium mailboxes, mailbox "
            "quota add-ons, WordPress blueprints, personal nameservers, "
            "timeline storage, SSL approval resend, and /hosting bulk "
            "operations across many packages.",
        how="Bulk endpoints fan one action across packages — a mistake "
            "multiplies; dry-run equivalents don't exist, so build "
            "target lists read-only first.",
    )),
])


def categorize(path):
    s = [x for x in path.split("/") if x]
    root = s[0]
    if root == "reseller":
        tail = s[-1]
        if re.match(r"^(addWeb|deleteWeb|updatePackage|updateWebType|splitPackage|packageTypeBrand)$", tail):
            return "Reseller: package lifecycle"
        if BILLING_PAT.search(path) or tail.endswith("Pre") or "TimelineUpgrade" in tail:
            return "Reseller: orders & renewals (billing)"
        return "Reseller: account, settings & users"
    if root in ("domain", "domain-search", "domain-period", "domainPremiumType",
                "domainVerification"):
        return "Domain registry & commerce"
    if root == "package":
        joined = "/".join(s)
        if "email" in s:
            return "Email"
        if re.search(r"dns|dnssec|nameservers|maxNameservers|defaultDns", joined, re.I):
            return "DNS"
        if "web" in s[:3]:
            w = "/".join(s[3:]) if len(s) > 3 else ""
            if re.search(r"wordpress", w, re.I):
                return "Web: WordPress management"
            if re.search(r"timelineBackup|timelineStorages", w):
                return "Web: backups & restore (Timeline)"
            if re.search(r"Cdn|cache", w, re.I):
                return "Web: CDN & caching"
            if re.search(r"certificates|SSL", w):
                return "Web: SSL"
            if re.search(r"php", w, re.I):
                return "Web: PHP & runtime"
            if re.search(r"sql|database", w, re.I):
                return "Web: databases"
            if re.search(r"malware|blocked|hotlink|passwordProtection", w, re.I):
                return "Web: security"
            if re.search(r"oneclick|installed|reinstall|easyBuilder|clearPendingInstall|pending", w, re.I):
                return "Web: applications & builders"
            if re.search(r"ftp|ssh|filePermissions|directory|documentRoots|homeDirectory", w, re.I):
                return "Web: files, FTP & SSH"
            return "Web: stats & operations"
        if "domain" in s and len(s) >= 4:
            joined_tail = s[-1]
            if re.search(r"[Tt]ransfer|authCode|tag$", joined_tail):
                return "Domains: transfers"
            return "Domains: registrant, privacy & config"
        return "Packages: core"
    if root in ("vps", "managed_vps"):
        return "VPS & managed VPS"
    return "Standalone services & bulk ops"


# ---------------------------------------------------------------- rendering


def php_example(path, verb):
    p = re.sub(r"\{(\w+)\}", r"{$\1}", path)
    call = {"GET": "getWithFields", "POST": "postWithFields",
            "PUT": "putWithFields", "DELETE": "deleteWithFields",
            "PATCH": "postWithFields"}[verb]
    args = f'"{p}"' if verb == "GET" else f'"{p}", $payload'
    return f"$api->{call}({args});"


def render(endpoints):
    cats = OrderedDict((c, []) for c in CATEGORIES)
    for e in endpoints:
        cats.setdefault(categorize(e["path"]), []).append(e)

    out = []
    w = out.append
    w("# 20i API Reference (annotated)")
    w("")
    w("> Generated by `tools/generate-api-reference.py` from `docs/20i.apib`")
    w("> — regenerate after updating the blueprint. Do not hand-edit the")
    w("> endpoint sections; curated notes live in the generator.")
    w("")
    w(f"**{len(endpoints)} documented endpoints** across "
      f"{sum(1 for c in cats.values() if c)} categories.")
    w("")
    w("## How to use this reference")
    w("")
    w("- **Client**: `new \\TwentyI\\API\\Services($api_key)` "
      "(vendored submodule; bearer = the *general* API key only). "
      "Base URL `https://api.20i.com`.")
    w("- **Verbs**: everything is `getWithFields`/`postWithFields`; the "
      "path+payload carry all semantics. HTTP DELETE/PUT exist in the "
      "client but the API models removal via POST payloads.")
    w("- **404s are swallowed** by the client into a PHP notice + `null` "
      "return. `null` ALWAYS means \"not found\", never \"empty success\".")
    w("- **Safety legend**: 🟢 read-only · 🟡 config write · 🔴 destructive "
      "· 💰 charges account balance · ⚠️ trap (side-effect GET or doc "
      "artifact).")
    w("- **Toolkit column**: ✅ implemented by this repo's CLI · 🔍 verified "
      "live read-only during audits · blank = documented but unexercised.")
    w("")
    w("### Rules for AI agents")
    w("")
    w("1. 🟢 endpoints may be called freely. Everything else needs an "
      "operator-approved plan; 💰 additionally needs a balance check and "
      "explicit confirmation; 🔴 needs a snapshot/rollback story first.")
    w("2. Prefer the toolkit CLIs (`scripts/…`) over raw API calls — they "
      "encode preflight, dry-run, verification and journaling.")
    w("3. Never print credential-bearing responses (FTP/SSH/DB/webmail/SSO "
      "endpoints).")
    w("4. Verify every write by re-reading; several endpoints silently "
      "ignore malformed payloads and return 200.")
    w("5. Path ids: resolve packages via `GET /package` (names[] match), "
      "domains via `GET /domain` or package `names`; email paths take the "
      "bare domain.")
    w("")
    w("## Contents")
    w("")
    for c, eps in cats.items():
        if not eps:
            continue
        anchor = re.sub(r"[^a-z0-9]+", "-", c.lower()).strip("-")
        w(f"- [{c}](#{anchor}) ({len(eps)})")
    w("")

    for c, eps in cats.items():
        if not eps:
            continue
        meta = CATEGORIES.get(c, {})
        w(f"## {c}")
        w("")
        if meta:
            w(f"**Why / what for:** {meta['why']}")
            w("")
            w(f"**How / gotchas:** {meta['how']}")
            w("")
        for e in sorted(eps, key=lambda x: (x["path"], x["verb"])):
            w(f"### `{e['verb']} {e['path']}`")
            w("")
            head = f"**{e['title']}**"
            if e["action"] and e["action"].lower() not in e["title"].lower():
                head += f" — {e['action']}"
            w(head)
            w("")
            w(f"- Safety: {safety(e['path'], e['verb'])}")
            ts = toolkit_status(e["path"], e["verb"])
            if ts:
                w(f"- Toolkit: {ts}")
            if e["desc"]:
                w(f"- Notes: {e['desc']}")
            if e["params"]:
                ps = "; ".join(f"`{n}` {d}" for n, d in e["params"][:6])
                w(f"- Path params: {ps}")
            if e["attrs"] and e["verb"] != "GET":
                at = "; ".join(f"`{n}` {d}" for n, d in e["attrs"][:10])
                w(f"- Payload attributes: {at}")
            w(f"- Example: `{php_example(e['path'], e['verb'])}`")
            if e["resp"]:
                w(f"- Response sample: `{e['resp']}`")
            w("")
    return "\n".join(out) + "\n"


def main():
    endpoints = parse_apib(APIB)
    OUT.write_text(render(endpoints))
    print(f"wrote {OUT} ({len(endpoints)} endpoints)")


if __name__ == "__main__":
    sys.exit(main())
