# MikroTik ISP Platform — Architecture

Laravel 11 · Inertia.js · Vue 3 · MySQL · MikroTik RouterOS 7 (API 8728)

This document describes how the platform talks to MikroTik routers, how customer
access is granted/revoked for each authentication type, how calendar-day expiry
works, and where every piece lives in the codebase.

---

## 1. High-level overview

```
┌──────────────────────────────  Web UI (Vue 3 + Inertia)  ──────────────────────────────┐
│  Dashboard │ Routers │ Plans │ Subscribers │ Router Users │ Connected Devices          │
└───────────────────────────────────────┬────────────────────────────────────────────────┘
                                        │ HTTP (session + CSRF)
┌───────────────────────────────────────▼────────────────────────────────────────────────┐
│                                   Laravel 11 (HTTP layer)                              │
│  RouterController  PlanController  ClientController  SubscriptionController            │
│  ConnectedDeviceController  DashboardController                                        │
└───────────────────────────────────────┬────────────────────────────────────────────────┘
                                        │ Eloquent models
┌───────────────────────────────────────▼────────────────────────────────────────────────┐
│  MySQL: routers · plans · clients · subscriptions · devices · router_activity_logs     │
└───────────────────────────────────────┬────────────────────────────────────────────────┘
                                        │ scheduled (routes/console.php)
                      isp:enforce-quotas (1 min)   isp:sync-routers (5 min)
                                        │
┌───────────────────────────────────────▼────────────────────────────────────────────────┐
│                                   Service layer                                        │
│  MikrotikService        – all RouterOS operations (one connection per request/job)     │
│  MacAuthManager         – grant/revoke/purge for direct (non-hotspot) devices          │
│  ConnectedDeviceService – merges ARP + DHCP + Hotspot + PPP + neighbors by MAC/IP      │
│  RouterDataService      – health snapshot for sync                                     │
│  RouterOsProtocol       – raw RouterOS API sentence protocol (pure PHP, no deps)       │
└───────────────────────────────────────┬────────────────────────────────────────────────┘
                                        │ TCP :8728 (or :8729 SSL)
                              ┌─────────▼──────────┐
                              │  MikroTik RouterOS  │
                              └────────────────────┘
```

---

## 2. Authentication types supported

| Type | Router object | Created by | Billing gate | Cut-off mechanism |
|---|---|---|---|---|
| **Hotspot** | `/ip/hotspot/user` + active session | `SubscriptionController` → `MikrotikService::createOrUpdateHotspotUser` | Hotspot login + calendar expiry | User `disabled=yes`, session kicked, IP isolated (`EXPIRED_USERS` list) |
| **PPPoE** | `/ppp/secret` + active session | `SubscriptionController` → `createOrUpdatePppoeSecret` | PPPoE auth + calendar expiry | Secret `disabled=yes`, session kicked |
| **Direct / LAN device** (CPE, ONU, private SSID) | `active-clients` address-list + static DHCP lease + bridge filter | `ClientController` device CRUD → `MacAuthManager` | MAC authorization (allow-list) | L2 bridge-filter MAC gate + allow-list removal |

Direct devices are **not** in the Hotspot user database — they authenticate purely
by MAC address. See §4.

---

## 3. Router access layer

* `app/Services/Mikrotik/RouterOsProtocol.php` — length-prefixed RouterOS API
  sentences over TCP (`tcp://ip:8728`). Supports pre/post-6.43 login and SSL.
  Query syntax: separate words, e.g. `?chain=forward`, `=.proplist=.id,comment`
  (never `?x=y` inside the path).
* `app/Services/Mikrotik/MikrotikService.php` — the only class that performs
  RouterOS writes. One instance = one connection; always `disconnect()` in a
  `finally` block. Credentials come from the `routers` table.
* `Router::service()` returns a configured `MikrotikService` for that router.
* Read paths (`/profiles`, `/connected-devices`, `/firewall-policy`) use JSON
  endpoints that return `502 + message` on router failure so the UI can show the
  error without failing the whole page.

### RouterOS quirks discovered on the live router (v7.24.2)

1. **`bridge/filter src-mac-address` requires the MAC-mask form**
   (`AA:BB:CC:DD:EE:FF/FF:FF:FF:FF:FF:FF`). A bare MAC traps with
   “invalid value for argument src-mac-address”. (`/ip/firewall/filter`
   accepts a bare MAC; the bridge menu does not.)
2. **Rule placement uses `move` with `=.id=` + `=destination=`** (not
   `place-before`, which this build rejects).
3. **REST API** is available on plain HTTP (`http://router/rest/...`) — used for
   ops verification; the app uses the TCP API only.
4. Rules `LOCKDOWN-B2 <MAC>` and address list `blocked-devices` belong to
   another system — never touch them (the platform filters exclusively on its
   own `ISP-DEVICE-*` / `ISP-BILLING:` / `device:*` tags).

---

## 4. Direct-device MAC authorization (deep dive)

Implemented in `MacAuthManager` + `MikrotikService` and applied by
`app/Models/Device.php`.

### Three cooperating control points per device

| # | Control point | Router object | Purpose | Tag |
|---|---|---|---|---|
| 1 | **L2 MAC gate** | `/interface/bridge/filter` rule `chain=forward action=drop src-mac-address=<mac>/FF:FF:FF:FF:FF:FF` | Instant, IP-independent cut | comment `ISP-DEVICE-{device_id}`; `disabled=yes` = allowed, `disabled=no` = blocked |
| 2 | **Allow-list** | `/ip/firewall/address-list` list `active-clients` | IP allow-list enforced by the optional default-deny policy (§5) | comment `device:{id}\|client:{cid}\|{mac}` |
| 3 | **Static lease** | `/ip/dhcp-server/lease` (static) | Pins IP→MAC so the IP allow-list always maps to the customer | same `device:` comment (only leases with this tag are ever removed) |

### Lifecycle

```
provision (device created / enabled / renewed)
  ├─ add address-list entry          (idempotent, "created"/"updated")
  ├─ ensure static DHCP lease        (idempotent; updates IP if changed)
  └─ create/enable bridge filter     (rule exists but disabled = allowed)
       → RouterActivityLog event: device_mac_auth_enable

disable (manual or isp:enforce-quotas on expiry)
  ├─ enable bridge filter            → cut is immediate at L2
  ├─ remove address-list entry       (lease is kept for fast re-activation)
  └─ devices.is_active = false       → event: device_mac_auth_disable

purge (device / client deleted)
  ├─ remove bridge filter rule
  ├─ remove address-list entry
  └─ remove tagged static lease      → event: device_mac_auth_purge
```

Changing a device's IP (`updateDevice`) removes the old allow-list entry and
re-provisions the new one.

`renewLanAccess()` also resets `access_expires_at = today + access_duration_days`.

### State on the device model

* `access_duration_days` — calendar days granted per renewal.
* `access_expires_at` — absolute calendar deadline (UTC).
* `is_active` — mirrors the last provisioning result.

---

## 5. Firewall policy (opt-in default-deny)

Router → **“Firewall Policy · MAC Allow-List”** modal.
Endpoints: `GET/POST/DELETE /routers/{router}/firewall-policy`.

**Not installed by default.** Installing enforces: *only `active-clients`
allow-listed IPs (plus authenticated hotspot sessions) may reach the WAN.*

### Why placement matters (verified on the live router)

The production router already contains, at forward-chain index 8:

```
accept  out-interface=ether2  src-address=192.168.20.0/24     ← the "anchor"
```

Appending a deny **after** it would never match (everything LAN→WAN is accepted
first). `installFirewallPolicy()` therefore inserts its rules **directly before
the first `accept out-interface=<WAN>` rule** via `move =.id= =destination=<anchor>`:

```
... blocklist / management rules ...
[ISP-BILLING: allow active-clients]        accept  src-address-list=active-clients  out=<WAN>
[ISP-BILLING: accept hotspot-authenticated] accept  hotspot=from-client,auth        out=<WAN>
[ISP-BILLING: default-deny (allow-list)]   drop    src-address-list=!active-clients
                                                connection-state=new                out=<WAN>
[anchor]                                   accept  out=<WAN>  src-address=<LAN>
... LOCKDOWN-B2 ... (foreign rules untouched)
```

* **Hotspot exemption** — hotspot users have their own login/billing gate, so
  authenticated hotspot traffic is accepted unconditionally.
* **`connection-state=new`** on the drop — pre-existing flows are not torn down
  the moment the policy is installed.
* **Seeding** (default ON) — before the drop is created, every ARP-complete IP
  plus every managed device IP is written into `active-clients`, so nobody is
  cut off at install time.
* `GET` state reports `installed` and `effective` (drop sits before the anchor).
* **Remove** deletes only `ISP-BILLING:` prefixed rules; allow-list entries are
  kept, so re-installing later is safe.

---

## 6. Calendar-day access model & auto-disable

Expiry is **calendar-based** (no RouterOS `limit-uptime`; stale values are
cleared when hotspot users are updated):

* `plans.validity_days` — plan length in days (migration
  `2026_10_15_000002` replaced the old `validity_hours`).
* `subscriptions.expires_at` — subscription deadline (UTC).
* `devices.access_expires_at` — direct-device deadline.

`app/Console/Commands/EnforceSubscriptionQuotas.php` (`php artisan
isp:enforce-quotas`, scheduled **every minute** in `routes/console.php`):

```
for each active subscription:
  expired? or data quota exceeded?
    → hotspot:  disable user + kick session + isolate IP to EXPIRED_USERS
    → pppoe:    disable secret + kick session + isolate IP
for each active direct device:
  access_expires_at past?
    → Device::disableLanAccess()   (bridge gate + allow-list removal)
```

`routes/console.php` also schedules `isp:sync-routers` every 5 minutes
(CPU/memory/uptime/online state).

---

## 7. Service layer map

| Class | Responsibility |
|---|---|
| `MikrotikService` | Hotspot users, hotspot profile device cap (`shared-users` — v7 keeps it on the user profile), PPPoE secrets, sessions, simple queues, profiles, connected-device sources, address lists, static leases, device comments (DHCP lease, ownership-tag preserving), bridge-filter gate, firewall policy, health/snapshot |
| `MacAuthManager` | `activate()` / `deactivate()` / `purge()` orchestration for direct devices |
| `ConnectedDeviceService` | Merges ARP + DHCP leases + hotspot active + PPP active + neighbors; dedupes by MAC (key-based `resolveKey`/`createKey` mutations); status `online`/`idle`/`offline` |
| `PaymentSettlementService` | Gateway-agnostic settlement of a confirmed `Payment`: subscription payments renew + reactivate on the router, wallet payments credit the ledger and cascade into armed (`auto_renew`) subscriptions |
| `RouterDataService` | `snapshot()` used by `isp:sync-routers` |
| `RouterOsProtocol` | Wire protocol (connect/login/read/write/parse) |

---

## 8. Database schema

Tables (all in `mikrotik_isp`):

* **routers** — `name, ip_address, api_port, api_username, api_password, use_ssl,
  identity, ros_version, board_name, is_online, last_seen_at, cpu_load, …`
* **plans** — `name, code, type(time|data|time_and_data|unlimited),
  service_type(hotspot|pppoe|both), price, validity_days, data_limit_mb,
  rate_limit_upload/download, hotspot_profile, pppoe_profile, shared_users, is_active`
* **clients** — `client_code (CLI-xxxx), name, email, phone, address, status,
  balance, notes`
* **subscriptions** — `client_id, plan_id, router_id, auth_type, username,
  password, assigned_ip, assigned_mac, status, start_at, expires_at,
  data_quota_bytes, data_used_bytes`; unique `(router_id, auth_type, username)`
* **devices** — `client_id, router_id (nullable), mac_address, ip_address,
  hostname, type(hotspot|pppoe|cpe|bridge|ap), comment, is_active,
  access_duration_days, access_expires_at`
* **router_activity_logs** — `router_id, subscription_id, event, level,
  details(json), ip_address, created_at` — audit trail for every router write
  (`device_mac_auth_*`, `firewall_policy_*`, subscription provisioning, …)

Phase-2 additions (migrations `2026_10_15_000000` – `..._000006`):

* **wallet_transactions** — append-only ledger: `client_id, type (topup|
  payment|voucher|invoice_payment|adjustment|recharge|debit), amount (signed),
  balance_after, description, reference, balance` — every money movement goes
  through `WalletService::credit()/debit()` (row-locked, transactional,
  balance-after recorded). Never update `clients.balance` directly.
* **invoices** + **invoice_items** — `number (INV-YYYYMM-xxxx), client_id,
  amount, balance_used, status (draft|unpaid|paid|cancelled), paid_at,
  period_start/period_end (nullable)`.
* **payments** — `payment_ref (MBL-…), client_id, invoice_id?, amount,
  currency (XAF), method (monetbil), gateway_transaction_id, status (pending|
  success|failed), payload(json)`.
* **vouchers** — `code (10 chars, ambiguous chars substituted), value,
  status (active|redeemed|void), redeemed_by (client FK), redeemed_at,
  batch_name`.
* **tickets** + **ticket_messages** — support thread, `author_type (staff|
  client)`, status `open|pending|closed`, `last_reply_at`.
* **settings** — JSON key/value store (Monetbil `service_key`/`service_secret`,
  cached 60 s via `Setting::getValue()`).
* **clients** += `portal_password (hashed), portal_password_set_at`;
  **plans** += `billing_mode`; **subscriptions** += `auto_renew`;
  **devices** += 12-code typology enum + infra fields (see §12.1).

---

## 9. HTTP routes (Inertia, no Ziggy — plain `href` / `router.visit`)

```
GET    /                                  dashboard
routers   CRUD + GET profiles + GET/POST/DELETE firewall-policy + POST test
plans     CRUD
clients   CRUD + POST clients/{c}/devices + POST wallet/topup|debit|redeem
devices   PUT/DELETE + POST disable/enable
subscriptions CRUD + POST disable/enable/kick
GET    /connected-devices                 live merged device table (10 s auto-refresh)
POST   /routers/{r}/sessions/kick
GET/POST /inventory  PUT/DELETE /inventory/{device}    device typology + topology
GET/POST /vouchers   POST /vouchers/{v}/void            voucher batches
GET/POST /payments   GET payments/{p}/return            Monetbil redirect + return
POST   /payments/monetbil/notify                         webhook (CSRF-exempt)
GET/PUT /settings                                          gateway config
GET/POST /invoices   POST invoices/{i}/pay|cancel        invoicing
GET/POST /tickets    POST tickets/{t}/reply  PUT tickets/{t}/status
Guest:  GET/POST /portal/login  → client-guard session (config/auth.php `client`)
Auth:   GET /portal  POST /portal/logout  GET/POST /portal/tickets  (+reply)
```

CSRF: session cookie + `X-XSRF-TOKEN` header (from the `XSRF-TOKEN` cookie) for
`fetch` calls.

---

## 10. Frontend structure (`resources/js`)

```
Layouts/AppLayout.vue        sidebar nav (icon-only < md), active state from usePage()
Pages/Routers/Index.vue      router cards: View Plans (live modal), Edit, Delete,
                             Firewall Policy modal (state, WAN, seed, install/remove)
Pages/Clients/Index.vue      subscriber table + create/edit/delete
Pages/Clients/Show.vue       subscriptions + devices; device renew/enable/disable/delete
Pages/Plans/, Subscriptions/ Index CRUD pages
Pages/ConnectedDevices/Index.vue + Components/Detail.vue  (stats, filters, raw details modal)
```

---

## 11. Production router safety rules

* The live router (“Main”, 192.168.20.1, serves real customers) is managed by
  another system's `LOCKDOWN-B2` rules and `blocked-devices` list — never
  modify non-`ISP-*` objects.
* The default-deny firewall policy is **opt-in** through the UI; seeding is ON
  by default so no customer is cut off.
* Every write goes through `MikrotikService` and is logged to
  `router_activity_logs` (JSON `details` includes the exact RouterOS actions).
* Test objects are always created with `disabled=yes` first, verified, then
  removed — verified cycles leave the router byte-for-byte at its original
  rule count.

---

## 12. Phase 2 — Convergent billing, device typology & self-care

### 12.1 Device typology (12 codes)

`app/Enums/DeviceType.php` — single source of truth (`label()`, `color()`,
`isInfrastructure()`, `isCustomerEdge()`, `options()`):

| Group | Codes |
|---|---|
| **Infrastructure** (no client, management-only) | `router`, `switch`, `ap`, `ont`, `antenna`, `ups`, `server`, `cpe`* |
| **Customer edge** (belongs to a client, billed) | `mobile_device`, `laptop`, `iot`, `tv`, `camera` |

\* `cpe` is infrastructure-classed but may be customer-provisioned.

* `devices.type` enum expanded by migration `..._000003`; `client_id` now
  nullable (infrastructure inventory), plus serial/model/manufacturer/
  firmware/management_ip/snmp_community/is_online/last_seen_at/signal_strength/
  uptime_seconds/notes and self-referencing `parent_device_id`.
* `DeviceController::buildTopology()` nests children by `parent_device_id`;
  self-parenting rejected; customer-edge devices get 30-day access on create,
  infrastructure gets `access_expires_at = null` (never auto-disabled).
* **Inventory** page (`/inventory`) = CRUD + type filters + recursive
  `TopologyNode.vue` tree. Sidebar: Inventory.
* `ConnectedDeviceService` classifies *live* router devices: managed-MAC link
  → typology + client name; otherwise `heuristicType()` heuristics.

### 12.2 Wallet ledger (single money authority)

```
WalletService::credit(client, amount, type, description, reference)
WalletService::debit(...)  → throws InsufficientBalanceException
```

* Transactional + `lockForUpdate`; every row records `balance_after`;
  decimals passed as formatted strings (no float drift).
* Types: `topup`, `payment`, `voucher`, `invoice_payment`, `adjustment`,
  `recharge`, `debit`.
* All balances across the UI display **XAF** (`formatXaf`), matching the
  Monetbil currency.
* `ClientController` exposes `POST clients/{c}/wallet/topup|debit|redeem`
  (redeem = voucher code → `VoucherService::redeem`).

### 12.3 Vouchers

`VoucherService::generateBatch()` (1–500, 10-char codes with 0→Q/O→P/1→I/L→M
substitution) and `redeem()` (row lock, wallet credit `type=voucher`, marks
redeemed; double-redeem/void/bad code rejected). Admin UI `/vouchers`;
redemption also available from the client Show page.

### 12.4 Monetbil payments (CM, XAF)

```
PaymentGatewayInterface  ← app/Services/Payments/PaymentGatewayInterface.php
MonetbilGateway          ← sign / initiate / verifyWebhookSignature / checkTransactionStatus
```

1. **Initiate** `POST /widget/v2.1/{service_key}` with amount/phone/country=CM/
   currency=XAF/operator/payment_ref/user/return_url/notify_url → redirect to
   `payment_url`. Sign = `md5(service_secret . implode('', ksort(params)))`.
2. **Webhook** `POST /payments/monetbil/notify` — CSRF-exempt (bootstrap),
   `checkSign()` verified first, idempotent on unique `payment_ref`, then an
   **authoritative** re-check `POST /payment/v1/checkPayment` with
   `paymentId=transaction_id` (status 1/7 = success). 500 on check failure so
   Monetbil retries.
3. **Settlement** is gateway-agnostic — `PaymentSettlementService::settle()`
   (any gateway that confirms a transaction hands it there, per
   `PaymentGatewayInterface`): **subscription payments** extend the calendar
   window and **reactivate immediately** — `provisionOnRouter()` +
   `activateOnRouter($newExpiry, false)` (re-enable user/secret, lift
   `EXPIRED_USERS`, `subscription_activate` log); **wallet top-ups / invoice
   payments** credit the wallet (`type=topup`, or `type=invoice_payment`) and
   **cascade** into any armed (`auto_renew`) due subscriptions of that client
   — top-up → service back on, without waiting for the hourly cron.
   Idempotent: an already-settled payment only re-runs activation while the
   subscription is still inactive/expired (router was down on first delivery —
   Monetbil's retry heals it).
4. Config lives in `settings` (`monetbil.service_key` / `service_secret` /
   `logo_url`), edited at `/settings`; `isConfigured()` gates the Pay button.

### 12.5 Invoicing & auto-renewal

* `InvoiceService::create()` → `INV-YYYYMM-xxxx` + line items;
  `payFromWallet()` (debit + mark paid); `cancel()`.
* `isp:renew-from-wallet` (hourly), the portal "Renew from wallet" button and
  the top-up cascade all share `SubscriptionRenewalService::renewFromWallet()`:
  debit the wallet, extend `expires_at` from the day after the current window,
  then **reactivate the account directly on the router** — `provisionOnRouter()` +
  `applyEnabledOnRouterState()` (enable user/secret, lift `EXPIRED_USERS`) and
  a `subscription_renew` activity log. A router outage never undoes a paid
  renewal (logged; the next provisioning run reconciles).
* Auto-renew candidates are `status IN (active, expired)` — the 1-minute
  enforcement pass normally flips an expired subscription to `expired` long
  before the hourly cron runs, so filtering on `active` alone would miss
  almost every renewal.
* Data quota is **cumulative until plan change**: renewals buy time only —
  `data_used_bytes` and the RouterOS `limit-bytes-total` counters are never
  reset by a payment (operator decision). A quota-exhausted account therefore
  stays blocked until the plan/quota is changed, even after renewing.
* Calendar model unchanged: `isp:enforce-quotas` (1 min) still enforces
  expiry/quotas regardless of payment path.

### 12.6 Self-care portal

* Separate **`client` guard** (`config/auth.php`): session guard on the
  `clients` table, login = `client_code` + `portal_password` (Hash, settable
  from the admin client edit modal).
* Routes under `guest:client` / `auth:client` (`/portal/login`, `/portal`,
  `/portal/tickets`, logout). Unauthenticated users anywhere are redirected to
  `/portal/login` (`redirectGuestsTo` in `bootstrap/app.php`).
* Portal pages: `Portal/Login.vue`, `Portal/Dashboard.vue` (services, invoices,
  wallet ledger), `Portal/Tickets.vue` — standalone dark layout, no admin nav.

### 12.7 Support tickets

* Admin `/tickets` (list/filter/create/reply/status) → `TicketController`;
* Portal `/portal/tickets` (own tickets only; cross-client access → 403);
* Client replies reopen `closed` tickets; staff replies do not change status.
