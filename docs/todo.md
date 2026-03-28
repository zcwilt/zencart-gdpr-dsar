# GDPR / DSAR Manager TODO

## Roadmap

1. Finish the policy and consent model
- Keep `privacy` and `terms` as separate policy types.
- Add clearer policy metadata:
  - version label
  - published date
  - active flag
  - optional policy URL/page mapping
  - optional internal notes
- Make the admin UI explicitly describe:
  - `privacy` = DSAR/privacy acceptance
  - `terms` = checkout terms acceptance

2. Integrate `agree to terms` with checkout
- Hook into stores using `DISPLAY_CONDITIONS_ON_CHECKOUT`.
- When checkout terms are shown and accepted, record a `terms` consent event in `gdpr_consent_events`.
- Store:
  - `consent_type = terms_policy`
  - `consent_status = accepted`
  - source page like `checkout_confirmation`
  - active `terms` version
- If a new active `terms` version exists, require fresh acceptance at checkout.

3. Add terms-version reacceptance logic
- Compare a customer’s last accepted `terms` version with the active version.
- If they differ:
  - show a clear checkout notice
  - link to the terms page in a new tab
  - block order completion until acceptance is renewed
- Keep privacy and terms reacceptance independent.

4. Create a stronger consent history experience
- Keep customer-facing consent history.
- Add filters or grouping so users can distinguish:
  - privacy policy acceptance
  - terms acceptance
  - newsletter consent
- Optionally show current accepted-version summaries.

5. Improve admin monitoring
- Keep SLA monitoring for DSAR requests.
- Add:
  - overdue-only filter
  - due-soon filter
  - average completion time
  - export of request metrics
- Optionally add dashboard cards for:
  - open DSARs
  - overdue DSARs
  - latest policy changes
  - recent consent activity

6. Strengthen audit and compliance reporting
- Add a lightweight report/export for:
  - DSAR request lifecycle
  - consent history by customer
  - current accepted privacy/terms versions
- Use this for compliance reviews and support investigations.

7. Harden export and erasure operations
- Replace opportunistic cleanup with a scheduled cleanup command or cron job.
- Add admin warnings for failed export generation or failed anonymization.
- Add optional dry-run or review mode for erasure before processing.

8. Improve customer safety and clarity
- Add an explicit confirmation checkbox for erasure requests.
- Add stronger wording around irreversible actions.
- Show the export expiry date directly in request history, not just the retention wording.

9. Add tests
- Privacy acceptance gating
- Terms acceptance at checkout
- Reacceptance after version change
- Export expiry cleanup
- Session invalidation after anonymization
- SLA due/overdue calculations

10. Expand documentation
- Document privacy acceptance behavior.
- Document checkout terms integration.
- Document logged events and retained records.
- Document how merchants should publish and update policy pages.

## Recommended Implementation Order

1. Terms acceptance integrated with `DISPLAY_CONDITIONS_ON_CHECKOUT`
2. Terms version reacceptance enforcement at checkout
3. Policy URL/page mapping
4. Scheduled cleanup command
5. Reporting and tests
