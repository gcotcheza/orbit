<?php

declare(strict_types=1);

namespace App\Infrastructure\Discovery;

use DateTimeZone;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use App\Domain\Discovery\SweptFare;
use Illuminate\Http\Client\Factory as Http;
use App\Application\Ports\OriginSweepProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use App\Infrastructure\Concerns\TravelpayoutsEnvelope;

/**
 * "What is cheap from Amsterdam, to anywhere" — `/v2/prices/latest` with the destination left
 * off. `one_way=true`, `limit` sent, `distance` never read (docs/BUSINESS-LOGIC.md §16).
 */
final readonly class TravelpayoutsSweepProvider implements OriginSweepProvider
{
    use TravelpayoutsEnvelope;

    private const PATH = '/v2/prices/latest';

    /** What this adapter's four envelope guards say; the guards themselves live in the seam. */
    private const SAYS = [
        'unreachable' => 'Could not reach Travelpayouts for an origin sweep.',
        'refused'     => 'Travelpayouts refused an origin sweep.',
        'notJson'     => 'Travelpayouts answered an origin sweep with something that is not a JSON object.',
        'currency'    => 'Travelpayouts answered an origin sweep in the wrong currency.',
    ];

    public function __construct(
        private Http $http,
        private LoggerInterface $logger,
        private Cache $cache,
        private string $baseUrl,
        private string $token,
        private float $connectTimeout,
        private float $timeout,
        private int $retries,
        private int $retryDelayMs,
        private int $warnEveryMinutes,
        private int $limit,
    ) {
        if (trim($this->token) === '') {
            throw new InvalidArgumentException(
                'The Travelpayouts origin-sweep provider is selected but TRAVELPAYOUTS_TOKEN is empty. '
                .'Set the token, or set ORBIT_SWEEP_PROVIDER=fake.',
            );
        }
    }

    /**
     * @return list<SweptFare>
     */
    public function cheapestFromOrigin(string $originIata): array
    {
        $fares = [];

        foreach ($this->entries($originIata) as $entry) {
            $fare = $this->fare($entry);

            if ($fare !== null) {
                $fares[] = $fare;
            }
        }

        return $fares;
    }

    /**
     * The whole cached answer for an origin, or none if anything went wrong.
     *
     * @return list<mixed>
     */
    private function entries(string $origin): array
    {
        return $this->fetch(self::PATH, [
            'origin' => $origin,
            // ⚠ No `destination` -- its absence is what turns a
            // one-route lookup into a sweep of everywhere.
            'currency'    => self::CURRENCY,
            'one_way'     => 'true',
            'period_type' => 'year',
            'limit'       => $this->limit,
        ], ['origin' => $origin], self::SAYS);
    }

    /**
     * One entry, or null if it is not one we can believe.
     */
    private function fare(mixed $entry): ?SweptFare
    {
        if (! is_array($entry)) {
            return null;
        }

        // `actual` false means Travelpayouts itself no longer stands behind
        // the price -- not a fare this screen can claim is real.
        if (($entry['actual'] ?? true) === false) {
            return null;
        }

        /** @var mixed $destination */
        $destination = $entry['destination'] ?? null;

        // Must be 3 uppercase letters -- it becomes half of a route code,
        // and routes/web.php constrains that path segment the same way.
        if (! is_string($destination) || preg_match('/^[A-Z]{3}$/', $destination) !== 1) {
            return null;
        }

        /** @var mixed $value */
        $value = $entry['value'] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        // A free flight is a bug, not a deal -- it would be the best "find"
        // ever, at 0 €/km, at the top of the list, forever.
        if ($value <= 0) {
            return null;
        }

        /** @var mixed $date */
        $date = $entry['depart_date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        // '!' zeroes unmentioned fields (midnight); UTC because a sweep has
        // no caller-supplied window timezone to inherit, unlike its siblings.
        $departure = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($departure === false || $departure->format('Y-m-d') !== $date) {
            return null;
        }

        return new SweptFare($destination, $departure, (int) round($value * 100), $this->foundAt($entry));
    }

    /**
     * Own key, not the other two adapters' -- a shared key would let the
     * 05:20 sweep's failure silence the 06:10/06:40 polls' own reports.
     */
    private function warnKey(): string
    {
        return 'orbit:travelpayouts:sweep:warned';
    }
}
