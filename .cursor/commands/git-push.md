# /git-push

Inspect the current git working tree and do the following:

1. Review changed files and git diff.
2. Stage all changes (`git add -A`).
3. Propose a conventional commit message based on the actual changes.
4. Commit with that message.
5. Push to the current branch (`git push origin HEAD` or `git push -u origin HEAD` if needed).
6. At the end, print:
   - commit message
   - branch name
   - short git status

Rules:

- Use conventional commits: feat, fix, refactor, chore, docs, test, perf.
- Keep the commit title under 72 chars.
- Be specific, based on the actual diff.
- If there are no changes, say so and stop.
- If there are no staged changes after add, say so and stop.
