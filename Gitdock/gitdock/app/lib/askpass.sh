#!/bin/sh
# Git credential helper for GitDock.
# The token is passed in the environment for a single git invocation and never written to disk.
case "$1" in
  *Username*) printf '%s\n' "${GITDOCK_LOGIN:-x-access-token}" ;;
  *Password*) printf '%s\n' "${GITDOCK_TOKEN:-}" ;;
  *)          printf '\n' ;;
esac
