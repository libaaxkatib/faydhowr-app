import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/session/auth_session.dart';
import '../../../../core/session/session_provider.dart';
import 'auth_providers.dart';

/// Splash Screen responsibility: restore any persisted session (so
/// `isAuthenticatedProvider` is correct immediately for the redirect guard
/// on protected routes) before continuing.
///
/// Splash always continues to Welcome (Authentication UX Revision) — it no
/// longer decides Home vs. Onboarding itself; that branch now happens on
/// Welcome's Continue/swipe action, based on the same
/// `hasCompletedOnboardingProvider` used before. Guest browsing remains
/// unaffected: destination never depends on authentication state
/// (docs/02_SRS.md — "Value Before Login").
Future<void> restoreSessionOnLaunch(WidgetRef ref) async {
  final AuthSession? session = await ref.read(authenticationRepositoryProvider).restoreSession();
  ref.read(sessionProvider.notifier).update(session);
}
