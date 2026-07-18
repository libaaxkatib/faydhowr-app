<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Faq;
use Illuminate\Support\Facades\DB;

class UpdateFaqAction
{
    public function __construct(private FaqRepositoryInterface $faqs) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Admin $admin, Faq $faq, array $attributes): Faq
    {
        $faq = DB::transaction(
            fn (): Faq => $this->faqs->update($faq, $attributes),
        );

        event(AuditEvent::record(
            action: AuditAction::FaqUpdate,
            admin: $admin,
            description: 'FAQ updated.',
            entityType: Faq::class,
            entityId: $faq->id,
            metadata: [
                'changed_fields' => array_keys($attributes),
                'is_active' => $faq->is_active,
            ],
        ));

        return $faq;
    }
}
