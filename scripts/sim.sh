#!/usr/bin/env bash
#
# A disposable ProjectSend v2 to migrate *into*, plus the v1 fixtures to
# migrate *from*.
#
# The tool imports into a fresh install only, so it cannot be exercised
# properly against a development checkout: that one has content, and its
# storage/app/files is shared with everything else you have been doing.
# This stands up a v2 of its own — own database, own storage, own port —
# in the same shape as the sim-cloud/sim-community instances described
# in the host's docs/dev-environment-topology.md.
#
#   scripts/sim.sh up                 build (or rebuild) the instance
#   scripts/sim.sh fixture small      stage a v1 install into it
#   scripts/sim.sh run small          preflight, import, verify
#   scripts/sim.sh reset              undo the last import
#   scripts/sim.sh shell              a shell in the app container
#   scripts/sim.sh down               stop and destroy everything
#
# `up` destroys whatever was there. Nothing in the instance directory
# should ever be edited by hand — re-run instead.
#
# More than one instance can exist at a time; each needs its own name and
# its own ports:
#
#   SIM_NAME=v1-migration-test SIM_APP_PORT=8193 SIM_DB_PORT=33164 \
#   SIM_PROVISION_ADMIN=0 scripts/sim.sh up
#
# SIM_PROVISION_ADMIN=0 leaves ADMIN_EMAIL/ADMIN_PASSWORD out of .env, so
# the instance boots with no accounts at all and shows ProjectSend's real
# first-run setup screen instead of being handed an administrator. That
# is the honest starting point for a migration: an install someone has
# just set up and not yet used.
#
# ## Stop the instances you are not using
#
# Each instance is a whole stack, and its MySQL is the expensive part.
# Docker\'s VM has its own memory limit — far below the host\'s — and when
# it is reached the kernel kills MySQL rather than refusing to start it.
# That surfaces much later as a bare "getaddrinfo for db failed" from a
# page that was working ten minutes earlier, with nothing in the app\'s
# own logs. `docker ps -a` showing `Exited (137)` is the tell.
#
# So: `scripts/sim.sh down` an instance when you are finished with it, or
# `docker compose -p projectsend-<name> stop` to keep its data and free
# the memory. Raising Docker\'s memory limit is the other half of the fix.
#
# ## Why fixtures are staged with `cp -al`
#
# A hardlink cannot cross a mount point, and the app container sees each
# bind mount separately even when they are the same filesystem on the
# host. So a v1 install mounted from outside could never demonstrate
# --files=hardlink, which is the whole reason the tool is usable on a
# 400 GB install. Staging the fixture *inside* the instance directory
# puts it on the same bind mount as storage/app/files, and `cp -al`
# makes that staging itself free: the copy is hardlinks to the original
# fixture's bytes, so a 5 GB install stages instantly and costs nothing.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT="$(cd "$PACKAGE_DIR/../.." && pwd)"

NAME="${SIM_NAME:-sim-migrate}"
SIM_DIR="$ROOT/$NAME"
PROJECT="projectsend-$NAME"
APP_PORT="${SIM_APP_PORT:-8192}"
DB_PORT_FORWARD="${SIM_DB_PORT:-33163}"

# 1 hands the instance an administrator so it can be scripted; 0 leaves
# the setup screen to be completed by hand.
PROVISION_ADMIN="${SIM_PROVISION_ADMIN:-1}"

# Where the seeded v1 installs live (see the host's ps-seed notes).
FIXTURE_ROOT="${V1_FIXTURE_ROOT:-$(cd "$ROOT/.." && pwd)}"

# Until both are merged, the instance is built from the branches the
# work is on — the same reason refresh-sim.sh takes --branch.
HOST_BRANCH="${SIM_HOST_BRANCH:-feature/installable-package-pages}"
PACKAGE_BRANCH="${SIM_PACKAGE_BRANCH:-feature/migration-engine}"

ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD="sim-admin-password"

DB_USER="projectsend"
DB_PASSWORD="secret"
DB_ROOT_PASSWORD="root"

dc() { (cd "$SIM_DIR" && docker compose -p "$PROJECT" "$@"); }

say() { printf '\n==> %s\n' "$1"; }

require_instance() {
    if [[ ! -d "$SIM_DIR" ]]; then
        echo "No instance at $SIM_DIR. Run: scripts/sim.sh up" >&2
        exit 1
    fi
}

fixture_path() {
    local name="$1"
    case "$name" in
        small | large | messy) echo "$FIXTURE_ROOT/ps-$name.local" ;;
        *)
            echo "Unknown fixture '$name'. Use one of: small, messy, large." >&2
            exit 1
            ;;
    esac
}

cmd_up() {
    # Tear down before deleting the directory: a container whose bind
    # mount source vanishes underneath it leaves a broken mount
    # namespace that Docker then refuses to exec into at all.
    if [[ -d "$SIM_DIR" ]]; then
        say "Tearing down the existing instance"
        dc down -v --remove-orphans || true
        rm -rf "$SIM_DIR"
    fi

    say "Cloning projectsend/cloud ($HOST_BRANCH)"
    gh repo clone projectsend/cloud "$SIM_DIR" -- --branch "$HOST_BRANCH"

    say "Cloning projectsend/v1-migration-tool ($PACKAGE_BRANCH)"
    mkdir -p "$SIM_DIR/packages"
    gh repo clone projectsend/v1-migration-tool \
        "$SIM_DIR/packages/v1-migration-tool" -- --branch "$PACKAGE_BRANCH"

    say "Writing .env"
    cp "$SIM_DIR/.env.example" "$SIM_DIR/.env"
    sed -i -e "s#^APP_URL=.*#APP_URL=http://localhost:$APP_PORT#" "$SIM_DIR/.env"

    if [[ "$PROVISION_ADMIN" == "1" ]]; then
        # docker/app/entrypoint.sh runs `projectsend:admin --if-none`
        # only when both of these are set; without them it leaves the
        # setup screen to prompt, which is what SIM_PROVISION_ADMIN=0 is
        # for.
        sed -i \
            -e "s/^# ADMIN_NAME=.*/ADMIN_NAME=\"Sim Administrator\"/" \
            -e "s/^# ADMIN_EMAIL=.*/ADMIN_EMAIL=$ADMIN_EMAIL/" \
            -e "s/^# ADMIN_PASSWORD=.*/ADMIN_PASSWORD=$ADMIN_PASSWORD/" \
            "$SIM_DIR/.env"
    fi
    {
        echo ""
        echo "APP_PORT=$APP_PORT"
        echo "DB_PORT_FORWARD=$DB_PORT_FORWARD"
        echo "DB_ROOT_PASSWORD=$DB_ROOT_PASSWORD"
        # Session cookies are scoped by domain+path+name and not by port,
        # so every instance on localhost would otherwise fight over one
        # cookie slot — logging into this one would sign you out of the
        # dev checkout.
        echo "SESSION_COOKIE=${PROJECT//-/_}_session"
    } >>"$SIM_DIR/.env"

    say "Building images"
    dc build

    say "Starting db and redis"
    dc up -d db redis
    dc up -d --wait db

    say "Installing PHP dependencies with the migration tool"
    # Neither edition package is wanted here: this instance exists to be
    # migrated into, and cloning two more private repos to delete them
    # again is pure cost.
    dc run --rm --entrypoint sh app -c "
        set -e
        composer remove projectsend/cloud-modules projectsend/community-modules \
            --no-interaction --no-update || true
        rm -f composer.lock
        composer require projectsend/v1-migration-tool:* --no-interaction
        php artisan key:generate --force
    "

    say "Building the frontend (no Node in the app image)"
    (cd "$SIM_DIR" && npm install --no-audit --no-fund >/dev/null && npm run build >/dev/null)

    say "Starting everything"
    dc up -d

    say "Ready"

    if [[ "$PROVISION_ADMIN" == "1" ]]; then
        echo "    http://localhost:$APP_PORT/system/migrate"
        echo "    $ADMIN_EMAIL / $ADMIN_PASSWORD"
    else
        echo "    http://localhost:$APP_PORT"
        echo "    No accounts yet — it will show the setup screen."
    fi

    echo
    echo "    Next: SIM_NAME=$NAME scripts/sim.sh fixture small"
}

cmd_fixture() {
    require_instance
    local name="${1:-}"
    [[ -n "$name" ]] || { echo "Usage: scripts/sim.sh fixture {small|messy|large}" >&2; exit 1; }

    local source db_name
    source="$(fixture_path "$name")"
    db_name="ps_$name"

    [[ -d "$source" ]] || { echo "No v1 install at $source" >&2; exit 1; }

    say "Staging $source into the instance"
    rm -rf "$SIM_DIR/v1/$db_name"
    mkdir -p "$SIM_DIR/v1"
    # Hardlinked, so this costs nothing even for the 5 GB fixture, and
    # lands on the same bind mount as the instance's own storage so the
    # tool's --files=hardlink can be exercised for real.
    cp -al "$source" "$SIM_DIR/v1/$db_name"

    say "Rewriting its sys.config.php to point at this instance's database"
    # Breaking the hardlink for this one file, deliberately: editing it
    # in place would edit the real fixture's config too.
    rm -f "$SIM_DIR/v1/$db_name/includes/sys.config.php"
    sed \
        -e "s/define('DB_HOST'.*/define('DB_HOST', 'db');/" \
        -e "s/define('DB_NAME'.*/define('DB_NAME', '$db_name');/" \
        -e "s/define('DB_USER'.*/define('DB_USER', '$DB_USER');/" \
        -e "s/define('DB_PASSWORD'.*/define('DB_PASSWORD', '$DB_PASSWORD');/" \
        "$source/includes/sys.config.php" >"$SIM_DIR/v1/$db_name/includes/sys.config.php"

    say "Copying the v1 database into the instance's MySQL"
    local host_password
    host_password="$(grep -oP "DB_PASSWORD', '\K[^']+" "$source/includes/sys.config.php")"
    local host_user
    host_user="$(grep -oP "DB_USER', '\K[^']+" "$source/includes/sys.config.php")"

    mysqldump -h 127.0.0.1 -u "$host_user" -p"$host_password" \
        --single-transaction --no-tablespaces "$db_name" 2>/dev/null |
        dc exec -T db sh -c "
            mysql -uroot -p'$DB_ROOT_PASSWORD' -e \"
                DROP DATABASE IF EXISTS \\\`$db_name\\\`;
                CREATE DATABASE \\\`$db_name\\\` CHARACTER SET utf8mb3;
                GRANT ALL ON \\\`$db_name\\\`.* TO '$DB_USER'@'%';
            \" 2>/dev/null
            mysql -uroot -p'$DB_ROOT_PASSWORD' $db_name 2>/dev/null
        "

    # Files created inside the bind mount after the containers started
    # are not reliably visible to them — a known artifact of this setup,
    # and it shows up here as the tool reading the *pre-staging* config
    # and trying to reach `localhost`. Restarting is the fix; `web` is
    # included because nginx caches php-fpm's address and answers 502 for
    # the rest of its life otherwise.
    say "Restarting the containers so they see the staged install"
    dc restart app worker scheduler web >/dev/null
    sleep 6

    say "Staged"
    echo "    In the screen at http://localhost:$APP_PORT/system/migrate, choose"
    echo "    \"On this machine\" and enter:  /var/www/html/v1/$db_name"
    echo
    echo "    Or from the command line:      SIM_NAME=$NAME scripts/sim.sh run $name"
}

cmd_run() {
    require_instance
    local name="${1:-}"
    [[ -n "$name" ]] || { echo "Usage: scripts/sim.sh run {small|messy|large} [extra artisan flags]" >&2; exit 1; }
    shift

    local path="/var/www/html/v1/ps_$name"

    say "Preflight"
    dc exec -T app php artisan projectsend:migrate:preflight --v1-path="$path" || true

    say "Import"
    dc exec -T app php artisan projectsend:migrate:import \
        --v1-path="$path" --accept-skips "$@"

    say "Verify"
    dc exec -T app php artisan projectsend:migrate:verify
}

cmd_reset() {
    require_instance
    say "Undoing the last import"
    dc exec -T app php artisan projectsend:migrate:reset --force
}

cmd_shell() {
    require_instance
    dc exec app sh
}

cmd_down() {
    if [[ -d "$SIM_DIR" ]]; then
        say "Destroying the instance"
        dc down -v --remove-orphans || true
        rm -rf "$SIM_DIR"
    fi
    echo "Gone."
}

case "${1:-}" in
    up) shift; cmd_up "$@" ;;
    fixture) shift; cmd_fixture "$@" ;;
    run) shift; cmd_run "$@" ;;
    reset) shift; cmd_reset "$@" ;;
    shell) shift; cmd_shell "$@" ;;
    down) shift; cmd_down "$@" ;;
    *)
        sed -n '3,30p' "${BASH_SOURCE[0]}" | sed 's/^#\s\?//'
        exit 1
        ;;
esac
