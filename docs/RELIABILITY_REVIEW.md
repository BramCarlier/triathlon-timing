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

## Remaining recommendations

### Before the real event

1. Configure a real mail transport and test password reset delivery. The existing log mailer does not deliver email. Requires the owner's provider/sender details.
2. Configure encrypted off-server database backups with a retention policy and perform one restoration rehearsal. Current backup coverage has not been verified; choose the destination and acceptable retention with the owner.
3. Rehearse on the actual phones, accounts and mobile network: screen lock/wake, long session, lost signal, replay of pending timings, organizer reassignment, and simultaneous taps. Browser checks and SQLite CI do not establish cellular reliability or MySQL concurrency under load.
4. Improve the offline queue before relying on it for an extended outage: per-account ownership, visible retained/rejected items with retry/review, periodic retries for temporary server failures, and rebuilding pending taps after a browser reload. Today pending data stays in IndexedDB, but the recovery UI is limited. Do not clear site storage or change accounts with pending timings.

### Next usability improvements

- Participant editing (including bib assignment after import), with an audit record for changes made after the start.
- Searchable/paginated athlete linking and user management for larger events; creation currently offers up to 500 unlinked athletes.
- Optional screen wake lock and clearer clock synchronization/freshness indicators for checkpoint devices.
- Category/solo/relay result views, tied-place rules, and explicit DNF/DNS/disqualification handling agreed with the race organizer.
- Replace remaining native confirmation dialogs with the shared accessible dialog; add inline validation to older checkpoint forms.
- Add automated browser E2E and MySQL concurrency tests, plus monitoring for failed broadcasts, queue failures and stale stations.

The application is deployed on MySQL. PostgreSQL portability is not currently verified: participant sorting uses MySQL-specific `CAST(... AS UNSIGNED)` and should be changed and tested before advertising PostgreSQL support.

## Event-readiness implementation

- Pending timings are scoped to the recording account, restored from IndexedDB, retried every 15 seconds and on reconnect, and deleted only after a matching server acknowledgement. Authorization/validation/conflict failures stay visible for review. Operators can retry, explicitly acknowledge checkpoint warnings, discard with confirmation, or export a JSON backup. Legacy records without an owner are never automatically uploaded; administrators can export them for manual review.
- Participant edits include later bib assignment, category and athlete profile corrections, and DNS/DNF/DSQ status, with mandatory reasons and before/after audit history. Shared athlete profile corrections explicitly apply to all their entries. Existing timing history is retained.
- Results and CSV/XLSX exports support type, category and status filters. Exact equal millisecond totals share competition places (1, 1, 3); this is a transparent general default, not a claim to implement a federation's rule book.
- Station wake lock is opt-in where supported. Native timing, participant and checkpoint confirmation dialogs use the shared accessible dialog. Checkpoint validation errors appear inline.
- CI includes an isolated Chromium workflow exercising offline recovery, account switching, participant edits, status filters and frozen clocks; a MySQL 8.4 workflow tests simultaneous distinct taps and retries against real row locks.
- `/admin/health` and `php artisan timing:health` report database connectivity, scheduler/worker heartbeats, Reverb handshakes, queue failures, disk space, mail configuration and running-race station presence. Checks expose no credentials. Reverb handshakes run every five minutes and queue/scheduler heartbeats every minute.

### Forge inspection

The server's **Storage → Backups** and **Observe → Monitoring** screens both report **Upgrade to Business**. No backup configuration or server monitor was enabled through these gated features, and no subscription was changed. An off-server storage destination and retention policy are still needed, or the owner can choose the Forge upgrade. Application health visibility does not replace independent uptime alerting or off-server backups. SMTP delivery still needs provider credentials. Actual device power management and mobile reception require a rehearsal on the event devices.
