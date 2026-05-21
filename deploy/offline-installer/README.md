# ARISE Offline Installer

Scripts for building, deploying, and cloning the ARISE platform onto Ubuntu machines with no internet at the target site.

## Files

| File | Run on | Purpose |
|---|---|---|
| `make_package.sh` | Source machine (with internet) | Downloads required `.deb` packages, archives `/var/www/arise/`, and bundles everything into `arise_package.tar.gz` for offline deployment. |
| `setup.sh` | Target machine | Convenience wrapper — extracts `arise_package.tar.gz` and invokes `install.sh`. |
| `install.sh` | Target machine (called by `setup.sh`) | Installs bundled `.deb`s, restores app files (preserving an existing DB), generates SSL, configures Apache, prompts for WiFi hotspot settings. |
| `first-boot-fix.sh` | A *cloned* target machine | One-shot per-clone fix: regenerates machine-id and SSH host keys, sets a unique hostname (uses the MAC suffix), rebinds `ARISE-Hotspot` to the actual WiFi interface, generates a fresh `datapost_config.school_id`, restarts Apache. |

## Typical flows

**Build a package** (on a machine with internet):
```bash
sudo bash make_package.sh
# produces arise_package.tar.gz
```

**Install on a brand-new machine**:
```bash
sudo bash setup.sh
```

**Bring a cloned disk image online** (already has everything from the source machine, just needs a fresh identity):
```bash
sudo bash first-boot-fix.sh
```

## Notes

- The bundled packages must match the target's CPU architecture (build amd64 → install amd64).
- Re-running `setup.sh` on a machine with an existing ARISE database now preserves the DB (a timestamped copy is taken before the extract and restored after).
- WiFi hotspot mode requires a chipset that supports AP — Intel Centrino 5000/6000-series (`iwldvm`) does not. If `nmcli connection up ARISE-Hotspot` reports `supplicant-timeout`, fall back to ethernet or use a USB WiFi dongle with Realtek 8188CUS / Ralink 5370 / 3070.
- `curl` and `php-curl` are now required for the cloud-sync code path; both are downloaded by `make_package.sh` and installed by `install.sh`.
