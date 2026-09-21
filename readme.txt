=== User Import ===
Contributors: zagrev
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later

Import WordPress users from a CSV file.

== Description ==

User Import adds an Import Users page under Users in the WordPress admin.

The importer supports arbitrary column mappings. Enter one mapping per line in the form:

`CSV column name or zero-based column number=user field`

For example:

`Email Address=user_email`
`0=user_login`
`First Name=first_name`
`Membership ID=meta:membership_id`

Supported standard fields include `user_login`, `user_email`, `user_pass`, `user_nicename`, `user_url`, `display_name`, `first_name`, `last_name`, `nickname`, `description`, `locale`, and `role`. Use `meta:KEY` to store a value as user metadata.

If the mapping is blank, CSV headers matching the standard field names are used automatically.

New users receive securely generated passwords. Existing usernames and email addresses are skipped.
