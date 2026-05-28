# PocketMine-MP Fork Operations

## Repository Boundary

- This directory is the PocketMine-MP source fork used by CoreWar.
- Keep PocketMine-MP source commits separate from CoreWar runtime and deploy changes.
- Do not commit CoreWar runtime state, generated phars, maps, worlds, logs, crash dumps, vendor artifacts, or local caches from this repository.
- Treat CoreWar deployment files as belonging to the parent CoreWar repository, not to this PocketMine-MP fork.

## Branches And Remotes

- Work on the `stable` branch unless a task explicitly says otherwise.
- Push PocketMine-MP source and documentation commits to `origin` (`https://github.com/bellemyviolet/PocketMine-MP.git`).
- Treat `pmmp-ng` (`https://github.com/shawtymarco/PocketMine-MP-NG.git`) as an upstream/reference remote unless the user explicitly asks to push there.
- Before changing files, check:

  ```powershell
  git status --short --branch
  git remote -v
  ```

## Release Behavior

- `.github/workflows/stable-phar-release.yml` builds and publishes `PocketMine-MP.phar` for pushes to `stable`.
- Real PMMP source changes should normally allow that workflow to run so a release phar is produced.
- For docs-only or operations-only commits that should not build a phar, include `[skip ci]` in the commit message.
- Do not manually create or overwrite stable release tags unless explicitly requested.

## Deployment Flow

- After pushing a real PMMP source commit to `stable`, wait for the GitHub release whose tag is `stable-<commit-sha>`.
- Use `gh` from the CoreWar workspace or this repository to inspect and download the release:

  ```powershell
  gh release list --repo bellemyviolet/PocketMine-MP --limit 5
  gh release view --repo bellemyviolet/PocketMine-MP
  gh release download <tag> --repo bellemyviolet/PocketMine-MP --pattern PocketMine-MP.phar --pattern PocketMine-MP.phar.sha256 --dir <download-dir>
  ```

- Verify the downloaded `PocketMine-MP.phar` SHA256 against `PocketMine-MP.phar.sha256` before deploying it.
- Copy the verified phar to the CoreWar shared phar location:

  ```powershell
  Copy-Item <download-dir>\PocketMine-MP.phar ..\..\Server\shared\PocketMine-MP.phar -Force
  Get-FileHash ..\..\Server\shared\PocketMine-MP.phar -Algorithm SHA256
  ```

- `Server/shared/PocketMine-MP.phar` is a CoreWar runtime artifact. It is intentionally not committed here.

## Runtime Rollout

- Validate the CoreWar compose configuration after replacing the shared phar:

  ```powershell
  docker compose -f ..\..\Server\deploy\docker-compose.yml config --quiet
  ```

- Recreate the PMMP services so containers reload the mounted phar:

  ```powershell
  docker compose -f ..\..\Server\deploy\docker-compose.yml up -d --force-recreate corewar-hub corewar-lobby corewar-land1 corewar-land2 corewar-land3 corewar-land4 corewar-land5
  ```

- If Docker is not available locally, report that the phar was replaced but service recreation could not be performed.

## Validation And Closeout

- For PHP source changes, run `php -l` on every changed PHP file at minimum.
- For docs-only changes, run:

  ```powershell
  git diff --check
  ```

- Before committing, confirm only task-related files are staged.
- Push the completed commit to `origin stable` unless the user requested a different branch or remote.
