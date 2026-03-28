# GDPR / DSAR Manager: How It Works

## Overview

This plugin adds a customer-facing GDPR / DSAR request workflow to Zen Cart. It lets logged-in customers:

- request an export of their personal data
- request erasure/anonymization of their personal data
- review the status/history of those requests
- download completed exports before they expire

Requests are reviewed and processed by an administrator from the Zen Cart admin area.

## Main Components

- `manifest.php`
  Declares plugin metadata such as version, name, and grouping.

- `Installer/ScriptedInstaller.php`
  Creates configuration keys, creates plugin tables, and registers admin menu entries.

- `database_tables.php`
  Defines the plugin table constants.

- `filenames.php`
  Defines the storefront/admin page filename constants used by the plugin.

- `admin/gdpr_dsar_admin.php`
  Provides the admin request queue, request approval/rejection/processing, export generation, and erasure/anonymization logic.

- `catalog/includes/modules/pages/gdpr_dsar/header_php.php`
  Handles storefront request submission, policy acceptance, export downloads, and request-history loading.

- `catalog/includes/templates/default/templates/tpl_gdpr_dsar_default.php`
  Renders the customer-facing DSAR page.

- `catalog/includes/classes/observers/`
  Adds storefront behavior such as account-page links, consent tracking, and session invalidation for anonymized accounts.

## Database Tables

The plugin creates these tables during installation:

- `gdpr_dsar_requests`
  Stores DSAR requests and their statuses.

- `gdpr_dsar_exports`
  Stores generated export metadata such as file path, checksum, token, expiry date, and download count.

- `gdpr_dsar_audit_log`
  Stores audit events for customer and admin actions.

- `gdpr_consent_events`
  Stores privacy/newsletter consent events.

- `gdpr_policy_versions`
  Stores privacy/terms policy versions and active-version status.

## Configuration

The installer creates a dedicated configuration group for the plugin. Common settings include:

- plugin enable/disable
- export expiry days
- maximum active requests per request type
- export storage folder
- customer email notifications
- admin notification on new requests

The plugin also registers:

- a `Configuration` menu link to the plugin settings
- a `Customers` menu link for the admin DSAR request queue

## Storefront Flow

### 1. Customer opens the GDPR / DSAR page

The page is intended for logged-in customers only. If a visitor is not logged in, they are redirected to the login page.

### 2. Policy acceptance is checked

If the plugin has an active privacy-policy version and the customer has not accepted that exact version, the page requires acceptance before a new DSAR request can be submitted.

### 3. Customer submits a request

The customer can choose between:

- export
- erasure/anonymization

The plugin prevents too many concurrent active requests of the same type by checking the configured limit.

### 4. Request is recorded

A new row is created in `gdpr_dsar_requests` with:

- customer ID
- request type
- current status
- request notes
- email snapshot

An audit entry is written to `gdpr_dsar_audit_log`.

### 5. Notifications are sent

If enabled:

- the customer receives a submission confirmation email
- the store owner receives a new-request notification

## Admin Flow

The admin queue in `admin/gdpr_dsar_admin.php` supports:

- reviewing submitted requests
- approving requests
- rejecting requests
- processing approved requests

### Request statuses

Typical statuses are:

- `submitted`
- `approved`
- `processing`
- `completed`
- `rejected`
- `failed`

## Export Processing

When an approved export request is processed:

1. The plugin loads the customer profile, saved addresses, orders, and reviews.
2. It builds a ZIP archive containing JSON exports such as:
   - `profile.json`
   - `addresses.json`
   - `orders.json`
   - `reviews.json`
3. The ZIP file is written to the configured export directory.
4. A row is inserted into `gdpr_dsar_exports` with:
   - file path
   - token
   - checksum
   - file size
   - expiry timestamp
5. The request is marked `completed`.
6. An audit record is written.
7. If enabled, the customer receives an email that the export is ready.

## Export Availability and Cleanup

Export retention is controlled by `GDPR_DSAR_EXPORT_EXPIRY_DAYS`.

Behavior:

- exports remain downloadable until `expires_at`
- after expiry, the export is no longer downloadable
- expired ZIP files are deleted from disk
- expired rows are removed from `gdpr_dsar_exports`

Cleanup is opportunistic and currently runs when the admin DSAR page or storefront DSAR page is loaded.

Default retention is 14 days unless changed in configuration.

## Erasure / Anonymization Processing

When an approved erasure request is processed:

1. The plugin loads the customer record.
2. It calls Zen Cart customer deletion in `forget_only` mode.
3. It explicitly anonymizes address-book records.
4. It anonymizes order personal data.
5. The request is marked `completed`.
6. An audit record is written.
7. If enabled, the customer receives an erasure-complete email.

### What gets anonymized

The current implementation is designed to remove or overwrite personally identifiable data while preserving operational records where needed.

Examples include:

- customer name/email/telephone fields
- saved address details
- order billing/delivery customer details
- review ownership association behavior handled by Zen Cart

## Logged-In Session Handling After Erasure

An anonymized account should not remain usable in an existing storefront session.

To handle that, the plugin observes Zen Cart login-state checks and invalidates stale sessions when it detects that the customer record:

- no longer exists, or
- has already been anonymized

That cleanup clears customer session data such as:

- `customer_id`
- name and address session values
- checkout address pointers
- cart-related customer session state

This means a customer may still see the current page they already had open, but on the next request they should be treated as logged out.

## Consent Tracking

The plugin also records selected consent-related events, including:

- privacy policy acceptance
- newsletter consent changes

These events are written to `gdpr_consent_events` with:

- customer ID
- consent type
- consent status
- source page
- policy version
- hashed IP

## Audit Logging

Important actions are written to `gdpr_dsar_audit_log`, including:

- request submission
- approval
- rejection
- export processing
- erasure processing
- failures

This provides an internal audit trail for compliance and troubleshooting.

## Operational Notes

- Export files are stored relative to the catalog root, by default in `cache/gdpr_dsar_exports`.
- Download links are token-based and only valid for the matching logged-in customer.
- Expired exports are deleted automatically during normal DSAR page activity, not by a true background job.
- Data tables are preserved on uninstall by default so compliance records are not silently removed.

## Current Limitations

- Export cleanup is opportunistic rather than cron-driven.
- The plugin depends on page activity to trigger expired-export deletion.
- The exported dataset is focused on customer/account-related records currently gathered by the admin export processor.

## Recommended Future Improvements

- add a dedicated scheduled cleanup command or cron entry
- add automated tests for export cleanup and erasure behavior
- expand exported/anonymized data coverage if more customer-linked tables are introduced
- document deployment and upgrade steps in a separate admin-facing guide
