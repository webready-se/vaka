#!/bin/bash
# PreToolUse hook: stop a gh command that would send a real name, domain, IP
# or URL to GitHub in a PR, issue, comment or release text, inline or in a
# --body-file. Commits are covered by scripts/hooks/commit-msg and pre-commit.
# See .claude/rules/no-real-names.md.

CMD=$(jq -r '.tool_input.command // empty' 2>/dev/null)
if ! printf '%s' "$CMD" | grep -qE 'gh (pr|issue) (create|edit|comment|review)|gh release (create|edit)'; then
  exit 0
fi

TEXT="$CMD"
for FILE in $(printf '%s' "$CMD" | grep -oE -- '--body-file[= ]+[^ ;&|]+' | sed -E 's/^--body-file[= ]+//; s/^["'\'']//; s/["'\'']$//'); do
  [ -f "$FILE" ] && TEXT="$TEXT
$(cat "$FILE")"
done

FOUND=$(printf '%s\n' "$TEXT" | VAKA_ROOT="$CLAUDE_PROJECT_DIR" "$CLAUDE_PROJECT_DIR/scripts/hooks/real-names")
if [ -n "$FOUND" ]; then
  echo "Stopped: the text for GitHub contains names from .private/real-names.txt. Describe the case with example names instead (see .claude/rules/no-real-names.md):" >&2
  echo "$FOUND" >&2
  exit 2
fi
exit 0
