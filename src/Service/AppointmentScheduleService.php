<?php

declare(strict_types=1);

namespace ProOceanVan\Service;

use ProOceanVan\Domain\RequestStatus;
use ProOceanVan\Domain\WorkState;
use ProOceanVan\Repository\AppointmentRepository;
use ProOceanVan\Repository\CommunicationRepository;
use ProOceanVan\Repository\RequestRepository;

final class AppointmentScheduleService
{
    public function confirm(int $requestId, array $input, string $publicCity = '', string $notes = ''): array
    {
        $existingOnly = ($input['schedule_mode'] ?? '') === 'existing';
        $overridePreference = in_array($input['override_request_dates'] ?? false, [true, 1, '1'], true);
        $days = AppointmentDates::fromSelection($input);
        if (! $existingOnly && ! $days) {
            return ['notice' => 'schedule-invalid'];
        }
        if ($days && AppointmentDates::parse($days[0])->diff(AppointmentDates::parse($days[count($days) - 1]))->days >= AppointmentDates::MAX_DAYS) {
            return ['notice' => 'schedule-invalid'];
        }

        $appointments = new AppointmentRepository();
        return $appointments->withScheduleLocks($requestId, $days, function () use ($requestId, $days, $existingOnly, $overridePreference, $publicCity, $notes, $appointments): array {
            $requests = new RequestRepository();
            $request = $requests->find($requestId);
            if (! $request || in_array((string) $request['work_state'], [WorkState::REJECTED, WorkState::CANCELLED], true)) {
                return ['notice' => 'response-closed'];
            }
            $current = $appointments->forRequest($requestId, true);
            if ($existingOnly) {
                return $current ? ['appointments' => $current] : ['notice' => 'schedule-invalid'];
            }
            $newDays = array_values(array_diff($days, array_column($current, 'appointment_date')));
            if (! $newDays) {
                return ['notice' => 'schedule-already-confirmed'];
            }
            $available = (new AvailabilityService())->requestableDates(
                (string) $request['state_code'], $newDays[0], $newDays[count($newDays) - 1], AppointmentDates::WEEKDAYS
            );
            foreach ($newDays as $day) {
                if ((! $overridePreference && ! AppointmentDates::fitsRequest($day, $request)) || ! in_array($day, $available, true)) {
                    return ['notice' => 'schedule-conflict', 'date' => $day];
                }
            }

            $route = [
                'start_label' => (string) get_option('pov_default_start_label', ''),
                'start_latitude' => is_numeric(get_option('pov_default_start_latitude')) ? (float) get_option('pov_default_start_latitude') : null,
                'start_longitude' => is_numeric(get_option('pov_default_start_longitude')) ? (float) get_option('pov_default_start_longitude') : null,
                'distance_km' => is_numeric($request['route_distance_km'] ?? null) ? (float) $request['route_distance_km'] : null,
                'cost' => is_numeric($request['route_cost'] ?? null) ? (float) $request['route_cost'] : null,
            ];
            return $this->transaction(function () use ($requestId, $request, $requests, $appointments, $newDays, $publicCity, $route, $notes): array {
                foreach ($newDays as $day) {
                    if ($appointments->createFromRequest($request, $day, $publicCity ?: (string) $request['city'], $route, $notes, true) <= 0) {
                        return ['notice' => 'schedule-conflict', 'date' => $day];
                    }
                }
                if (! $requests->updateStatus($requestId, RequestStatus::CONFIRMED, WorkState::ACCEPTED, [
                    'confirmed_at' => ($request['confirmed_at'] ?? '') ?: current_time('mysql'),
                    'closed_at' => null,
                ]) || ! $this->record($requestId, 'Einsatztage bestätigt', implode(', ', array_map([GermanDateFormatter::class, 'short'], $newDays)))) {
                    return ['notice' => 'confirm-failed'];
                }
                $confirmed = $appointments->forRequest($requestId, true);
                return $confirmed ? ['appointments' => $confirmed] : ['notice' => 'confirm-failed'];
            });
        }) ?? ['notice' => 'confirm-failed'];
    }

    public function changeDay(int $requestId, int $appointmentId, string $action, string $date, string $reason): array
    {
        if (! in_array($action, ['move', 'cancel', 'restore'], true) || ($action !== 'cancel' && ! AppointmentDates::parse($date))) {
            return ['notice' => 'schedule-invalid'];
        }
        $appointments = new AppointmentRepository();
        return $appointments->withRequestLock($requestId, function () use ($requestId, $appointmentId, $action, $date, $reason, $appointments): array {
            $item = $appointments->find($appointmentId);
            if (! $item || (int) $item['request_id'] !== $requestId) {
                return ['notice' => 'schedule-invalid'];
            }
            $old = (string) $item['appointment_date'];
            $active = (string) $item['status'] === 'confirmed';
            if ($action === 'restore' ? $active : ! $active) {
                return ['notice' => 'schedule-invalid'];
            }
            if ($action === 'move' && $old === $date) {
                return [];
            }
            return $appointments->withScheduleLocks($requestId, $action === 'cancel' ? [$old] : [$old, $date], function () use ($requestId, $appointmentId, $action, $date, $reason, $appointments, $item, $old): array {
                if ($action !== 'cancel' && ! (new AvailabilityService())->requestableDates(
                    (string) $item['state_code'], $date, $date, AppointmentDates::WEEKDAYS
                )) {
                    return ['notice' => 'schedule-conflict', 'date' => $date];
                }
                return $this->transaction(function () use ($requestId, $appointmentId, $action, $date, $reason, $appointments, $old): array {
                    $requests = new RequestRepository();
                    $request = $requests->find($requestId);
                    if (! $request || ($action !== 'cancel' && $request['work_state'] === WorkState::REJECTED)) {
                        return ['notice' => 'response-closed'];
                    }
                    if ($action === 'cancel') {
                        if (! $appointments->cancel($appointmentId, $reason)) {
                            return ['notice' => 'confirm-failed'];
                        }
                        if (! $appointments->existsForRequest($requestId) && ! $requests->updateStatus(
                            $requestId, (string) $request['main_status'], WorkState::CANCELLED, ['closed_at' => current_time('mysql')]
                        )) {
                            return ['notice' => 'confirm-failed'];
                        }
                        $message = 'Abgesagt: ' . GermanDateFormatter::full($old) . ($reason !== '' ? ' · ' . $reason : '');
                    } else {
                        if (! $appointments->reschedule($appointmentId, $date)) {
                            return ['notice' => 'schedule-conflict', 'date' => $date];
                        }
                        if ($action === 'restore' && ! $requests->updateStatus($requestId, RequestStatus::CONFIRMED, WorkState::ACCEPTED, ['closed_at' => null])) {
                            return ['notice' => 'confirm-failed'];
                        }
                        $message = $action === 'restore'
                            ? 'Reaktiviert: ' . GermanDateFormatter::full($date)
                            : GermanDateFormatter::full($old) . ' → ' . GermanDateFormatter::full($date);
                    }
                    return $this->record($requestId, 'Einsatztag geändert', $message) ? [] : ['notice' => 'confirm-failed'];
                });
            }) ?? ['notice' => 'confirm-failed'];
        }) ?? ['notice' => 'confirm-failed'];
    }

    private function transaction(callable $write): array
    {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) {
            return ['notice' => 'confirm-failed'];
        }
        $committed = false;
        try {
            $result = $write();
            if (isset($result['notice'])) {
                return $result;
            }
            $committed = $wpdb->query('COMMIT') !== false;
            return $committed ? $result : ['notice' => 'confirm-failed'];
        } finally {
            if (! $committed) {
                $wpdb->query('ROLLBACK');
            }
        }
    }

    private function record(int $requestId, string $subject, string $message): bool
    {
        return (new CommunicationRepository())->record($requestId, [
            'direction' => 'system',
            'communication_type' => 'appointment_schedule',
            'subject' => $subject,
            'message' => $message,
            'sender_name' => wp_get_current_user()->display_name,
            'delivery_status' => 'recorded',
        ]) > 0;
    }
}
