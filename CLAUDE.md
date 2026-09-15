# CLAUDE.md — releasecommon2 (SalesIgniter_Common)

Shared utilities for the Sales Igniter Magento extensions, and the CLI commands registered in `etc/di.xml`:
`uninstallRental` (`Console/Command/UninstallRentalUseWithCaution.php` — drops every `sirental_*` table,
including the legacy `sirental_inventory_grid`), `removeAttributes`, `restoreAttributes`.
`CreateProductsCommand` defines no `execute()` and inherits Symfony's; `RunTestCommand` is commented out.

**1.2.54 is the minimum for Magento 2.4.9**: below it every `bin/magento` call fatals under Symfony Console 7.
Owns no database tables (`Setup/` is legacy-style but creates nothing). Default branch `master`. No tests.
