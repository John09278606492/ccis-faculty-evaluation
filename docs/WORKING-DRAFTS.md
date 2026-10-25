# Working drafts

Critical create/edit pages keep private working copies: academic students, sections,
teaching classes, evaluation assignments/cycles, departments, subjects, school years,
instrument settings and assessment settings. Criteria and assessment question editors
also preserve unfinished properties. Teaching Class → Evaluators preserves the section
selection, including explicit individual exceptions, when its modal is closed.

Return to the same page and record and use **Resume draft**. Restoring a draft does
not submit, publish, enroll or assign anyone. Review the form and use its normal Save
button. **Discard draft** restores the saved version. Successful saves clear the draft;
validation failures keep it. A create page has one working draft per user and campus.

Autosave starts after a short typing pause. A menu change waits for pending input and
draft saving. The status explicitly reports saving/failure; if saving fails, navigation
stays on the editor. Browser reload/close still warns about changes that have not reached
the server. Passwords, PINs, uploaded files and unknown form keys are excluded. Re-select
uploads after returning. This feature covers staff editors, not timed student attempts,
which retain their separate assessment recovery rules.

Draft payloads are encrypted with the application key in `workspace_drafts`, scoped by
account, campus, page and record/action. Access is rechecked when saving or resuming.
Normal form and roster validation still runs on the final Save. A changed/deleted or
locked record cannot be overwritten by restoring an obsolete draft. Version checks
reject another tab's stale draft writes, including writes arriving after Save/Discard.

Drafts expire after 30 days. The scheduler runs `workspace-drafts:prune` daily; deployments
must run Laravel's scheduler as already required by the classroom host. Draft writes do
not broadcast public record updates, send notifications or change evaluation results.
