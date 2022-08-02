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
