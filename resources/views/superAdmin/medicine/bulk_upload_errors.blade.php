@if ($errors->has('file'))
    <div class="alert alert-danger text_transform_none">
        <strong>{{ __('Bulk upload failed. No products were imported.') }}</strong>
        <ul class="mb-0 mt-2">
            @foreach ($errors->get('file') as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
