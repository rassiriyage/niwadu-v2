# Second Claude agent: Windows setup and collaboration rules

Hand this whole file to the Claude Code session on the Windows machine. It tells that agent how to get the Niwadu v2 repository running and how to work alongside the other Claude agent without the two overwriting each other.

## For the Claude agent reading this

You are the **Windows agent** for the Niwadu v2 project. Another Claude agent (the **primary agent**, running from a different Claude account on the owner's Mac and in Claude Code cloud sessions) works on the same repository at the same time.

The two Claude accounts are not linked, and you cannot message the other agent directly. You coordinate only through GitHub:

- **Repository:** https://github.com/rassiriyage/niwadu-v2 (default branch `main`)
- **Branches:** each agent works on its own branches and never pushes to the other's.
- **Pull requests:** all work reaches `main` through a pull request that the owner merges.
- **GitHub Issues:** these record which agent owns which task.

Before you write any code, read `AGENTS.md`, `docs/requirements.md`, `tasks/plan.md` and `tasks/todo.md`. The rules in `AGENTS.md` take precedence over anything here. Be especially careful with these:

- Never use the existing Frappe/Surge database, and never run migrations against it. The Frappe bench lives on the owner's Mac only. Do not try to reach it from Windows.
- Do not send real payments, bookings, invitations or live PMS updates.
- Never commit secrets. Local `.env` files are git-ignored. Ask the owner for real values and have them typed directly into the file, not pasted into chat.
- Never call a stub a working integration.

## 1. Install the toolchain (one time)

Run these in PowerShell. If `winget` is not available, install each tool from its official site instead.

```powershell
winget install --id Git.Git -e
winget install --id GitHub.cli -e
winget install --id OpenJS.NodeJS --version 24.* -e   # project requires Node 24.x (see .nvmrc)
winget install --id PHP.PHP.8.4 -e
winget install --id Composer.Composer -e
```

Close and reopen the terminal, then check the versions:

```powershell
git --version; gh --version; node -v; npm -v; php -v; composer -V
```

`node -v` must report `v24.x` and `php -v` must report `8.4.x`. If winget's Node package will not pin a major version, use `nvm-windows` (`winget install CoreyButler.NVMforWindows`, then `nvm install 24` and `nvm use 24`).

**Enable the PHP extensions.** Laravel needs extensions that a fresh Windows PHP install leaves disabled. Run `php --ini` to find `php.ini`. If the file does not exist, copy `php.ini-development` to `php.ini`. Then uncomment (remove the leading `;` from) these lines:

```
extension_dir = "ext"
extension=curl
extension=fileinfo
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
```

Check that they loaded with `php -m`. The list must include `pdo_sqlite`, `mbstring`, `openssl` and `fileinfo`.

## 2. Connect to GitHub

The owner does this step, not the agent. Credentials are never pasted into chat.

1. Sign in to GitHub:
   ```powershell
   gh auth login        # choose GitHub.com → HTTPS → "Login with a web browser"
   gh auth setup-git    # lets git push using the gh credential
   ```
   Use the owner's GitHub account (`rassiriyage`), or another GitHub account that the owner has added as a collaborator with write access (repo **Settings → Collaborators**).
2. Set the commit identity for this machine:
   ```powershell
   git config --global user.name  "<your name>"
   git config --global user.email "<your GitHub email or noreply address>"
   ```
3. Keep line endings LF. The repository's `.gitattributes` already forces LF. This setting also stops Git for Windows from converting files to CRLF on checkout:
   ```powershell
   git config --global core.autocrlf false
   ```
   CRLF line endings would make CI's Pint formatting check fail.
4. Check that GitHub access works: `gh repo view rassiriyage/niwadu-v2` should print the repository description.

**Using Claude Code on the web from this second account** (claude.ai/code instead of the local CLI) requires two more things. The second Claude account must connect its own GitHub account at https://claude.ai/settings/connectors (or by following the GitHub prompt the first time a session starts). The Claude GitHub App must also be allowed on `rassiriyage/niwadu-v2`. Connectors such as Railway, Sentry, Figma and Google Drive are tied to each Claude account, so connect them on the second account only if this agent needs them.

## 3. Clone and set up the project

Choose a short path. Long Windows paths break `node_modules` and `vendor`.

```powershell
cd C:\dev
git clone https://github.com/rassiriyage/niwadu-v2.git
cd niwadu-v2

# Frontend
npm --prefix apps/web ci

# API (local SQLite only, never the Frappe database)
cd apps/api
composer install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan key:generate
if (-not (Test-Path database\database.sqlite)) { New-Item database\database.sqlite -ItemType File }
php artisan migrate
cd ..\..
```

Then run the same checks CI runs. All of them must pass before you start any work:

```powershell
npm run check:web
npm run build:web
cd apps/api; vendor\bin\pint --test; php artisan test; cd ..\..
```

The root `package.json` scripts use `cd apps/api && ...`. That works in PowerShell 7+ and in Git Bash. On Windows PowerShell 5.1, run the API commands from inside `apps/api` instead.

To run the apps locally, use two terminals: `npm run dev:web` (http://127.0.0.1:3000/api/health) and `npm run dev:api` (http://127.0.0.1:8000/up).

## 4. Working at the same time as the primary agent

### Claim a task before starting it

1. Pick work only from the items the owner assigned to you, or from `tasks/todo.md` / `tasks/plan.md` when the owner says to.
2. Check for an existing GitHub issue for the task: `gh issue list --state open`. If one exists and is labelled `agent:primary`, it is taken. Choose something else.
3. If no issue exists, create one and label it as yours:
   ```powershell
   gh label create agent:windows --color 1d76db 2>$null
   gh issue create --title "<task>" --label agent:windows --body "Claimed by the Windows agent. Plan: ..."
   ```
4. If the owner has not given you a task, ask them. Do not guess.

### Branches

- Always branch from the latest `main`:
  ```powershell
  git fetch origin
  git switch -c win/<short-topic> origin/main
  ```
- Your branches start with `win/`. The primary agent's branches start with `claude/` (or `mac/`). Never commit to, push to, rebase or force-push a branch you did not create.
- Never push directly to `main`.
- Keep each branch to one task and keep pull requests small. Small pull requests merge quickly and conflict less.

### Avoid conflicts with the primary agent

- Tasks are split by area to keep the agents' changes apart. Unless the owner says otherwise, stay inside the files your task needs. Before editing shared files, say so in your issue. Shared files include `AGENTS.md`, `docs/requirements.md`, `tasks/plan.md`, `package.json`, `composer.json`, lockfiles, `.github/workflows/*` and `apps/api/config/*`.
- Change dependencies only when your task requires it. Regenerate lockfiles with `npm install` or `composer update <pkg>`. Never edit lockfiles by hand.
- Laravel migrations: always create them with `php artisan make:migration`, which gives each a unique timestamp. Never edit a migration that is already on `main`. Add a new one instead.
- In `tasks/todo.md`, change only the checklist lines for your own task.
- Before opening a pull request, and again whenever `main` moves, merge the latest `main` into your branch and rerun the checks:
  ```powershell
  git fetch origin
  git merge origin/main
  ```

### Commits and pull requests

- End every commit message with the trailer `Agent: windows`. This lets the owner see which agent made which change in `git log`.
- Push and open a pull request:
  ```powershell
  git push -u origin win/<short-topic>
  gh pr create --base main --fill --label agent:windows
  ```
  In the pull request description, include `Closes #<issue>`, what changed, and the checks you ran with their results.
- Leave merging to the owner. Do not merge your own pull request unless the owner tells you to.
- After a pull request merges, delete your local branch and start the next task from a fresh `origin/main`.

### Communicating with the primary agent

The only shared channels are GitHub issues and pull requests. If your work depends on, or would change, something the primary agent owns, comment on that agent's issue or pull request and tell the owner. Do not change that agent's code yourself.

## 5. Owner checklist

- [ ] Install the toolchain and enable the PHP extensions on the Windows machine (section 1).
- [ ] Run `gh auth login` with an account that has write access to `rassiriyage/niwadu-v2` (section 2).
- [ ] Optional but recommended: protect `main` under repo **Settings → Branches**. Require a pull request and the passing **Checks** workflow before merging. Neither agent can then push to `main` by accident.
- [ ] Give each agent a separate area of work (for example, Windows on `apps/web` frontend tasks and primary on `apps/api` domain tasks, or split by item in `tasks/todo.md`), and say so in each agent's first prompt.
- [ ] Put real secrets only in the ignored local `.env` files on each machine. Never put them in chat, issues or commits.

## 6. Suggested first prompt for the Windows agent

> Read `docs/second-agent-setup.md` and follow sections 1–4. Confirm the toolchain versions and that the CI checks pass locally, then stop and tell me. Do not start any feature work until I give you a task. Use `win/` branches, claim tasks with the `agent:windows` label, and open a pull request for every change.
