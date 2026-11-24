<?php

declare(strict_types=1);

namespace Sattleme\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sattleme\PrestaShop\Payment\Schedule;

/**
 * The background checks' schedule, as pretix's "Background checks": every minute while payable,
 * just before and just after the end of the grace period, every five minutes until final, less
 * often after failures, given up after 90 days.
 */
final class ScheduleTest extends TestCase
{
    private const EXPIRES = 1_800_000_000;
    private const END = self::EXPIRES + Schedule::GRACE_SECONDS;

    public function testEveryMinuteWhileTheInvoiceCanBePaid(): void
    {
        self::assertSame(60, Schedule::nextDelay(self::EXPIRES, 0, self::EXPIRES - 900));
        self::assertSame(60, Schedule::nextDelay(self::EXPIRES, 0, self::EXPIRES));
        // Failures change nothing while it can be paid.
        self::assertSame(60, Schedule::nextDelay(self::EXPIRES, 5, self::EXPIRES - 300));
    }

    public function testTheNextCheckCountsFromTheRunsStartLessTheSlack(): void
    {
        $run = self::EXPIRES - 900;
        self::assertSame($run + 60 - Schedule::SLACK, Schedule::nextCheckAt(self::EXPIRES, 0, $run));
        // The checks around the end stay on their side of it.
        self::assertLessThan(self::END, Schedule::nextCheckAt(self::EXPIRES, 0, self::END - 50));
        self::assertGreaterThan(self::END, Schedule::nextCheckAt(self::EXPIRES, 0, self::END - 5));
    }

    public function testJustBeforeAndJustAfterTheEndOfTheGracePeriod(): void
    {
        // 40 s before the mark 10 s before the end: the mark.
        self::assertSame(40, Schedule::nextDelay(self::EXPIRES, 0, self::END - 50));
        // Between the two marks: the one 10 s after the end.
        self::assertSame(15, Schedule::nextDelay(self::EXPIRES, 0, self::END + 10 - 15));
        // Never sooner than a page's checks.
        self::assertSame(Schedule::WEB_INTERVAL, Schedule::nextDelay(self::EXPIRES, 0, self::END - 12));
        self::assertSame(Schedule::WEB_INTERVAL, Schedule::nextDelay(self::EXPIRES, 0, self::END + 9));
    }

    public function testEveryFiveMinutesOnceEndedUntilFinal(): void
    {
        self::assertSame(300, Schedule::nextDelay(self::EXPIRES, 0, self::END + 10));
        self::assertSame(300, Schedule::nextDelay(self::EXPIRES, 0, self::END + 86400));
    }

    public function testLessAndLessOftenAfterFailuresOnceEnded(): void
    {
        $delays = array_map(static fn (int $errors): int => Schedule::nextDelay(self::EXPIRES, $errors, self::END + 3600), range(1, 8));
        self::assertSame([60, 120, 240, 480, 960, 1800, 1800, 1800], $delays);
        self::assertSame(Schedule::MAX_BACKOFF, Schedule::nextDelay(self::EXPIRES, 1000, self::END + 3600));
    }

    /**
     * The checks a cron calling every minute runs, for each of the 60 ways its minute may fall
     * against an invoice's end, each check due again from its run's start (nextCheckAt): one at
     * every run while it can be paid, when the last one before the end of the grace period comes,
     * and the first after it. README.md says it.
     */
    public function testWhatACronCallingEveryMinuteRunsAroundTheEndOfTheGracePeriod(): void
    {
        $expiresAt = self::EXPIRES + 900;
        $end = $expiresAt + Schedule::GRACE_SECONDS;
        $before = [];
        $after = [];
        $gaps = [];
        for ($offset = 0; $offset < 60; ++$offset) {
            $due = self::EXPIRES;
            $last = null;
            $next = null;
            for ($run = self::EXPIRES + $offset; $run < $end + 600; $run += 60) {
                if ($run < $due) {
                    continue;
                }
                if ($run < $end) {
                    if ($last !== null) {
                        $gaps[$run - $last] = true;
                    }
                    $last = $run;
                } else {
                    $next ??= $run;
                }
                $due = Schedule::nextCheckAt($expiresAt, 0, $run);
            }
            self::assertNotNull($last);
            self::assertNotNull($next);
            $before[] = $end - $last;
            $after[] = $next - $end;
        }
        self::assertNotEmpty($before);
        self::assertNotEmpty($after);
        self::assertSame([1, 60], [min($before), max($before)]);
        self::assertCount(10, array_filter($before, static fn (int $seconds): bool => $seconds <= Schedule::END_MARGIN), 'one case out of six');
        self::assertLessThan(60, max($after));
        // While it can be paid, every run checks it: never two minutes apart.
        self::assertSame([60], array_keys($gaps));
        $readme = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/README.md'));
        self::assertStringContainsString('the last background check before the end of an invoice\'s grace period comes between 1 and 60 seconds before it (10 seconds or less in one case out of six, as the cron\'s minute falls), and the next within a minute after it.', $readme);
    }

    public function testGivenUpNinetyDaysAfterItWasMade(): void
    {
        self::assertFalse(Schedule::isGivenUp(self::EXPIRES, self::EXPIRES + 90 * 86400 - 1));
        self::assertTrue(Schedule::isGivenUp(self::EXPIRES, self::EXPIRES + 90 * 86400));
    }
}
