import 'package:dio/dio.dart';
import 'package:nubloca/core/auth/auth_session.dart';
import 'package:nubloca/core/auth/user.dart';
import 'package:nubloca/core/network/api_client.dart';

abstract interface class AuthRepository {
  Future<AuthSession> login({required String email, required String password});

  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  });

  Future<User?> restoreSession();

  Future<void> logout();
}

class ApiAuthRepository implements AuthRepository {
  ApiAuthRepository(this._apiClient);

  final ApiClient _apiClient;
  Dio get _dio => _apiClient.dio;

  @override
  Future<AuthSession> login({
    required String email,
    required String password,
  }) async {
    final response = await _dio.post<Object?>(
      'login',
      data: {'email': email, 'password': password},
      options: Options(extra: const {'publicAuthRequest': true}),
    );

    return _saveSession(response.data);
  }

  @override
  Future<AuthSession> register({
    required String name,
    required String email,
    required String password,
    required String passwordConfirmation,
  }) async {
    final response = await _dio.post<Object?>(
      'register',
      data: {
        'name': name,
        'email': email,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
      options: Options(extra: const {'publicAuthRequest': true}),
    );

    return _saveSession(response.data);
  }

  Future<AuthSession> _saveSession(Object? responseData) async {
    if (responseData is! Map<String, dynamic>) {
      throw const FormatException(
        'The API returned an invalid sign-in response.',
      );
    }

    final session = AuthSession.fromJson(responseData);
    await _apiClient.tokenStorage.writeToken(session.token);
    return session;
  }

  @override
  Future<User?> restoreSession() async {
    final token = await _apiClient.tokenStorage.readToken();
    if (token == null || token.isEmpty) {
      return null;
    }

    try {
      final response = await _dio.get<Object?>('user');
      if (response.data is! Map<String, dynamic>) {
        throw const FormatException('The API returned an invalid user.');
      }

      return User.fromJson(response.data! as Map<String, dynamic>);
    } on DioException catch (error) {
      if (error.response?.statusCode == 401) {
        await _apiClient.tokenStorage.deleteToken();
        return null;
      }

      rethrow;
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _dio.post<void>('logout');
    } finally {
      await _apiClient.tokenStorage.deleteToken();
    }
  }
}
