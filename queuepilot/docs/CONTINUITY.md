# Continuity: when the internet or QueuePilot is down

A clinic cannot stop seeing patients because a web page will not load. This is
the procedure reception follows. Print it, laminate it, keep it at the desk.

The governing rule: **never present stale data as live.** If the screen cannot
confirm it is current, it says so — and staff fall back to paper.

---

## 1. Recognise the state

| What you see | What it means | Do this |
| --- | --- | --- |
| Green "Live" pill, board updating | Normal | Nothing |
| Amber "Connection lost — showing last known state" | The last poll failed. Data on screen may be seconds old | Watch it for 30 seconds; if it returns to Live, carry on |
| Same amber pill for more than 2 minutes | Treat the screen as historical | Start the paper fallback below |
| Full-page "You are offline" | The device has no network at all | Start the paper fallback below |
| Login page rejects a correct password | Server unreachable, or account locked by rate limiting | Try once more, then paper fallback and call the owner |

The board deliberately keeps showing the last known queue rather than going
blank — a blank screen loses the order of patients, which is worse.

## 2. Paper fallback (2-minute setup)

Keep a printed **token pad** (numbered 1–200, perforated) in the reception
drawer, plus the "Manual queue log" sheet.

1. **Do not clear the screen.** Leave it as the last known state; note the time.
2. Write the time you went manual at the top of the manual log.
3. Issue the next paper token by hand. Continue from the highest number you can
   see on screen, plus one.
4. Record for each patient: paper token, name, phone (optional), arrival time,
   appointment or walk-in, and who called them.
5. Use the same order rules as the system: booked patients first, then walk-ins
   by arrival, clinical priority only when a clinician says so **and** writes the
   reason on the log.
6. When the doctor calls a patient, write the time in the "called" column.

## 3. Reconciling when service returns

This is the part that goes wrong if it is improvised. Do it **before** issuing
any new digital tokens.

1. Open the manual log and the board side by side.
2. For each paper token that is still waiting, register the patient in
   QueuePilot (`New patient`) and **write the paper number on the new token's
   note**. The note is included in the audit trail.
3. For patients already seen on paper, add them and immediately complete the
   entry, so the day's counts and average consultation times stay correct.
4. Do not re-issue tokens for patients already on the screen — that creates a
   duplicate active entry, which the system now refuses outright.
5. Note the outage window in the clinic log (`Settings → audit log` shows what
   the system recorded; the manual log covers the gap).

## 4. What the system already does for you

* The patient tracking page and the waiting-room screen both mark themselves
  **stale** after 30 seconds without data, with the last update time. On the TV
  that is an amber banner across the top and the green "Live" pill turning
  into "Offline — not live"; a permanently green pill beside an amber banner
  would be worse than having no pill, so the two are driven by the same poll.
* `/api/*` is never cached by the service worker. The board's own JSON is live
  or it is absent — a patient is never shown a cached "you are next" from an
  hour ago. The shell (CSS, JS, icons) is cached, so a screen that loses its
  connection still renders the last page instead of a browser error page.
  This is enforced in `public/sw.js` by returning early for any `/api/` path,
  which means the request is handled by the network alone.
* Polling backs off to 30 seconds during an outage, so a weak link is not
  hammered by 50 devices, and resumes immediately when the tab is focused.
* An operator action that loses a race returns 409 and the board refreshes;
  the action is never silently applied twice.

## 4a. Rehearsing this procedure

A procedure nobody has run is a guess. `tools/continuity-drill.php` walks the
steps a script can reach against real data: it prints the board state you would
copy off the screen, the next paper token numbers, blank rows for the manual
log, the reconciliation arithmetic, and whether any patient currently holds two
live entries (the one mistake that corrupts the day's counts).

```
php tools/continuity-drill.php --clinic 1
```

It ends with `DRILL PASSED` or `DRILL FAILED`. Run it once a quarter and write
the date on the printed copy at the desk. Last run on this installation:
**5 November 2026, passed** — 37 seen, 10 cancelled, 9 waiting, no duplicates.

What the drill cannot test, and what therefore still needs a person on the day:
issuing a physical paper token, and phone contact with a patient who left.

## 5. If the whole server is down

1. Reception runs on paper (above).
2. The clinic owner calls the platform operator on the number in the contract.
3. The operator checks, in this order:
   * `php tools/healthcheck.php` — database reachable, tables present, plans
     seeded, CSS bundle present, both schemas in agreement
   * database disk space (a full disk is the most common total outage)
   * the worker: `php tools/queue-worker.php` — a stuck worker only delays SMS,
     it never blocks the queue
4. Restore from the most recent verified backup (see `docs/BACKUPS.md`) and
   reconcile the gap with the paper log.
5. Post-incident: the audit log shows every queue action with the actor and
   timestamp, so the paper log only needs to cover the outage window.

## 6. What staff should never do

* Turn off the browser's clock or change device time zones to "fix" a display.
* Delete a queue entry to correct a mistake — cancel it, with a reason.
* Share a display link with someone outside the clinic; rotate the key instead
  (`Settings → display key → rotate`).
* Use the same login on the reception tablet and a personal phone; a session on
  a shared device is a privacy risk.
