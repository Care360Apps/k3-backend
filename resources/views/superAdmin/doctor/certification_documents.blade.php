<div class="card" id="certification-documents">
    <div class="card-header d-flex justify-content-between">
        <h6 class="mb-0">{{ __('Certification Documents') }}</h6>
        <span class="badge {{ $documents->isEmpty() ? 'badge-warning' : 'badge-primary' }}">{{ $documents->count() }}</span>
    </div>
    <div class="card-body">
        @if ($documents->isEmpty())
            <p class="text-muted mb-0">{{ __('No certification documents were uploaded.') }}</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th>{{ __('Document') }}</th>
                            <th>{{ __('Type') }}</th>
                            <th>{{ __('Size') }}</th>
                            <th>{{ __('Uploaded') }}</th>
                            <th class="text-right">{{ __('Action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($documents as $document)
                            <tr>
                                <td>
                                    @if ($document->isImage())
                                        <a href="{{ url('doctor_document/'.$document->id) }}" data-fancybox="doctor_document_images">
                                            <img src="{{ url('doctor_document/'.$document->id) }}" alt="{{ $document->original_name }}" width="40" height="40" class="rounded mr-2" style="object-fit: cover">
                                        </a>
                                    @else
                                        <i class="far fa-file-pdf text-danger mr-2"></i>
                                    @endif
                                    <span class="text_transform_none">{{ $document->original_name }}</span>
                                </td>
                                <td>{{ $document->isImage() ? __('Image') : 'PDF' }}</td>
                                <td class="text-nowrap">{{ number_format($document->size / 1024, 1) }} KB</td>
                                <td>{{ $document->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="text-right text-nowrap">
                                    <a href="{{ url('doctor_document/'.$document->id) }}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a>
                                    <a href="{{ url('doctor_document/'.$document->id.'?download=1') }}" class="btn btn-sm btn-outline-secondary">{{ __('Download') }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
