@extends('layouts.document')

{{--
    One card, or a sheet of them, ready for a card printer (§85, phase-19-23 §7.6).

    Every card on the page was rendered by `StudentIdCardService::renderHtml()` — the controller does
    that, not this file, so a printed card and the PDF of itself cannot disagree. Here there is only
    the paper, the template's stylesheet, and the marks the template must not be able to style away.

    **The whole sheet prints at the first card's paper size.** A batch of cards is the same size in
    practice; a page that tried to honour three sizes at once would honour none. The toolbar says
    which size it is before anybody presses print.
--}}

@php
    $documentTitle = $rendered->count() === 1
        ? sprintf('%s — %s', (string) $rendered->first()['card']->card_number, (string) $rendered->first()['card']->student_name_snapshot)
        : sprintf('%s student cards', app_number($rendered->count()));

    $css = $template?->custom_css;
    $marginMm = (float) ($template?->margin_mm ?? 3);

    $backUrl = $rendered->count() === 1
        ? route('admin.student-id-cards.show', $rendered->first()['card'])
        : route('admin.student-id-cards.index');
    $backLabel = $rendered->count() === 1 ? 'Back to the card' : 'Back to the register';
@endphp

@section('content')
    @foreach ($rendered as $row)
        @php
            $card = $row['card'];
            $prints = (int) $card->print_count;
            // Only `active` prints clean. Every other state is a card that should not be in a wallet,
            // and somebody handed one has to be able to see that at a glance.
            $void = $card->status !== \App\Enums\IdCardStatus::Active;
        @endphp

        <div class="page">
            @if ($void)
                <div class="watermark">{{ mb_strtoupper($card->status->label()) }}</div>
            @endif

            {!! $row['body'] !!}

            @if ($prints > 1)
                <div class="marker">Reprint #{{ app_number($prints) }}</div>
            @endif
        </div>
    @endforeach

    @if ($rendered->isEmpty())
        <div class="page">
            <p>There is nothing to print. Choose at least one card.</p>
        </div>
    @endif
@endsection
