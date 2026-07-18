<?php

namespace App\Enums;

enum AuditAction: string
{
    case Login = 'login';
    case Logout = 'logout';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case Approve = 'approve';
    case Cancel = 'cancel';
    case Payment = 'payment';
    case PermissionUpdate = 'permission_update';
    case RoleUpdate = 'role_update';
    case PaymentConfirm = 'payment_confirm';
    case PaymentReject = 'payment_reject';
    case BookingSchedule = 'booking_schedule';
    case BookingStart = 'booking_start';
    case BookingComplete = 'booking_complete';
    case BookingClose = 'booking_close';
    case BookingCancel = 'booking_cancel';
    case StoreOrderStatusChange = 'store_order_status_change';
    case QuotationAssign = 'quotation_assign';
    case QuotationIssue = 'quotation_issue';
    case QuotationRevision = 'quotation_revision';
    case QuotationDiscussionReply = 'quotation_discussion_reply';
    case QuotationCloseDiscussion = 'quotation_close_discussion';
    case QuotationExpire = 'quotation_expire';
    case QuotationCancel = 'quotation_cancel';
    case QuotationAdminAccept = 'quotation_admin_accept';
    case HeroBannerCreate = 'hero_banner_create';
    case HeroBannerUpdate = 'hero_banner_update';
    case HeroBannerDelete = 'hero_banner_delete';
    case HeroBannerPublish = 'hero_banner_publish';
    case HeroBannerHide = 'hero_banner_hide';
    case GalleryCreate = 'gallery_create';
    case GalleryUpdate = 'gallery_update';
    case GalleryDelete = 'gallery_delete';
    case FaqCreate = 'faq_create';
    case FaqUpdate = 'faq_update';
    case FaqDelete = 'faq_delete';
    case ServiceFeatureToggle = 'service_feature_toggle';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
