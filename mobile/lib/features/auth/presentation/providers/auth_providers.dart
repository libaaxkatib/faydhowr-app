import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../../core/di/root_providers.dart';
import '../../data/datasources/authentication_local_data_source.dart';
import '../../data/datasources/authentication_remote_data_source.dart';
import '../../data/datasources/mock_authentication_remote_data_source.dart';
import '../../data/datasources/secure_authentication_local_data_source.dart';
import '../../data/repositories/authentication_repository_impl.dart';
import '../../domain/repositories/authentication_repository.dart';

/// Authentication feature DI wiring — follows the same interface-provider
/// → impl-provider pattern as `core/di/root_providers.dart`
/// (docs/09_Flutter_Architecture.md §5). Lives in `features/auth/` (not
/// `core/di/`) because these bindings are feature-owned, not cross-cutting
/// infrastructure.

final authenticationRemoteDataSourceProvider = Provider<AuthenticationRemoteDataSource>(
  (ref) => const MockAuthenticationRemoteDataSource(),
);

final authenticationLocalDataSourceProvider = Provider<AuthenticationLocalDataSource>(
  (ref) => SecureAuthenticationLocalDataSource(ref.watch(secureStorageServiceProvider)),
);

final authenticationRepositoryProvider = Provider<AuthenticationRepository>(
  (ref) => AuthenticationRepositoryImpl(
    remoteDataSource: ref.watch(authenticationRemoteDataSourceProvider),
    localDataSource: ref.watch(authenticationLocalDataSourceProvider),
  ),
);
