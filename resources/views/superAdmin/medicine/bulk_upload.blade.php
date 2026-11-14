<div class="modal fade" id="bulk_upload_modal" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content text_transform_none">
            <form action="{{ $action }}" method="post" enctype="multipart/form-data">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Bulk upload products') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">×</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>
                        {{ __('Download the CSV template, enter one product per row, then preview and validate it before importing. Existing products are updated and incoming stock is added; duplicate names in the same file are rejected. Importing the same file again adds its stock again; preview does not change stock.') }}
                        <a href="{{ url('medicines/bulk_template') }}">{{ __('Download the CSV template') }}</a>
                    </p>
                    <div class="form-group">
                        <label>{{ __('CSV file') }}</label>
                        <input type="file" name="file" accept=".csv,.txt,text/csv" required class="form-control">
                        <small class="form-text text-muted">
                            {{ __('Max :rows rows, 2 MB. All rows are validated first; if any row is invalid nothing is imported.', ['rows' => \App\Services\MedicineImportService::MAX_ROWS]) }}
                        </small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0">
                            <thead>
                                <tr><th>{{ __('Column') }}</th><th>{{ __('Required') }}</th><th>{{ __('Notes') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr><td><code>name</code></td><td>{{ __('Yes') }}</td><td>{{ __('Product name') }}</td></tr>
                                <tr><td><code>category</code></td><td>{{ __('Yes') }}</td><td>{{ __('Category name or id') }}: {{ \App\Models\MedicineCategory::whereStatus(1)->orderBy('name')->pluck('name')->implode(', ') ?: __('none yet, create one first') }}</td></tr>
                                <tr><td><code>price_pr_strip</code></td><td>{{ __('Yes') }}</td><td>{{ __('Price per strip (number)') }}</td></tr>
                                <tr><td><code>number_of_medicine</code></td><td>{{ __('Yes') }}</td><td>{{ __('Units per strip (whole number)') }}</td></tr>
                                <tr><td><code>incoming_stock</code></td><td>{{ __('Yes') }}</td><td>{{ __('Stock to add (whole number)') }}</td></tr>
                                <tr><td><code>description</code></td><td>{{ __('Yes') }}</td><td></td></tr>
                                <tr><td><code>works</code></td><td>{{ __('Yes') }}</td><td>{{ __('How it works') }}</td></tr>
                                <tr><td><code>prescription_required</code></td><td>{{ __('No') }}</td><td>{{ __('yes / no; blank preserves existing setting (new products default no)') }}</td></tr>
                                <tr><td><code>status</code></td><td>{{ __('No') }}</td><td>{{ __('active / inactive; blank preserves existing setting (new products default active)') }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <small class="text-muted">{{ __('Imported products use the default image; you can change it by editing the product.') }}</small>
                </div>
                <div class="modal-footer">
                    <button type="submit" name="import_action" value="preview" class="btn btn-outline-primary">{{ __('Preview & validate') }}</button>
                    <button type="submit" name="import_action" value="import" class="btn btn-primary" onclick="return confirm('{{ __('Import validated medicines and add stock? This changes pharmacy inventory.') }}')">{{ __('Import medicines') }}</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ __('Close') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
