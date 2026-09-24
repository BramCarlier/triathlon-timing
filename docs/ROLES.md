# Roles and permissions

Accounts are an optional layer around the core race-day workflow. The simplest setup needs only an Organizer account: create the race, add athletes, start the clock and share the race-day timing link.

Anyone who has that unguessable race-day link can view the timing screen, choose an active transition/checkpoint or finish, and record an athlete's time without signing in. The link does **not** grant race setup, athlete editing, race controls, corrections, exports, user management or role management.

The account types remain Organizer (admin), Official and Athlete. Internal keys remain `admin`, `organizer` and `athlete` for compatibility.

## Organizer (admin)

The Organizer owns race setup and administration: creating races, editing course details and checkpoints, adding/importing athletes, starting or manually ending the race, correcting timings, publishing results, managing users and managing roles.

Once a race starts, setup and registration are locked. The Organizer can still record timings from the authenticated workspace or use the same race-day link as everyone else.

## Official — optional

An Official account is useful when an Organizer wants a named operator, a checkpoint locked to that account, permissions, exports, or the authenticated offline timing station. It is not required to run a race.

When an Official account is assigned to a checkpoint, that assignment follows the account across devices and is enforced by the authenticated timing station, including queued/offline timings.

Official roles intentionally expose only two optional permissions:

- **Record checkpoint times** — record and undo timings at the assigned checkpoint.
- **Export results** — download CSV/XLSX results for an assigned race.

Officials can view their assigned races and results. They cannot create or configure races, manage athletes, start/end races, publish results, correct arbitrary timings, manage users or manage roles.

## Athlete — optional

Athlete accounts are optional and are only needed when an athlete should sign in to a personal dashboard. Race registration itself does not require an account.

A linked Athlete account can view that athlete's races and results. It does not operate timing or race controls.

## Custom Official roles

Admins can use **Roles & permissions** when they need named account-based access beyond the public race-day link. The default Official role can be edited but cannot be renamed or deleted. Custom roles cannot be deleted while assigned to users.
