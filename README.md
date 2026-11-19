# pfSense-pkg-speedtest

A pfSense package that runs a per-WAN Ookla speedtest, compares results against each gateway's advertised speed, and shows them in a dashboard widget. Install with `pkg add <release url>` (use the package URL from the GitHub release) and remove with `pkg delete pfSense-pkg-speedtest`.

## Scheduled runs

Set an **Interval** per gateway on the Gateways tab (off, 1 h, 3 h, 6 h, 12 h or daily). Gateways are staggered 7 minutes apart so two WANs never test together. The package manages the cron entries itself: saving either settings page reconciles them, and disabling the package or setting a gateway to off removes them. Each entry runs `scheduled.php <gateway>`, which skips (and logs why) when a test is already running or the package is disabled. Scheduled results show a clock icon in the widget, which refreshes every 60 seconds while idle.

If a gateway is renamed or removed in System → Routing, delete its row here; otherwise its job logs `skipped: unknown gateway` at each interval.

## Updates

The **Updates** tab shows the installed and latest versions with release notes, and installs any release from GitHub (newer to upgrade, older to roll back) after checking its SHA-256. Settings and results are kept. A daily check posts a pfSense notice when a new release is out; it never installs anything itself (turn it off with **Disable update check** on the General tab). From a shell: `pfsense-speedtest version | check | list | update [version]`. Installing needs the *WebCfg - Services: Speedtest: Updates* privilege.

Licensed under Apache-2.0.
