@extends('layout.mainlayout_admin',['activePage' => 'hospital'])
@section('title', 'Facility Directory')
@section('content')
<section class="section">
  <div class="section_body">
    <div class="card">
      <div class="card-header"><h4>Care360 Facility Directory</h4></div>
      <div class="card-body">
        <p>Review facilities before publication. Directory inclusion does not activate pharmacy sales or partner status.</p>
        @if(session('status')) <div class="alert alert-success">{{ session('status') }}</div> @endif
        @if($errors->any())
          <div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <h5>Add draft facility</h5>
        <form method="POST" action="{{ url('admin/facility-directory') }}">
          @csrf
          <div class="form-row">
            <div class="form-group col-md-3"><label>Name</label><input class="form-control" name="name" required maxlength="200" value="{{ old('name') }}"></div>
            <div class="form-group col-md-2"><label>Type</label><select class="form-control" name="type" required><option value="hospital">Hospital</option><option value="pharmacy">Pharmacy</option></select></div>
            <div class="form-group col-md-2"><label>State</label><select class="form-control" name="state" required><option>FCT</option><option>Nasarawa</option><option>Plateau</option></select></div>
            <div class="form-group col-md-2"><label>LGA</label><input class="form-control" name="lga" value="{{ old('lga') }}"></div>
            <div class="form-group col-md-3"><label>Source</label><input class="form-control" name="source_name" required value="{{ old('source_name') }}"></div>
          </div>
          <div class="form-row">
            <div class="form-group col-md-5"><label>Address</label><input class="form-control" name="address" value="{{ old('address') }}"></div>
            <div class="form-group col-md-2"><label>Latitude</label><input class="form-control" name="latitude" type="number" step="any" value="{{ old('latitude') }}"></div>
            <div class="form-group col-md-2"><label>Longitude</label><input class="form-control" name="longitude" type="number" step="any" value="{{ old('longitude') }}"></div>
            <div class="form-group col-md-3"><label>Source record ID</label><input class="form-control" name="source_id" value="{{ old('source_id') }}"></div>
          </div>
          <button class="btn btn-primary" type="submit">Save draft</button>
        </form>
      </div>
    </div>
    <div class="card">
      <div class="card-header"><h4>Facility review queue</h4></div>
      <div class="card-body table-responsive">
        <table class="table table-striped">
          <thead><tr><th>Name</th><th>Type</th><th>State</th><th>Source</th><th>Verification</th><th>Listing</th><th>Review action</th></tr></thead>
          <tbody>
          @forelse($facilities as $facility)
          <tr>
            <td>{{ $facility->name }}<small class="d-block">{{ $facility->address }}</small></td>
            <td>{{ ucfirst($facility->type) }}</td><td>{{ $facility->state }}</td>
            <td>{{ $facility->source_name }}</td>
            <td>{{ $facility->review_status }}</td><td>{{ $facility->listing_status }}</td>
            <td style="min-width:260px">
              <form method="POST" action="{{ url('admin/facility-directory/'.$facility->id.'/review') }}">
                @csrf
                <label class="sr-only" for="decision-{{ $facility->id }}">Decision</label>
                <select class="form-control mb-2" name="decision" id="decision-{{ $facility->id }}" required>
                  <option value="">Choose decision</option><option value="publish">Publish verified listing</option>
                  <option value="suspend">Suspend listing</option><option value="reject">Reject record</option>
                </select>
                <label class="sr-only" for="evidence-{{ $facility->id }}">Evidence notes</label>
                <textarea class="form-control mb-2" id="evidence-{{ $facility->id }}" name="evidence_note" required minlength="20" maxlength="4000" placeholder="Verification source, evidence and rationale"></textarea>
                <label><input type="checkbox" name="evidence_confirmed" value="1" required> I checked the evidence</label>
                <button class="btn btn-outline-primary btn-sm" type="submit">Record decision</button>
              </form>
            </td>
          </tr>
          @empty <tr><td colspan="7">No directory records yet.</td></tr> @endforelse
          </tbody>
        </table>
        {{ $facilities->links() }}
      </div>
    </div>
  </div>
</section>
@endsection
