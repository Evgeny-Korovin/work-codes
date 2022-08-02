<?php

namespace App\Actions;

class UsersByWorkPlaceRoleAction
{
    /**
     * @param $workPlaceId
     * @param $roleName
     * @return array
     */
    public function handle($workPlaceId, $roleName) : array
    {
        $result = \DB::table('users as u')
                    ->where('u.work_place_id', $workPlaceId)
                    ->select('u.id')
                    ->leftJoin('model_has_roles as mhr', 'mhr.model_id', '=', 'u.id')
                    ->leftJoin('roles as r', 'r.id', '=', 'mhr.role_id')
                    ->whereIn('r.name', $roleName)
                    ->get()
                    ->toArray();

        $users = [];
        foreach ($result as $item) {
            $users[] = $item->id;
        }

        return $users;
    }
}
