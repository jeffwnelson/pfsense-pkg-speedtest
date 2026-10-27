# Acceptance results, 0.1.0 candidate (2026-10-23)

Installed on pfsense.tuxy.io (pfSense 2.9 CE, FreeBSD 16.0-CURRENT) from the CI artifact
`pfSense-pkg-speedtest-0.0.0-dev.d115087.pkg` (sha256 `7d80cbc87befdd6122a2d827888dedd05a64fcf184f46d44e349c12e71d8aef5`, verified on the firewall after upload). Runs were driven from the
PHP CLI (`speedtest_start()`), not the GUI.

## Defect found and fixed during acceptance

The first artifact (`dev.7a4dcdf`) installed fine but every run failed with "could not parse Ookla
output": `SPEEDTEST_BIN` pointed at `/usr/local/bin/speedtest` (the py311-speedtest-cli wrapper)
instead of `/usr/local/libexec/speedtest/ookla-speedtest`. Fixed in `d115087` (later hardened to derive the path from `SPEEDTEST_LIBEXEC`, with a regression test `tests/bin_path.test.sh`), rebuilt by CI,
reinstalled. All results below are from the fixed build.

## Checklist

- [x] Old widget removed; Ookla stub `speedtest` pkg deleted (owned only a man page); history backed up to `/var/db/speedtest_history.json.bak`
- [x] `pkg add` OK; post-install printed `installed Speedtest by Ookla 1.2.0.84 ... (freebsd13)`; `get_package_id("speedtest")` = 11; `/usr/local/bin/speedtest` still speedtest-cli 2.1.3
- [x] Config seeded via PHP CLI (METRONET 2000/2000, QUANTUM 1000/1000, STARLINK blank, threshold 80)
- [x] Quantum: state `pass`, 1333.8 down / 1282.0 up Mbps (133% / 128%), external_ip 198.51.100.214, 17:01:01
- [x] MetroNet: state `warn`, 2013.4 down / 1519.0 up Mbps (101% / 76%; upload under the 80% threshold on this run), external_ip 203.0.113.45, 17:01:26
- [x] Starlink: immediate `error` "interface has no address", no process spawned
- [x] Busy: second `speedtest_start("QUANTUM_GW")` during the MetroNet run returned `ok=false, error=busy, gateway=METRONET_GW`; one `ookla-speedtest` binary process (pgrep -f also lists the `timeout` and `run.sh` wrappers, 3 lines)
- [x] Lock and `running.json` gone after every run
- [x] Injection: `speedtest_start("METRONET_GW;id")` returned `unknown gateway`; `grep speedtest /var/log/system.log | tail -5` after the attempt showed only package install/deinstall and config-change lines (no speedtest runtime lines at all): `Removed speedtest package.`, `pfSense-pkg-speedtest-0.0.0-dev.7a4dcdf deinstalled`, `Installed speedtest package.`, `pfSense-pkg-speedtest-0.0.0-dev.d115087 installed` (17:00:47-48). No injection traces
- [x] Widget file passes `php -l`; `speedtest_status()['results']` has 3 keys
- [x] Uninstall: menu entry, priv file, widget, include, `.xml`/`.inc` files and the Ookla binary removed; `/var/db/speedtest` kept; `get_package_id` = -1 while removed
- [x] Reinstall: config survived (`installedpackages` kept), all three previous results still shown by `speedtest_status()`
- [x] Timing, responsiveness, row isolation (extra Quantum run, 17:03:54 to 17:04:05 CDT): `started_at` 22:03:54Z (17:03:54 CDT), `ran_at` 17:04:05, so 11 s elapsed (limit 60 s). `pgrep -x ookla-speedtest | wc -l` = 1; `pgrep -fl` listed the `timeout`, `run.sh` and binary processes (pids 31142, 30002, 31416). From the Mac during the run: `curl -k https://pfsense.tuxy.io/` returned `200 0.0757s`, `200 0.0466s`, `200 0.0446s` (the first two samples fall inside the run window, the third at about its end). MetroNet `ran_at` was `2026-10-23T17:01:26-05:00` before and after, so that row was unchanged.
- [ ] Reboot pfSense: pending user
- [ ] Visual check of the dashboard widget (default and dark themes): pending user
- [ ] Tag v0.1.0, let CI publish the release, reinstall from the release URL: pending controller
- [ ] Update lab docs (vault `Infrastructure.md` pfSense package list, lab-ansible `docs/pfsense.md` Packages note) and the memory note for the install path: pending controller
- [ ] Open issue "Phase 2: scheduled runs + history graph" linking `docs/design.md` section 6: pending controller

## Observations

- After uninstall, empty directories remain: `/usr/local/libexec/speedtest` and `/usr/local/share/pfSense-pkg-speedtest`.
- `/usr/local/pkg/speedtest.xml.pkgsave` and `speedtestgateways.xml.pkgsave` are leftovers from hand-staging on this host, not from the package.
- Hand-run Ookla with `--ip` logs many `bind(...)` error lines on stderr but still returns a valid result via the correct WAN.

## 0.2.0 - scheduled runs

- [ ] Tag v0.2.0, CI publishes the release; download, sha-verify, upgrade with `pkg add` on pfSense (no reboot)
- [ ] QUANTUM_GW interval 60 via PHP CLI config + `speedtest_resync()`: `grep scheduled.php /conf/config.xml` shows one item, minute 0, hour `*`
- [ ] `crontab -l` (root) shows the entry after `configure_cron()`
- [ ] `php -f /usr/local/libexec/speedtest/scheduled.php QUANTUM_GW` records `trigger=scheduled`
- [ ] Manual run in progress, then `scheduled.php`: log line "skipped: busy", exit 0
- [ ] Interval set to 0: item removed
- [ ] Package enable off: all items removed; re-enable restores
- [ ] Widget: clock icon on scheduled result, expanded rows survive refresh
- [ ] `pkg delete` (deinstall hook) leaves no `scheduled.php` cron items
- [ ] Deleting a row on the Gateways list page removes its job
- [ ] Browser check: idle refresh stops during a run and resumes after
- [ ] Future upgrades (0.2.x to 0.3) rely on POST-INSTALL resync to re-create jobs removed by the old DEINSTALL.

### Evidence (2026-10-23 20:58–21:05 CDT, controller via pfRest CLI; artifact sha256 `10e7ed670903071de473d7dfbda29f98b1765c7b7d069836453f5e7786f53895`, in-place upgrade 0.1.0 → 0.2.0, Ookla binary preserved)

- [x] Intervals QUANTUM_GW=60, METRONET_GW=180 + `speedtest_resync()` → `cron/item` has 2 items: `7 *` …`scheduled.php QUANTUM_GW`, `0 */3` …`scheduled.php METRONET_GW`; `/etc/crontab` shows 2 lines.
- [x] `php -f scheduled.php QUANTUM_GW` → rc 0, no stdout; `running.json` `{"gateway":"QUANTUM_GW","started_at":"2026-10-24T01:59:10Z","pid":86608}`; `latest.json`: `trigger=scheduled state=pass down=1333 ext=198.51.100.214 ran_at=2026-10-23T20:59:20-05:00`.
- [x] Busy: manual `speedtest_start("METRONET_GW")` → ok; immediate `scheduled.php QUANTUM_GW` → rc 0, `pgrep -x ookla-speedtest | wc -l` = 1, system.log: `speedtest: scheduled run skipped: busy (QUANTUM_GW)`. MetroNet run completed `trigger=manual state=warn 2009/1511`.
- [x] Intervals set to 0 → 0 items, 0 crontab lines.
- [x] Disable package (`enable` off) with one scheduled row → 0 items; re-enable → 1 item back (`0 *` QUANTUM_GW).
- [x] `pkg delete` → 0 `scheduled.php` items in config and crontab; results dir kept. `pkg add` 0.2.0 again → config rows intact (QUANTUM interval 60) and POST-INSTALL resync re-created the item (1).
- [x] Future-upgrade path implied by the above: DEINSTALL removes items, POST-INSTALL resync restores them from config.
- [ ] Row delete on the Gateways list page removes its job — **pending user** (GUI).
- [ ] Idle refresh stops during a run and resumes after; clock icon on scheduled result; expanded rows survive refresh — **pending user** (browser).
- Final state: all intervals set back to 0 (off) so the user can choose cadences in Services → Speedtest → Gateways.
