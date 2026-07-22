import 'package:dio/dio.dart';

import '../../../../core/error/failure.dart';
import '../../../../core/error/failure_mapper.dart';
import '../../../../core/error/result.dart';
import '../../../../core/session/auth_session.dart';
import '../../domain/repositories/authentication_repository.dart';
import '../datasources/authentication_local_data_source.dart';
import '../datasources/authentication_remote_data_source.dart';

/// Composes the (mock) remote and (real) local data sources. Holds no
/// reactive state itself — callers (ViewModels) write successful results
/// into `core/session/session_provider.dart` — reusing the existing
/// [Failure]/[FailureMapper] infrastructure rather than inventing
/// feature-local error types (docs/09_Flutter_Architecture.md, Milestone
/// F5 task 9).
class AuthenticationRepositoryImpl implements AuthenticationRepository {
  AuthenticationRepositoryImpl({
    required AuthenticationRemoteDataSource remoteDataSource,
    required AuthenticationLocalDataSource localDataSource,
  })  : _remote = remoteDataSource,
        _local = localDataSource;

  final AuthenticationRemoteDataSource _remote;
  final AuthenticationLocalDataSource _local;

  @override
  Future<AuthSession?> restoreSession() => _local.readSession();

  @override
  Future<Result<void>> requestOtp(String phoneNumber) async {
    try {
      await _remote.requestOtp(phoneNumber);
      return const Ok<void>(null);
    } on DioException catch (e) {
      return Err<void>(FailureMapper.fromDioException(e));
    } on Exception catch (e) {
      return Err<void>(UnknownFailure(message: e.toString()));
    }
  }

  @override
  Future<Result<AuthSession>> verifyOtp({required String phoneNumber, required String otp}) async {
    try {
      final String token = await _remote.verifyOtp(phoneNumber: phoneNumber, otp: otp);
      final AuthSession session = AuthSession(phoneNumber: phoneNumber, accessToken: token);
      await _local.saveSession(session);
      return Ok<AuthSession>(session);
    } on DioException catch (e) {
      return Err<AuthSession>(FailureMapper.fromDioException(e));
    } on Exception catch (e) {
      return Err<AuthSession>(UnknownFailure(message: e.toString()));
    }
  }

  @override
  Future<void> logout() => _local.clearSession();
}
