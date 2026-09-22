{{--
    The student's own copy of a receipt — the same document as the staff copy, built from the same
    `ReceiptData`. The route constructs `FeeSlipOptions::studentCopy()`, which is the only difference.
--}}
@include('fees.receipt', ['receipt' => $receipt])
