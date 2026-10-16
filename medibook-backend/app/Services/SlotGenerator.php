<?php

namespace App\Services;

use App\Models\Doctor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SlotGenerator
{
    /**
     * Free slots for one calendar day in the doctor's own timezone.
     *
     * @return Collection<int, array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, local_time: string}>
     */
    public function forDate(Doctor $doctor, string $date): Collection
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, $doctor->timezone);

        $ranges = $doctor->availabilities()
            ->where('weekday', $day->dayOfWeek)
            ->orderBy('start_time')
            ->get();

        if ($ranges->isEmpty()) {
            return collect();
        }

        $busy = $this->busyPeriods($doctor, $day, $day->endOfDay());
        $now = CarbonImmutable::instance(now());
        $length = $doctor->slot_minutes;
        $slots = collect();

        foreach ($ranges as $range) {
            $cursor = $day->setTimeFromTimeString($range->start_time);
            $rangeEnd = $day->setTimeFromTimeString($range->end_time);

            while ($cursor->addMinutes($length) <= $rangeEnd) {
                $start = $cursor;
                $end = $cursor->addMinutes($length);

                if ($start > $now && ! $this->overlapsBusy($start, $end, $busy)) {
                    $slots->push([
                        'starts_at' => $start->utc(),
                        'ends_at' => $end->utc(),
                        'local_time' => $start->format('H:i'),
                    ]);
                }

                $cursor = $end;
            }
        }

        return $slots->values();
    }

   
    public function isSlotFree(Doctor $doctor, CarbonInterface $startsAt): bool
    {
        $date = CarbonImmutable::instance($startsAt)
            ->setTimezone($doctor->timezone)
            ->format('Y-m-d');

        return $this->forDate($doctor, $date)
            ->contains(fn (array $slot) => $slot['starts_at']->equalTo($startsAt));
    }

    
    private function busyPeriods(Doctor $doctor, CarbonImmutable $dayStart, CarbonImmutable $dayEnd): Collection
    {
        $from = $dayStart->utc();
        $to = $dayEnd->utc();

       
        $appointments = $doctor->appointments()
            ->whereNotNull('slot_lock')
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at']);

        $timeOffs = $doctor->timeOffs()
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at']);

        return $appointments->concat($timeOffs)->map(fn ($row) => [
            'start' => CarbonImmutable::instance($row->starts_at),
            'end' => CarbonImmutable::instance($row->ends_at),
        ]);
    }

    private function overlapsBusy(CarbonImmutable $start, CarbonImmutable $end, Collection $busy): bool
    {
        return $busy->contains(fn (array $p) => $start < $p['end'] && $end > $p['start']);
    }
}