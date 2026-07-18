<?php

namespace App\Actions\Home;

use App\Contracts\Home\Repositories\FaqRepositoryInterface;
use App\DataTransferObjects\Home\CreateFaqData;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Faq;
use Illuminate\Support\Facades\DB;

class CreateFaqAction
{
    public function __construct(private FaqRepositoryInterface $faqs) {}

    public function handle(Admin $admin, CreateFaqData $data): Faq
    {
        $faq = DB::transaction(
            fn (): Faq => $this->faqs->create($data),
        );

        event(AuditEvent::record(
            action: AuditAction::FaqCreate,
            admin: $admin,
            description: 'FAQ created.',
            entityType: Faq::class,
            entityId: $faq->id,
            metadata: [
                'question' => $faq->question,
                'is_active' => $faq->is_active,
            ],
        ));

        return $faq;
    }
}
