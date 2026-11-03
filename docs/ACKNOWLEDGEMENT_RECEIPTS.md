# Acknowledgement Receipts (F-AQA-INS-003)

Replaces the standalone site at student-signin-system.free.nf. It starts empty: old
records are not imported.

## Who does what

- **Instructor:** Assessments > Acknowledgement Receipts. Create one receipt per document
  given to a class. The roster comes from the class enrollment, and the students are notified.
  Open a receipt to watch signatures arrive, capture one in person (Sign here), clear one,
  remind waiting students, or use Preview & print to see the document inside the system, with
  signatures appearing as they arrive and a Print button on the document. Use Copy to other classes to issue a document to several
  classes at once. Select several in the list to print them together or change their status.
- **Student:** My Learning > My Acknowledgements. The page shows how many forms are waiting in
  total and for each enrolled subject, and the sidebar and dashboard show the same count. A
  student sees only their own classes and signs only their own row, once. With two or more
  open forms, Sign all lets them draw once and apply it to the forms they leave checked. It
  only applies to open forms where they have an unsigned record, and each form keeps its own
  signature. A signature is never reused later: a new form needs a new drawing.
- **Status:** Active can be signed, Closed is visible but cannot be signed, Archived is hidden
  from students.

## Files

| Area | Files |
|---|---|
| Data | `database/migrations/2026_11_01_000001_create_acknowledgement_tables.php`, `app/Models/AcknowledgementForm.php`, `app/Models/AcknowledgementRecipient.php` |
| Rules | `app/Services/AcknowledgementForms.php`, `app/Support/SignatureStrokes.php`, `app/Policies/AcknowledgementFormPolicy.php` |
| Screens | `app/Filament/Resources/AcknowledgementFormResource.php` and its folder, `app/Filament/Pages/MyAcknowledgements.php`, `app/Filament/Forms/Components/SignaturePad.php`, `resources/views/filament/forms/components/signature-pad.blade.php`, `resources/views/filament/pages/my-acknowledgements.blade.php`, `app/Filament/Widgets/StudentAcknowledgementsWidget.php`, `resources/views/filament/widgets/student-acknowledgements.blade.php` |
| Printout | `app/Http/Controllers/AcknowledgementPrintController.php`, `resources/views/reports/acknowledgement-receipt.blade.php`, `app/Filament/Resources/AcknowledgementFormResource/Pages/PreviewAcknowledgementForm.php`, `resources/views/filament/resources/acknowledgement-form-resource/pages/preview.blade.php`, `config/acknowledgements.php`, `public/images/acknowledgements/` |
| Tests | `tests/Feature/AcknowledgementReceiptTest.php` |
| Small edits | `routes/web.php` (print route), `app/Models/CourseOffering.php` (relation and delete guard), `database/seeders/RolesSeeder.php` (default permissions), `app/Filament/Pages/Dashboard.php` (student reminder widget) |

No npm build is needed: the pad and the printout carry their own script and styles.

## Installing on a running copy

Take a database backup first, then copy the files above and run:

```bash
php artisan migrate --force
php artisan optimize:clear
```

The migration only adds two tables and the new permissions, and gives them to the
`instructor`, `student` and `super_admin` roles. Web requests use Filament's cached page list,
so the new pages return 404 until `optimize:clear` runs. Do not run `shield:generate --all`
or the seeders.

The letterhead and footer code are in `config/acknowledgements.php`, because they belong to
the controlled form. Instructors can change the lines below the letterhead on each receipt.
