@extends('layouts.print')

@php
    $backUrl = route('admin.demo-classes.index');
    $backLabel = 'Back to demo classes';
@endphp

@section('title', 'Demo class slip')

@section('document')
    <h2>Demo class</h2>
    <div class="mono strong">DEMO-{{ str_pad((string) $demo->id, 6, '0', STR_PAD_LEFT) }}</div>
    <div class="muted tiny" style="margin-top:6px;">
        {{ app_date($demo->scheduled_on) }}<br>
        {{ app_time($demo->startsAt()) }} – {{ app_time($demo->endsAt()) }}
    </div>
@endsection

@section('content')
    <div class="parties">
        <div>
            <h3 class="section">Attendee</h3>
            <div class="strong">{{ $demo->attendee_name }}</div>
            @if (filled($demo->attendee_phone))
                <div class="muted">{{ $demo->attendee_phone }}</div>
            @endif
            <div class="muted tiny">{{ $demo->subject_type->label() }}</div>
        </div>
        <div>
            <h3 class="section">Class</h3>
            <div class="strong">{{ $demo->course?->name ?? '—' }}</div>
            <div class="muted">{{ $demo->delivery_mode->label() }}</div>
            <div class="muted tiny">{{ $demo->branch?->name ?? 'Main branch' }}</div>
        </div>
    </div>

    @if (filled($demo->meeting_url))
        <div class="panel avoid-break">
            <h4>Joining link</h4>
            <div class="pre-line">{{ $demo->meeting_url }}</div>
        </div>
    @endif

    @if (filled($demo->notes))
        <div class="panel avoid-break">
            <h4>Notes</h4>
            <div class="pre-line">{{ $demo->notes }}</div>
        </div>
    @endif

    <div class="panel avoid-break">
        <h4>Please bring</h4>
        <div class="pre-line">Your CNIC or B-Form, and a notebook. Please arrive ten minutes early.</div>
    </div>
@endsection
