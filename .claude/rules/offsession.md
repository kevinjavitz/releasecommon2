# Off-session payments (1.2.58, booking-deposits plan P1)

The layer that charges a saved card with no customer present (merchant-initiated, MIT), moved here
from releasesubscriptions2 so subscriptions (renewals) and the rental extension (booking balances)
share one copy. Namespace `SalesIgniter\Common\Model\Payment\OffSession\`.

## Pieces

| Class | What |
|---|---|
| `ChargeSubjectInterface` | the thing being charged (a subscription, a booking balance). `getOffSessionReference()` names ONE charge (`sisub-<id>-<cycle>`, `sirent-bal-<id>`): Stripe's idempotency key is `<reference>-<quote id>` and a pending Stripe charge is reused only for the same reference. Metadata and description go to the gateway. |
| `StrategyInterface`, `StrategyPool` (`has`, `get`, `forCheckoutMethod`, `forSubject`) | per-gateway charging. Core strategies `vault` (Magento_Vault, any gateway with an InstantPurchase integration), `offline`, `free`; sub-modules add `stripe`, `adyen`, `mollie`, `tokenbase`. A strategy flags the cart's payment with `StrategyInterface::PAYMENT_FLAG` (`sicommon_off_session`). |
| `OffSessionMethods` | checkout method -> vault code -> strategy (`vaultCodesByProvider`, `vaultCodeFor`, `strategyFor`, `canCharge`, `checkoutMethods`); di `offlineMethods`, `extraMethods`. |
| `MitContext` | `run($subject, $variableAmount, $work, $context)`: everything inside is an off-session charge. EVERY gateway plugin checks `isActive()`. Context key `MitContext::SUPPRESS_ORDER_EMAIL` holds the new-order email (`Observer\OffSession\SuppressMitOrderEmail`). The gateway result stash survives the end of `run()` (the classifier reads it after). |
| `Decline`, `DeclineClassifier`, `DeclineMapperInterface`, `PaymentDeclinedException` | hard / soft / action_required / config; each sub-module registers a mapper in `DeclineClassifier` di `mappers`. |
| `TokenService` | signed single-use links, table `sicommon_pay_token` (sha256 only, UTC). `create(subjectType, subjectId, contextId, purpose, days)`, `verify(raw, subjectType)`, `markUsed`, `expireAll(subjectType, subjectId)`. Subject types: `sisub_subscription`, `sirent_balance`. |
| `CartHandOff` | a payment link's cart through the STANDARD checkout, the visitor's own cart back afterwards; customers and guests. Column `quote.sicommon_restore_quote_id`; observers `RestoreParkedCart` (quote submit success) and `RestoreParkedGuestCart` (frontend `checkout_onepage_controller_success_action`, after Magento empties the session cart). |
| `SavedCardRequirementInterface` + composite `SavedCardRequirement` (di `providers`) | "this checkout must keep the card". `Observer\OffSession\ForceVaultSave` sets `is_active_payment_token_enabler` at `sales_model_service_quote_submit_before`; Stripe's `SaveCardForOffSession` plugin answers `off_session` for setup_future_usage; TokenBase's observer sets `save=1`. Never during `MitContext::run`. |
| `SubjectSaverInterface` + composite `SubjectSaver` (di `savers`) | persist a subject mid-charge (Stripe saves `stripe_pending` before the order is placed). A strategy that needs it declines with CONFIG when no saver supports the subject. |
| `PaymentLabel`, `Clock` | "Visa ending in 4242 (expires 12/2030)"; UTC now (freezable). |

## How a consumer plugs in

1. Implement `ChargeSubjectInterface` on its record.
2. Register a `SubjectSaverInterface` in `SubjectSaver` di `savers` and, if checkouts of its products
   must keep the card, a `SavedCardRequirementInterface` in `SavedCardRequirement` di `providers`.
3. At first checkout: `StrategyPool::forCheckoutMethod($method, $storeId)->captureFromOrder($subject, $order)`.
4. To charge: build a quote, `$strategy = $pool->forSubject($subject)`, `readiness()` (null = can
   charge, else `[Decline class, message]`), `configureQuote()` (Stripe charges HERE, before the
   order; the others only set the payment), save the quote, then
   `MitContext::run($subject, $variable, fn() => submit the quote + afterOrderPlaced(), [context])`
   (subscriptions' RenewalService is the reference); classify a failure with
   `DeclineClassifier::classify()`. `isAsync()` (Adyen, Mollie) = wait for the webhook;
   `isManual()` (offline) = a pending order someone pays by hand.

## Gateway sub-modules (`SubModules/*`)

`SalesIgniter_CommonPay{Stripe,Braintree,Adyen,Mollie,TokenBase}`, namespace
`SalesIgniter\Common\SubModules\<Gw>\` (Composer resolves it through the package's own PSR-4 root,
no extra autoload entries). Unlike subscriptions' old sub-modules they are registered ONLY when the
gateway's code is installed (`registration.php` asks the Composer class loaders for a marker class):
common is on every Sales Igniter store, `setup:upgrade` auto-enables every newly registered module,
and a class implementing a missing gateway interface fatals `setup:di:compile`. No `setup_version`,
no patches, no tables in sub-modules. Their unit tests live in `SubModules/<Gw>/Test/Unit` and run
with the gateway's vendor code (skip the path where a gateway is not installed).

Stays in the consumers: subscriptions' renewal policy, dunning, RefuseBothKinds / Stripe Billing
rules, the first-order `recurring_first` (Braintree) and Adyen "Subscription" first-order flags,
Mollie's "order contains a subscription" rule.

## Guards

- `Test/Unit/Model/Payment/OffSession/PublicApiTest` pins every signature and constant above.
  Interfaces exactly (a new method breaks implementors). Change one only together with the
  consumers' `salesigniter/releasecommon2` requirement.
- `Test/Unit/Architecture/NoConsumerDependencyTest`: the layer never names `SalesIgniter\Rental`,
  `SalesIgniter_Rental`, `SalesIgniter\Subscriptions` or `SalesIgniter_Subscriptions`.
- This is the first schema common owns (`etc/db_schema.xml`: `sicommon_pay_token`,
  `quote.sicommon_restore_quote_id`); CLAUDE.md's "owns no database tables" predates 1.2.58.

## i18n

The off-session phrases (28 in 1.2.58) are in `i18n/en_US.csv` only; locale files are optional
(Kevin, 2026-10-02). Test classes never call `__()` / `new Phrase()` in `SubModules/*/Test`: the
phrase scanner skips only top-level `Test/`.

## Saving the card at checkout, per Vault method (plan §15 R2 spike, 2026-10-02)

`ForceVaultSave` sets `is_active_payment_token_enabler` at `sales_model_service_quote_submit_before`,
which runs before `Order::place()` (`Test/Unit/Observer/OffSession/VaultEnablerByMethodTest`):

| Method | Forced server-side? | Why |
|---|---|---|
| Braintree (`braintree` -> `braintree_cc_vault`; PayPal, Apple Pay, Google Pay, Venmo, ACH) | yes | `VaultDataBuilder` reads the flag from the order payment while building the sale: `storeInVaultOnSuccess` |
| Payflow Pro (`payflowpro` -> `payflowpro_cc_vault`) | yes | `Transparent::authorize()` always makes the token (PNREF); the flag sets `is_visible` (Vault's AfterPaymentSaveObserver). Needs "reference transactions" on in PayPal Manager; token expiry = min(card, 1 year) |
| Payment Services (`payment_services_paypal_hosted_fields` -> `payment_services_paypal_vault`) | no | the vault intent is sent when the PayPal order is created (`paymentservicespaypal/order/create`, param `vault`) before place-order. Would need a plugin on `OrderService::create()` (`$data['vault']`, logged-in customers only) plus its own off-session module (VaultStrategy excludes it). Link collection until then |
| Stripe (official module) | yes, own path | `SaveCardForOffSession` plugin: `setup_future_usage=off_session` |
| TokenBase (Authorize.Net CIM, CyberSource) | yes, own path | `SaveCardForOffSession` observer sets `save=1` |

Guest checkouts: Magento saves a guest's vault token with `customer_id` NULL (and Payment Services
vaults only for signed-in customers). Charging a guest's token off-session is untested; the
deposits plan has guests pay by link.
