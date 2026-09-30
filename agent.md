# Working rules for this repository

## GUI rule

- This project is a Kimai plugin. Its GUI is generated from Knust (`shrippen/kimai-knust-bundle`),
  the Kante spinoff that adapts Kante to Kimai's look, not inspired by it: use Knust's tokens,
  classes, macros and components as they are.
- No own colours, fonts, sizes, radii, shadows, animation timings, no own copy or variant of
  a component that Knust has. Raw values (`#hex`, `px` for controls) are a bug.
- A missing element is added to Knust first, then used here. Where it would also help other
  projects, it is added to Kante as well (https://github.com/shrippen/shrippen.github.io, `kante/`).
- Rule text for all projects: https://github.com/shrippen/shrippen.github.io/blob/main/kante/AGENT-RULE.md

## Repository rule

- This repository lives on Gitea (`git.arianw.de`). GitHub is only a push mirror of it.
- Changes arrive as pull requests only: work on a branch, open a PR, leave the merge to the owner (who merges on Gitea; the mirror follows).
- Never merge a PR, push to `main` (or any default branch), push tags or publish releases on GitHub. A merge there is overwritten by the next Gitea push.
- Never force-push a branch that someone else's PR depends on.
