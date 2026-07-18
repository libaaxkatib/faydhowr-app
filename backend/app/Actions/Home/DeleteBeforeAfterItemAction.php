<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\BeforeAfterItem;
use Illuminate\Support\Facades\DB;

class DeleteBeforeAfterItemAction
{
    public function __construct(private BeforeAfterItemRepositoryInterface $items) {}

    public function handle(Admin $admin, BeforeAfterItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $this->items->delete($item);
        });

        event(AuditEvent::record(
            action: AuditAction::GalleryDelete,
            admin: $admin,
            description: 'Before and after gallery item deleted.',
            entityType: BeforeAfterItem::class,
            entityId: $item->id,
            metadata: ['title' => $item->title],
        ));
    }
}
