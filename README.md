# MikroTik ISP Management Platform

A high-performance Internet Service Provider (ISP) management and billing-ready core platform built with **Laravel 11**, **Inertia.js**, **Vue 3**, **Tailwind CSS**, and **MySQL**, designed for direct control over **MikroTik RouterOS** devices (RB951Ui, CCR, Cloud Hosted Routers, and RB series).

---

## ⚡ Architecture Highlights

1. **Multi-Router Fleet Control**:
   - Manage multiple MikroTik routers from a single pane of glass.
   - Encrypted storage for RouterOS API credentials.
   - Live health checks: CPU load, free memory, board model, RouterOS version, and uptime.

2. **Native RouterOS Socket API Engine (`RouterOsProtocol`)**:
   - Zero-dependency, pure PHP implementation of the RouterOS API binary sentence protocol.
   - Full compatibility with **RouterOS v6.x** (both legacy MD5 challenge and post-v6.43 plaintext) and **RouterOS v7.x**.
   - Supports both standard API (TCP `8728`) and SSL/TLS API (`8729`).

3. **Dual Client Authentication Protocols**:
   - **Hotspot (Captive Portal)**: Provisions `/ip/hotspot/user` with data limits only — access duration is calendar-based (`expires_at` date), never RouterOS `limit-uptime` hours — and dynamically terminates active sessions via `/ip/hotspot/active/remove`.
   - **PPPoE (Point-to-Point Broadband)**: Provisions `/ppp/secret`, manages bandwidth queues/profiles, and terminates sessions via `/ppp/active/remove`.

4. **Prepaid Quota & Calendar-Time Enforcement Engine**:
   - Automated artisan command `isp:enforce-quotas` runs every minute via the Laravel scheduler.
   - Access duration is always **calendar time**, not accumulated hours: a 30-day plan expires 30 calendar days after activation regardless of how many hours the client was connected (plans use `validity_days`, LAN devices use `access_duration_days`).
   - Scans subscriptions where `expires_at <= now()` or `data_used_bytes >= data_quota_bytes`, plus LAN/CPE devices where `access_expires_at <= now()`.
   - Automatically disables the user on MikroTik (hotspot or PPPoE), flushes active socket connections, places the client IP into the `EXPIRED_USERS` firewall address-list, and installs an L2 bridge filter drop rule for expired LAN devices.

5. **Live Metric & Traffic Synchronization**:
   - `isp:sync-routers` runs every 5 minutes to poll online status and capture active bytes consumption.
   - Router accounts, profiles, enabled state, and active session details are fetched directly from the configured RouterOS devices when the dashboard and Router Users page load. Passwords are never requested for display.
   - RouterOS does not provide ISP billing or contact records; client profiles, billing plans, and balances remain application-managed data.

---

## 📁 Project Directory Structure

```text
mikrotik-isp/
├── app/
│   ├── Console/Commands/
│   │   ├── EnforceSubscriptionQuotas.php    # Scheduled auto-disconnection daemon
│   │   └── SyncRouterMetrics.php            # Router telemetry & bandwidth polling
│   ├── Http/Controllers/
│   │   ├── DashboardController.php          # NOC Overview & fleet health
│   │   ├── RouterController.php             # Router CRUD & API test connection
│   │   ├── PlanController.php               # Time & data bandwidth packages
│   │   ├── ClientController.php             # Subscriber management
│   │   └── SubscriptionController.php       # Live provisioning & session kicks
│   ├── Models/
│   │   ├── Router.php                       # RouterOS hardware model (encrypted creds)
│   │   ├── Plan.php                         # Bandwidth plans & rate limits
│   │   ├── Client.php                       # Subscriber details & billing balance
│   │   ├── Subscription.php                 # Service bindings & lifecycle hooks
│   │   ├── Device.php                       # CPEs, ONUs, and MAC addresses
│   │   └── RouterActivityLog.php            # Disconnection & enforcement audits
│   └── Services/Mikrotik/
│       ├── RouterOsProtocol.php             # Binary socket API client (ROS v6 & v7)
│       └── MikrotikService.php              # High-level ISP automation service
├── database/
│   ├── migrations/
│   │   ├── 2026_10_14_000001_create_mikrotik_isp_tables.php
│   │   └── 2026_10_15_000001_remove_demo_isp_records.php
│   └── seeders/
│       └── DatabaseSeeder.php               # Does not add demo records
├── resources/js/
│   ├── Layouts/
│   │   └── AppLayout.vue                    # Dark-themed NOC layout with daemon indicator
│   └── Pages/
│       ├── Dashboard.vue                    # Fleet health, active tunnels, recent logs
│       ├── Routers/Index.vue                # Router fleet cards & onboarding modal
│       ├── Plans/Index.vue                  # Bandwidth tiers & rate limiting
│       ├── Clients/
│       │   ├── Index.vue                    # Client directory with search
│       │   └── Show.vue                     # Profile, subscriptions & instant provision
│       └── Subscriptions/
│           └── Index.vue                    # All active sessions, filters & remote kick
└── routes/
    ├── web.php                              # Web & API routes
    └── console.php                          # Scheduler configuration
```

---

## 🚀 MikroTik RouterOS Setup

Before connecting your MikroTik router (e.g. RB951Ui), enable the API service and create a dedicated API user on RouterOS via WinBox or terminal:

```routeros
# 1. Enable API Service on default port 8728
/ip service enable api

# Optional: If using SSL on port 8729
# /ip service enable api-ssl

# 2. Create a restricted API user group with write permissions
/user group add name=isp-api policy=api,read,write,test

# 3. Create the API user (replace with strong password)
/user add name=isp_manager group=isp-api password=YourStrongPassword123 comment="Laravel ISP Controller"
```

---

## 💻 Installation & Local Setup

### 1. Database Configuration
Create a MySQL database:
```sql
CREATE DATABASE mikrotik_isp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Copy `.env.example` to `.env` and set your database credentials:
```bash
cp .env.example .env
```

### 2. Install Dependencies
```bash
composer install
npm install
```

### 3. Generate Key & Run Migrations
```bash
php artisan key:generate
php artisan migrate
```

### 4. Start the Application & Asset Watcher
```bash
php artisan serve
npm run dev
```

### 5. Running the Background Auto-Disable Daemon
For local testing of quota and expiration enforcement:
```bash
# Test one-off quota audit
php artisan isp:enforce-quotas

# Test router telemetry sync
php artisan isp:sync-routers

# Or start the Laravel scheduler worker:
php artisan schedule:work
```

In production, add the standard Laravel cron job to your server's crontab:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```
