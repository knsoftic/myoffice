<?php

declare(strict_types=1);

namespace App\Services\Institute\Exceptions;

use LogicException;

/**
 * Something tried to rewrite a certificate that has already been handed over (INV-21-1, §2.14).
 *
 * **A `LogicException`, not a `ValidationException`, and the distinction is the point.** Every other
 * refusal in this namespace is a 422 with a message on a field, because it describes something a
 * person did that they may reasonably do differently. This one describes something a *service* did:
 * the screens never offer these fields on an issued certificate, the policy refuses the route, and
 * the Form Request does not accept them — so by the time the model hook fires, three layers have
 * already been passed and what remains is a bug. Rendering it as a friendly field error would put a
 * red message under an input that is not on the page.
 *
 * It follows the shape Phase 19 and Phase 20 settled on: `Exam`'s publication freeze, `ExamResult`'s
 * deletion refusal and `GradeScaleBand`'s all throw `LogicException` from a model hook for the same
 * reason. This one is merely named, because "which certificate, and which columns" is worth carrying
 * in a type rather than only in a string.
 */
final class CertificateIssuedException extends LogicException
{
    /**
     * @param  list<string>  $columns
     */
    public static function cannotChange(string $certificate, array $columns): self
    {
        sort($columns);

        return new self(sprintf(
            'Certificate %s has been issued, so %s cannot be changed. A document somebody is holding '
            .'has to keep saying what it said when they were given it — revoke it with a reason and '
            .'reissue if it is genuinely wrong (INV-21-1).',
            $certificate,
            self::list($columns),
        ));
    }

    /**
     * "a, b and c" — because "a, b, c cannot be changed" reads like a list of three separate
     * sentences that have lost their verbs.
     *
     * @param  list<string>  $columns
     */
    private static function list(array $columns): string
    {
        if (count($columns) <= 1) {
            return $columns[0] ?? 'it';
        }

        $last = array_pop($columns);

        return implode(', ', $columns).' and '.$last;
    }
}
