import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:nubloca/features/vehicles/data/vehicle.dart';
import 'package:nubloca/features/vehicles/data/vehicle_repository.dart';
import 'package:nubloca/features/vehicles/presentation/vehicle_screens.dart';

final _qrToken = List.filled(64, 'a').join();
final _vehicle = Vehicle(
  id: 5,
  licensePlate: 'CJ01ABC',
  publicToken: _qrToken,
  publicUrl: 'https://nubloca.clarfiscal.ro/v/$_qrToken',
);

class _FakeVehicleRepository implements VehicleRepository {
  _FakeVehicleRepository(this.vehicles);

  final List<Vehicle> vehicles;

  @override
  Future<List<Vehicle>> list() async => vehicles;

  @override
  Future<Vehicle> create(String licensePlate) async => _vehicle;

  @override
  Future<Vehicle> update(int id, String licensePlate) async => _vehicle;

  @override
  Future<void> delete(int id) async {}

  @override
  Future<Vehicle> rotatePublicToken(int id) async => _vehicle;
}

Widget _app(Widget child, _FakeVehicleRepository repository) => ProviderScope(
  overrides: [vehicleRepositoryProvider.overrideWithValue(repository)],
  child: MaterialApp(home: child),
);

void main() {
  testWidgets('vehicle list presents the empty state and add action', (
    tester,
  ) async {
    await tester.pumpWidget(
      _app(const VehicleListScreen(), _FakeVehicleRepository([])),
    );
    await tester.pumpAndSettle();

    expect(find.text('No vehicles yet'), findsOneWidget);
    expect(find.byKey(const Key('addVehicleButton')), findsOneWidget);
  });

  testWidgets('vehicle details render the validated public QR code', (
    tester,
  ) async {
    await tester.pumpWidget(
      _app(
        const VehicleDetailsScreen(vehicleId: 5),
        _FakeVehicleRepository([_vehicle]),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('CJ01ABC'), findsOneWidget);
    expect(find.byKey(const Key('vehicleQrCode')), findsOneWidget);
    expect(find.text('Share QR link'), findsOneWidget);
  });
}
