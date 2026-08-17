# ARISE box onboarding — courier checklist

**Print this page.** Keep it with the USB. One box takes ~5 minutes.

---

## Before you start

- [ ] You have the ARISE onboarding USB.
- [ ] The box is powered on and Apache is running (visit http://localhost/arise/ from the box to check — you should see the ARISE homepage).
- [ ] You have the box's root password OR sudo access.

## Steps

### 1. Plug in the USB.

Wait a few seconds for it to mount.

### 2. Open a terminal on the box.

If the box has a monitor + keyboard: press `Ctrl+Alt+T`.
If you're connecting from another laptop: SSH in as usual.

### 3. Find where the USB mounted.

Run this command:

    ls /media/

You should see one entry, e.g. `USB` or `ARISE-USB`. Take note of that name.

### 4. Run the onboarding script.

Substitute the USB name from step 3:

    sudo bash /media/*/box-onboarding.sh

Type the root password if prompted. **Then wait — do not close the terminal, do not touch the keyboard.**

The script prints 9 steps. Each step should print `OK` or `✓`. This takes 3–5 minutes.

### 5. Look at the last line the script prints.

**If it's GREEN and says `SUCCESS: box '<name>' is now on daily auto-updates`:**

- [ ] Safely eject the USB (right-click on the desktop icon → Eject, OR run `sudo umount /media/*`).
- [ ] Note down the box name (from the SUCCESS line) on your visit sheet.
- [ ] Move to the next box.

**If it's RED and says `FAILED: <reason>`:**

- [ ] **Take a phone photo of the entire terminal window** — the FAILURE reason and the last 20 lines above it.
- [ ] Do NOT close the terminal. Do NOT re-run the script.
- [ ] The script has already tried to auto-restore the DB backup. The box should still work as it did before.
- [ ] Note the box name and the FAILURE line on your visit sheet, then move on.
- [ ] Flag the box for a technical follow-up.

---

## Common issues

**"USB payload missing"** — you're running the wrong USB. Get the correct onboarding USB from ops.

**"cannot locate ARISE install"** — this box has ARISE in a nonstandard folder. Skip this box, flag for follow-up.

**"must run as root"** — you forgot `sudo`. Re-run with `sudo bash …`.

**"not enough disk space"** — the box's disk is full. Skip and flag; someone needs to free space first.

**"no web server running"** — Apache is stopped. Try `sudo systemctl start apache2` and re-run the script.

---

## After the visit

Bring the USB back to ops. The `fleet-log/` folder on the USB contains one log per box you visited — those go into central ops for review.
