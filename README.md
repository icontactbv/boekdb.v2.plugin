# boekdb.v2.plugin

This WordPress plugin fetches and displays book data provided by the BoekDBv2 CMS.

The BoekDB plugin and CMS are proprietary software applications developed specifically for VBK Uitgevers B.V. by Icontact B.V.

Please contact [Jacqueline Remmers](mailto:jremmers@vbku.nl) for more info.

## Development

The test suite boots a real WordPress against a MySQL database and runs the plugin's import
against canned API payloads. Requirements: PHP 7.4 or newer, Composer and a MySQL server.

    composer install
    mysql -uroot -e 'CREATE DATABASE boekdb_plugin_test'
    composer test

The database connection defaults to `127.0.0.1` / `root` / no password / `boekdb_plugin_test`
and can be overridden with `WP_TESTS_DB_HOST`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and
`WP_TESTS_DB_NAME`. The WordPress test installer drops and recreates its tables on every run,
so give it a database of its own.

`composer phpcs` runs the WordPress coding standard checks.

