# Organic To Go

Read /Users/younesdiouri/.codex/RTK.md. Prefix shell commands with rtk.

## Workflow
The primary agent owns scope, architecture and review. GPT-6.1-sol implements in small verified slices. Runtime remains local: no deployment at this stage. The user authorized publishing this repository publicly at younesdiouri/organic on their personal GitHub account. Do not modify other projects.

## Skills
Read applicable project skills under .agents/skills: ponytail (always for coding), incremental-implementation, security-and-hardening, code-review-and-quality, code-simplification. Shared upstream references live in .agents/references. Apply proportionally to this small MVP; user scope prevails over generic process. No speculative abstractions, feature flags, queues, API layer or enterprise architecture. Verify current version-specific Symfony documentation before framework configuration. Use Symfony native security, forms and validation.

## Stack and isolation
Symfony, Twig, Bootstrap, Doctrine, PostgreSQL. Minimal JS when needed. Docker Compose project name organic. HTTP 127.0.0.1:8097; verify availability before starting. Database stays internal without a published host port. No external/shared Docker networks or container_name. Never stop, prune or change phalcon-user/grrind services. Keep dependencies and runtime inside Docker. Local credentials are development-only; no production secrets.

## MVP scope
French mobile-friendly admin app for one restaurant. Login, clients, product catalogue, deliveries with quantities and unit prices snapshotted, occasional dated returns attached to delivery lines, per-client date-range recap, payments and CSV export. Currency MAD, store amounts as integer centimes, never float arithmetic for stored money. Default product prices with editable delivery prices suffice; no contract pricing engine.
A delivery is recorded on its day; return later preserves original delivery. Sum of returns cannot exceed delivered quantity; reject negative/zero quantities, invalid dates and invalid money. Guard concurrent return submissions transactionally. Payments are dated entries per client; do not infer paid status from an arbitrary reporting window. Reports distinguish period activity and cumulative balance as of end date. CSV must resist spreadsheet formula injection.
No client portal, orders, lots, QR, recipes, global inventory, automated Sheets sync, invoice/tax engine or deployment. Demo records must be clearly fictitious; do not import private Drive data. Protect all business routes, CSRF on mutations, proper password hashing. Avoid destructive editing of delivery/payment history.

## First local milestone
Docker boot, migrations, admin bootstrap command, demonstrable full MVP flow, concise README commands and representative tests for authentication, persisted delivery price, bounded returns, correct balances and CSV export. Verify from a fresh isolated project database without touching other projects. Root reviewer inspects implementation and evidence before reporting completion.
