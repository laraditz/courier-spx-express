# SPX Open Service API — working notes

Source: <https://spx.com.my/integration/> (a JS-rendered SPA, so it can't be fetched with curl). Summarised 2026-10-26, with behaviour observed in the MY sandbox. The facts here aren't all in SPX's docs, so check them before changing the driver.

## Transport

- `POST {{host}}/open/api/v1/<path>` with a JSON body. Every endpoint is a POST.
- Hosts: sandbox `https://test-stable.spx.com.my`, live `https://spx.com.my`.
- Headers: `app-id`, `check-sign`, `timestamp` (seconds), `random-num` (signed int64).
- `check-sign` = `hex(HMAC-SHA256(app_secret, "{app_id}_{timestamp}_{random_num}_{body}"))`.
  Test vector: app 100000 / secret `H25HY53GO4BA2GQ` / ts 1677918414 / rand 926611981 /
  body `{"user_id":239404781503925,"user_secret":"85f0d570-a265-4d9d-857e-b30aa57c4fbe","service_type":1}`
  → `97e21b23940e4ddc96fb4d2474f02425353d2b0e23aee384f3f94c3d0b9ba17d`.
- Every body carries `user_id` (integer) and `user_secret`.
- Envelope: `{ret_code, message, data}`. When the whole batch fails, `ret_code` is non-zero and `data` is null
  (seen: `11001` "parcel value can not be null or less than 0", `13051` unserviceable area).
  When a single order fails, `ret_code` is 0 and the reason goes in `data.fail_list[]`.

## Endpoints used

| Path | Purpose | Notes |
|---|---|---|
| `order/get_pickup_time` | `{service_type}` → `[{date, pickup_time, slots[{pickup_time_range_id, pickup_time_range}]}]` | `pickup_time` is 16:00 MYT on `date`. In the sandbox, `slots` are empty. |
| `order/batch_create_order` | `{orders[≤100]}` → `data.orders[{order_id, tracking_no, tracking_link, estimated_shipping_fee, …}]`, `data.fail_list` | `order_id` ≤ 31 chars and must be unique (`13103` on reuse). State and city are optional: SPX maps them from the postcode and overrides what you send. `parcel_value` is required. Phones are digits only. |
| `order/batch_search_order` | `{tracking_no_list}` or `{order_id_list}` → `data.orders[]` with `status_code` and `routes[]` (**newest first**) | Unknown order: `fail_list` `13251`. A just-created order may not be findable for about 1–3 s. |
| `order/batch_check_order` | Rate check | **State and city required** (blank → `13051`). Returns `estimated_shipping_fee`, `edt_min`/`edt_max` (days). |
| `order/batch_cancel_order` | `{tracking_no_list}` → `data.tracking_no_list` (cancelled), `data.fail_list` | Unknown waybill: `13001` "MismatchedSpxTn". |
| `order/batch_get_shipping_label` | `{tracking_no_list}` → `data.awb_link` | The link lasts 30 min and opens at most 10 times. It still returned a link for a cancelled order in the sandbox. |

Not used: `account/check_account_credentials` returns 404 in the MY sandbox. `order/batch_get_order_fee` also returns 404 at that path. There is also Update Order V2, Create Account, and the address-file download.

## Status codes

1001 Pending Pickup · 2001 In Transit · 2006 Delivering · 3001 On Hold · 4001 Delivered ·
5001 Pickup Failed · 5002 Damaged · 5003 Lost · 6001 Returning · 6002 Return Failed ·
6003 Returned · 7001 Cancelled

## Webhooks

- SPX binds the URL to the app when they issue credentials. There is no API to set it.
- The body is a flat JSON object: `status, status_code, message, tracking_no, tracking_link, order_id, id,
  timestamp, latest_shipping_fee, latest_chargeable_weight, proof_of_delivery_list, edd_min/edd_max, …`
- Signed with `check-sign`, the same way as requests.
- SPX expects HTTP 200 within 3 s. If pushes are repeatedly rejected or time out, SPX stops sending them.
- Other webhook types: 4.2 Order Create, 4.6 Shipping Fee, 4.7 Evidence Proofs.
