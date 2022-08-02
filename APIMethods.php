<?php

namespace App\Http\Controllers\Exchange;

use App\Actions\NotificationSendAction;
use App\Actions\UsersByWorkPlaceRoleAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\CRMController;
use App\Library\OneCAPI;
use App\Notifications\NotificationsProvider;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Documents\Entities\WorkingDocumentationChange;
use Modules\Items\Entities\Bill;
use Modules\Items\Entities\Item;
use Modules\Orders\Entities\Order;
use Modules\Platform\User\Entities\User;
use Modules\ProductionPlan\Entities\ProductionPlan;
use Modules\Accounts\Entities\Account;
use Modules\ProductionWorklog\Entities\ProductionWorklog;
use Modules\Projects\Entities\Project;
use Illuminate\Support\Facades\DB;
use function auth;
use function request;
use function response;

class OneCController extends Controller
{
    
public function workingDocumentationCreate(NotificationSendAction $actionSend, UsersByWorkPlaceRoleAction $actionUsers)
    {
        $credentials = request(['item_id', 'creator', 'change_description', 'changed_pages', 'document_type']);

        if (
            empty($credentials['item_id'])
            || empty($credentials['creator'])
            || $credentials['change_description'] == ''
            || $credentials['changed_pages'] == ''
            || $credentials['document_type'] == '') {
            return response()->json(['error' => 'not enough params', 'credentials' => $credentials], 500);
        }

        try {
            $created_at = Carbon::now()->setTimezone('Europe/Moscow')->toDateTimeString();

            $insertData = [
                'item_id' => $credentials['item_id'],
                'creator' => $credentials['creator'],
                'created_at' => $created_at,
                'change_description' => $credentials['change_description'],
                'changed_pages' => $credentials['changed_pages'],
                'document_type' => $credentials['document_type']
            ];

            $docChange = WorkingDocumentationChange::create($insertData);

            list($workPlaceId, $itemArticul) = $this->getWorkPlaceId($credentials['item_id']);

            $notificationMessage = 'Внесены изменения в документацию на изделие ' . $itemArticul;
            $usersIds = $actionUsers->handle($workPlaceId, ['itr']);
            $actionSend->handle($notificationMessage, $usersIds);

            return response()->json([
                'id' => $docChange->id,
                'item_id' => $docChange->item_id,
                'creator' => $docChange->creator,
                'created_at' => $docChange->created_at,
                'change_description' => $docChange->change_description,
                'changed_pages' => $docChange->changed_pages,
                'document_type' => $docChange->document_type
            ]);

        } catch (\Exception $e) {
            Log::error(json_encode(['error' => $e->getMessage(), 'code' => $e->getCode(), 'exception' => $e]));
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 520);
        }
    }

    public function workingDocumentationIssue(NotificationSendAction $actionSend, UsersByWorkPlaceRoleAction $actionUsers)
    {
        $credentials = request(['id', 'issued']);

        if (empty($credentials['id']) || empty($credentials['issued'])) {
            return response()->json(['error' => 'not enough params', 'credentials' => $credentials], 500);
        } else {
            $id = $credentials['id'];
            $issued = $credentials['issued'];
        }

        try {
            $issued_at = Carbon::now()->setTimezone('Europe/Moscow')->toDateTimeString();

            $docChange = WorkingDocumentationChange::find($id);

            if ($docChange) {
                $docChange->issued = $issued;
                $docChange->issued_at = $issued_at;
                $docChange->save();
            }

            list($workPlaceId, $itemArticul) = $this->getWorkPlaceId($docChange->item_id);

            $notificationMessage = 'Изменения в рабочей документации по изделию ' . $itemArticul . ' выданы в производство';
            $usersIds = $actionUsers->handle($workPlaceId, ['production_manager', 'master']);
            $actionSend->handle($notificationMessage, $usersIds);

            return response()->json([
                'id' => $docChange->id,
                'item_id' => $docChange->item_id,
                'creator' => $docChange->creator,
                'created_at' => $docChange->created_at,
                'change_description' => $docChange->change_description,
                'changed_pages' => $docChange->changed_pages,
                'document_type' => $docChange->document_type
            ]);

        } catch (\Exception $e) {
            Log::error(json_encode(['error' => $e->getMessage(), 'code' => $e->getCode(), 'exception' => $e]));
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 520);
        }
    }

    public function patchData()
    {
        $entityTables = [
            'order_items' => 'Modules\Items\Entities\Item',
            'orders' => 'Modules\Orders\Entities\Order',
            'calculations' => 'Modules\Calculations\Entities\Calculation',
            'problems' => 'Modules\ItemProblems\Entities\ItemProblem',
            'tasks' => 'Modules\Tasks\Entities\Task',
            'accounts' => 'Modules\Accounts\Entities\Account',
            'construction_drawings' => 'Modules\ConstructionDrawings\Entities\ConstructionDrawings',
            'contacts' => 'Modules\Contacts\Entities\Contact',
            'deficit' => 'Modules\Deficit\Entities\Deficit',
            'documents' => 'Modules\Documents\Entities\Document',
            'engineer_worklog' => 'Modules\EngineerWorklog\Entities\EngineerWorklog',
            'production_plan' => 'Modules\ProductionPlan\Entities\ProductionPlan',
            'production_worklog' => 'Modules\ProductionWorklog\Entities\ProductionWorklog',
            'production_timelog' => 'Modules\ProductionWorklog\Entities\ProductionTimelog',
            'projects' => 'Modules\Projects\Entities\Project',
            'properties' => 'Modules\Properties\Entities\Property',
            'returns_to_production' => 'Modules\ReturnsToProduction\Entities\ReturnToProduction',
            'vendor_registration' => 'Modules\VendorRegistration\Entities\VendorRegistration',
        ];

        $credentials = request(['table', 'ids', 'field', 'value']);

        if (empty($credentials['table']) || empty($credentials['ids']) || empty($credentials['field'])) {
            return response()->json(['error' => 'not enough params', 'credentials' => $credentials], 500);
        } else {
            $table = $credentials['table'];
            $ids = $credentials['ids'];
            $field = $credentials['field'];
            $value = $credentials['value'];
            if ($value == 'null') {
                $value = null;
            }
        }

        try {

            if (isset($entityTables[$table])) {

                foreach ($ids as $id) {
                    $entity = \App::make($entityTables[$table])::find($id);
                    if ($entity) {
                        $entity->{$field} = $value;
                        $entity->save();
                    }
                }

                $entities = \App::make($entityTables[$table])::whereIn('id', $ids)->get()->toArray();

                return response()->json($entities);

            }

        } catch (\Exception $e) {
            Log::error(json_encode(['error' => $e->getMessage(), 'code' => $e->getCode(), 'exception' => $e]));
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 520);
        }
    }

    public function postData()
    {
        $entityTables = [
            'order_items' => 'Modules\Items\Entities\Item',
            'orders' => 'Modules\Orders\Entities\Order',
            'calculations' => 'Modules\Calculations\Entities\Calculation',
            'problems' => 'Modules\ItemProblems\Entities\ItemProblem',
            'tasks' => 'Modules\Tasks\Entities\Task',
            'accounts' => 'Modules\Accounts\Entities\Account',
            'construction_drawings' => 'Modules\ConstructionDrawings\Entities\ConstructionDrawings',
            'contacts' => 'Modules\Contacts\Entities\Contact',
            'deficit' => 'Modules\Deficit\Entities\Deficit',
            'documents' => 'Modules\Documents\Entities\Document',
            'engineer_worklog' => 'Modules\EngineerWorklog\Entities\EngineerWorklog',
            'production_plan' => 'Modules\ProductionPlan\Entities\ProductionPlan',
            'production_worklog' => 'Modules\ProductionWorklog\Entities\ProductionWorklog',
            'production_timelog' => 'Modules\ProductionWorklog\Entities\ProductionTimelog',
            'projects' => 'Modules\Projects\Entities\Project',
            'properties' => 'Modules\Properties\Entities\Property',
            'returns_to_production' => 'Modules\ReturnsToProduction\Entities\ReturnToProduction',
            'vendor_registration' => 'Modules\VendorRegistration\Entities\VendorRegistration',
        ];

        $credentials = request(['table', 'entries']);

        if (empty($credentials['table']) || empty($credentials['entries'])) {
            return response()->json(['error' => 'not enough params', 'credentials' => $credentials], 500);
        } else {
            $table = $credentials['table'];
            $entries = $credentials['entries'];
        }

        try {

            $ids = [];

            if (isset($entityTables[$table]))  {

                foreach ($entries as $entry) {
                    $entity = new $entityTables[$table];
                    foreach ($entry as $key => $value) {
                        $entity->{$key} = $value;
                    }
                    $entity->save();

                    $ids[] = $entity->id;
                }

                $response = \App::make($entityTables[$table])->whereIn('id', $ids)->get()->toArray();

                return response()->json($response);
            }

        } catch (\Exception $e) {
            Log::error(json_encode(['error' => $e->getMessage(), 'code' => $e->getCode(), 'exception' => $e]));
            return response()->json(['error' => $e->getMessage()], $e->getCode() ?: 520);
        }
    }
}
