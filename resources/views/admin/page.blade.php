@extends('layouts.portal')

@section('content')
@if (in_array($page, ['dashboard', 'calendar', 'attendance', 'medical', 'medical-follow-ups', 'messages', 'achievements', 'achievement-certificate', 'certificate-requests', 'reports'], true))
    @include('admin.modules.'.$page)
@else
    <div class="card"><p>Use the navigation to manage this module.</p></div>
@endif
@endsection
