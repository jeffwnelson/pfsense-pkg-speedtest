# pfSense-pkg-speedtest

Run Ookla speed tests on each WAN of your pfSense firewall, and see the results on the dashboard.

[![build](https://github.com/jeffwnelson/pfsense-pkg-speedtest/actions/workflows/build.yml/badge.svg)](https://github.com/jeffwnelson/pfsense-pkg-speedtest/actions/workflows/build.yml)
[![release](https://img.shields.io/github/v/release/jeffwnelson/pfsense-pkg-speedtest)](https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/latest)
[![license](https://img.shields.io/github/license/jeffwnelson/pfsense-pkg-speedtest)](LICENSE)

## Features

- **One test per WAN.** Each test is bound to the gateway's own address, so it measures that link and nothing else.
- **Dashboard widget.** Start a test with one click and see download, upload, ping and when it last ran.
- **Pass or fail.** Enter the speed your ISP sells you, and each result is marked pass, warn or fail against it.
- **Wrong-WAN check.** If a test leaves through a different WAN than the one you picked, the result is flagged instead of counted.
- **Scheduled tests.** Run each gateway every 1, 3, 6 or 12 hours, or daily. Gateways never test at the same time.
- **Built-in updater.** See new releases and their notes, then install, update or roll back from the GUI or the shell.

## Requirements

- pfSense CE 2.9 or newer on amd64
- Internet access to `github.com` (package and updates) and `install.speedtest.net` (Ookla CLI)

## Install

1. Open the [latest release](https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/latest) and note its version number.
2. On the firewall, open **Diagnostics > Command Prompt** (or SSH in as root) and run the commands below, with `VERSION` set to that number:

```sh
VERSION=0.3.3
cd /tmp
fetch https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/download/v$VERSION/pfSense-pkg-speedtest-$VERSION.pkg
fetch https://github.com/jeffwnelson/pfsense-pkg-speedtest/releases/download/v$VERSION/pfSense-pkg-speedtest-$VERSION.pkg.sha256
sha256 -c "$(cut -d' ' -f1 pfSense-pkg-speedtest-$VERSION.pkg.sha256)" pfSense-pkg-speedtest-$VERSION.pkg && pkg-static add pfSense-pkg-speedtest-$VERSION.pkg
```

The install also downloads the Ookla Speedtest CLI and checks it against a pinned checksum. Using this package means you accept Ookla's [EULA](https://www.speedtest.net/about/eula) and [privacy policy](https://www.speedtest.net/about/privacy).

After the first install, use the **Updates** tab for new versions.

## Setup

1. Go to **Services > Speedtest > Gateways** and add a row for each WAN you want to test.
   - **Advertised download / upload:** the speeds your ISP sells you, in Mbps. Leave blank to skip the pass/fail check.
   - **Pass threshold:** how close to the advertised speed counts as a pass (default 80%).
   - **Server ID:** an Ookla server to always use. Leave blank to let Ookla pick.
   - **Interval:** how often to test automatically, or Off.
2. Add the **Speedtest** widget to the dashboard and click the play button next to a gateway.

### How results are rated

The widget colors each speed by how it compares to the advertised speed:

| Color | Meaning |
|---|---|
| Green (pass) | At or above the pass threshold |
| Yellow (warn) | Below the threshold, but at least 50% of the advertised speed |
| Red (fail) | Below 50% of the advertised speed |
| Gray | No advertised speed was entered, so there is nothing to compare |

If a test's public IP belongs to another gateway, it left through the wrong WAN. The widget shows an error for that run instead of speeds.

### General settings

Found at **Services > Speedtest > General**:

- **Enable:** turn all tests on or off.
- **Result retention:** how many results to keep per gateway (default 500).
- **Show all gateways:** also list VPN and tunnel gateways.
- **Remove data on uninstall:** delete stored results when the package is removed.
- **Disable update check:** stop the daily check for new releases.
- **Ookla CLI version / Reinstall CLI:** reinstall the Ookla CLI if it is missing or broken.

## Scheduled tests

Each gateway with an interval gets its own cron job. The package adds, changes and removes these jobs for you when you save a settings page. Gateways are spread 7 minutes apart so two WANs never test at once. A scheduled run is skipped (and logged) if another test is already running or the package is disabled. Scheduled results show a clock icon in the widget.

If you rename or remove a gateway under **System > Routing**, delete its row on the Gateways tab too.

## Updates

The **Updates** tab shows your version, the latest release and what changed. Pick any version and click **Install** to update, or pick an older one to roll back. Each download is checked against its SHA-256 before it is installed. Your settings, gateways and results are kept.

Once a day the package checks for a new release and shows a pfSense notice when one is out. It never installs anything on its own.

The same tools are available from the shell:

```sh
pfsense-speedtest version          # installed version
pfsense-speedtest check            # is there a newer release?
pfsense-speedtest list             # all releases
pfsense-speedtest update [version] # install the latest, or a given version
```

Installing updates from the GUI needs the **WebCfg - Services: Speedtest: Updates** privilege, which is separate from the one for the other Speedtest pages.

## Uninstall

```sh
pkg-static delete pfSense-pkg-speedtest
```

Stored results are kept unless **Remove data on uninstall** is checked.

## Building from source

The package is built and tested in a FreeBSD VM by GitHub Actions on every pull request. To build it yourself on FreeBSD:

```sh
pkg install -y hs-ShellCheck node php85 libxml2
sh tests/run.sh
sh build.sh 0.0.0
```

The package is written to `dist/`.

## Contributing

Issues and pull requests are welcome.

- Pull request titles must follow [Conventional Commits](https://www.conventionalcommits.org/) (for example `fix(widget): ...` or `feat: ...`). CI checks this.
- Every merge to `main` makes a new release. The version and release notes come from the pull request title: `feat` bumps the minor version, everything else bumps the patch version.
- Please add or update tests in `tests/` with your change.

## Security

Please report security issues privately. See [SECURITY.md](SECURITY.md).

## License

[Apache-2.0](LICENSE)
