# Pomodoro

A Pomodoro focus timer for Nextcloud that works on the cards already in your Deck boards.

- **App ID:** `pomodoro`
- **Namespace:** `Pomodoro`
- **Compatible with:** Nextcloud 31 – 36
- **Licence:** AGPL-3.0-or-later

---

## 1. Purpose

Work was already being tracked as cards in Nextcloud Deck, and focusing on it meant keeping a
*second* task list alive inside a separate timer application — the same items written down twice,
with the two copies drifting apart.

This app removes that duplication. It reads the outstanding cards straight out of Deck, lets you
pick one to focus on, runs the Pomodoro cycle against it, and — when the work is finished — hands
the card back to Deck as done. There is one list, and it is the one already being maintained.

Two constraints shaped the design:

- **Stay inside Nextcloud.** No external service, no second account, no data leaving the instance.
  Deck's data is read over Deck's own API using the existing session, so the app needs no
  credentials of its own.
- **The timer must not be lost to a stray click or a refresh.** Time is therefore owned by the
  server: a phase stores the wall-clock instant it *ends* rather than a countdown, so navigating
  away, reloading, or reopening the app days later resumes with the correct remaining time.

---

## 2. Features

**Working from Deck**

- A board picker listing every board you can see; the outstanding cards of the selected board are
  listed below it.
- "Outstanding" excludes archived boards and cards, and cards Deck has marked done.
- Scrollable list, so a long backlog does not push the page around.
- Each row carries its own cycle target and its own counters.
- Each row links back to the card in Deck (**Go to card**), opened in a new tab so the running timer
  is left alone.

**Timing**

- Configurable focus and rest length (focus 1–180 min, default 25; rest 1–60 min, default 5).
- **Manual by default:** when a phase runs out, the timer stops at `00:00` and waits for you to
  start the next one. Auto-transition is opt-in.
- Pause and resume preserve the remaining time exactly, across reloads.
- The session survives navigation and reloads, because the deadlines live on the server.
- The final focus cycle of a session finishes the session — it does not offer a rest that has
  nowhere to go.

**Per-item tracking**

- Each item has its own cycles-per-task target, defaulting to **1** until you set otherwise.
- Two counters per item: **completed** (a focus phase that ran its full course) and **skipped**
  (one abandoned with Skip). A skipped cycle deliberately does *not* count towards the target —
  abandoning a cycle is not finishing it. Skipping a rest changes no counters.
- Counters accumulate across sessions and are shown against each row in the list.

**Notifications**

- A desktop pop-up the moment a focus or rest period ends (browser notification API).
- A Nextcloud notification, and the matching desktop pop-up from the Nextcloud client, for when the
  app is not open — raised by a background job.
- Only one of the two ever fires for a given phase: whichever side manages to announce it records
  that fact, so you are never told twice.

**Finishing work in Deck**

- **Mark as Done** hands the card back to Deck, which stamps the completion date and moves it into
  the board's done column. This calls Deck's own action endpoint rather than moving the card
  itself, so Deck's permission checks, activity entries and notifications all still apply.

**Presentation**

- Two halves: the outstanding list on the left, the timer on the right, appearing once a session
  starts.
- Colour-coded timer state, deliberately independent of the theme so it stays legible:
  red for focus running, bright green for rest running, bright blue when a phase has ended and is
  waiting on you, white when paused.

---

## 3. Trying it on a development instance

Develop against a throwaway Nextcloud, never against the instance you care about.

**Set up the environment** — [nextcloud-docker-dev](https://github.com/nextcloud/nextcloud-docker-dev):

```sh
git clone https://github.com/nextcloud/nextcloud-docker-dev.git
cd nextcloud-docker-dev
./bootstrap.sh deck tasks        # clone the apps the app reads from
docker compose up -d nextcloud
```

The instance is then at <http://nextcloud.local>, with the documented dev credentials
`admin` / `admin`. On this dev proxy there is no pretty-URL rewriting, so the login page is
`/index.php/login`.

**Install the app into it:**

```sh
cp -r <this-repo> nextcloud-docker-dev/workspace/server/apps-extra/pomodoro
cd nextcloud-docker-dev/workspace/server/apps-extra/pomodoro
npm install && npm run build
cd ../../../../
docker compose exec -T --user www-data nextcloud php occ app:enable pomodoro
```

**Then:** open the app, pick a board, select a card and press **Start focus**. To avoid waiting out
a real 25-minute phase while testing, set focus and rest to 1 minute. Both are per-user settings.

### Dev environment gotchas worth knowing

These cost real time the first time round and are not specific to this app:

- **Built assets are not in git.** `js/` and `css/` are gitignored build output. Copying the source
  without running the front-end build gives you an app that enables cleanly and renders nothing.
- **Nextcloud only cache-busts app scripts when the app version changes**, so after a rebuild a
  hard reload (or a bumped version) is needed or the browser keeps serving the old bundle.
- **Deck is a source checkout and needs its own dependencies twice over:** `composer install` in
  `apps-extra/deck` (for `justinrainbow/json-schema`) and `npm ci && npm run build` for Deck's own
  UI. Without the latter, Deck's pages come up empty.
- **The `notifications` app is not part of the server checkout.** It has to be cloned into
  `apps-extra`, given `composer install`, and — because it bundles its dependencies under a
  namespaced directory — processed with **Mozart** (`composer bin mozart install` then
  `vendor/bin/mozart compose`). Skipping that leaves its API throwing class-not-found.
- **Background jobs.** `occ background:ajax` is needed if the dev environment has no cron
  container, otherwise nothing runs. To trigger the job by hand:
  `occ background-job:execute <id> --force-execute` — without `--force-execute` it honours the
  job's own interval and silently does nothing.

---

## 4. Installing on a production instance

**Requirements**

- Nextcloud 31–36.
- The **Deck** app enabled (that is where the cards come from).
- The **`notifications`** app enabled — it ships with Nextcloud and is on by default. This is what
  delivers the server-side pop-ups.
- **HTTPS** for the browser pop-ups: browsers refuse notification permission in an insecure
  context, so over plain HTTP the app says so instead of failing quietly.
- No Composer dependencies of its own: everything under `lib/` uses Nextcloud's public APIs only,
  so no `composer install` is needed on the target.

**Package it** (build first — see the gotcha above):

```sh
cd <this-repo>
npm run build
tar --exclude='./.git' --exclude='./node_modules' --exclude='./vendor' \
    --exclude='./vendor-bin' --exclude='./tests' --exclude='./.github' \
    -czf ../pomodoro-1.0.0.tar.gz --transform 's,^\./,pomodoro/,' .
```

**Copy it in.** On Nextcloud AIO the writable apps directory is
`/var/www/html/custom_apps` inside the `nextcloud-aio-nextcloud` container:

```sh
scp pomodoro-1.0.0.tar.gz <aio-host>:/tmp/
ssh <aio-host>

docker cp /tmp/pomodoro-1.0.0.tar.gz nextcloud-aio-nextcloud:/tmp/
docker exec nextcloud-aio-nextcloud \
  tar -xzf /tmp/pomodoro-1.0.0.tar.gz -C /var/www/html/custom_apps/
docker exec nextcloud-aio-nextcloud \
  chown -R www-data:www-data /var/www/html/custom_apps/pomodoro

docker exec -u www-data nextcloud-aio-nextcloud php occ app:enable pomodoro
docker exec -u www-data nextcloud-aio-nextcloud php occ background-job:list | grep -i pomodoro
```

On a non-AIO install, extract into whichever apps directory `apps_paths` in `config.php` lists as
writable, then `occ app:enable pomodoro`.

**Then** open the app over HTTPS and press Start focus once — that is when it asks for browser
notification permission, because browsers only allow the prompt to follow a user gesture.

### Things to expect

- **The admin overview will warn** that the app is not from the official app store. That is the
  code-signing integrity check, expected for an unsigned private app. Leave it, disable the check,
  or sign the app with a certificate issued by Nextcloud.
- **Server-side notifications are only as punctual as cron.** Nextcloud runs background jobs —
  **every 5 minutes by default** — so a phase that ends while the browser is closed is announced up
  to about five minutes late. With the app open, the browser pop-up is immediate. Shortening the
  cron interval tightens the former.
- **It lives in the volume**, so it survives AIO container updates. But if a future Nextcloud major
  falls outside the declared `min-version="31" max-version="36"`, an upgrade will disable the app
  until that range is widened and the bundle rebuilt.
- **Nothing to migrate.** Sessions, timer settings, per-item targets and counters are stored per
  user in Nextcloud's app config.

---

## How it works

The server owns time. A session is a JSON blob in the user's app config holding the task, the
current phase, when that phase started and when it ends, the cycle target and how many cycles have
been finished, and which phase has already been announced. The front-end only ever renders
`phaseEndsAt − now`.

| Route | Purpose |
| --- | --- |
| `GET /state` | Session, timer settings, and per-item config and counters |
| `POST /settings` | Save focus/rest lengths and the auto-transition preference |
| `POST /session` | Start a session for an item |
| `PUT /session/phase` | Move to the next phase (`skipped` distinguishes an abandoned cycle) |
| `PUT /session/pause` | Pause or resume, preserving the remaining time |
| `DELETE /session` | Clear the session; counters are history and are kept |
| `POST /task-config` | Set an item's cycle target |
| `POST /session/announced` | Record that the front-end already showed its pop-up |

Deck is read and written over its own API: boards, stacks and cards for the list, and
`PUT /apps/deck/cards/{id}/done` to finish a card.

A `PhaseWatcher` background job (declared in `appinfo/info.xml`, runs every minute when the runner
wakes it) covers what the closed page cannot: when a phase has run out it raises a Nextcloud
notification, and — if auto-transition is on — starts the next phase exactly as the page would.
Whichever side announces a phase first records it, so the two never both fire.

```
lib/
  AppInfo/Application.php        app bootstrap; registers the notifier
  BackgroundJob/PhaseWatcher.php keeps sessions moving when the app is closed
  Controller/ApiController.php   the routes above
  Notification/Notifier.php      renders notifications into text
  Service/SessionService.php     the session and the user's timer settings
  Service/TaskConfigService.php  per-item cycle target and counters
  Service/PhaseNotifier.php      raises the notification for a finished phase
src/
  App.vue                        the whole interface
  api/deck.ts                    Deck boards, cards, and marking a card done
  api/session.ts                 our own endpoints
  api/notify.ts                  browser pop-ups
  api/items.ts, api/types.ts     the normalised item the UI works with
```

---

## Development

```sh
npm install          # front-end dependencies
npm run build        # production bundle into js/ and css/
npm run watch        # rebuild on change
composer install     # dev tooling only (the app needs no runtime dependencies)

# unit tests, from inside the Nextcloud container
docker compose exec -T --user www-data nextcloud sh -lc \
  'cd /var/www/html/apps-extra/pomodoro && phpunit tests -c tests/phpunit.xml --do-not-cache-result'
```

`TaskConfigService` is covered by unit tests (target defaults, clamping, and counter accumulation).
Note that PHPUnit has to run as the web-server user, because Nextcloud's test bootstrap writes to
the config directory.

---

## Licence

AGPL-3.0-or-later.
