#!/usr/bin/env bash
set -euo pipefail

# Run as school_sevia_usr on the hosting server.
APP_DIR="${SCHOOL_APP_DIR:-$HOME/www/school.sevia.com.ua}"
DEPLOY_DIR="${SCHOOL_DEPLOY_DIR:-$HOME/.school-deploy}"
REPO_DIR="$DEPLOY_DIR/repository.git"
REPO_URL="${SCHOOL_REPO_URL:-https://github.com/basil832025/school.git}"

if [[ ! -d "$APP_DIR" ]]; then
    printf 'Web root does not exist: %s\n' "$APP_DIR" >&2
    exit 1
fi

mkdir -p "$DEPLOY_DIR"
if [[ ! -d "$REPO_DIR" ]]; then
    git init --bare "$REPO_DIR"
fi
if git --git-dir="$REPO_DIR" remote get-url origin >/dev/null 2>&1; then
    git --git-dir="$REPO_DIR" remote set-url origin "$REPO_URL"
else
    git --git-dir="$REPO_DIR" remote add origin "$REPO_URL"
fi

echo "Fetching school/main..."
git --git-dir="$REPO_DIR" fetch origin refs/heads/main
revision="$(git --git-dir="$REPO_DIR" rev-parse FETCH_HEAD)"

if [[ -n "$(git --git-dir="$REPO_DIR" ls-tree -r --name-only "$revision" -- config.php app/telegram_config.local.php)" ]]; then
    echo "Deployment stopped: the repository contains a local configuration file." >&2
    exit 1
fi

archive="$(mktemp "$DEPLOY_DIR/release.XXXXXXXX.tar")"
trap 'rm -f -- "$archive"' EXIT
git --git-dir="$REPO_DIR" archive --format=tar --output="$archive" "$revision" -- \
    .htaccess app assets database config.example.php \
    index.php teacher.php results.php admin.php admin-students.php student-account.php \
    telegram-webhook.php telegram-registration.php telegram-login.php

if [[ ! -f "$DEPLOY_DIR/deployed-revision" ]]; then
    backup_dir="$DEPLOY_DIR/first-deploy-backup"
    mkdir -p "$backup_dir"
    for file in index.php .htaccess; do
        if [[ -f "$APP_DIR/$file" && ! -e "$backup_dir/$file" ]]; then
            cp -p -- "$APP_DIR/$file" "$backup_dir/$file"
        fi
    done
fi

echo "Publishing school.sevia.com.ua..."
tar -xf - -C "$APP_DIR" < "$archive"
printf '%s\n' "$revision" > "$DEPLOY_DIR/deployed-revision"

printf 'Deployed %s to %s\n' "$revision" "$APP_DIR"
if [[ ! -f "$APP_DIR/config.php" ]]; then
    printf 'First setup: copy %s/config.example.php to config.php and enter the hosting settings.\n' "$APP_DIR"
fi
