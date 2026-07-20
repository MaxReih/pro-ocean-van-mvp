<?php

declare(strict_types=1);

namespace ProOceanVan\Security;

final class Capabilities
{
    public const ACCESS_PORTAL = 'pov_access_portal';
    public const VIEW_REQUESTS = 'pov_view_requests';
    public const MANAGE_REQUESTS = 'pov_manage_requests';
    public const SEND_RESPONSES = 'pov_send_responses';
    public const VIEW_TOURS = 'pov_view_tours';
    public const MANAGE_TOURS = 'pov_manage_tours';
    public const VIEW_CALENDAR = 'pov_view_calendar';
    public const MANAGE_CALENDAR = 'pov_manage_calendar';
    public const VIEW_STATISTICS = 'pov_view_statistics';
    public const EXPORT_CALENDAR = 'pov_export_calendar';
    public const MANAGE_PRIVACY = 'pov_manage_privacy';

    public const TEAM_ROLE = 'pov_van_team';
    public const READER_ROLE = 'pov_van_reader';

    public static function all(): array
    {
        return [
            self::ACCESS_PORTAL,
            self::VIEW_REQUESTS,
            self::MANAGE_REQUESTS,
            self::SEND_RESPONSES,
            self::VIEW_TOURS,
            self::MANAGE_TOURS,
            self::VIEW_CALENDAR,
            self::MANAGE_CALENDAR,
            self::VIEW_STATISTICS,
            self::EXPORT_CALENDAR,
            self::MANAGE_PRIVACY,
        ];
    }

    public static function syncRoles(): void
    {
        $teamCaps = array_fill_keys([
            'read',
            self::ACCESS_PORTAL,
            self::VIEW_REQUESTS,
            self::MANAGE_REQUESTS,
            self::SEND_RESPONSES,
            self::VIEW_TOURS,
            self::MANAGE_TOURS,
            self::VIEW_CALENDAR,
            self::MANAGE_CALENDAR,
            self::VIEW_STATISTICS,
            self::EXPORT_CALENDAR,
        ], true);
        $readerCaps = array_fill_keys([
            'read',
            self::ACCESS_PORTAL,
            self::VIEW_TOURS,
            self::VIEW_CALENDAR,
            self::VIEW_STATISTICS,
        ], true);

        self::syncRole(self::TEAM_ROLE, 'Ocean Van Team', $teamCaps);
        self::syncRole(self::READER_ROLE, 'Ocean Van Lesend', $readerCaps);

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (self::all() as $capability) {
                $administrator->add_cap($capability);
            }
        }
    }

    public static function removeRoles(): void
    {
        remove_role(self::TEAM_ROLE);
        remove_role(self::READER_ROLE);

        $administrator = get_role('administrator');
        if ($administrator) {
            foreach (self::all() as $capability) {
                $administrator->remove_cap($capability);
            }
        }
    }

    public static function viewForPage(string $page): string
    {
        return match ($page) {
            'pov-routes' => self::VIEW_TOURS,
            'pov-calendar' => self::VIEW_CALENDAR,
            'pov-statistics' => self::VIEW_STATISTICS,
            default => self::VIEW_REQUESTS,
        };
    }

    private static function syncRole(string $slug, string $label, array $capabilities): void
    {
        $role = get_role($slug);
        if (! $role) {
            add_role($slug, $label, $capabilities);
            $role = get_role($slug);
        }
        if (! $role) {
            return;
        }

        foreach (array_merge(['read'], self::all()) as $capability) {
            if (isset($capabilities[$capability])) {
                $role->add_cap($capability);
            } else {
                $role->remove_cap($capability);
            }
        }
    }
}
