@php
    $documentErrors = collect($errors->get('documents'))->merge(collect($errors->get('documents.*'))->flatten());
@endphp
<div class="form-group">
    <label for="documents">{{ __('Certification documents') }}</label>
    <input id="documents" type="file" name="documents[]" multiple required
           accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
           class="form-control-file {{ $documentErrors->isNotEmpty() ? 'is-invalid' : '' }}">
    <small class="form-text text-muted">
        {{ __('Upload your medical licence / practice certificates. PDF, JPG or PNG, up to :count files, 5 MB each.', ['count' => \App\Services\DoctorDocumentService::MAX_FILES]) }}
    </small>
    @foreach ($documentErrors->unique() as $message)
        <div class="invalid-feedback d-block">{{ $message }}</div>
    @endforeach
</div>
