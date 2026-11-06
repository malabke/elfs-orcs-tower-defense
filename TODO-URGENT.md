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

- released: JumPHP 0.5.4 (engine 0.5.4 installed on jumphp.net), IDE 0.1.34; jumphp.net deployed; the release `v1.0`
  made by mistake on JumPHP's repository deleted with its tag.

Left:
- try it for real once the IDE is 0.1.34: New Project > CLI app -> a repository on the user's account with `origin`,
  commit + push from the IDE, Publish -> on jumphp.net; Create an Installer > GitHub -> a release of that repository;
- existing projects without `origin` (like todojum): done in IDE 0.1.35: the IDE offers "Create its GitHub
  repository…" (also behind Publish): public repository, commit + push, then a jumphp.net project
  (`POST /api/ide/projects/import`); try it on todojum.

- checked 2026-11-04 (David's Chrome + gh): todojumphp has its own `origin` (github.com/davidnurdin/todojumphp, public, pushed) and is a
  published project on jumphp.net (/todojumphp, "en développement", listed, published 03/11/2026 13:58). Still to try by hand in the IDE:
  Create an Installer > GitHub on it (a release of that repository, not JumPHP's)
