# /git-save

Inspect the current git working tree and do the following:

1. Review changed files and git diff.
2. Propose a short semantic branch name in kebab-case.
3. Create and switch to that new branch.
4. Stage all relevant changes.
5. Create one conventional commit message based on the actual changes.
6. Commit the changes.
7. At the end, print:
    - created branch name
    - commit message
    - short git status

Rules:

- Use conventional commits: feat, fix, refactor, chore, docs, test, perf.
- Keep the commit title under 72 chars.
- Be specific, based on the actual diff.
- If there are no changes, say so and stop.
- If branch already exists, append a short suffix.
