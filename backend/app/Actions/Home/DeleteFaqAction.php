<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Faq;
use Illuminate\Support\Facades\DB;

class DeleteFaqAction
{
    public function __construct(private FaqRepositoryInterface $faqs) {}

    public function handle(Admin $admin, Faq $faq): void
    {
        DB::transaction(function () use ($faq): void {
            $this->faqs->delete($faq);
        });

        event(AuditEvent::record(
            action: AuditAction::FaqDelete,
            admin: $admin,
            description: 'FAQ deleted.',
            entityType: Faq::class,
            entityId: $faq->id,
            metadata: ['question' => $faq->question],
        ));
    }
}
