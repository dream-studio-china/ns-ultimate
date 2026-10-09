# Core subtree workflow

The upstream remotes are named `crud-admin` and `crud-skeleton`. Their tracking prefixes are `core/crud-admin` and `core/crud-skeleton` respectively. Use normal (non-squashed) subtree history so upstream ancestry and the ability to propose core changes remain available.

## Update a core

Fetch the named upstream and pull its canonical branch into the corresponding prefix:

```sh
git fetch crud-admin master
git subtree pull --prefix=core/crud-admin crud-admin master

git fetch crud-skeleton main
git subtree pull --prefix=core/crud-skeleton crud-skeleton main
```

Review the merge, inspect upstream changes and license notices, run that core's checks, then run integration/regression tests. Do not automatically track a moving upstream branch in production; the subtree merge commit in this repository pins the selected source state.

## Propose a core change upstream

Keep the core change separate from business and integration commits. Split and push only the relevant prefix to a topic branch (never push directly to a protected default branch):

```sh
git subtree split --prefix=core/crud-admin -b export/crud-admin-change
git push crud-admin export/crud-admin-change:refs/heads/ns-ultimate/<topic>
```

For backend changes, replace the prefix and remote with `core/crud-skeleton` and `crud-skeleton`. Open a PR from that topic branch to the upstream default branch. After it is merged, fetch upstream and subtree-pull the merged commit back into this repository, then remove temporary local export branches when no longer needed.

Before pushing, verify the split contains only intended core changes. A subtree split exports the prefix history; avoid mixing application changes into the core prefix. Never use force-push on shared or protected upstream branches.

## Repository setup

Subtree remotes are local Git configuration and are not included in a clone. Set them up once per clone:

```sh
git remote add crud-admin https://github.com/immane/crud-admin.git
git remote add crud-skeleton https://github.com/immane/crud-skeleton.git
```

If a remote already exists, update its URL instead of adding a duplicate.
