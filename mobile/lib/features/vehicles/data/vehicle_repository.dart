import 'package:dio/dio.dart';
import 'package:nubloca/core/network/api_client.dart';
import 'package:nubloca/features/vehicles/data/vehicle.dart';

abstract interface class VehicleRepository {
  Future<List<Vehicle>> list();

  Future<Vehicle> create(String licensePlate);

  Future<Vehicle> update(int id, String licensePlate);

  Future<void> delete(int id);

  Future<Vehicle> rotatePublicToken(int id);
}

class ApiVehicleRepository implements VehicleRepository {
  ApiVehicleRepository(this._apiClient);

  final ApiClient _apiClient;
  Dio get _dio => _apiClient.dio;

  @override
  Future<List<Vehicle>> list() async {
    final response = await _dio.get<Object?>('vehicles');
    final data = response.data;
    if (data is! List) {
      throw const FormatException('The API returned an invalid vehicle list.');
    }

    return data
        .map((item) {
          if (item is! Map<String, dynamic>) {
            throw const FormatException('The API returned an invalid vehicle.');
          }
          return Vehicle.fromJson(item);
        })
        .toList(growable: false);
  }

  @override
  Future<Vehicle> create(String licensePlate) async {
    final response = await _dio.post<Object?>(
      'vehicles',
      data: {'license_plate': licensePlate},
    );
    return Vehicle.fromResponse(response.data);
  }

  @override
  Future<Vehicle> update(int id, String licensePlate) async {
    final response = await _dio.put<Object?>(
      'vehicles/$id',
      data: {'license_plate': licensePlate},
    );
    return Vehicle.fromResponse(response.data);
  }

  @override
  Future<void> delete(int id) async {
    await _dio.delete<void>('vehicles/$id');
  }

  @override
  Future<Vehicle> rotatePublicToken(int id) async {
    final response = await _dio.post<Object?>(
      'vehicles/$id/rotate-public-token',
    );
    return Vehicle.fromResponse(response.data);
  }
}
