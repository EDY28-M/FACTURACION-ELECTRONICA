# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Business owners in Peru who issue electronic receipts and invoices to SUNAT through their own company portal (tenant subdomain, e.g. `net.localhost:8080`). They sign in to their company's back office to issue documents, sell, manage products, clients, purchases and inventory, and review reports. The System Admin panel (`localhost:8080`) is a separate audience (the platform operator) and is out of scope for the client portal.

## Product Purpose

A multi-tenant SUNAT electronic invoicing ERP. Each client company gets its own portal to issue and track facturas, boletas, notas de crédito/débito, guías, quotations and sale notes, run a POS, and keep the related commercial records. Success means a business owner can issue a valid comprobante quickly, trust that it reached SUNAT, and find any past document without friction.

## Positioning

The portal carries the client company's own identity (its `trade_name` greets users on sign-in), not the platform's. It is the client's own invoicing office, not a third-party tool.

## Operating Context

- Daily, repeated use: issuing documents, checking SUNAT status, POS sales, reports.
- SUNAT environment per company: DEMO, PRODUCCIÓN or INTERNO; users must always know which one is active.
- Documents are identified by series-correlative (F001-00000123), RUC/DNI, currency totals and IGV breakdowns.
- ~80 back-office routes across 16 sidebar groups and 20 domain modules; hub pages (`/list-reports`, `/list-settings`, etc.).

## Capabilities and Constraints

- Stack is fixed: Laravel 10 + Blade + Vue 2.5.7 + Element UI 2.13. No React migration.
- JS builds only with `npm run production:js`; tenant styles live in `public/css/tenant-theme.css`.
- Tenant Persistent App Shell (`public/js/tenant-shell.js`) must keep working: `#main-wrapper` swaps, `html.newinvoice`, sidebar state sync.
- Tenant-configurable visuals exist (`$visual->bg` light/dark, `$visual->sidebar_theme`) and must keep working.
- Redesign must preserve every existing function, route, field and flow.

## Brand Commitments

- Sign-in greets with the tenant company's `trade_name`.
- User-pinned direction (2026-11-10): subtle, minimal, trustworthy; few icons; avoid divider lines everywhere; keep smooth motion but faster; a light (not dark) blue as primary with white surfaces and one secondary accent for modals and cards; a quality login with purposeful SVG motion; nothing generic, recycled or robotic.

## Evidence on Hand

No real client logos, testimonials or metrics are available for marketing claims; do not invent them.

## Product Principles

1. Trust first: the user must always know which SUNAT environment they are in and whether a document was accepted.
2. Speed of the daily task beats decoration.
3. Quiet surfaces: hierarchy from spacing and type, not lines and icons.
4. Keep everything that works; change how it feels, not what it does.
