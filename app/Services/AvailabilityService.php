<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;

class AvailabilityService
{
    // Horario de trabajo (puedes moverlo a config)
    private const WORK_START = 9;  // 9:00
    private const WORK_END = 19;   // 19:00
    private const SLOT_INTERVAL = 15; // minutos

    /**
     * Obtiene slots disponibles para una fecha y duración específica
     */
    public function getAvailableSlots(string $date, int $durationMinutes): array
    {
        $targetDate = Carbon::parse($date);
        
        // Validar que sea día laboral futuro
        if ($targetDate->isPast() || $targetDate->isWeekend()) {
            return [];
        }

        $slots = [];
        $currentSlot = $targetDate->copy()->setTime(self::WORK_START, 0);
        $endOfDay = $targetDate->copy()->setTime(self::WORK_END, 0);

        // Obtener todas las citas del día
        $appointments = Appointment::whereDate('starts_at', $targetDate->toDateString())
            ->where('status', 'scheduled')
            ->orderBy('starts_at')
            ->get();

        while ($currentSlot->copy()->addMinutes($durationMinutes)->lte($endOfDay)) {
            $slotEnd = $currentSlot->copy()->addMinutes($durationMinutes);
            
            // Verificar si hay conflicto con alguna cita
            $hasConflict = $appointments->contains(function ($appointment) use ($currentSlot, $slotEnd) {
                return $this->hasTimeOverlap(
                    $currentSlot,
                    $slotEnd,
                    $appointment->starts_at,
                    $appointment->ends_at
                );
            });

            if (!$hasConflict) {
                $slots[] = [
                    'start' => $currentSlot->copy(),
                    'end' => $slotEnd->copy(),
                    'display' => $currentSlot->format('H:i'),
                ];
            }

            $currentSlot->addMinutes(self::SLOT_INTERVAL);
        }

        return $slots;
    }

    /**
     * Verifica si un slot específico está disponible
     */
    public function isSlotAvailable(Carbon $start, Carbon $end): bool
    {
        $conflicts = Appointment::where('status', 'scheduled')
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('starts_at', [$start, $end])
                    ->orWhereBetween('ends_at', [$start, $end])
                    ->orWhere(function ($q) use ($start, $end) {
                        $q->where('starts_at', '<=', $start)
                          ->where('ends_at', '>=', $end);
                    });
            })
            ->count();

        return $conflicts === 0;
    }

    /**
     * Encuentra el próximo slot disponible desde una fecha/hora
     */
    public function findNextAvailableSlot(Carbon $from, int $durationMinutes): ?array
    {
        $maxDays = 30;
        $currentDate = $from->copy()->startOfDay();

        for ($i = 0; $i < $maxDays; $i++) {
            $slots = $this->getAvailableSlots($currentDate->toDateString(), $durationMinutes);
            
            foreach ($slots as $slot) {
                if ($slot['start']->gte($from)) {
                    return $slot;
                }
            }

            $currentDate->addDay();
        }

        return null;
    }

    /**
     * Verifica solapamiento entre dos rangos de tiempo
     */
    private function hasTimeOverlap(Carbon $start1, Carbon $end1, Carbon $start2, Carbon $end2): bool
    {
        return $start1->lt($end2) && $end1->gt($start2);
    }
}
