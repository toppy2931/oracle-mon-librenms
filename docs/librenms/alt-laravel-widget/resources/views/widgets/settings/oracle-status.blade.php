@extends('widgets.settings.base')

@section('form')
    <div class="form-group">
        <label for="stale_threshold" class="col-sm-4 control-label">Stale Threshold (min)</label>
        <div class="col-sm-8">
            <input type="number" class="form-control" id="stale_threshold" name="stale_threshold"
                   value="{{ $stale_threshold ?? 60 }}" min="1" max="9999">
            <p class="help-block">超過此分鐘數的 FRESH MV 以黃色警示</p>
        </div>
    </div>
@endsection
