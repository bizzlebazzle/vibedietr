<div>
    <x-input-label for="reason-{{ $formKey }}" value="Decision reason" />
    <select id="reason-{{ $formKey }}" name="reason_code" required class="rounded dark:bg-gray-800">
        @foreach (['reviewed' => 'Reviewed evidence', 'duplicate' => 'Duplicate identity', 'distinct' => 'Distinct identity', 'insufficient_evidence' => 'Insufficient evidence', 'incorrect_decision' => 'Incorrect earlier decision'] as $value => $label)
            <option value="{{ $value }}" @selected(old('reason_code', 'reviewed') === $value)>{{ $label }}</option>
        @endforeach
    </select>
</div>
<div><x-input-label for="note-{{ $formKey }}" value="Private note (optional, 500 characters maximum)" /><textarea id="note-{{ $formKey }}" name="note" maxlength="500" rows="3" class="w-full rounded dark:bg-gray-800">{{ old('note') }}</textarea></div>
