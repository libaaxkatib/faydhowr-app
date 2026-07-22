import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Connectivity abstraction — docs/09_Flutter_Architecture.md §9.3,
/// package table (`connectivity_plus`, "Offline detection").
///
/// Detects online/offline only — no retry logic, no synchronization. The
/// future Offline Strategy work (stale-while-revalidate, offline banners)
/// consumes this signal rather than reimplementing detection.
final connectivityProvider = Provider<Connectivity>((ref) => Connectivity());

/// Emits `true` when at least one active network interface is reachable.
final isOnlineProvider = StreamProvider<bool>((ref) {
  final Connectivity connectivity = ref.watch(connectivityProvider);
  return connectivity.onConnectivityChanged.map(
    (List<ConnectivityResult> results) =>
        results.isNotEmpty && !results.contains(ConnectivityResult.none),
  );
});
