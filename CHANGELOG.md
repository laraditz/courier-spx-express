# Changelog

All notable changes to `laraditz/courier-spx-express` will be documented in this file.

## Unreleased

### Added

- Initial SPX Express Malaysia driver (`spxexpress`) for `laraditz/courier`.
- `createShipment`, `getShipment`, `track`, `getRates`, `cancelShipment`, `getLabel` on the SPX Open Service API, each sending a batch of one and turning per-order `fail_list` entries into typed exceptions.
- `check-sign` HMAC-SHA256 request signing, verified against SPX's published test vector.
- Drop-off and pickup fulfilment, with automatic pickup slot selection through `get_pickup_time` and a public `getPickupTimeslots()` helper.
- COD (`meta['cod_amount']`) and voucher (`meta['voucher_code']`) support.
- Tracking Web Hook verification over the raw body, with timestamp replay protection, plus a `TrackingUpdated` event.
- `user_secret` and `check-sign` added to `courier.logging.redact`.

### Verified against the sandbox (2026-10-26)

- Rate check, drop-off create, track, lookup by order id, label and cancel, all run through the driver.
- Pickup is **not** verified: the sandbox sender account has no pickup slots.
