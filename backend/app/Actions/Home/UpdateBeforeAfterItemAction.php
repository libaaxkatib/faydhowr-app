<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\BeforeAfterItemRepositoryInterface;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\BeforeAfterItem;
use Illuminate\Support\Facades\DB;

class UpdateBeforeAfterItemAction
{
    public function __construct(private BeforeAfterItemRepositoryInterface $items) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Admin $admin, BeforeAfterItem $item, array $attributes): BeforeAfterItem
    {
        $item = DB::transaction(
            fn (): BeforeAfterItem => $this->items->update($item, $attributes),
        );

        event(AuditEvent::record(
            action: AuditAction::GalleryUpdate,
            admin: $admin,
            description: 'Before and after gallery item updated.',
            entityType: BeforeAfterItem::class,
            entityId: $item->id,
            metadata: [
                'changed_fields' => array_keys($attributes),
                'is_active' => $item->is_active,
            ],
        ));

        return $item->load('service');
    }
}
