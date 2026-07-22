// Foundation + Authentication bootstrap smoke test.
//
// Guest browsing is a business rule (docs/02_SRS.md — "Value Before
// Login"): the app never forces login on launch. Splash always continues
// to Welcome (Authentication UX Revision); Welcome's Continue/swipe then
// branches to Onboarding (first time only) or Home (already onboarded).
// Login is triggered solely by the redirect guard on a protected route
// (currently only `/account`).
//
// `authenticationLocalDataSourceProvider` is overridden with an in-memory
// fake because the real implementation is backed by `flutter_secure_storage`,
// which uses a platform channel with no plugin available in the widget
// test environment — this is a test-environment concern only, not an
// app behavior change.

import 'package:fayadhowr/app/app.dart';
import 'package:fayadhowr/core/config/environment.dart';
import 'package:fayadhowr/core/di/root_providers.dart';
import 'package:fayadhowr/core/session/auth_session.dart';
import 'package:fayadhowr/features/auth/data/datasources/authentication_local_data_source.dart';
import 'package:fayadhowr/features/auth/presentation/providers/auth_providers.dart';
import 'package:fayadhowr/features/booking/presentation/widgets/booking_selectable_card.dart';
import 'package:fayadhowr/features/booking/presentation/widgets/booking_selected_service_card.dart';
import 'package:fayadhowr/features/services/presentation/widgets/service_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _FakeAuthenticationLocalDataSource implements AuthenticationLocalDataSource {
  _FakeAuthenticationLocalDataSource([this._session]);

  AuthSession? _session;

  @override
  Future<void> saveSession(AuthSession session) async => _session = session;

  @override
  Future<AuthSession?> readSession() async => _session;

  @override
  Future<void> clearSession() async => _session = null;
}

/// A pre-seeded session so tests that need to reach a protected route
/// (e.g. `/booking`) can do so without exercising the real login/OTP flow,
/// which is already covered by the Authentication Module's own tests.
/// Splash's `restoreSessionOnLaunch` reads this at startup exactly as it
/// would a real persisted session.
const AuthSession _testSession = AuthSession(phoneNumber: '+252611234567', accessToken: 'test-token');

Future<void> _pumpApp(WidgetTester tester, {required bool onboarded, bool authenticated = false}) async {
  SharedPreferences.setMockInitialValues(<String, Object>{
    if (onboarded) 'onboarding_completed': true,
  });
  final SharedPreferences prefs = await SharedPreferences.getInstance();

  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        environmentProvider.overrideWithValue(Environment.dev),
        sharedPreferencesProvider.overrideWithValue(prefs),
        authenticationLocalDataSourceProvider.overrideWithValue(
          _FakeAuthenticationLocalDataSource(authenticated ? _testSession : null),
        ),
      ],
      child: const FayadhowrApp(),
    ),
  );
  // Splash now holds on its own branded artwork for ~2s (entrance fade/
  // scale + a fixed hold + exit fade) before continuing to Welcome. That
  // hold is a bare `Future.delayed`, not tied to a ticker/animation, so
  // `pumpAndSettle()` alone considers the tree "settled" as soon as the
  // short entrance animation stops — well before the hold timer fires.
  // Explicitly advance past the whole sequence first, then let the
  // Welcome route's own transition settle normally.
  await tester.pump(const Duration(milliseconds: 2700));
  await tester.pumpAndSettle();
}

/// Taps Welcome's "Continue" button — used by tests that only care about
/// what's past Welcome.
Future<void> _continuePastWelcome(WidgetTester tester) async {
  expect(find.text('Professional Cleaning You Can Trust'), findsOneWidget);
  await tester.tap(find.text('Continue'));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('Every launch (first install or returning): Splash routes to Welcome', (
    WidgetTester tester,
  ) async {
    await _pumpApp(tester, onboarded: false);

    expect(find.text('Professional Cleaning You Can Trust'), findsOneWidget);
    expect(
      find.text('Book trusted cleaning services and shop cleaning essentials — all in one place.'),
      findsOneWidget,
    );
    expect(find.text('Continue'), findsOneWidget);
    // Welcome is a branding screen only — no Home business CTAs here.
    expect(find.text('Book a Service'), findsNothing);
    expect(find.text('Browse Store'), findsNothing);
  });

  testWidgets('First install: Welcome Continue routes to Onboarding (not seen before)', (
    WidgetTester tester,
  ) async {
    await _pumpApp(tester, onboarded: false);
    await _continuePastWelcome(tester);

    expect(find.text('Book Trusted Cleaning Services'), findsOneWidget);
    expect(find.text('Skip'), findsOneWidget);
    expect(find.text('Get Started'), findsNothing); // first page shows "Next", not "Get Started"
    expect(find.text('Next'), findsOneWidget);
  });

  testWidgets('Returning user (already onboarded): Welcome Continue routes to Home, never Onboarding', (
    WidgetTester tester,
  ) async {
    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    // Lands on Home — never sees Onboarding again, never forced to Login.
    expect(find.text('Book Trusted Cleaning Services'), findsNothing);
    expect(find.text('Enter your phone number'), findsNothing);
    expect(find.text('Home'), findsWidgets);
    expect(find.text('Services'), findsOneWidget);
    expect(find.text('Store'), findsOneWidget);
    expect(find.text('Cart'), findsOneWidget);
    expect(find.text('Account'), findsOneWidget);
  });

  testWidgets('Guest browsing Home/Services/Store/Cart never sees Login', (WidgetTester tester) async {
    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    for (final String tab in <String>['Services', 'Store', 'Cart']) {
      await tester.tap(find.text(tab));
      await tester.pumpAndSettle();
      expect(find.text('Enter your phone number'), findsNothing);
    }
  });

  testWidgets('Guest tapping Account is redirected to Login (protected route)', (
    WidgetTester tester,
  ) async {
    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();

    expect(find.text('Enter your phone number'), findsOneWidget);
    expect(find.text('Continue'), findsOneWidget);
  });

  testWidgets('Home screen renders all eight sections with mock data, no Hero Banner', (
    WidgetTester tester,
  ) async {
    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    // No Hero Banner — Welcome already owns branding/hero content, and
    // repeating it on Home would duplicate that experience.
    expect(find.text('Welcome to Fayadhowr'), findsNothing);
    expect(find.text('Book a Service'), findsNothing);
    expect(find.text('Browse Store'), findsNothing);
    // Search Bar is the first section
    expect(find.widgetWithText(TextField, 'Search services and products'), findsOneWidget);
    // Section headers, in order
    expect(find.text('Service Categories'), findsOneWidget);
    expect(find.text('Featured Services'), findsOneWidget);
    expect(find.text('Store Products'), findsOneWidget);
    expect(find.text('Before & After Gallery'), findsOneWidget);
    expect(find.text('Customer Reviews'), findsOneWidget);
    expect(find.text('Frequently Asked Questions'), findsOneWidget);
    expect(find.text('Contact Fayadhowr'), findsOneWidget);
    // Spot-check mock content from each section
    expect(find.text('Sofa & Chair Cleaning'), findsOneWidget);
    expect(find.text('Deep Cleaning'), findsNWidgets(2)); // category AND featured service share this name
    expect(find.text('All-Purpose Cleaner'), findsOneWidget);
    expect(find.text('Kitchen Deep Clean'), findsOneWidget);
    expect(find.text('Amina H.'), findsOneWidget);
    expect(find.text('How do I book a service?'), findsOneWidget);
    expect(find.text('+252 61 000 0000'), findsOneWidget);
    expect(find.text('WhatsApp'), findsOneWidget);
    // Stock indicator: mock data has both an in-stock and out-of-stock product.
    expect(find.text('In Stock'), findsWidgets);
    expect(find.text('Out of Stock'), findsOneWidget);

    // Add to Cart is present but disabled (Cart is out of scope).
    final Finder addToCart = find.widgetWithText(OutlinedButton, 'Add to Cart').first;
    expect(tester.widget<OutlinedButton>(addToCart).onPressed, isNull);
  });

  testWidgets('Services List shows search, category filter, and all 9 approved services', (
    WidgetTester tester,
  ) async {
    // Tall surface so every card/chip is actually built by the lazy
    // ListView.builder — off-screen items don't exist in the tree yet.
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(TextField, 'Search services'), findsOneWidget);
    // 'All' only exists as a filter chip — unambiguous.
    expect(find.text('All'), findsOneWidget);
    for (final String name in <String>[
      'Deep Cleaning',
      'Pest Control',
      'Carpet Cleaning',
      'Sofa & Chair Cleaning',
      'Post Construction Cleaning',
      'Window Cleaning',
      'Fumigation Services',
      'Housekeeper',
      'Monthly Cleaning Staff',
    ]) {
      // Each name is also a filter-chip label, so `find.text` alone is
      // ambiguous — target the card specifically.
      expect(
        find.widgetWithText(ServiceCard, name),
        findsOneWidget,
        reason: '$name should have a card in the Services List',
      );
    }
    // Service-mode badges and starting prices from the card spec.
    expect(find.text('One-Time'), findsWidgets);
    expect(find.text('Monthly Contract'), findsWidgets);
    expect(find.text('From \$45'), findsOneWidget);
  });

  testWidgets('Services search filters the list', (WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    await tester.enterText(find.widgetWithText(TextField, 'Search services'), 'Pest');
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ServiceCard, 'Pest Control'), findsOneWidget);
    expect(find.widgetWithText(ServiceCard, 'Deep Cleaning'), findsNothing);
    expect(find.widgetWithText(ServiceCard, 'Housekeeper'), findsNothing);
  });

  testWidgets('Services category filter narrows the list to one service', (WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(ChoiceChip, 'Pest Control'));
    await tester.pumpAndSettle();

    expect(find.widgetWithText(ServiceCard, 'Pest Control'), findsOneWidget);
    expect(find.widgetWithText(ServiceCard, 'Deep Cleaning'), findsNothing);
    expect(find.widgetWithText(ServiceCard, 'Housekeeper'), findsNothing);
  });

  testWidgets('Tapping a service card opens Service Detail with Book Now wired, Quotation still disabled', (
    WidgetTester tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(ServiceCard, 'Deep Cleaning'));
    await tester.pumpAndSettle();

    // Full Service Detail layout — every required placeholder section.
    expect(find.text('Service Overview'), findsOneWidget);
    expect(find.text("What's Included"), findsOneWidget);
    expect(find.text("What's Not Included"), findsOneWidget);
    expect(find.text('Before & After Gallery'), findsOneWidget);
    expect(find.text('How It Works'), findsOneWidget);
    expect(find.text('Duration, Pricing & Coverage'), findsOneWidget);
    expect(find.text('FAQs'), findsOneWidget);
    expect(find.text('Customer Reviews'), findsOneWidget);
    expect(find.text('Related Services'), findsOneWidget);
    // Service Coverage, as specified.
    expect(find.text('Mogadishu'), findsOneWidget);
    expect(find.text('Hargeisa'), findsOneWidget);

    // Book Now is now wired to the Booking Module; Request Quotation
    // remains disabled (quotation workflow is out of scope).
    final Finder bookNow = find.widgetWithText(ElevatedButton, 'Book Now');
    final Finder requestQuotation = find.widgetWithText(OutlinedButton, 'Request Quotation');
    expect(bookNow, findsOneWidget);
    expect(requestQuotation, findsOneWidget);
    expect(tester.widget<ElevatedButton>(bookNow).onPressed, isNotNull);
    expect(tester.widget<OutlinedButton>(requestQuotation).onPressed, isNull);

    // Guest browsing: viewing a service never forces Login.
    expect(find.text('Enter your phone number'), findsNothing);
  });

  testWidgets('Guest tapping Book Now is redirected to Login (protected route)', (WidgetTester tester) async {
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ServiceCard, 'Deep Cleaning'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(ElevatedButton, 'Book Now'));
    await tester.pumpAndSettle();

    expect(find.text('Enter your phone number'), findsOneWidget);
  });

  testWidgets('Booking Screen: service pre-selected, Continue enables as selections complete, summary updates', (
    WidgetTester tester,
  ) async {
    await tester.binding.setSurfaceSize(const Size(1080, 4200));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await _pumpApp(tester, onboarded: true, authenticated: true);
    await _continuePastWelcome(tester);

    await tester.tap(find.text('Services'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ServiceCard, 'Deep Cleaning'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(ElevatedButton, 'Book Now'));
    await tester.pumpAndSettle();

    // Landed on Booking, not Login — authenticated users go straight through.
    expect(find.text('Book Service'), findsOneWidget);
    expect(find.text('Enter your phone number'), findsNothing);

    // Selected Service Card shows the service carried over from Service Detail.
    expect(find.widgetWithText(BookingSelectedServiceCard, 'Deep Cleaning'), findsOneWidget);

    // Continue starts disabled — no selections made yet.
    Finder continueButton = find.widgetWithText(ElevatedButton, 'Continue');
    expect(tester.widget<ElevatedButton>(continueButton).onPressed, isNull);

    // Fill in every required field.
    await tester.tap(find.widgetWithText(BookingSelectableCard, 'One-Time'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(BookingSelectableCard, 'Apartment'));
    await tester.pumpAndSettle();
    await tester.tap(find.widgetWithText(BookingSelectableCard, 'Studio'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Select Date'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Tomorrow'));
    await tester.pumpAndSettle();

    await tester.tap(find.widgetWithText(BookingSelectableCard, 'Morning'));
    await tester.pumpAndSettle();

    await tester.tap(find.text('Select Location'));
    await tester.pumpAndSettle();
    // The address sheet's ListTile subtitle — unique, unlike its 'Home'
    // title which collides with the (inactive but still-mounted) bottom
    // nav tab underneath this top-level route.
    await tester.tap(find.text('Hodan District, Mogadishu'));
    await tester.pumpAndSettle();

    // Booking Summary reflects every selection automatically.
    expect(find.text('One-Time'), findsWidgets);
    expect(find.text('Apartment'), findsWidgets);
    expect(find.text('Studio'), findsWidgets);
    expect(find.text('Tomorrow'), findsWidgets);
    expect(find.text('Morning'), findsWidgets);

    // Continue is now enabled.
    continueButton = find.widgetWithText(ElevatedButton, 'Continue');
    expect(tester.widget<ElevatedButton>(continueButton).onPressed, isNotNull);
  });
}
