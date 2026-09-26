# Tests

Two harnesses. Neither ships in the release zip.

## Logic tests (PHP only)

`tests/logic-tests.php` stubs WordPress and HivePress, then drives the real plugin code. It needs
PHP only: no WordPress install, no database.

From the plugin folder:

```sh
php tests/run.php          # the whole matrix
php tests/logic-tests.php  # one run, everything present
```

The plugin behaves differently depending on which companion plugins are present, so the runner
repeats the suite with each of them absent:

```sh
HPFV_BAR=absent php tests/logic-tests.php        # no Action Bar for HivePress
HPFV_FAVORITES=absent php tests/logic-tests.php  # no Favorites extension
HPFV_ALERTS=absent php tests/logic-tests.php     # no Search Alerts extension
HPFV_FAFH=absent php tests/logic-tests.php       # no shared icon library
```

Each prints one line per assertion and exits non-zero if anything fails. What is covered:

- `can_follow()`: the owner, a draft Vendor, Vendor pages switched off, a non-Vendor object, the
  `hpfv_can_follow` filter.
- The digest queue: a non-publish status, emails off, an already-announced Listing (renewals and
  hide/unhide), two publishes in one quiet period sharing one job, a publish after the job ran
  getting its own, the window arithmetic and the delay clamp.
- The digest send: one email per follower, the tokens, the 50-Listing cap and the cursor that
  follows the last announced stamp, the same-second exclusion, follower batches of 50 with the
  continuation job carrying its bounds, emails switched off after queueing, a follower with no
  address, an unpublished Vendor, escaping of `%listings%`, both developer filters.
- The Action Bar items: every route for a guest, a user and the owner, the current state
  server-rendered, the on/off switch, the script enqueue conditions, and the
  `hpfv_action_bar_items` filter. Also that this plugin produces NO Save item and NO alert item:
  both moved to Action Bar for HivePress (its Quick Actions section) and adding them here again
  would show the visitor two of each.
- Settings read-through: absent, `''`, non-numeric and out-of-list stored values.
- The three labels flowing into the toggle, the route title and the bar items; the account menu
  item; the follower count.
- Version drift between the header, the constant and the readme; the author credit; no em-dash,
  no business name, no `innerHTML`.

## Runtime check (a real HivePress install, WP-CLI)

`tests/runtime-check.php` runs against a live install through `wp eval-file`, in phases, because
the account page can only render for a user who was signed in before WordPress loaded:

```sh
HPFV_PHASE=seed  wp eval-file tests/runtime-check.php                      # prints FOLLOWER_ID=N
HPFV_PHASE=check wp eval-file tests/runtime-check.php --user=N             # every assertion
HPFV_PHASE=card  wp eval-file tests/runtime-check.php --user=N             # one Vendor card and page (run per theme)
HPFV_PHASE=clean wp eval-file tests/runtime-check.php                      # removes the fixtures
```

No email is sent: `pre_wp_mail` short-circuits `wp_mail()` and records what would have gone. It
proves the routes and their URL shape, the comment type resolving to the model, a follow
round-tripping through the REST action with the caches clearing, the permissions (owner 403, guest
401, unknown 404), the toggle and count blocks in every state, the account page rendering, the
digest queued once per quiet period, sent once per follower with every Listing linked, the cursor,
a later publish getting its own job, the hook reaching the sender through Action Scheduler's own
execution path, the emails-off switch, the Action Bar items in every state, and the settings
read-through. It starts with one deliberately false assertion so a run proves the harness can fail.
