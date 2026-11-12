# Reliability review — 22 September 2026

Reviewed application routes, authorization, clock/timing services, user and race management, imports/exports, browser timing and offline storage, Reverb configuration, deployment, scheduler and CI.

## Repairs in this review

- Race state reconciles every five seconds and after reconnect/focus independently of IndexedDB initialization; live start/finish events remain the immediate path.
- Deployment reports actual local/public WebSocket handshakes and frontend public-key consistency, without logging credentials.
- Broadcast delivery errors no longer turn an already-committed clock or timing operation into an HTTP error. They remain logged, and reconciliation retrieves authoritative state.
- Timing request identifiers must match race, entry, checkpoint and operator. Station requests cannot claim the manual-correction source to bypass timestamp checks.
- Disabled required checkpoints do not block timing progression.
- Malformed JSON participant rows produce validation errors, rather than type errors.
- CSV formula prefixes are escaped; XLSX text is written explicitly as text, preserving leading-zero bibs.
- Results refresh while open; editing an administrator preserves existing race assignments; login email normalization is consistent.

## Verified production behavior

Forge deployed PR 10; its log confirmed the frontend public key matches the server and both local and public WebSocket handshakes complete. In two open browser screens for a disposable test race, the finish reached the station in 319 ms and both clocks stopped at 00:00:18. Race delete/restore preserves participants and timing history. The real KOKO LOCO race was not changed.

## External setup still needed

1. Configure a real mail transport and test password reset delivery. The log mailer does not deliver email. Provider credentials and a sender are needed.
2. Configure encrypted off-server database backups and rehearse restoration. A destination and retention policy are needed; Forge's backup feature is gated by its Business plan.
3. Rehearse on actual phones and mobile networks: screen lock/wake, long sessions, lost signal and simultaneous taps. Browser CI and the MySQL concurrency tests below do not establish cellular reliability or full event load capacity.
4. Independent uptime alerting needs a selected service and notification destination. The application health page provides diagnostics, not external alert delivery.

The application is deployed on MySQL. Participant sorting no longer uses MySQL-specific SQL (November 2026), but PostgreSQL is still not tested and therefore not supported.

## Event-readiness implementation

- Pending timings are scoped to the recording account, restored from IndexedDB, retried every 15 seconds and on reconnect, and deleted only after a matching server acknowledgement. Authorization/validation/conflict failures stay visible for review. Operators can retry, explicitly acknowledge checkpoint warnings, discard with confirmation, or export a JSON backup. Legacy records without an owner are never automatically uploaded; administrators can export them for manual review.
- Accounts have paginated name/email search; athlete linking searches and pages through available profiles instead of silently limiting creation to the first 500.
- Participant edits include later bib assignment, category and athlete profile corrections, and DNS/DNF/DSQ status, with mandatory reasons and before/after audit history. Shared athlete profile corrections explicitly apply to all their entries. Existing timing history is retained.
- Results and CSV/XLSX exports support type, category and status filters. Exact equal millisecond totals share competition places (1, 1, 3); this is a transparent general default, not a claim to implement a federation's rule book.
- Station wake lock is opt-in where supported. Native timing, participant and checkpoint confirmation dialogs use the shared accessible dialog. Checkpoint validation errors appear inline.
- CI includes an isolated Chromium workflow exercising offline recovery, account switching, participant edits, status filters and frozen clocks; a MySQL 8.4 workflow tests simultaneous distinct taps and retries against real row locks.
- `/admin/health` and `php artisan timing:health` report database connectivity, scheduler/worker heartbeats, Reverb handshakes, queue failures, disk space, mail configuration and running-race station presence. Checks expose no credentials. Reverb handshakes run every five minutes and queue/scheduler heartbeats every minute.

### Forge inspection

The server's **Storage → Backups** and **Observe → Monitoring** screens both report **Upgrade to Business**. No backup configuration or server monitor was enabled through these gated features, and no subscription was changed. An off-server storage destination and retention policy are still needed, or the owner can choose the Forge upgrade. Application health visibility does not replace independent uptime alerting or off-server backups. SMTP delivery still needs provider credentials. Actual device power management and mobile reception require a rehearsal on the event devices.

## November 2026 update

A full review found that the account-free race-day link, the recommended flow, lacked protections the authenticated station already had. Changes:

- **Clock.** All timing screens stamp taps on the server clock, sampled from `GET /time` with the NTP midpoint method, instead of `new Date()`. Before, a phone clock that was up to 10 s off went straight into results. A browser test runs a device 8 s fast and checks the recorded time.
- **Lost taps.** The race-day link now saves failed taps on the device with their observed time and uploads them later (accepted for 30 minutes). Before, a failed tap was lost and a re-tap recorded a later time.
- **Checkpoint on reload.** The race-day link no longer defaults to Swim Exit. It asks once and remembers the checkpoint per device.
- **Undo.** The tapping device can undo a race-day link tap for 60 seconds, proven by the tap's UUID, which is no longer exposed in public payloads.
- **Link control.** The Organizer can switch the link off or rotate it.
- **Database guard.** A generated `active_key` column with a unique index makes a second active timing for the same entry and checkpoint impossible at the database level as well.
- **Destructive migration.** The one-time production reset migration of 24 September 2026 is now a no-op, so restoring an older backup cannot trigger it again.
- **Roles.** The Official role is stored as `official` (it was `organizer`).
- **Results.** While a race runs, results show provisional places; live standings are cached per race version so polling devices share one computation.
- **Tooling.** PHP 8.5, PHPUnit 13, Larastan, Pint, ESLint and Prettier in CI; the Feature suite also runs on MySQL.

Still external: off-server backups (see `deploy/FORGE.md` → Backups, with `deploy/backup-database.sh`) and an uptime monitor.
