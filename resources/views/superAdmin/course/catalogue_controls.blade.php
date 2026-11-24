<div class="form-group">
    <label for="provider">Provider</label>
    <select id="provider" name="provider" class="form-control" required>
        <option value="cnu" @selected(old('provider', $course->provider ?? 'cnu') === 'cnu')>CNU</option>
        <option value="speakout" @selected(old('provider', $course->provider ?? 'cnu') === 'speakout')>SpeakOut</option>
    </select>
    <small>CNU tuition is in NGN. Leave blank for Contact for tuition. SpeakOut is always 100% free; registration and approval happen on the SpeakOut website.</small>
    @error('provider')<div class="text-danger">{{ $message }}</div>@enderror
</div>
<div class="form-group">
    <label for="is_published">Publication</label>
    <select id="is_published" name="is_published" class="form-control" required>
        <option value="0" @selected(!old('is_published', $course->is_published ?? false))>Draft — hidden in both apps</option>
        <option value="1" @selected(old('is_published', $course->is_published ?? false))>Published — visible in both apps</option>
    </select>
    @error('is_published')<div class="text-danger">{{ $message }}</div>@enderror
</div>
