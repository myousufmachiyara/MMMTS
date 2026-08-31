@extends('layouts.app')

@section('title', 'Daily Jobs | Edit Job')

@section('content')

@if(session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<h2 class="mb-3">Edit Daily Job — {{ $job->job_no }}</h2>

@if($job->job_type === 'party_to_party')
    @include('daily_jobs._form_pty')
@else
    @include('daily_jobs._form')
@endif

@include('layouts.partials.modal-scripts')
@endsection
