# Roles and permissions

The account types are Organizer (admin), Official and Athlete. Internal keys remain `admin`, `organizer` and `athlete` for compatibility.

Organizer (admin) has full access to all races, user administration, roles, health, deletion and restoration. At least one active admin must remain. Athlete accounts can view only their linked athlete’s races and results. These two built-in roles are protected.

Admins use **Roles & permissions** to create, rename, describe or delete custom official roles and grant or remove permissions. The default Official role can have its permissions changed, but cannot be renamed or deleted. Its initial permissions match the previous organizer access. Custom roles cannot be deleted while assigned to users.

On **Users → Create account / Edit user**, select Official, choose the official role and assign races. Leaving the official role at its default uses the current default Official permissions. Permissions are checked on every request; a removed permission also prevents queued offline timings from uploading. Preserve pending timing backups before revoking timing access.

All officials can view assigned race overviews and results. The permission catalogue grants:

- Create races (requires race setup too).
- Edit race settings, checkpoints and public publication.
- Manage and import participants.
- Record timings at stations and undo own records.
- Start/finish races and correct timings; void own records.
- Export CSV/XLSX results.

Race assignments remain mandatory, even when a role has all permissions. Officials cannot manage users or roles or delete races. Permission names correspond to implemented server actions; new types of action require a code change. The page adds and removes these permissions from roles rather than creating unenforced permission names.
