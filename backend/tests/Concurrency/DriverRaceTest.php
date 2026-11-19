<?php

/*
 * Real concurrency for the driver's side of dispatch: forked processes, each with its own PostgreSQL connection, act on the same delivery at the
 * same moment. Uses committed data (DatabaseTruncation), so it is excluded from the transactional Feature suite.
 * Run: ./vendor/bin/pest tests/Concurrency/DriverRaceTest.php
 */

require_once __DIR__.'/../Feature/Orders/OpsFixtures.php';

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryDecline;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Fulfillment\Services\DispatchService;
use App\Support\DomainException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, DatabaseTruncation::class);

/** Fork $n children running $work($i); each returns an exit code: 0 done, 2 refused with a DomainException, 1 anything else. */
function raceDriver(int $n, Closure $work): array
{
    DB::disconnect();
    $pids = [];
    for ($i = 0; $i < $n; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            try {
                $work($i);
                $code = 0;
            } catch (DomainException) {
                $code = 2;
            } catch (Throwable $e) {
                fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
                $code = 1;
            }
            exit($code); // child
        }
        $pids[] = $pid;
    }
    $codes = [];
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
        $codes[] = pcntl_wexitstatus($status);
    }
    DB::purge();

    return $codes;
}

it('hands a job back once when the same driver taps six times at the same moment', function () {
    $d = opsAssignedDelivery();
    $delivery = $d->delivery;
    $driver = $d->driver;

    $codes = raceDriver(6, fn () => app(DispatchService::class)->declineJob(Delivery::findOrFail($delivery->id), $driver, 'too_far'));

    expect(array_count_values($codes))->toEqual([0 => 6])
        ->and(DeliveryDecline::where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(DB::table('delivery_status_history')->where('delivery_id', $delivery->id)->where('note', 'like', 'declined%')->count())->toBe(1)
        ->and($delivery->fresh()->status->value)->toBe('pending')->and($delivery->fresh()->driver_id)->toBeNull();
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('lets either the driver accept or the watchdog take the job back, never both', function () {
    $d = opsAssignedDelivery();
    DB::table('deliveries')->where('id', $d->delivery->id)->update(['assigned_at' => now()->subMinutes(10)]);
    $delivery = $d->delivery;
    $driver = $d->driver;

    $codes = raceDriver(2, function (int $i) use ($delivery, $driver) {
        $fresh = Delivery::findOrFail($delivery->id);
        if ($i === 0) {
            app(DeliveryService::class)->accept($fresh, $driver);
        } else {
            app(DeliveryService::class)->releaseUnaccepted($fresh, 'timeout', now()->subMinutes(5));
        }
    });

    $final = $delivery->fresh();
    $taken = DeliveryDecline::where('delivery_id', $delivery->id)->exists();
    expect(in_array(1, $codes, true))->toBeFalse();
    // Exactly one of the two won: accepted and still his, or taken back and waiting.
    expect($final->status->value === 'accepted' && $final->driver_id === $driver->id && ! $taken || $final->status->value === 'pending' && $final->driver_id === null && $taken)->toBeTrue();
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');

it('lets two watchdog runs at the same moment take a job back once', function () {
    $d = opsAssignedDelivery();
    DB::table('deliveries')->where('id', $d->delivery->id)->update(['assigned_at' => now()->subMinutes(10)]);
    $delivery = $d->delivery;

    $codes = raceDriver(4, fn () => app(DispatchService::class)->watchdog());

    expect(array_count_values($codes))->toEqual([0 => 4])
        ->and(DeliveryDecline::where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(DB::table('delivery_status_history')->where('delivery_id', $delivery->id)->where('note', 'taken back: timeout')->count())->toBe(1);
})->skip(! function_exists('pcntl_fork'), 'pcntl not available');
