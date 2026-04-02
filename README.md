# dpl

A minimal PHP CLI deploy tool that uses `git` and `rsync` to transfer only
the files that changed since the last deploy to a remote host over SSH.

## Requirements

- PHP 8.3 or higher (CLI)
- `git`
- `rsync`
- SSH access to the remote host

## Installation

Copy `dpl.php` to your project or to any directory on your `$PATH`.

```bash
# Run directly
php /path/to/dpl.php

# Or make it executable and add a wrapper
chmod +x dpl.php
```

## Quick start

1. Go to your project directory (must be a git repository):

```bash
cd /my/project
```

2. Create a `dpl.ini` configuration file:

```bash
php dpl.php --init
```

3. Edit `dpl.ini` and fill in your host and path:

```ini
[*]
    exclude[] = dpl.ini
    exclude[] = .git*

[main]
    host = example.com
    path = /var/www/html

[production]
    host = prod.example.com
    path = /var/www/html
    user = deploy

    exclude[] = .env
```

4. Deploy:

```bash
php dpl.php             # deploy [main]
php dpl.php production  # deploy [production]
php dpl.php --all       # deploy every section
```

## How it works

- On first deploy the remote path must exist and be writable. dpl creates a
  revision tracking file there (`.dplrev` by default, or the name set via
  `revision_file` in `dpl.ini`) to track the last deployed git revision.
- On subsequent deploys dpl diffs the stored revision against `HEAD` and
  transfers only the changed files, uploading new/modified ones and deleting
  removed ones.
- After a successful deploy the revision file is updated with the current `HEAD` SHA.
- The revision file is always excluded from uploads automatically, even if not
  listed in `exclude[]`.

## Multiple environments

You can define multiple sections in `dpl.ini`, one per environment:

```ini
[*]
    exclude[] = dpl.ini
    exclude[] = .git*

[main]
    host = dev.example.com
    path = /var/www/dev

[production]
    host = prod.example.com
    path = /var/www/html
    user = deploy

    exclude[] = .env
```

Pass the section name as the first argument to deploy to a specific environment,
or use `--all` to deploy every section in sequence:

```bash
php dpl.php             # deploys [main]
php dpl.php production  # deploys [production]
php dpl.php --all       # deploys [main], then [production]
```

Each section tracks its own remote revision independently, so deploying to one
environment does not affect another.

When `--all` is used, the uncommitted-changes guard checks files against the
`exclude[]` patterns defined in `[*]` only (see [Notes](#notes) for details).

## Shared defaults with `[*]`

The special `[*]` section defines defaults inherited by all other sections.
It is not a deploy target — running `php dpl.php '*'` is an error.

- **Scalar keys** (`host`, `user`, `port`, `ssh_key`, `revision_file`): the
  section's own value takes priority; `[*]` provides the fallback.
- **`exclude[]`**: patterns from `[*]` and the target section are **merged**,
  so both lists are always applied.

This is most useful for `exclude[]` patterns that are the same across all
environments:

```ini
[*]
    exclude[] = dpl.ini
    exclude[] = .git*
    exclude[] = .env
    user = www-data

[main]
    host = dev.example.com
    path = /var/www/dev
    user = myuser          ; overrides [*] user for this section
    exclude[] = tests/*    ; merged with [*] excludes
```

## dpl.ini reference

Keys are per section. The default section is `[main]`.

| Key              | Required | Default              | Description                                      |
|------------------|----------|----------------------|--------------------------------------------------|
| `host`           | yes      | —                    | Remote hostname or IP address                    |
| `path`           | yes      | —                    | Absolute path on the remote host to deploy into  |
| `port`           | no       | `22`                 | SSH port                                         |
| `user`           | no       | current OS user      | SSH username                                     |
| `ssh_key`        | no       | SSH agent / default  | Path to SSH private key                          |
| `revision_file`  | no       | `.dplrev`            | Name of the remote revision tracking file        |
| `exclude[]`      | no       | —                    | Glob pattern to exclude (repeatable)             |

### exclude patterns

Patterns are matched against the relative file path and against the filename
alone. Wildcards follow `fnmatch` rules. A pattern without wildcards that
matches a path prefix is treated as a directory exclusion.

```ini
exclude[] = dpl.ini        ; exact filename
exclude[] = .env           ; exact filename
exclude[] = .*             ; all hidden files
exclude[] = *.log          ; all .log files
exclude[] = composer.*     ; composer.json, composer.lock, ...
exclude[] = tmp/*          ; everything inside tmp/
exclude[] = vendor         ; entire vendor/ directory
```

## Options

| Argument / Flag | Description                                                              |
|-----------------|--------------------------------------------------------------------------|
| `section`       | Section from `dpl.ini` to deploy (default: `main`)                       |
| `--all`         | Deploy every section in `dpl.ini` sequentially                           |
| `--init`        | Create a `dpl.ini` template in the current directory                     |
| `--yes`, `-y`   | Skip the confirmation prompt (useful for CI/CD)                          |
| `--no-color`    | Disable coloured output                                                  |
| `--help`, `-?`  | Show the help screen                                                     |

## Deploy output

### Single section

```
Local revision:  4b78a267...
Remote revision: b285efed...
Files to deploy (3):
  U  src/index.php
  U  src/lib/utils.php
  D  src/old-file.php

Continue? [Y/n]

Deploy summary:
  Uploaded : 2
  Deleted  : 1
```

### All sections (`--all`)

When deploying with `--all`, each section is announced with a header before
its own deploy output:

```
Local revision:  4b78a267...

==> Deploying [main]...
Remote revision: b285efed...
Files to deploy (2):
  U  src/index.php
  D  src/old-file.php

Continue? [Y/n]

Deploy summary:
  Uploaded : 1
  Deleted  : 1

==> Deploying [production]...
Remote revision: 91c3f02a...
Files to deploy (1):
  U  src/index.php

Continue? [Y/n]

Deploy summary:
  Uploaded : 1
  Deleted  : 0
```

`U` (green) — file will be uploaded. `D` (red) — file will be deleted on the remote.

## CI/CD usage

```bash
# Deploy a single section without prompts
php dpl.php --yes --no-color
php dpl.php production --yes --no-color

# Deploy all sections without prompts
php dpl.php --all --yes --no-color
```

## Notes

- dpl will refuse to deploy if there are uncommitted changes to files that are
  not covered by an `exclude[]` pattern. Stash or commit them first.
- When deploying a **single section**, the uncommitted-changes check uses the
  merged `exclude[]` list for that section (i.e. patterns from `[*]` plus the
  section's own patterns).
- When deploying **`--all`**, the uncommitted-changes check uses **only** the
  `exclude[]` patterns from `[*]`. This ensures a single consistent gate for
  all sections — if a file would not be excluded globally, the deploy is
  blocked regardless of what individual sections might exclude.
- If the remote revision file contains a revision that does not exist in the
  local repository, dpl aborts with an error suggesting you check the remote
  path. This usually means `path` in `dpl.ini` points to a directory that was
  previously deployed from a different project.
- dpl does **not** push to git. Make sure your commits are pushed before
  deploying if other team members need to reproduce the exact deployed state.
