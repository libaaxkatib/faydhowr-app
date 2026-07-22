import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../session/session_provider.dart';

/// Whether the current user is authenticated — derived from the real
/// session (Milestone F5 Authentication), replacing the Foundation
/// placeholder (Milestone F2) that always returned `false`. The redirect
/// guard in `app/app_router.dart` is unchanged: it only ever consumed this
/// boolean, never the session's shape, so no router logic needed to change.
final isAuthenticatedProvider = Provider<bool>((ref) => ref.watch(sessionProvider) != null);
