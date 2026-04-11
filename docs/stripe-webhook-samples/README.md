# Stripe webhook payload samples (test mode)

These JSON files are **real `event.data` excerpts** captured from Stripe (shape: `{ "object": { … }, "previous_attributes": … }`). Use them when adjusting `StripeController` to match current API objects.

## How the backend uses them

| Event                                       | File                                 | Fields we rely on                                                                                                                                                                                                                       |
| ------------------------------------------- | ------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `customer.subscription.created` / `updated` | `customer.subscription.created.json` | `customer`, `items.data[0].price.id`, `status`. With **`billing_mode.type = flexible`**, root `current_period_end` may be **absent**; period is on **`items.data[0].current_period_end`**.                                              |
| `invoice.paid`                              | `invoice.paid.json`                  | `customer`, `amount_paid`, `currency`, `billing_reason`, `lines`. Subscription id may be missing at **`subscription`**; use **`parent.subscription_details.subscription`** or line **`parent.subscription_item_details.subscription`**. |
| `checkout.session.completed`                | `checkout.session.completed.json`    | `metadata`, `client_reference_id`, `customer`, `subscription` (IDs for logging / `StripeCheckoutLog`).                                                                                                                                  |

## Stripe Dashboard

You can always pull the latest delivery from **Developers → Webhooks → [endpoint] → event → Request**.
