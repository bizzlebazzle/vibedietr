@if ($import->type->value === 'uploaded_image')
    <div class="space-y-2 text-sm" role="note">
        @if ($import->extractor_identifier)
            <p>OCR source: {{ match ($import->extractor_identifier) { 'tesseract' => 'Local Tesseract', 'google_document_ai' => 'Google Document AI (EU fallback)', default => 'Recorded OCR extractor' } }}{{ isset($import->provenance['extractor_version']) ? ' · '.$import->provenance['extractor_version'] : '' }}. Recovered OCR text may be inaccurate; it is not an exact transcription.</p>
        @endif
        @if ($import->status->value === 'review_ready')
            @if (in_array('low_confidence_text', $import->warnings ?? [], true))
                <p>Low-confidence OCR text was recovered into a reviewable private draft. Compare it with your source and correct quantities, units, servings, and instructions. Confidence does not verify correctness.</p>
            @else
                <p>Review the recovered text and draft against your source, especially quantities, units, servings, and instructions.</p>
            @endif
            <p>OCR does not verify food matches or nutrition. Finalize the reviewed draft explicitly before using it in a meal plan.</p>
        @endif
        @if (config('production.ocr.google.enabled'))
            <p>If local OCR technically fails or finds no usable text, a metadata-free canonical image may be processed by Google Document AI in the EU. Low confidence alone does not trigger this fallback.</p>
        @endif
    </div>
@endif
