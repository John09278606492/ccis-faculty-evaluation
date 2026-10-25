<label for="{{ $key }}_id">{{ $label }}</label>
@php($selectedId = old($key.'_id', $details[$key.'_id'] ?? ''))
<select id="{{ $key }}_id" name="{{ $key }}_id" style="width:100%;padding:9px;border:1px solid #bac4cf;border-radius:4px;font:inherit" @disabled(!$canEdit)>
    <option value="">{{ !empty($details[$key.'_name']) && empty($details[$key.'_id']) ? $details[$key.'_name'].' (previous entry — choose staff to replace)' : 'Select staff member' }}</option>
    @foreach($staff as $member)<option value="{{ $member->id }}" @selected((string)$selectedId === (string)$member->id)>{{ $member->name }}</option>@endforeach
</select>
<div class="print-text">{{ $details[$key.'_name'] ?? '________________________' }}</div>
