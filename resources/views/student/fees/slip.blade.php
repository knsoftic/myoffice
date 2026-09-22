{{--
    The student's own copy of the fee slip.

    It is the SAME document as the staff copy — one layout, one letterhead — built from the same
    `FeeSlipData`. The difference is entirely in the options object: the panel route constructs
    `FeeSlipOptions::studentCopy()`, which cannot be talked out of hiding the commission block by any
    setting or query parameter (§6.7.1). That is why this file includes the staff view rather than
    being a second template that has to remember the rule.
--}}
@include('admin.student-fees.slip', ['slip' => $slip])
