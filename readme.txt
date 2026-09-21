=== User Import ===
Contributors: zagrev
Requires at least: 6.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later

Import WordPress users from a CSV file.

== Description ==

User Import adds an Import Users page under Users in the WordPress admin.

== Column Mapping ==

The importer supports arbitrary column mappings. Enter one mapping per line in the form:

`CSV column name or zero-based column number=user field`

The left side identifies a CSV column. Use the column header text, matched without regard to case, or use its zero-based numeric position. For example, `0` is the first CSV column and `2` is the third. The right side is the WordPress user field that should receive the value. Whitespace around the `=` is ignored.

On the Import Users page, choose the CSV file to display its columns. Drag a user field onto a CSV column to create the mapping automatically. You can click an assigned field to remove it or edit the generated mapping text directly.

Mappings can be saved by entering a name and clicking **Save mapping**. Select a saved mapping to load it into the mapping editor for reuse.

Choose a CSV file, select or create a mapping, and click **Export Mapped CSV** to download a new CSV whose columns follow the selected user fields. The original CSV is not changed.

For example:

`Email Address=user_email`
`0=user_login`
`First Name=first_name`
`Membership ID=meta:membership_id`

The `user_login` and `user_email` fields are required. A mapping can use either a header name or a column number, so this is also valid:

`0=user_login`
`1=user_email`
`4=display_name`

Supported standard fields include `user_login`, `user_email`, `user_pass`, `user_nicename`, `user_url`, `display_name`, `first_name`, `last_name`, `nickname`, `description`, `locale`, and `role`. Use `meta:KEY` to store a value as user metadata; for example, `Membership ID=meta:membership_id` stores the value in the `membership_id` user-meta key.

If the mapping is blank, the importer uses the CSV headers as field names after sanitizing them. Therefore, blank mapping works when the CSV contains headers such as `user_login`, `user_email`, and `display_name`. For headers such as `Email Address`, provide an explicit mapping.

If `user_pass` is not mapped, new users receive a securely generated password. Existing usernames and email addresses are skipped. Rows with missing required mappings, invalid fields, or missing CSV columns are reported as errors.
