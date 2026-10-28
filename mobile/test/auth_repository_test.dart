import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nubloca/core/network/api_client.dart';
import 'package:nubloca/core/storage/token_storage.dart';
import 'package:nubloca/features/auth/data/auth_repository.dart';

class _MemoryTokenStorage implements TokenStorage {
  String? token;

  @override
  Future<String?> readToken() async => token;

  @override
  Future<void> writeToken(String token) async {
    this.token = token;
  }

  @override
  Future<void> deleteToken() async {
    token = null;
  }
}

class _MockAdapter implements HttpClientAdapter {
  _MockAdapter(this.respond);

  final ResponseBody Function(RequestOptions options) respond;
  final requests = <RequestOptions>[];

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    requests.add(options);
    return respond(options);
  }

  @override
  void close({bool force = false}) {}
}

ResponseBody _jsonResponse(Object body, int statusCode) =>
    ResponseBody.fromString(
      jsonEncode(body),
      statusCode,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );

void main() {
  test(
    'login uses the backend contract and stores Sanctum token securely',
    () async {
      final storage = _MemoryTokenStorage();
      final adapter = _MockAdapter(
        (options) => _jsonResponse({
          'token': '1|secret-token',
          'user': {
            'id': 42,
            'name': 'Taylor Driver',
            'email': 'taylor@example.com',
          },
        }, 200),
      );
      final client = ApiClient(
        tokenStorage: storage,
        dio: Dio(BaseOptions(baseUrl: apiBaseUrl))..httpClientAdapter = adapter,
      );
      final repository = ApiAuthRepository(client);

      final session = await repository.login(
        email: 'taylor@example.com',
        password: 'correct-horse-battery',
      );

      expect(adapter.requests.single.method, 'POST');
      expect(adapter.requests.single.uri.path, '/api/login');
      expect(adapter.requests.single.data, {
        'email': 'taylor@example.com',
        'password': 'correct-horse-battery',
      });
      expect(
        adapter.requests.single.headers.containsKey('Authorization'),
        isFalse,
      );
      expect(storage.token, '1|secret-token');
      expect(session.user.name, 'Taylor Driver');
    },
  );

  test(
    'registration sends Laravel confirmation field and stores the token',
    () async {
      final storage = _MemoryTokenStorage();
      final adapter = _MockAdapter(
        (options) => _jsonResponse({
          'token': '2|secret-token',
          'user': {
            'id': 2,
            'name': 'Taylor Driver',
            'email': 'taylor@example.com',
          },
        }, 201),
      );
      final client = ApiClient(
        tokenStorage: storage,
        dio: Dio(BaseOptions(baseUrl: apiBaseUrl))..httpClientAdapter = adapter,
      );

      await ApiAuthRepository(client).register(
        name: 'Taylor Driver',
        email: 'taylor@example.com',
        password: 'correct-horse-battery',
        passwordConfirmation: 'correct-horse-battery',
      );

      expect(adapter.requests.single.uri.path, '/api/register');
      expect(adapter.requests.single.data, {
        'name': 'Taylor Driver',
        'email': 'taylor@example.com',
        'password': 'correct-horse-battery',
        'password_confirmation': 'correct-horse-battery',
      });
      expect(storage.token, '2|secret-token');
    },
  );

  test(
    'session restoration attaches Bearer token and parses /user response',
    () async {
      final storage = _MemoryTokenStorage()..token = '3|stored-token';
      final adapter = _MockAdapter((options) {
        expect(options.headers['Authorization'], 'Bearer 3|stored-token');
        return _jsonResponse({
          'id': 3,
          'name': 'Stored User',
          'email': 'stored@example.com',
        }, 200);
      });
      final client = ApiClient(
        tokenStorage: storage,
        dio: Dio(BaseOptions(baseUrl: apiBaseUrl))..httpClientAdapter = adapter,
      );

      final user = await ApiAuthRepository(client).restoreSession();

      expect(adapter.requests.single.uri.path, '/api/user');
      expect(user?.email, 'stored@example.com');
    },
  );

  test(
    'a protected 401 clears token and notifies the authenticated state',
    () async {
      final storage = _MemoryTokenStorage()..token = '4|expired-token';
      var unauthorized = false;
      final adapter = _MockAdapter(
        (_) => _jsonResponse({'message': 'Unauthenticated.'}, 401),
      );
      final client = ApiClient(
        tokenStorage: storage,
        dio: Dio(BaseOptions(baseUrl: apiBaseUrl))..httpClientAdapter = adapter,
      )..onUnauthorized = () => unauthorized = true;

      await expectLater(
        ApiAuthRepository(client).restoreSession(),
        completion(isNull),
      );

      expect(storage.token, isNull);
      expect(unauthorized, isTrue);
    },
  );

  test(
    'logout calls Laravel endpoint and always clears the local token',
    () async {
      final storage = _MemoryTokenStorage()..token = '5|stored-token';
      final adapter = _MockAdapter((options) {
        expect(options.headers['Authorization'], 'Bearer 5|stored-token');
        return ResponseBody.fromString('', 204);
      });
      final client = ApiClient(
        tokenStorage: storage,
        dio: Dio(BaseOptions(baseUrl: apiBaseUrl))..httpClientAdapter = adapter,
      );

      await ApiAuthRepository(client).logout();

      expect(adapter.requests.single.uri.path, '/api/logout');
      expect(storage.token, isNull);
    },
  );
}
