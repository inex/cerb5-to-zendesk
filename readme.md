## Cerberus to Zendesk Migration Tool

This is a simple PHP script to migrate a [Cerberus 5 helpdesk](http://www.cerberusweb.com/)
to [Zendesk](https://www.zendesk.com/). Specifically, we migrated from Cerberus 5.7.2.

### DISCLAIMERS

* This is not a Swiss Army Knife tool where one size fits all. This was written
for our own specific migration. The script itself amounts to 500 lines of well documented
code and so should be usable by anyone with moderate PHP skills.
* This has been used and our migration is complete. We do not have the time nor any
reason to support / maintain this.


### Overview

This is coded as a Larival Artison command. All the migration code can be found
in the file `app/Console/Commands/Cerb5ToZendesk.php`.

This script needs to run on your Cerberus server to upload attachments and it
needs access to the Cerberus MySQL database.

### Usage

1. Clone the repository from GitHub
2. In the application's root directory, run `composer install`
3. Edit `.env` and set:
  - the `DB_` parameters to the MySQL database of your Cerberus installation
  - the `ZENDESK_` parameters to your Zendesk's API credentials (or, starting off, a sandbox)
  - the `CERB5_STORAGE_PATH` to the location of Cerberus' `storage/` directory
4. Edit `app/Console/Commands/Cerb5ToZendesk.php` and:
  - read through in its entirety and make any changes required
  - on line 505, set your Cerberus agents to Zendesk users mapping
  - on line 450, convert your helpdesk email addresses to something else
5. run via `php artisan cerb5-to-zendesk`

### License

This is open-source software licensed under the [MIT license](http://opensource.org/licenses/MIT).
