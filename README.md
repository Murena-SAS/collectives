# Unite

Unite is a Nextcloud App for activist and community projects to organize
together. Come and gather in collectives to build shared knowledge.

* **Collective and non-hierarchical workflow by heart**: Collectives are
  tied to a [Nextcloud Circle](https://github.com/nextcloud/circles) and
  owned by the collective.
* **Collaborative page editing** like known from Etherpad thanks to the
  [Text app](https://github.com/nextcloud/text)
* **Well-known [Markdown](https://en.wikipedia.org/wiki/Markdown) syntax**
  for page formatting

## Dependencies

For installation (see below), the following tools need to be available:

* make: to run the Makefile targets
* curl: to fetch some build tools from the web
* npm: to install NodeJS dependencies and compile JS assets
* g++: to compile some NodeJS dependencies
* gettext: to generate pot/translation files

Additionally, this app builds on the functionality of the Circles and the
Text App. They both need to be installed for Unite to work properly.

## Installation

The app is *not* published yet to the Nextcloud app store, so you have to
install it manually:

1. Clone this into the `apps` folder of your Nextcloud
2. Install build tools and dependencies by running `make dev-setup`
3. Compile NodeJS assets by running `make build`

Afterwards, you can enable the app from the Nextcloud app management menu.

### Development environment

Development environments often do not use proper hostnames and are not
using ssl. In order to make the Circles API work in such environments,
a few configuration settings need to be adjusted.

You can do so by running the following commands on the nextcloud server:
```
./occ config:system:set --type bool --value true -- allow_local_remote_servers
./occ config:app:set --value 1 -- circles self_signed_cert
./occ config:app:set --value 1 -- circles allow_non_ssl_links
./occ config:app:set --value 1 -- circles local_is_non_ssl
```

## Development Background: Circle and user management

In Nextcloud, every file/folder is owned by a user. Even when shared with a
circle, the ultimate power over this object remains at the owner user. In
collective workflows, this leads to several problems. Instead of individual
users, we want the collective pages to be owned and maintained by the
collective. Since this concept is unsupported by the Nextcloud and Circles
per default, we plan to implement it on our own.

Current status: Creating a new collective internally does the following:

2. Create a vehicle circle `<NAME>`
3. Create a collective folder `<NAME>`
4. Share the collective folder with the vehicle circle

Planned: Creating a new collective internally does the following:

1. Create a vehicle user `collective@<NAME>@<UUID>` (with random UUID as password)
2. Create a vehicle circle `<NAME>` with the vehicle user as owner
3. Create a collective folder `<NAME>` with the vehicle user as owner
4. Share the collective folder with the vehicle circle

## Maintainers

* Azul <azul@riseup.net>
* Jonas <jonas@freesources.org>

## Licence

Files: *
Copyright: (c) 2019-2020 Azul <azul@riseup.net>
           (c) 2019-2020 Jonas <jonas@freesources.org>
License: AGPL v3 or later

Files: images/app.svg
Copyright: (c) Phạm Thanh Lộc
License: CC-BY-4.0
