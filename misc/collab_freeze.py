#!/usr/bin/env python3
"""Collaboration freeze for the AI agents working on this repository.

Any participating agent (Claude Code or Codex) can stop all changes by opening
a GitHub issue labelled `freeze`. Only a human releaser can lift it. See
docs/CLAUDE_COLLABORATION.md.

Commands:
  status            Exit 0 when not frozen, 3 when frozen, 2 when unknown.
  publish-statuses  (GitHub Actions) Set the `collab-freeze` commit status on
                    the head commit of every open pull request.
  guard             (GitHub Actions) Undo a freeze lift by anyone who is not
                    a releaser: reopen the issue or put the label back.

Environment:
  GITHUB_REPOSITORY  owner/repo (default oyst12rsas/taransvar)
  GITHUB_TOKEN       optional for `status`, required for the Actions commands
  FREEZE_RELEASERS   comma-separated GitHub logins who may lift a freeze
                     (default oyst12rsas)
"""

import json
import os
import sys
import urllib.error
import urllib.request

LABEL = "freeze"
STATUS_CONTEXT = "collab-freeze"
DEFAULT_REPO = "oyst12rsas/taransvar"
DEFAULT_RELEASERS = "oyst12rsas"
# Labels can only be applied by people with triage or write access, but check
# the author as well so a mislabelled outside issue cannot stop the project.
TRUSTED_ASSOCIATIONS = {"OWNER", "MEMBER", "COLLABORATOR"}

EXIT_CLEAR, EXIT_UNKNOWN, EXIT_FROZEN = 0, 2, 3


def releasers():
    raw = os.environ.get("FREEZE_RELEASERS", DEFAULT_RELEASERS)
    return {name.strip().lower() for name in raw.split(",") if name.strip()}


def has_label(issue):
    return any((label.get("name") if isinstance(label, dict) else label) == LABEL
               for label in issue.get("labels", []))


def active_freezes(issues):
    """Open, labelled freeze issues written by a trusted account. Pull requests are ignored."""
    return [issue for issue in issues
            if isinstance(issue, dict)
            and issue.get("state") == "open"
            and "pull_request" not in issue
            and has_label(issue)
            and issue.get("author_association") in TRUSTED_ASSOCIATIONS]


def guard_decision(event_name, payload, allowed):
    """What to undo for an `issues` event, or None. `allowed` is the set of releaser logins."""
    if event_name != "issues":
        return None
    action = payload.get("action")
    issue = payload.get("issue") or {}
    actor = ((payload.get("sender") or {}).get("login") or "").lower()
    if actor in allowed:
        return None
    if action == "closed" and has_label(issue):
        return "reopen"
    if action == "unlabeled" and (payload.get("label") or {}).get("name") == LABEL \
            and issue.get("state") == "open":
        return "relabel"
    return None


def describe(freezes):
    if not freezes:
        return "No collaboration freeze."
    first = freezes[0]
    text = "Frozen by #%s: %s" % (first.get("number"), first.get("title", ""))
    if len(freezes) > 1:
        text += " (+%d more)" % (len(freezes) - 1)
    return text


class GitHub:
    def __init__(self, repo=None, token=None):
        self.repo = repo or os.environ.get("GITHUB_REPOSITORY") or DEFAULT_REPO
        self.token = token if token is not None else os.environ.get("GITHUB_TOKEN", "")

    def request(self, method, path, body=None):
        url = path if path.startswith("https://") else "https://api.github.com/repos/" + self.repo + path
        headers = {"Accept": "application/vnd.github+json", "User-Agent": "tarasec-collab-freeze",
                   "X-GitHub-Api-Version": "2022-11-28"}
        if self.token:
            headers["Authorization"] = "Bearer " + self.token
        data = None
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            headers["Content-Type"] = "application/json"
        req = urllib.request.Request(url, data=data, headers=headers, method=method)
        with urllib.request.urlopen(req, timeout=15) as response:
            raw = response.read()
        return json.loads(raw) if raw else None

    def paged(self, path):
        items, page = [], 1
        while True:
            sep = "&" if "?" in path else "?"
            batch = self.request("GET", "%s%sper_page=100&page=%d" % (path, sep, page))
            if not isinstance(batch, list):
                raise ValueError("Unexpected GitHub response")
            items.extend(batch)
            if len(batch) < 100:
                return items
            page += 1

    def freezes(self):
        return active_freezes(self.paged("/issues?state=open&labels=" + LABEL))


def status(gh):
    try:
        freezes = gh.freezes()
    except (OSError, ValueError, urllib.error.URLError) as error:
        print("Freeze status unknown (%s); treat as frozen." % type(error).__name__)
        return EXIT_UNKNOWN
    print(describe(freezes))
    return EXIT_FROZEN if freezes else EXIT_CLEAR


def publish_statuses(gh):
    freezes = gh.freezes()
    state = "failure" if freezes else "success"
    description = describe(freezes)[:140]
    target = ("https://github.com/%s/issues/%s" % (gh.repo, freezes[0]["number"])) if freezes else None
    pulls = gh.paged("/pulls?state=open")
    for pull in pulls:
        body = {"state": state, "context": STATUS_CONTEXT, "description": description}
        if target:
            body["target_url"] = target
        gh.request("POST", "/statuses/" + pull["head"]["sha"], body)
    print("%s on %d open pull request(s): %s" % (state, len(pulls), description))
    return 0


def guard(gh):
    with open(os.environ["GITHUB_EVENT_PATH"], encoding="utf-8") as source:
        payload = json.load(source)
    decision = guard_decision(os.environ.get("GITHUB_EVENT_NAME", ""), payload, releasers())
    if not decision:
        return 0
    number = payload["issue"]["number"]
    actor = payload["sender"]["login"]
    if decision == "reopen":
        gh.request("PATCH", "/issues/%d" % number, {"state": "open"})
    else:
        gh.request("POST", "/issues/%d/labels" % number, {"labels": [LABEL]})
    gh.request("POST", "/issues/%d/comments" % number, {"body":
        "The freeze is still active. `%s` is not allowed to lift it; only %s can, "
        "after review. See docs/CLAUDE_COLLABORATION.md." %
        (actor, ", ".join("`%s`" % r for r in sorted(releasers())))})
    print("Undid freeze lift on #%d by %s (%s)" % (number, actor, decision))
    return 0


def main(argv):
    command = argv[1] if len(argv) > 1 else "status"
    gh = GitHub()
    if command == "status":
        return status(gh)
    if command == "publish-statuses":
        return publish_statuses(gh)
    if command == "guard":
        return guard(gh)
    print(__doc__)
    return 64


if __name__ == "__main__":
    sys.exit(main(sys.argv))
