<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\RemainderPlacement;
use App\Support\Money;
use Illuminate\Container\Container;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Money::distribute()` and `Money::allocate()` — the only two functions allowed to split a money
 * value (installments, commission shares, payout allocations).
 *
 * The one property that matters above all others: **the shares always sum to exactly the amount**,
 * to the paisa, for every amount, every part count, every weight set and every remainder rule. A
 * split that loses or invents one paisa is a ledger that can never reconcile (CLAUDE.md §5: the
 * wallet must always equal SUM(ledger)).
 *
 * Every sum below is taken with `Money::sum()` / bcmath and compared as a string — never through a
 * float — and the property is checked on a large deterministic pseudo-random sample as well as on
 * the hand-picked edge cases, so the guarantee is tested rather than illustrated.
 *
 * A pure unit test: no application, no database.
 */
final class MoneyDistributeTest extends TestCase
{
    /** Fixed seed: the random sample is identical on every run, so a failure is reproducible. */
    private const SEED = 20260913;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(null);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | distribute()
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function distributeProvider(): array
    {
        return [
            'the classic thirds' => ['100.00', 3],
            'one paisa over three' => ['0.01', 3],
            'two paisa over seven' => ['0.02', 7],
            'zero over five' => ['0.00', 5],
            'a single part' => ['1250.50', 1],
            'more parts than paisa' => ['0.05', 12],
            'an installment plan' => ['145000.00', 7],
            'a negative reversal' => ['-100.00', 3],
            'a negative paisa' => ['-0.01', 4],
            'a large fee' => ['1234567.89', 11],
            'beyond float precision' => ['999999999999.99', 13],
            'an amount given without decimals' => ['10', 4],
            'an amount given at one decimal' => ['7.5', 2],
            'prime parts' => ['1000.00', 97],
        ];
    }

    #[Test]
    #[DataProvider('distributeProvider')]
    public function distribute_always_sums_exactly_to_the_amount(string $amount, int $parts): void
    {
        foreach (RemainderPlacement::cases() as $placement) {
            $shares = Money::distribute($amount, $parts, $placement);

            $this->assertShareShape($shares, $parts);
            $this->assertSame(Money::of($amount), Money::sum($shares), sprintf(
                'distribute(%s, %d, %s) sums to %s.',
                $amount,
                $parts,
                $placement->name,
                Money::sum($shares),
            ));

            $this->assertSpreadAtMostOnePaisa($shares);
            $this->assertEverySharecarriesTheSignOf($amount, $shares);
        }
    }

    #[Test]
    public function the_documented_examples_hold(): void
    {
        $this->assertSame(['33.34', '33.33', '33.33'], Money::distribute('100.00', 3));
        $this->assertSame(['33.33', '33.33', '33.34'], Money::distribute('100.00', 3, RemainderPlacement::Last));
        $this->assertSame(['-33.34', '-33.33', '-33.33'], Money::distribute('-100.00', 3));
        $this->assertSame(['0.01', '0.00', '0.00'], Money::distribute('0.01', 3));
    }

    #[Test]
    public function the_remainder_lands_where_the_placement_says(): void
    {
        $first = Money::distribute('0.05', 3, RemainderPlacement::First);
        $last = Money::distribute('0.05', 3, RemainderPlacement::Last);

        $this->assertSame(['0.02', '0.02', '0.01'], $first);
        $this->assertSame(['0.01', '0.02', '0.02'], $last);
    }

    #[Test]
    public function a_reversal_mirrors_the_split_it_reverses_share_for_share(): void
    {
        foreach ([['100.00', 3], ['0.07', 4], ['145000.01', 9]] as [$amount, $parts]) {
            $forward = Money::distribute($amount, $parts);
            $reverse = Money::distribute(Money::negate($amount), $parts);

            $this->assertSame(array_map(Money::negate(...), $forward), $reverse);
            $this->assertSame(Money::zero(), Money::add(Money::sum($forward), Money::sum($reverse)));
        }
    }

    #[Test]
    public function distribute_sums_exactly_across_a_large_random_sample(): void
    {
        mt_srand(self::SEED);

        for ($i = 0; $i < 750; $i++) {
            $amount = $this->randomAmount();
            $parts = mt_rand(1, 60);
            $placement = RemainderPlacement::cases()[mt_rand(0, count(RemainderPlacement::cases()) - 1)];

            $shares = Money::distribute($amount, $parts, $placement);

            $this->assertCount($parts, $shares);
            $this->assertSame(
                Money::of($amount),
                Money::sum($shares),
                sprintf('Sample %d: distribute(%s, %d, %s) does not sum exactly.', $i, $amount, $parts, $placement->name),
            );
            $this->assertSpreadAtMostOnePaisa($shares);
        }
    }

    #[Test]
    public function distribute_refuses_fewer_than_one_part(): void
    {
        foreach ([0, -1] as $parts) {
            try {
                Money::distribute('100.00', $parts);
                $this->fail(sprintf('distribute() accepted %d parts.', $parts));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function distribute_refuses_an_amount_it_cannot_read_rather_than_splitting_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::distribute('1e3', 3);
    }

    /*
    |--------------------------------------------------------------------------
    | allocate()
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string, 1: array<array-key, string|int>}>
     */
    public static function allocateProvider(): array
    {
        return [
            'one two three' => ['100.00', ['1', '2', '3']],
            'equal weights' => ['100.00', ['1', '1', '1']],
            'integer weights' => ['1000.00', [1, 1, 2]],
            'a zero weight among others' => ['50.00', ['0', '1', '1']],
            'fractional weights' => ['999.99', ['33.3333', '33.3333', '33.3334']],
            'installments keyed by id' => ['145000.00', [101 => '40000.00', 102 => '40000.00', 103 => '65000.00']],
            'string keys' => ['0.10', ['alpha' => '1', 'beta' => '1', 'gamma' => '1']],
            'a negative reversal' => ['-250.05', ['3', '5', '7']],
            'tiny amount many weights' => ['0.03', ['1', '1', '1', '1', '1', '1', '1']],
            'a single weight' => ['12.34', ['9']],
            'wildly unequal weights' => ['10000.00', ['0.0001', '9999.9999']],
            'beyond float precision' => ['999999999999.99', ['1', '2', '3', '4', '5']],
        ];
    }

    /**
     * @param  array<array-key, string|int>  $weights
     */
    #[Test]
    #[DataProvider('allocateProvider')]
    public function allocate_always_sums_exactly_and_keeps_the_keys(string $amount, array $weights): void
    {
        foreach (RemainderPlacement::cases() as $placement) {
            $shares = Money::allocate($amount, $weights, $placement);

            $this->assertSame(array_keys($weights), array_keys($shares), 'Keys are preserved in order.');
            $this->assertShareShape(array_values($shares), count($weights));
            $this->assertSame(Money::of($amount), Money::sum(array_values($shares)), sprintf(
                'allocate(%s, %s, %s) sums to %s.',
                $amount,
                json_encode($weights),
                $placement->name,
                Money::sum(array_values($shares)),
            ));
            $this->assertEverySharecarriesTheSignOf($amount, array_values($shares));
        }
    }

    /**
     * @param  array<array-key, string|int>  $weights
     */
    #[Test]
    #[DataProvider('allocateProvider')]
    public function the_largest_remainder_rule_keeps_every_share_within_one_paisa_of_its_exact_value(string $amount, array $weights): void
    {
        $shares = Money::allocate($amount, $weights);

        $total = '0';

        foreach ($weights as $weight) {
            $total = bcadd($total, (string) $weight, 10);
        }

        foreach ($weights as $key => $weight) {
            $exact = bcdiv(bcmul(Money::of($amount), (string) $weight, 10), $total, 10);
            $difference = bcsub($shares[$key], $exact, 10);

            if (str_starts_with($difference, '-')) {
                $difference = substr($difference, 1);
            }

            $this->assertLessThan(
                0,
                bccomp($difference, '0.01', 10),
                sprintf('Share %s is %s but its exact proportional value is %s.', (string) $key, $shares[$key], $exact),
            );
        }
    }

    #[Test]
    public function allocate_matches_its_documented_examples(): void
    {
        $this->assertSame(['25.00', '25.00', '50.00'], Money::allocate('100.00', ['1', '1', '2']));
        $this->assertSame(['16.67', '33.33', '50.00'], Money::allocate('100.00', ['1', '2', '3']));
        $this->assertSame(['33.34', '33.33', '33.33'], Money::allocate('100.00', ['1', '1', '1']));
        $this->assertSame(['50.00' => '0.00', 'x' => '12.34'], Money::allocate('12.34', ['50.00' => '0', 'x' => '1']));
    }

    #[Test]
    public function allocate_sums_exactly_across_a_large_random_sample(): void
    {
        mt_srand(self::SEED + 1);

        for ($i = 0; $i < 500; $i++) {
            $amount = $this->randomAmount();
            $weights = [];

            for ($w = 0, $n = mt_rand(1, 25); $w < $n; $w++) {
                $weights['k'.$w] = mt_rand(0, 100000).'.'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            }

            if (bccomp(array_reduce($weights, static fn (string $carry, string $weight): string => bcadd($carry, $weight, 4), '0'), '0', 4) === 0) {
                $weights['k0'] = '1';
            }

            $placement = RemainderPlacement::cases()[mt_rand(0, count(RemainderPlacement::cases()) - 1)];
            $shares = Money::allocate($amount, $weights, $placement);

            $this->assertSame(
                Money::of($amount),
                Money::sum(array_values($shares)),
                sprintf('Sample %d: allocate(%s, %s, %s) does not sum exactly.', $i, $amount, json_encode($weights), $placement->name),
            );
        }
    }

    /**
     * @return array<string, array{0: array<array-key, string|int>}>
     */
    public static function refusedWeightsProvider(): array
    {
        return [
            'no weights' => [[]],
            'all zero' => [['0', '0.00', 0]],
            'a negative weight' => [['1', '-1', '2']],
        ];
    }

    /**
     * @param  array<array-key, string|int>  $weights
     */
    #[Test]
    #[DataProvider('refusedWeightsProvider')]
    public function allocate_refuses_weights_that_cannot_describe_a_split(array $weights): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::allocate('100.00', $weights);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A random amount built from integers only — never via a float — between -10^12 and 10^12.
     */
    private function randomAmount(): string
    {
        $magnitude = [mt_rand(0, 9), mt_rand(0, 999), mt_rand(0, 99999), mt_rand(0, 999999999)][mt_rand(0, 3)];
        $amount = $magnitude.'.'.str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);

        return mt_rand(0, 4) === 0 ? '-'.$amount : $amount;
    }

    /**
     * @param  list<string>  $shares
     */
    private function assertShareShape(array $shares, int $parts): void
    {
        $this->assertCount($parts, $shares);

        foreach ($shares as $share) {
            $this->assertIsString($share, 'A share is a string — never a float.');
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $share, 'Every share carries exactly two decimals.');
            $this->assertNotSame('-0.00', $share, 'No share is negative zero.');
        }
    }

    /**
     * @param  list<string>  $shares
     */
    private function assertSpreadAtMostOnePaisa(array $shares): void
    {
        $spread = Money::sub(Money::max(...$shares), Money::min(...$shares));

        $this->assertLessThanOrEqual(0, Money::compare($spread, '0.01'), 'Equal parts differ by at most one paisa: '.implode(', ', $shares));
    }

    /**
     * @param  list<string>  $shares
     */
    private function assertEverySharecarriesTheSignOf(string $amount, array $shares): void
    {
        foreach ($shares as $share) {
            if (Money::isNegative($amount)) {
                $this->assertFalse(Money::isPositive($share), 'A negative amount never produces a positive share.');
            } else {
                $this->assertFalse(Money::isNegative($share), 'A positive amount never produces a negative share.');
            }
        }
    }
}
