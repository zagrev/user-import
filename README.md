# user-import

Wordpress plugin to import users from CSV files, mapping column names to core user fields, Ultimate Member fields, and ACF user fields

## Description

User Import adds separate Import Users and Export Users pages under Users in the WordPress admin. Both pages share the same mapping editor and saved mappings.

The Import Users page is a four-step wizard:

1. Upload the CSV file.
2. Map CSV columns to WordPress user fields.
3. Choose the roles to apply to every imported or updated user.
4. Run the import and review the created and updated users as well as the final summary.

Use Back on any step to return to the previous one; the uploaded file, mapping, mapping name, and selected roles are preserved across the round trip. If the mapping is missing the required `user_login` or `user_email` fields, continuing from the mapping step shows an error and keeps you on that step.

## Column Mapping

The importer supports arbitrary column mappings. Enter one mapping per line in the form:

`CSV column name or zero-based column number=user field`

The left side identifies a CSV column. Use the column header text, matched without regard to case, or use its zero-based numeric position. For example, `0` is the first CSV column and `2` is the third. The right side is the WordPress user field that should receive the value. Whitespace around the `=` is ignored.

On the Import Users page, choose the CSV file to display its columns. Drag a user field onto a CSV column to create the mapping automatically, or drag a CSV column onto a user field. You can drag an assigned field back to the user fields list to remove it, or edit the generated mapping text directly.

Mappings can be saved by entering a name and clicking **Save mapping**. Select a saved mapping to load it into the mapping editor for reuse.

On the Export Users page, choose a CSV file, select or create a mapping, and click **Export Mapped CSV** to download a new CSV whose columns follow the selected user fields. The original CSV is not changed.

For example:

`Email Address=user_email`
`0=user_login`
`First Name=first_name`
`Membership ID=meta:membership_id`

The `user_login` and `user_email` fields are required. A mapping can use either a header name or a column number, so this is also valid:

`0=user_login`
`1=user_email`
`4=display_name`

Supported standard fields include `user_login`, `user_email`, `user_pass`, `user_nicename`, `user_url`, `display_name`, `first_name`, `last_name`, `nickname`, `description`, `locale`, and `role`. Use `meta:KEY` to store a value as user metadata; for example, `Membership ID=meta:membership_id` stores the value in the `membership_id` user-meta key. When Ultimate Member or Advanced Custom Fields (ACF) user fields are registered on the site, they are listed alongside the standard fields as draggable `meta:` fields, labeled `UM:` or `ACF:` respectively.

If the mapping is blank, the importer uses the CSV headers as field names after sanitizing them. Therefore, blank mapping works when the CSV contains headers such as `user_login`, `user_email`, and `display_name`. For headers such as `Email Address`, provide an explicit mapping.

## Roles

After the mapping is validated, choose the roles to apply to every row in the import. The first selected role becomes each user's primary role; any additional selected roles are added alongside it. Selecting no roles leaves existing users' roles unchanged and lets WordPress assign its default role to new users. If a CSV column is also mapped to the `role` field, the per-row value takes precedence over the roles chosen on this step.

If `user_pass` is not mapped, new users receive a securely generated password and are not sent the default new-user notification email. Existing users are matched by the mapped `user_login` and `user_email` values and are updated without changing their login, email address, or password. Rows with missing required mappings, invalid fields, or missing CSV columns are reported as errors and counted separately from imported and updated rows.

When an import runs, the uploaded CSV is retained temporarily for the current user so the mapping can be revisited or submitted again without reselecting the file. You can also drag and drop a CSV file onto the upload step instead of using the file picker. Selecting a replacement file replaces the retained copy; uninstalling the plugin removes retained files.
