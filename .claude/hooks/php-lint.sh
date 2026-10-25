#!/bin/bash
# PostToolUse hook: syntax-check PHP files after edit/write
# Reads tool result JSON from stdin, extracts file_path, runs php -l

FILE=$(jq -r '.tool_input.file_path // empty' 2>/dev/null)

# Only check .php files and the extensionless CLI entrypoint
if [[ -z "$FILE" ]]; then
  exit 0
fi
if [[ "$FILE" != *.php && "$(basename "$FILE")" != "vaka" ]]; then
  exit 0
fi

OUTPUT=$(php -l "$FILE" 2>&1)
if [[ $? -ne 0 ]]; then
  echo "$OUTPUT" >&2
  exit 2
fi

exit 0
