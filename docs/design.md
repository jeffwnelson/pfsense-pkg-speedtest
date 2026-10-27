# pfSense speedtest package: per-WAN "am I getting what I pay for?" — design

Date: 2026-10-23. Status: reviewed (builder check done 2026-10-23); ready for planning.

## Intent

Jeff runs three WAN links into one pfSense box (MetroNet/T-Mobile Fiber 2 Gbps,
Quantum Fiber 1 Gbps, Starlink). The question he reaches for a speedtest to
answer is: **is each WAN delivering what the ISP advertises?** Today he uses a
hand-copied third-party dashboard widget (an "all-solutions IT-Consulting" fork
of Leon Straathof's widget). It is currently broken because the Ookla CLI it
shells out to was removed during the pfSense 2.9 upgrade, it has a command
injection hole, it blocks the web server for the whole test, and it has no
per-WAN view, no persistence beyond ten runs, and no schedule.

This project replaces it with a proper pfSense package that is installable,
upgrade-safe, per-WAN by design, and looks like it belongs on the dashboard.

## Goals (day one)

1. **Per-WAN on-demand test.** From the dashboard, pick a WAN gateway and run a
   test that provably egresses that WAN regardless of the default route.
2. **Pass/fail against advertised speed.** Each WAN has configured advertised
   down/up numbers. The widget shows measured vs advertised as a percentage
   and a colour state.
3. **Non-blocking.** The test runs in the background; the widget polls for the
   result. The web server never waits on a 30-second test.
4. **Persistent per-WAN results.** Latest result per WAN survives reboots and
   is shown even if the last run was on a different WAN.
5. **Native look.** Matches the stock pfSense widgets (Gateways, Interfaces):
   Bootstrap table, same heading icon controls, same text sizes, light/dark
   theme safe.
6. **Installable package.** `pfSense-pkg-speedtest` built as a pfSense package
   (pkg XML + PHP + info.xml), installed with `pkg add`, removable with
   `pkg delete`, and owning its Ookla CLI dependency.
7. **Safe.** No user-controlled strings reach a shell. All settings validated
   server-side.

## Non-goals (day one)

- A history-over-time graph page. Designed for, not built, on day one (see
  "Phase 2"). Scheduled runs shipped in 0.2.0 (§7).
- Alerting/notifications.
- Non-Ookla engines (iperf, librespeed). Ookla is the engine.
- Submitting to the official pfSense package repo. This is a personal package
  hosted on GitHub; it can be contributed later.
- IPv6 testing.

## Anti-goals (what would make this a failure even if it works)

- A test that silently runs out the default WAN when a different one was
  selected. The whole point is per-WAN truth.
- Anything that can be lost by a pfSense upgrade: the Ookla binary, the
  results, or the widget file.
- Reintroducing shell injection or a blocking request.
- A widget that looks bolted on (hard-coded colours, fonts, inline-style
  tables unlike the neighbours).

## Constraints

- Target: pfSense CE 2.9.0 (FreeBSD 16 ABI `FreeBSD:16:amd64`), PHP 8.5.
  Must not break on 2.9.x point releases.
- Ookla's FreeBSD package is published per FreeBSD major
  (`ookla-speedtest-<ver>-freebsd<N>-x86_64.pkg`). The package install must
  fetch the one matching the running ABI and must not conflict with
  `py311-speedtest-cli` (which installs its own `/usr/local/bin/speedtest`).
- pfSense packages are PHP + XML "package.xml" descriptors; config lives in
  `config.xml` under `installedpackages/speedtest`. No daemons unless needed.
- The firewall has policy routing (BrickLink → Quantum) and a gateway group as
  default route; the test traffic must be bound explicitly.
- The box is production for the house; a test saturates a WAN for ~10–20 s.
  No automatic tests without an explicit schedule (Phase 2).

## Decisions made (as decided in conversation)

| Decision | Choice | Whose |
|---|---|---|
| Engine | Ookla `speedtest` CLI, `-f json` | Jeff (keep what works) |
| Primary unit of work | A WAN *gateway* (not an interface, not an IP) | Jeff ("my different WAN interfaces") |
| Day-one scope | On-demand per-WAN test with pass/fail vs advertised | Jeff |
| Schedule/history graph | Later add-on, not day one | Jeff |
| Look | Themed like the native widgets | Jeff |
| Advertised speeds | Entered on the package's settings page, one row per gateway | **Claude's call** (asked; unanswered). A gateway "description" field is free text and would be fragile to parse. |
| Packaging | Real pfSense package in its own GitHub repo `jeffwnelson/pfsense-pkg-speedtest`; built with the pfSense `FreeBSD-ports` package skeleton (`net/pfSense-pkg-speedtest`) | Claude's call |
| Ookla binary install | Package post-install script fetches the Ookla pkg for the detected ABI into `/usr/local/libexec/speedtest/ookla-speedtest` (not `/usr/local/bin/speedtest`, so it cannot collide with `py311-speedtest-cli`) and records the version in the package config | Claude's call |
| Result storage | One JSON file per gateway under `/var/db/speedtest/<gwname>/latest.json`, plus an append-only `history.jsonl` per gateway (cap 500 lines) to feed Phase 2 without a schema change. Verified 2026-10-23: this box does **not** use RAM disks (`/var` is a persistent ZFS dataset, `use_mfs_var` unset), so `/var/db` survives reboots. If "Use RAM Disks" is ever enabled, the settings page must warn and the runner should write to `/usr/local/share/pfSense-pkg-speedtest/db` instead; implement the path as a single constant resolved at runtime from the RAM-disk setting | Claude's call |
| Background execution | The widget POSTs "start"; PHP spawns the runner detached (`nohup … &` via `mwexec_bg`) writing to `running.json`; the widget polls a status endpoint every 2 s | Claude's call |
| Egress binding | Runner resolves the gateway → interface → current IPv4 address at run time and passes `--ip=<addr>`; additionally sets a temporary pf tag? **No.** Keep `--ip` binding only; it is what Ookla supports and matches today's working behaviour. Runner verifies after the run that `interface.externalIp` in the Ookla result matches the WAN's address (or the WAN's known public IP if NATted) and marks the result `egress_mismatch` otherwise | Claude's call |

## The parts

### 1. Package skeleton

- Repo `jeffwnelson/pfsense-pkg-speedtest`, laid out like `FreeBSD-ports/net/pfSense-pkg-iperf`:
  `Makefile`, `pkg-plist`, `files/usr/local/pkg/speedtest.xml`,
  `files/usr/local/pkg/speedtest.inc`, `files/usr/local/www/widgets/widgets/speedtest.widget.php`,
  `files/usr/local/www/widgets/include/speedtest.inc`,
  `files/usr/local/share/pfSense-pkg-speedtest/info.xml`,
  `files/etc/inc/priv/speedtest.priv.inc`, `files/usr/local/libexec/speedtest/run.sh`.
- `speedtest.xml` provides: menu entry **Services → Speedtest** (settings page),
  the privilege definition, and `custom_php_install_command` /
  `custom_php_deinstall_command` hooks.
- Built with `make package` inside a FreeBSD 16 builder (a GitHub Actions job
  using a FreeBSD VM action, or `poudriere` on a VM). Release artifacts: the
  `.pkg` file. Install on pfSense: `pkg add https://github.com/…/releases/download/vX/pfSense-pkg-speedtest-X.pkg`.

### 2. Settings page (Services → Speedtest)

Standard pfSense package form (XML-driven, `rowhelper` for the per-gateway table):

| Field | Type | Notes |
|---|---|---|
| Enable widget | checkbox | default on |
| Ookla CLI status | read-only | version, path, "license accepted" state, button "Reinstall CLI" |
| Per-gateway rows | rowhelper | Gateway (select from IPv4 gateways, excluding groups and tunnels unless "show all" ticked), Advertised download (Mbps, **optional**), Advertised upload (Mbps, **optional**), Pass threshold % (default 80), Preferred server ID (optional, blank = auto) |
| Result retention | integer | history lines per gateway, default 500 |
| Schedule (`interval`) | per row | minutes (0 = off); implemented in 0.2.0, see §7 |

Validation: Mbps are positive integers **or blank** (blank = "measure only":
Starlink has no advertised figure); threshold 50–100 (below 50 % is already `fail`); server ID numeric;
gateway must exist. Save writes `installedpackages/speedtest/config/…` and
rewrites nothing else except that saving reconciles the cron items (§7).

### 3. Runner (`/usr/local/libexec/speedtest/run.sh` + `speedtest.inc`)

- PHP function `speedtest_start(string $gwname): bool` validates `$gwname`
  against configured gateways (whitelist, never from the request directly),
  refuses if a run is already active (lock file), writes
  `running.json {gateway, started_at, pid}` and spawns the runner detached.
- Runner (sh): takes **only** a gateway name as argv; looks up interface and
  address via `pfSsh.php`-free means: a tiny PHP helper
  `speedtest_resolve_gateway.php` prints `ifname addr server_id`. Builds argv as
  an array in PHP and passes via `escapeshellarg`; the sh script does no
  string interpolation of user data.
- Executes `ookla-speedtest -f json --accept-license --accept-gdpr --ip=<addr>
  [--server-id=<id>] --selection-details`, 90 s timeout (`timeout(1)`),
  captures stdout/stderr.
- Post-processes: adds `gateway`, `interface`, `advertised_down/up`,
  `pct_down/up`, `state` (`pass`/`warn`/`fail`/`error`/`egress_mismatch`),
  `ran_at` (ISO 8601 with offset), `error` text if any. Writes `latest.json`
  atomically (write temp, rename) and appends to `history.jsonl`. Removes
  `running.json` and the lock.
- Runtime state (`lock`, `running.json`, `others.json`, `gwcfg.json`, the
  Ookla stdout/stderr captures) lives in `/var/run/speedtest/`, which a reboot
  clears; only `latest.json`, `history.jsonl` and `last_public_ip` persist
  under the result root. A lock older than 180 s, or whose recorded pid is
  gone, is stale and is cleared by both start and status.
- If `finish.php` fails, `run.sh` still records `state=error`,
  `error="finish.php failed (rc N)"` (retrying PHP with a preset error, then
  writing the JSON directly).
- State rule (Claude's call): `pass` if both down and up ≥ threshold %;
  `warn` if either is between 50 % and threshold; `fail` if either < 50 %;
  `measured` if the gateway has no advertised numbers (neutral grey badge,
  shows Mbps only); `error` if the CLI failed; `egress_mismatch` if the
  measured external IP isn't the WAN's — see "Egress verification" below.
  The `state` enum is therefore `pass|warn|fail|measured|error|egress_mismatch`
  and is frozen for Phase 2.

**Egress verification (Claude's call, after review):** a WAN behind carrier
NAT (Starlink hands out 100.64/10) will never report its interface address as
`externalIp`, so equality is the wrong test. The rule is: the result is
`egress_mismatch` if `interface.externalIp` equals the current address or last
observed public IP of **any other configured gateway**; otherwise it is accepted
and the observed `externalIp` is stored as that gateway's `last_public_ip` for
future comparisons (an `error` or `egress_mismatch` run never updates it, so
one misrouted run cannot poison later checks). No per-row "expected IP" or "skip check" field. Starlink is
configured on day one if its gateway exists; a WAN with no carrier simply
produces `error` ("interface has no address").

### 4. Widget (`speedtest.widget.php`)

Looks like the Gateways widget. One row per configured gateway:

```
 WAN            Download     Upload       Ping    Last run           ▶
 MetroNet       1,984 Mbps   1,922 Mbps   3.0 ms  2026-10-23 14:02   ▶
 Quantum        1,333 Mbps   1,282 Mbps   27 ms   2026-10-23 14:05   ▶
 Starlink       —            —            —       never              ▶
```

- Columns: WAN name (gateway description), Download, Upload, Ping, Last run,
  a per-row **Run** button. **Download/Upload cells show only the measured
  speed and unit** (e.g. `1,984 Mbps`) — no percentage badge and no
  `/ advertised` denominator (Jeff, 2026-10-23). The pass/warn/fail state is
  conveyed by colouring the number with pfSense's `text-success` /
  `text-warning` / `text-danger` (`text-muted` for `measured`), never
  hard-coded hex. Advertised speed and percentage appear only in the expanded
  detail row.
- Heading icon area: a single refresh icon that reloads results (not runs
  tests), matching other widgets.
- Row expands (chevron, like the fork's history toggle) to show server
  name/location, jitter, packet loss, external IP, and an Ookla result link.
- While a run is active: that row's button becomes a spinner, other rows'
  buttons disable (one run at a time), the widget polls
  `/widgets/widgets/speedtest.widget.php?act=status` every 2 s and updates in
  place. **No `location.reload()`.**
- Widget settings (gear icon, like Gateways): which gateways to show, and two
  independent toggles, **Hide Ping** (`hide_ping`) and **Hide Last run**
  (`hide_lastrun`) (Jeff, 2026-10-23); each removes its column header and
  cells, and the detail/empty-row colspan follows. Stored per-widget in
  `config.xml` `widgets/speedtest-N` like native widgets. Settings saved by
  the earlier single "compact" toggle (`compact=1`, neither new key present)
  are read as both hidden; saving the form writes the new keys and drops
  `compact`.
- Empty state: "No gateways configured — Services → Speedtest" with a link.
- All output through `htmlspecialchars`; all AJAX endpoints check the
  `speedtest` privilege and the CSRF token (`csrf_get_token()` / pfSense's
  standard `$_POST['__csrf_magic']` handling).

### 5. Ookla CLI handling

- Post-install: detect ABI with `pkg config ABI` → FreeBSD major N; download
  `https://install.speedtest.net/app/cli/ookla-speedtest-<ver>-freebsd<N>-x86_64.pkg`.
  The package ships a manifest of known-good `(version, freebsd-major) →
  sha256, size` entries; the settings page offers **only** versions from that
  manifest (dropdown, default = newest that has a build for the running or
  nearest lower major). A new Ookla release therefore requires a package
  release, by design — the package never fetches or runs an unverified binary
  (Claude's call, after review). Verify size/sha256 against the manifest, extract **only** the
  binary into `/usr/local/libexec/speedtest/ookla-speedtest` (do not `pkg add`
  it, avoiding ABI mismatch and the `/usr/local/bin/speedtest` collision).
  Record version in config. Run `--accept-license --accept-gdpr` once.
- If no FreeBSD-16 build exists from Ookla yet, fall back to the newest lower
  major build **present in the manifest** that runs (test with `--version`),
  and surface which one is in use on the settings page. (This is the realistic
  case today: Ookla publishes freebsd12/13 builds only; the FreeBSD 13 build
  ran on 2.8/2.9 before it was removed. FreeBSD ports `net/speedtest` does the
  same thing and is a good reference for the fetch/extract steps.)
- Deinstall removes the binary and, optionally (checkbox, default keep), the
  results directory.

### 6. Phase 2 (designed for, not built)

- Cron: implemented in §7 by direct `cron/item` edits (per-gateway interval;
  `install_cron_job()` is not used); staggered so two WANs never test together.
- Status → Speedtest page: table + chart (pfSense bundles d3/nvd3 for traffic
  graphs; reuse) from `history.jsonl`, selectable range, per-WAN overlay with
  advertised line.
- Optional threshold notification via pfSense's `notify_via_*`.
- Data model already supports it: `history.jsonl` lines are the same objects
  as `latest.json`.

## Left to the builder

- Exact Bootstrap markup to match the Gateways widget (read
  `/usr/local/www/widgets/widgets/gateways.widget.php` and
  `widgets/include/gateways.inc` on the box and mirror them).
- How to run the FreeBSD build (Actions VM vs. a one-off builder jail on
  ESXi); either is fine. The deliverable is a `.pkg` in a GitHub release.
- Lock/timeout details, log location (`/var/log/speedtest.log` via
  `log_error()` is fine), and the exact JSON field names beyond the ones
  listed.
- Whether the settings page uses pure XML `rowhelper` or a hand-written PHP
  page if XML can't express the per-row widgets; prefer XML.

## Acceptance (day one)

1. Fresh pfSense 2.9 VM: `pkg add` the release artifact → Services → Speedtest
   exists, Ookla CLI reports a version, widget can be added to the dashboard.
2. Configure MetroNet (2000/2000) and Quantum (1000/1000). Run MetroNet from
   the widget: dashboard stays responsive; row spins; result appears in ≤ 60 s
   with external IP 203.0.113.x and a green badge. Run Quantum: external IP
   198.51.100.x and result appears without touching MetroNet's row.
3. Reboot: both latest results still shown.
4. Attempt a POST with a bogus gateway name and with shell metacharacters in
   any field: rejected, nothing executed (check `/var/log/speedtest.log`).
5. `pkg delete`: widget, menu, binary gone; results kept unless opted out.
6. Visual: side by side with Gateways and Interfaces widgets in both the
   default and dark themes, no inline colours, same font sizes.

## 7. Phase 2a — scheduled runs (decided 2026-10-23)

Scope: automatic per-WAN tests on a schedule. **Not** in this phase: the history
graph page, alerts.

Decisions (Claude's calls unless marked):
- **Per-gateway interval**, chosen on the Gateways row: `off` (default), every
  1 h, 3 h, 6 h, 12 h, daily. Stored as minutes (`0, 60, 180, 360, 720, 1440`)
  in the row key `interval`.
- **pfSense cron**: the package edits `cron/item` directly with exact command
  matching (pfSense's `install_cron_job` substring-matches and is unsafe with
  prefix-named gateways), then `write_config()` + `configure_cron()` once if
  anything changed. One entry per scheduled gateway. Command (exact, used as the match key):
  `/usr/local/bin/php -f /usr/local/libexec/speedtest/scheduled.php <gwname>`.
- **Stagger**: gateway *i* (0-based position among scheduled rows) runs at
  minute `(i * 7) % 60`. Hour field: `*` for 1 h, `*/3`, `*/6`, `*/12`, and
  `3` for daily (03:00 local + offset). A run takes ≤ 90 s, so 7 minutes apart
  never overlaps; if it ever does, the lock makes the second run skip with a
  log line, and it simply waits for its next slot.
- **Reconcile on every save** (General *and* Gateways pages call
  `speedtest_resync()`): desired jobs are computed from the rows; stale jobs
  (gateway removed, interval set to off, or package disabled) are removed from `cron/item`
  by exact command match. `custom_php_deinstall_command` removes all
  jobs. A disabled package (`enable` off) installs no jobs and
  `scheduled.php` also refuses to run.
- **`scheduled.php <gw>`** (CLI, requires `speedtest.inc`): calls
  `speedtest_start($gw, 'scheduled')`; `busy`/`disabled`/`unknown gateway`
  are logged via `log_error("speedtest: scheduled run skipped: …")` and exit 0
  so cron never emails.
- **Record gains `trigger`** = `manual | scheduled`, carried through
  `gwcfg.json` → `finish.php` → record. The widget shows it in the detail row
  and marks scheduled results with a small `fa-solid fa-clock` after the Last
  run time (tooltip "scheduled").
- **Widget auto-refresh**: when idle, the widget reloads its rows every 60 s
  (so scheduled results appear without a page reload). While a run is active
  the existing 2 s poll takes over.
- Version bump to **0.2.0**.

Acceptance: set QUANTUM_GW to every 1 h → a `cron/item` with the exact command
appears and `crontab -l` shows it; `scheduled.php QUANTUM_GW` from the CLI
produces a record with `trigger=scheduled`; setting the interval back to off
removes the item; disabling the package removes all items; `pkg delete`
leaves no `scheduled.php` cron items.
