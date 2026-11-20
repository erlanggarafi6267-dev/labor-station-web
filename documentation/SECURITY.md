# Security Notes

## Owner account seeding

The owner seeder does not contain a default password. Configure these values in the
backend environment file before running the owner seeder:

- `OWNER_NAME`
- `OWNER_EMAIL`
- `OWNER_PASSWORD` (at least 12 characters; use a unique random password)

The seeder creates the owner only when the email does not already exist. Re-running
the seeder will not reset an existing owner's password.

Never commit `.env`, real credentials, access tokens, session cookies, or database
dumps containing personal or production data.

## Previously exposed owner credential

A previous version of `OwnerSeeder.php` contained a hardcoded owner password and
was pushed to a public repository. Removing it from the current file does not erase
it from Git history or existing clones.

Before using this application with real data:

1. Change the password of any owner account that may have been created with the
   previously committed credential.
2. If that credential was reused elsewhere, change it there too.
3. Review repository history and rotate any other secrets if exposure is found.
4. Do not paste passwords, tokens, or the contents of `.env` into issues or chats.

The repository maintainer is responsible for completing the account password
rotation and deciding whether to rewrite public Git history. History rewriting can
disrupt existing clones and should be coordinated deliberately.
