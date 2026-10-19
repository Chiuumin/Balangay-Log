# Case workflow

## Official statuses

| Status | Meaning |
| --- | --- |
| Pending Triage (`PENDING`; legacy `CRITICAL` and `UNDER_REVIEW`) | A report is waiting for an officer to assess it. `CRITICAL` remains a stored legacy value and displays as Pending Triage; priority is shown separately. |
| In Progress (`IN_PROGRESS`) | An officer has accepted the case for handling, but field dispatch or a referral has not started. |
| Dispatched (`DISPATCHED`) | Tanod/responding personnel have been assigned and sent to the incident. |
| Awaiting Resolution (`FOR_RESOLUTION`) | The case has been referred to Lupon mediation and awaits its outcome. Existing Lupon cases in mediation map here. |
| Resolved (`RESOLVED`) | An outcome or settlement has been documented. The case is read-only to residents and regular officers. |
| Finalized resolution (`CLOSED`, internal) | An authorized Captain or administrator has finalized the saved resolution. The dashboards continue to display this as Resolved; the internal value preserves edit-lock and controlled-reopen protections. |

A finalized resolution is displayed as Resolved rather than as a separate Closed status. A concluded Lupon mediation leaves the active Lupon list. Resolution submissions mark the incident Resolved; any recorded obligations or follow-up remain visible in the resolution record, but do not keep the resolution form open or return the case to In Progress. Resolution finalization is a separate, authorized step recorded internally to lock the case.

## Findings, resolution, and finalization

Officers record findings as structured, append-only entries containing observations, action taken, and findings; persons contacted and recommended next steps are optional. The submitting user and timestamp are recorded by the server. Findings can be added while a case is active, including during Lupon mediation, but not after resolution finalization.

Resolution requires an active case (In Progress, Dispatched, or Awaiting Resolution), at least one recorded finding, an explicit confirmation that the findings were reviewed, an outcome, and a written conclusion of at least 30 characters. Mediation settlements require documented settlement terms. External referrals require a follow-up date or a remaining obligation. Submitting a valid resolution marks the case Resolved even when obligations or follow-up details are recorded; those details remain in the resolution record. Saving a resolution ends the active tanod dispatch and returns assigned responders to Available; field assignment history remains recorded. A completed settlement or resolution is shown as Resolved, removes the case from active Lupon mediation, and hides the resolution form. Pending Triage cases must first be triaged before resolution; an authorized Captain or administrator may finalize a non-actionable pending case only after recording findings and a written conclusion.

The final review step previews the outcome, findings review, conclusion, settlement terms, obligations, follow-up, and resulting status before saving. Only a Captain or administrator can finalize a resolution; finalization is rejected while obligations or follow-up remain. Resolution and finalization records are append-only and retain the outcome, conclusion, responsible user, and timestamp. Reopening preserves those records and adds a reasoned activity entry.

## Allowed transitions

| From | To |
| --- | --- |
| Pending Triage | In Progress, Dispatched, Finalized (authorized non-actionable resolution) |
| In Progress | Dispatched, Awaiting Resolution, Resolved, Finalized |
| Dispatched | In Progress, Awaiting Resolution, Resolved, Finalized |
| Awaiting Resolution | In Progress, Resolved, Finalized |
| Resolved | Finalized |
| Finalized (`CLOSED`, internal) | In Progress (authorized reopen only) |

Severity changes do not change the workflow status. Referring to the Captain records a referral and reason in the case timeline without changing status; referring to Lupon moves the case to Awaiting Resolution. Dispatch is a separate action.

## Role permissions

| Action | Officer | Captain | Admin / System Admin |
| --- | --- | --- | --- |
| Triage, set priority, assign/dispatch, record findings | Yes | Yes | Yes |
| Refer to Lupon or Captain | Yes | Yes | Yes |
| Record a Lupon resolution | Yes | Yes | Yes |
| Finalize or reopen a case | No | Yes | Yes |
| Approve a Captain referral | No | Yes | Yes |

All permissions and status transitions are checked by the server. The alternate status-update endpoint cannot bypass required findings, conclusion, outcome, review, or finalization records. A reopen must include a reason and moves the internally finalized case to In Progress.

Before deploying to an existing database, apply [`case_workflow_migration.sql`](./case_workflow_migration.sql) so the incident status enum accepts `DISPATCHED` and `CLOSED`, and so the append-only findings, resolution, and finalization tables are available.

For responder profiles, team dispatch, acknowledgements, arrival times, and field response records, apply [`tanod_dispatch_migration.sql`](./tanod_dispatch_migration.sql). Field updates are entered by an authorized desk officer on behalf of assigned responders; the officer and timestamp are saved with each update. Submitting field findings returns the case to In Progress for desk follow-up.
