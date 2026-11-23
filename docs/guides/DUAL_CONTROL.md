# Dual control: refunds and security settings

Roadmap 6.2.

## Refunds
A refund request can **never be approved by the person who asked for it while another active team member holds the refund-approval right** on the branches of that order. This now holds for a super admin too.

**One-person shop**: when nobody else holds the right, the person who asked may approve their own request, but only with
1. a written reason (at least 10 characters), and
2. a two-factor code typed just now (or a fresh proof from the last 5 minutes),
and the refund is flagged **Self-approved** in the refund list (the reason shows on hover) and in the audit log (`refund.approved` carries `self_approved`, plus a separate `refund.self_approved` entry with the reason). Setting: **Settings → Security → "Let the requester approve their own refund when nobody else can"**, default **on**, so a one-person shop is never stuck. Switched off, a refund always waits for a second person.

In the refund queue the requester sees: "Needs a second approver" while someone else can approve; **Approve my own request** when they are alone; "Needs a second approver" with an explanation when self-approval is switched off. The staff API takes `reason` and `two_factor_code` (see API contract section 14).

Who counts as "another approver": an active staff account (any super admin, or anyone with the right through a role) who may approve on **every** branch of the order. Suspended or deactivated accounts do not count.

## Security settings
Weakening one of these needs **the owner (super admin) and a two-factor code typed in the last 5 minutes**:
- Require two-factor sign-in for the team (turning it **off**).
- Let the requester approve their own refund (turning it **on**).
- Wall display sign-in hours (making them **longer**).
Making any of them stricter needs only the normal settings right. The dashboard opens a "Confirm with your two-factor code" window and saves nothing until the code is right. A person without two-factor enrolled is asked to enrol first. **Every change to these settings is audit-logged** (`security.setting_changed`, old and new value, whether it weakened). The list lives in `SecuritySettingGuard::keys()`: add a new security setting there. There are no session-lifetime or IP-limit settings in the dashboard today, so there is nothing to guard for them.

## Design note
- The "fresh" proof is `session('two_factor_confirmed_at')`, set at sign-in (after the code) and by every successful code in a confirm window; valid 5 minutes. Codes are rate limited (5 / 5 min) and cannot be replayed.
- Refund approval stays race-safe: the row is locked and re-checked inside the transaction.
- The settings guard sits in the settings screen (where people act); code that writes settings directly (console, tests, seeders) is not blocked, on purpose.
- Old tests that relied on a super admin approving their own request were rewritten: there is always another approver in those fixtures.
