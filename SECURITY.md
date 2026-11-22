# Security and public repository policy

This repository is public and contains application source and synthetic test fixtures only.

Never commit production credentials, OAuth tokens, `.env` files, `storage/config.php`, SQLite/MySQL data, member uploads, private recordings, or unredacted applicant and model data.

The `storage/.htaccess` file is committed to retain access controls; all other runtime files in `storage/` are ignored. Check the complete diff and file inventory before submitting each pull request.

Production deployment requires a separate database and private `storage/` backup. Do not rerun `install.php` on an existing installation. Use the CI `azhottie-studio-deploy` artifact for application-file updates only; it does not include private configuration or data.
