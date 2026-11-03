# Scores and Tally Reports

In **Faculty Evaluation > Faculty Results**, use **View Reports** on a faculty row. The modal's **Report** selector offers the Scores & Tally overview, Scores Report, Tally Report, Annex C and Annex D. Printable formats load inside the same modal when selected. The embedded preview has **Print / Save PDF**. Printing uses **A4 landscape**, with repeated table headings on continuation pages. Shared empty CSS page-margin boxes suppress the browser date, title, URL and page count in current Chrome and Edge while preserving the report margins and pagination. The same print rule covers Annex A/B copies, Annex C/D reports and printable instruments. Browsers without CSS page-margin box support need their print dialog's Headers and footers option turned off.

Both reports retain the selected academic period, teaching department, cycle, instrument version and evaluation type. Each assignment and historical question set has its own table; SET and SEF are never combined. Campus and department authorization still apply. Faculty accessing their own reports must satisfy the existing closed-period and minimum-response rules.

- **Scores Report:** each question shows its complete-response count, sum of ratings and average on the original scale. The footer shows the sum across questions and the average per question. The summary gives the mean total per response and the canonical Mean / 5 and Rating / 100.
- **Tally Report:** anonymous evaluator rows contain Q1 through the final question, followed immediately by **Total Score** and **Average**. Total is the sum of that evaluator's question ratings; average is the total divided by the number of rating questions. Question totals and averages appear below the evaluator rows. Question text appears in the guide below each table.

Only complete, valid responses contribute to aggregates. Incomplete responses remain visible with missing ratings shown as a dash; they are not zero ratings. Historical instrument snapshots are used, including deleted students' submitted responses. Student names, IDs, submission timestamps and technical identity metadata are not included. Supervisor rows are labelled **Supervisor**.

Custom instruments wider than 15 questions are split into question panels instead of shrinking text or clipping columns. Full-response totals and averages follow the final question panel. Table rows stay intact at page breaks.

Legacy responses without an instrument snapshot explicitly load their original form and fields. Tabular reports reuse the assignment's eagerly loaded form, so strict lazy-loading prevention does not cause a 500 or add a query for every legacy response. Regression coverage includes several snapshot-free submissions with strict loading enabled.

If a report fails, the preview displays a responsive, light error screen with **Try again**, **Close preview**, and a support reference matched to the server log. Technical exception details remain private. Reload is offered only for safe GET/HEAD requests. Standard access, missing-page, expired-session, throttling and service errors use the same recovery screen; JSON responses retain their original format and status.

The official Filament plugin directory's [CMS Max Error Pages](https://filamentphp.com/plugins/cmsmax-error-pages) was reviewed. It handles panel 403/404 pages, while report previews use separate Laravel routes and need 500 handling. The shared Laravel fallback covers those routes without adding a panel-only dependency.
