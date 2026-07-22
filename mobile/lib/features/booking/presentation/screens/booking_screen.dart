import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/theme/app_spacing.dart';
import '../providers/booking_providers.dart';
import '../widgets/booking_address_section.dart';
import '../widgets/booking_continue_button.dart';
import '../widgets/booking_notes_section.dart';
import '../widgets/booking_selected_service_card.dart';
import '../widgets/booking_summary_section.dart';
import '../widgets/booking_type_section.dart';
import '../widgets/booking_upload_section.dart';
import '../widgets/preferred_date_section.dart';
import '../widgets/preferred_time_section.dart';
import '../widgets/property_size_section.dart';
import '../widgets/property_type_section.dart';

/// Booking Module (Phase 1): full form layout, mock data only. No
/// payments, no backend, no booking submission — Continue is prepared
/// with disabled/enabled/loading visual states but creates nothing.
class BookingScreen extends ConsumerStatefulWidget {
  const BookingScreen({required this.serviceId, super.key});

  final String serviceId;

  @override
  ConsumerState<BookingScreen> createState() => _BookingScreenState();
}

class _BookingScreenState extends ConsumerState<BookingScreen> {
  /// Ephemeral demo-only flag: shows the Continue button's prepared
  /// [BookingContinueState.loading] state briefly when tapped while
  /// enabled, then reverts. Purely a UI-state demonstration — it does not
  /// touch the Riverpod draft, navigate anywhere, or create a booking.
  bool _isDemoLoading = false;

  @override
  void initState() {
    super.initState();
    // Riverpod disallows modifying a provider synchronously during a
    // widget life-cycle method (including `initState`) — defer to a
    // microtask, same as the framework's own suggested fix.
    Future.microtask(() {
      if (mounted) {
        ref.read(bookingDraftProvider.notifier).resetForService(widget.serviceId);
      }
    });
  }

  Future<void> _handleContinueTap() async {
    setState(() => _isDemoLoading = true);
    await Future<void>.delayed(const Duration(milliseconds: 900));
    if (mounted) {
      setState(() => _isDemoLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bool isComplete = ref.watch(bookingDraftProvider).isComplete;
    final BookingContinueState continueState = _isDemoLoading
        ? BookingContinueState.loading
        : isComplete
        ? BookingContinueState.enabled
        : BookingContinueState.disabled;

    return Scaffold(
      // Explicit (matches the default, but documents the intent): the
      // sticky Continue button lives in `body`, not a `bottomNavigationBar`
      // — resizing the body around the keyboard is what keeps Continue
      // above it (rather than pushed off-screen) while typing Notes.
      resizeToAvoidBottomInset: true,
      appBar: AppBar(title: const Text('Book Service')),
      body: Column(
        children: <Widget>[
          Expanded(
            child: SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  BookingSelectedServiceCard(serviceId: widget.serviceId),
                  const BookingTypeSection(),
                  const PropertyTypeSection(),
                  const PropertySizeSection(),
                  const PreferredDateSection(),
                  const PreferredTimeSection(),
                  const BookingAddressSection(),
                  const BookingNotesSection(),
                  const BookingUploadSection(),
                  BookingSummarySection(serviceId: widget.serviceId),
                  const SizedBox(height: AppSpacing.space6),
                ],
              ),
            ),
          ),
          BookingContinueButton(state: continueState, onPressed: _handleContinueTap),
        ],
      ),
    );
  }
}
