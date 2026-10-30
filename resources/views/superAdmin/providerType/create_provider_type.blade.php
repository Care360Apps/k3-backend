@extends('layout.mainlayout_admin',['activePage' => 'provider_type'])

@section('title',__('Add Provider Type'))
@section('content')
<section class="section">
    @include('layout.breadcrumb',[
        'title' => __('Add Provider Type'),
        'url' => url('provider_type'),
        'urlTitle' => __('Provider Types'),
    ])
    <div class="section-body">
        <div class="card">
            <form action="{{ url('provider_type') }}" method="post" class="myform">
                @csrf
                <div class="card-body">
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="form-group">
                                <label class="col-form-label">{{__('Name')}}</label>
                                <input type="text" value="{{ old('name') }}" name="name" class="form-control @error('name') is-invalid @enderror" placeholder="e.g. ECG Technician">
                                @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Description')}}</label>
                                <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="3" placeholder="Optional description">{{ old('description') }}</textarea>
                                @error('description')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Icon')}}</label>
                                <input type="text" value="{{ old('icon') }}" name="icon" class="form-control @error('icon') is-invalid @enderror" placeholder="e.g. fas fa-heartbeat or icon class">
                                @error('icon')
                                <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="form-group">
                                <label class="col-form-label">{{__('Status')}}</label>
                                <label class="cursor-pointer">
                                    <input type="checkbox" id="status" class="custom-switch-input" name="status" checked>
                                    <span class="custom-switch-indicator"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary">{{__('Submit')}}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
