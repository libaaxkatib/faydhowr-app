<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\DataTransferObjects\Home\CreateBeforeAfterItemData;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\BeforeAfterItem;
use Illuminate\Support\Facades\DB;

class CreateBeforeAfterItemAction
{
    public function __construct(private BeforeAfterItemRepositoryInterface $items) {}

    public function handle(Admin $admin, CreateBeforeAfterItemData $data): BeforeAfterItem
    {
        $item = DB::transaction(
            fn (): BeforeAfterItem => $this->items->create($data),
        );

        event(AuditEvent::record(
            action: AuditAction::GalleryCreate,
            admin: $admin,
            description: 'Before and after gallery item created.',
            entityType: BeforeAfterItem::class,
            entityId: $item->id,
            metadata: [
                'title' => $item->title,
                'service_id' => $item->service_id,
                'is_active' => $item->is_active,
            ],
        ));

        return $item->load('service');
    }
}
