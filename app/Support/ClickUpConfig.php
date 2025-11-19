<?php

namespace App\Support;

class ClickUpConfig
{
    /**
     * Get the primary ClickUp team id.
     */
    public static function teamId(): ?string
    {
        $teamId = config('clickup.team_id');
        if ($teamId !== null && $teamId !== '') {
            return (string) $teamId;
        }

        $teamIds = config('clickup.team_ids', []);
        if (is_array($teamIds) && isset($teamIds[0]) && $teamIds[0] !== '') {
            return (string) $teamIds[0];
        }

        return null;
    }

    /**
     * Get all configured ClickUp team ids.
     *
     * @return array<int,string>
     */
    public static function teamIds(): array
    {
        $ids = [];

        $primary = config('clickup.team_id');
        if ($primary !== null && $primary !== '') {
            $ids[] = (string) $primary;
        }

        $extra = config('clickup.team_ids', []);
        if (is_array($extra)) {
            foreach ($extra as $value) {
                if ($value !== null && $value !== '') {
                    $ids[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Get all configured ClickUp space ids.
     *
     * @return array<int,string>
     */
    public static function spaceIds(): array
    {
        $ids = [];

        $primary = config('clickup.space_id');
        if ($primary !== null && $primary !== '') {
            $ids[] = (string) $primary;
        }

        $extra = config('clickup.space_ids', []);
        if (is_array($extra)) {
            foreach ($extra as $value) {
                if ($value !== null && $value !== '') {
                    $ids[] = (string) $value;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}


