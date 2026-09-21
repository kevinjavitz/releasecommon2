# CLAUDE.md — releasecommon2 (SalesIgniter_Common)

Shared utilities for the Sales Igniter Magento extensions, and the CLI commands registered in `etc/di.xml`:
`uninstallRental` (`Console/Command/UninstallRentalUseWithCaution.php` — drops every `sirental_*` table,
including the legacy `sirental_inventory_grid`), `removeAttributes`, `restoreAttributes`.
`CreateProductsCommand` defines no `execute()` and inherits Symfony's; `RunTestCommand` is commented out.

**1.2.54 is the minimum for Magento 2.4.9**: below it every `bin/magento` call fatals under Symfony Console 7.
Owns no database tables (`Setup/` is legacy-style but creates nothing). Default branch `master`.

## Tests

```bash
ddev exec vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist extensions/salesigniter/releasecommon2/Test/Unit
```

88 unit tests, no database. **Judge by the `OK` / `FAILURES` line, not the exit status** — every run ends
with the same Allure-bootstrap warning the rest of this project has, which alone makes the status 1.

There are no integration tests. Nothing in this module talks to the database except through EAV setup
objects it is handed, so mocks reach further here than a fixture install would.

## What the commands actually do

- **`salesigniter:Uninstall`** — removes 71 `sirent_*` product attributes, drops 8 tables
  (`sirental_fixed_dates`, `sirental_fixed_names`, `sirental_inventory_grid`,
  `sirental_payment_transaction`, `sirental_price`, `sirental_reservationorders`, `sirental_return`,
  `sirental_serialnumber_details` — i.e. every reservation, serial number and payment row), removes the
  `Rental` attribute group from the default attribute set, deletes every `core_config_data` row under
  `salesigniter_rental/%`, and deletes the `setup_module` rows for **both** `SalesIgniter_Rental` **and
  `SalesIgniter_Common`**. None of it is recoverable.

  **It now asks first.** Interactively it needs a typed `yes`; non-interactively it refuses and exits 1
  unless `--force` is given. It used to do all of the above the moment you pressed enter. Added
  2026-09-21 — a script that called it without `--force` will now abort instead of destroying the
  install, which is the intended direction of that break.

- **`salesigniter:Remove:Attributes`** — sets `backend_model` and `source_model` to `null` on the rental
  attributes, so `SalesIgniter_Rental` can be disabled without the product grid and product form dying
  on a source model whose class is gone. Writes nothing else and drops nothing; safe.

- **`salesigniter:Restore:Attributes`** — the inverse, and the only place the attribute → model mapping
  is written down. Also re-applies `apply_to = 'sirent,bundle'` to the 17 store-hours attributes. The
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
- **`RemoveAttributeSourceModels` lists `sirent_autoselectstartdate` twice** (52 entries, 51 distinct).
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
