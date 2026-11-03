# TODO URGENT: bugs to fix before anything else (before TODO-FIRST.md and TODO.md)

## Every project its own GitHub repository, publishing from the IDE (reported 2026-11-03, fixed the same day)

Reported by David on a CLI app made with the IDE (`todojum`): `git push` failed, the only remote was `jumphp` (the
framework), and Create an Installer > GitHub made the release `v1.0` **on JumPHP's repository** (its notes said
"Windows, Linux, macOS" though only Linux was made).

Done:
- IDE New Project (`games/ide/src/Creator.php`): every kind needs the jumphp.net sign-in and gets the user's own
  repository: games and apps through jumphp.net (`Creator::PLAYGROUND_KINDS`: gui, mobile, desktop, cli; hybrid = a
  game + `ui: hybrid`), IDE plugins through GitHub's API with the user's token; the remote `jumphp` is fetch-only;
- jumphp.net: `POST /api/ide/projects` accepts the application kinds (it could already make them);
- Publish (IDE) pushes the commits not pushed yet, then syncs;
- `bin/jumphp`: `project_github_repo` (origin only, never JumPHP's from a project) for `installer --github`, `android`,
  `apple`, `pages`; `gh release` always with `--repo`; the release notes name the systems really made;
  `post-create.sh`, `init.sh`, `update`: the `jumphp` remote cannot be pushed to;
- Create an Installer: remembers the ticked systems, no longer ticks macOS by itself (default: Linux).

Left:
- delete the release `v1.0` (and its tag) made by mistake on JumPHP's repository, put "Latest" back on `v0.5.3`
  (David's yes needed);
- release the IDE (the fix reaches the installed IDEs with a new version) and deploy jumphp.net;
- existing projects without `origin` (like todojum): not needed for now (David), maybe later a "Create its GitHub
  repository" button in the IDE.
