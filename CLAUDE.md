# CLAUDE.md — releasecommon2 (SalesIgniter_Common)

Shared utilities for the Sales Igniter Magento extensions, and the CLI commands registered in `etc/di.xml`:
`uninstallRental` (`Console/Command/UninstallRentalUseWithCaution.php` — **retired in 1.2.56: deletes
nothing**, prints the `module:uninstall` command and exits 1), `removeAttributes`, `restoreAttributes`.
`CreateProductsCommand` defines no `execute()` and inherits Symfony's; `RunTestCommand` is commented out.

**1.2.54 is the minimum for Magento 2.4.9**: below it every `bin/magento` call fatals under Symfony Console 7.
Owns no database tables (`Setup/` is legacy-style but creates nothing). Default branch `master`.

## Tests

```bash
ddev exec vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist extensions/salesigniter/releasecommon2/Test/Unit
```

86 unit tests, no database. **Judge by the `OK` / `FAILURES` line, not the exit status** — every run ends
with the same Allure-bootstrap warning the rest of this project has, which alone makes the status 1.

There are no integration tests. Nothing in this module talks to the database except through EAV setup
objects it is handed, so mocks reach further here than a fixture install would.

## What the commands actually do

- **`salesigniter:Uninstall`** — **retired in 1.2.56. It deletes nothing.** It prints
  `bin/magento module:uninstall --remove-data <modules>` for the Sales Igniter modules this install has
  (from `FullModuleList`, reversed so every add-on comes before what it depends on, then
  `SalesIgniter_Bookingsgrid SalesIgniter_Rental SalesIgniter_Common`), lists any that live in
  `app/code` separately (module:uninstall only takes Composer packages), and exits 1 so a script that
  still calls it stops. `--force` is still accepted and ignored.

  Why it was retired rather than fixed: it passed bare table names to `dropTable()`, so on a prefixed
  database (demo2 is `mgaq_`) it dropped **no** tables while still deleting the attributes, the
  settings and the `setup_module` rows of both Rental and Common; its table list was stale (it named
  `sirental_return` and the long-gone `sirental_inventory_grid`, and missed every table since 1.2.197,
  the 1.2.206 serial tables, the Pro add-ons' tables and the quote/order columns); it dropped
  `sirental_serialnumber_details` with foreign-key checks off, leaving the 1.2.206 foreign keys
  dangling; and it never deleted `patch_list` rows, so a reinstall skipped every data patch. The
  removal now lives where Magento looks for it: each module's `Setup/Uninstall.php`, with the shared
  code in releaserental2's `Setup/ModuleRemoval/` (>= 1.2.206). See releaserental2's CLAUDE.md,
  "Uninstalling".

- **`salesigniter:Remove:Attributes`** — sets `backend_model` and `source_model` to `null` on the rental
  attributes, so `SalesIgniter_Rental` can be disabled without the product grid and product form dying
  on a source model whose class is gone. Writes nothing else and drops nothing; safe. **Kept in
  1.2.56, because the disable procedure (modules off, code still installed) still needs it** - measured
  on a copy of the dev install on 2026-09-24: with the rental modules disabled, every product edit page
  answered 500 (*Cannot instantiate interface SalesIgniter\Rental\Api\FixedRentalDatesRepositoryInterface*,
  from `Attribute::getBackend()`): the classes are still autoloadable, but the interfaces they need only
  have preferences in the disabled module's `di.xml`. **1.2.56 adds `sirent_minmaxhidecalendar`**, which
  the list had always missed; it alone kept the page dead after this command had run, and with it nulled
  the page opens. The add-ons' attributes (waiverdamage's three selects, purchaserentals'
  `sirent_special_price_productid`) did not break it: their source models build without their modules'
  DI. The uninstall no longer uses either command - module:uninstall deletes the attributes.

- **`salesigniter:Restore:Attributes`** — the inverse, and the only place the attribute → model mapping
  is written down (`sirent_minmaxhidecalendar` -> `SirentBackendConfig` added in 1.2.56). Also re-applies `apply_to = 'sirent,bundle'` to the 17 store-hours attributes. The
  two are only safe as a pair: Remove throws the mapping away and Restore is what knows it.

Both skip any attribute this install does not have (`getAttributeId()` first), so they are idempotent
and harmless on a partial install.

## What other modules call

A signature change in any of these breaks another extension with no warning here:

| This module | Used by |
|---|---|
| `Ui\Component\Listing\Column\Order` | releaserental2: `rental_send_listing`, `rental_send_history_listing`, `rental_return_history_listing`, `rental_returns_listing` |
| `Ui\Component\Listing\Column\Product` | releaserental2: `rental_send_history_listing`, `rental_return_history_listing`; releasemaintenance2: `maintenance_automated_listing` |
| `Ui\Component\Listing\Column\Orderincrement` | releaserental2: `reservations_orders_listing` |
| `Model\Source\Product`, `Model\Source\TypeDayToYear` | releasemaintenance2: `Block/Adminhtml/{Ticket,Template,Automated}/Edit/Form.php` |

`Ui\Component\Listing\Column\Type` is not referenced by any ui_component today.

## Known quirks, pinned by tests rather than fixed

- **`Model\Source\TypeDayToYear::getAllOptions()` appends instead of assigning** and never checks whether
  it has already run, so a second call on the same instance returns eight options
  (Day, Week, Month, Year, Day, Week, Month, Year). Nothing shows duplicates today because the source
  model is not shared in DI and each form calls it once. Making it a singleton would surface this.
- **`Model\Source\Product::getAllOptions(false)` returns `null`, not `[]`, when there are no rental
  products** — `$this->_options` was never assigned. Both callers use the default `$withEmpty = true`,
  which is why nobody has hit it.
- **The `<name>backup` key the grid columns write holds the rendered anchor, not the id.** The comment in
  the source says it is a backup of the id, but it is assigned *after* the overwrite. Three grids ship
  this.
- **`Column\Product` catches only `NoSuchEntityException`** where `Column\Order` catches `\Exception`, so
  the two columns in the same grid fail differently.
- **`RemoveAttributeSourceModels` lists `sirent_autoselectstartdate` twice** (53 entries, 52 distinct).
  Harmless — the second write is identical.
- **`Model\Import::setBehavior()` silently ignores an unrecognised behaviour**, so
  `--behavior=anything` runs an APPEND and says nothing. `ImportCommand` is not registered in `di.xml`,
  so this is unreachable from the CLI today.
- **`Helper\Data` takes a `$backendConfig` it never stores** and has no methods. It is dead weight, not a
  bug; left alone.

## The one thing this module is NOT responsible for

The Magento integration-test suite was blocked for months by *"The default website isn't defined"*
during `setup:install`, and these three commands were blamed for it. They are innocent — verified by
constructing all 90 registered CLI commands against a database with no store tables: only
`SalesIgniter\Rental\Console\Command\MigrateDatesCommand` (releaserental2) fails. See the project
CLAUDE.md, "The integration test suite".
