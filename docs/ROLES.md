# Roles and permissions

The account types are Organizer (admin), Official and Athlete. Internal keys remain `admin`, `organizer` and `athlete` for compatibility.

## Organizer (admin)

The Organizer owns the race workflow: creating races, editing course details and checkpoints, assigning Officials, adding/importing athletes, starting or manually ending the race, correcting timings, publishing results, managing users and managing roles.

Once a race starts, setup and registration are locked. The Organizer can still record timings and may switch between checkpoints.

## Official

An Official is a race-day checkpoint operator. Access to a race comes from a checkpoint assignment made by an Organizer.

An Official cannot choose or switch checkpoints. The assigned checkpoint follows the account across devices and is enforced by the backend, including queued/offline timings.

Official roles intentionally expose only two optional permissions:

- **Record checkpoint times** — record and undo timings at the assigned checkpoint.
- **Export results** — download CSV/XLSX results for an assigned race.

Officials can view their assigned races and results. They cannot create or configure races, manage athletes, assign checkpoints, start/end races, publish results, correct arbitrary timings, manage users or manage roles.

## Athlete

Athlete accounts can view only their linked athlete’s races and results. They do not operate timing or race controls.

## Custom Official roles

Admins use **Roles & permissions** to create custom Official roles, for example a normal checkpoint Official or a read-only results helper. The default Official role can be edited but cannot be renamed or deleted. Custom roles cannot be deleted while assigned to users.

Race and checkpoint assignments remain mandatory regardless of role permissions.
