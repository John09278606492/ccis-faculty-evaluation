<div class="section"><h2>Department Leadership</h2>
<table><thead><tr><th>Teaching Department</th><th>Dean / Department Head</th><th>Chairperson</th></tr></thead><tbody>@foreach($heads as $department)<tr><td>{{ $department->code }}</td><td>{{ $department->supervisor?->name ?? 'Not assigned' }}</td><td>{{ $department->chairperson?->name ?? 'Not assigned' }}</td></tr>@endforeach</tbody></table>
@if(($additionalSupervisors ?? collect())->isNotEmpty())<p><b>Assigned supervisor evaluators for this faculty:</b> {{ $additionalSupervisors->pluck('name')->join(', ') }}</p>@endif
<p class="muted no-print">Prepared/Reviewed names below are report signatories. Changing them does not change department heads or the person who submitted Annex B. Save changes before printing.</p></div>
