import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nubloca/core/network/api_client.dart';
import 'package:nubloca/core/storage/token_storage.dart';
import 'package:nubloca/features/vehicles/data/vehicle.dart';
import 'package:nubloca/features/vehicles/data/vehicle_repository.dart';

final _token = List.filled(64, 'a').join();

Map<String, Object> _vehicleJson({
  int id = 1,
  String plate = 'CJ01ABC',
  String? token,
}) {
  final actualToken = token ?? _token;
  return {
    'id': id,
    'license_plate': plate,
    'public_token': actualToken,
    'public_url': 'https://nubloca.clarfiscal.ro/v/$actualToken',
  };
}

class _MemoryTokenStorage implements TokenStorage {
  @override
  Future<String?> readToken() async => 'stored-token';

  @override
  Future<void> writeToken(String token) async {}

  @override
  Future<void> deleteToken() async {}
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
    'vehicle list uses authenticated Laravel endpoint and parses QR URL',
    () async {
      final adapter = _MockAdapter((_) => _jsonResponse([_vehicleJson()], 200));
      final repository = ApiVehicleRepository(
        ApiClient(
          tokenStorage: _MemoryTokenStorage(),
          dio: Dio(BaseOptions(baseUrl: apiBaseUrl))
            ..httpClientAdapter = adapter,
        ),
      );

      final vehicles = await repository.list();

      expect(adapter.requests.single.method, 'GET');
      expect(adapter.requests.single.uri.path, '/api/vehicles');
      expect(
        adapter.requests.single.headers['Authorization'],
        'Bearer stored-token',
      );
      expect(vehicles.single.licensePlate, 'CJ01ABC');
      expect(
        vehicles.single.publicUrl,
        'https://nubloca.clarfiscal.ro/v/$_token',
      );
    },
  );

  test(
    'create, update, delete and token rotation use existing API routes',
    () async {
      final adapter = _MockAdapter((options) {
        if (options.method == 'DELETE') {
          return ResponseBody.fromString('', 204);
        }
        final rotatedToken = 'b' * 64;
        return _jsonResponse(
          _vehicleJson(
            token: options.uri.path.endsWith('rotate-public-token')
                ? rotatedToken
                : _token,
          ),
          options.method == 'POST' && options.uri.path == '/api/vehicles'
              ? 201
              : 200,
        );
      });
      final repository = ApiVehicleRepository(
        ApiClient(
          tokenStorage: _MemoryTokenStorage(),
          dio: Dio(BaseOptions(baseUrl: apiBaseUrl))
            ..httpClientAdapter = adapter,
        ),
      );

      await repository.create('CJ 01 ABC');
      await repository.update(7, 'BV 02 XYZ');
      await repository.delete(7);
      await repository.rotatePublicToken(7);

      expect(
        adapter.requests.map(
          (request) => '${request.method} ${request.uri.path}',
        ),
        [
          'POST /api/vehicles',
          'PUT /api/vehicles/7',
          'DELETE /api/vehicles/7',
          'POST /api/vehicles/7/rotate-public-token',
        ],
      );
      expect(adapter.requests[0].data, {'license_plate': 'CJ 01 ABC'});
      expect(adapter.requests[1].data, {'license_plate': 'BV 02 XYZ'});
    },
  );

  test('QR parsing rejects unsafe or mismatched public URLs', () {
    expect(
      () => Vehicle.fromJson({
        ..._vehicleJson(),
        'public_url': 'https://attacker.example/v/$_token',
      }),
      throwsFormatException,
    );
    expect(
      () => Vehicle.fromJson({
        ..._vehicleJson(),
        'public_url': 'https://nubloca.clarfiscal.ro/v/${'b' * 64}',
      }),
      throwsFormatException,
    );
    expect(
      () => Vehicle.fromJson({..._vehicleJson(), 'public_token': '7'}),
      throwsFormatException,
    );
  });
}
