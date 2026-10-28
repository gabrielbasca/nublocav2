import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:nubloca/core/network/api_client.dart';
import 'package:nubloca/core/providers.dart';
import 'package:nubloca/features/vehicles/data/vehicle.dart';
import 'package:nubloca/features/vehicles/data/vehicle_repository.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:share_plus/share_plus.dart';

final vehicleRepositoryProvider = Provider<VehicleRepository>(
  (ref) => ApiVehicleRepository(ref.watch(apiClientProvider)),
);

final vehiclesProvider =
    AsyncNotifierProvider<VehiclesController, List<Vehicle>>(
      VehiclesController.new,
    );

class VehiclesController extends AsyncNotifier<List<Vehicle>> {
  VehicleRepository get _repository => ref.read(vehicleRepositoryProvider);

  @override
  Future<List<Vehicle>> build() => _repository.list();

  Future<Vehicle> create(String licensePlate) async {
    final vehicle = await _repository.create(licensePlate);
    state = AsyncData([...state.asData?.value ?? const [], vehicle]);
    return vehicle;
  }

  Future<Vehicle> updateVehicle(int id, String licensePlate) async {
    final vehicle = await _repository.update(id, licensePlate);
    state = AsyncData([
      for (final existing in state.asData?.value ?? const [])
        if (existing.id == id) vehicle else existing,
    ]);
    return vehicle;
  }

  Future<void> deleteVehicle(int id) async {
    await _repository.delete(id);
    state = AsyncData([
      for (final vehicle in state.asData?.value ?? const [])
        if (vehicle.id != id) vehicle,
    ]);
  }

  Future<Vehicle> rotatePublicToken(int id) async {
    final vehicle = await _repository.rotatePublicToken(id);
    state = AsyncData([
      for (final existing in state.asData?.value ?? const [])
        if (existing.id == id) vehicle else existing,
    ]);
    return vehicle;
  }

  Future<void> refresh() async {
    state = const AsyncLoading();
    state = await AsyncValue.guard(_repository.list);
  }
}

class VehicleListScreen extends ConsumerWidget {
  const VehicleListScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(vehiclesProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('My vehicles')),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('addVehicleButton'),
        onPressed: () => context.push('/vehicles/new'),
        icon: const Icon(Icons.add),
        label: const Text('Add vehicle'),
      ),
      body: vehicles.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (error, _) => _VehicleLoadError(
          onRetry: () => ref.read(vehiclesProvider.notifier).refresh(),
        ),
        data: (items) => RefreshIndicator(
          onRefresh: () => ref.read(vehiclesProvider.notifier).refresh(),
          child: items.isEmpty
              ? ListView(
                  key: const Key('vehicleList'),
                  physics: const AlwaysScrollableScrollPhysics(),
                  children: const [
                    SizedBox(height: 110),
                    Icon(Icons.directions_car_outlined, size: 64),
                    SizedBox(height: 18),
                    Center(
                      child: Text(
                        'No vehicles yet',
                        style: TextStyle(
                          fontSize: 21,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ),
                    SizedBox(height: 8),
                    Center(
                      child: Text('Add your vehicle to create its contact QR.'),
                    ),
                  ],
                )
              : ListView.separated(
                  key: const Key('vehicleList'),
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.fromLTRB(20, 12, 20, 100),
                  itemCount: items.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 10),
                  itemBuilder: (context, index) {
                    final vehicle = items[index];
                    return Card(
                      elevation: 0,
                      child: ListTile(
                        leading: const CircleAvatar(
                          child: Icon(Icons.directions_car_outlined),
                        ),
                        title: Text(
                          vehicle.licensePlate,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        subtitle: const Text('Tap to view contact QR'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => context.push('/vehicles/${vehicle.id}'),
                      ),
                    );
                  },
                ),
        ),
      ),
    );
  }
}

class _VehicleLoadError extends StatelessWidget {
  const _VehicleLoadError({required this.onRetry});

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cloud_off_outlined, size: 42),
          const SizedBox(height: 12),
          const Text('Could not load your vehicles.'),
          const SizedBox(height: 12),
          FilledButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: const Text('Try again'),
          ),
        ],
      ),
    ),
  );
}

class VehicleFormScreen extends ConsumerStatefulWidget {
  const VehicleFormScreen({super.key, this.vehicleId});

  final int? vehicleId;

  @override
  ConsumerState<VehicleFormScreen> createState() => _VehicleFormScreenState();
}

class _VehicleFormScreenState extends ConsumerState<VehicleFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _plateController = TextEditingController();
  bool _saving = false;
  String? _error;

  bool get _editing => widget.vehicleId != null;

  @override
  void initState() {
    super.initState();
    if (_editing) {
      final vehicles = ref.read(vehiclesProvider).asData?.value ?? const [];
      final match = vehicles.where((item) => item.id == widget.vehicleId);
      if (match.isNotEmpty) _plateController.text = match.first.licensePlate;
    }
  }

  @override
  void dispose() {
    _plateController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _saving = true;
      _error = null;
    });

    try {
      final plate = _plateController.text.trim();
      if (_editing) {
        await ref
            .read(vehiclesProvider.notifier)
            .updateVehicle(widget.vehicleId!, plate);
      } else {
        await ref.read(vehiclesProvider.notifier).create(plate);
      }
      if (mounted) context.pop();
    } catch (error) {
      if (mounted) setState(() => _error = apiErrorMessage(error));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(title: Text(_editing ? 'Edit vehicle' : 'Add vehicle')),
    body: SafeArea(
      child: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Text(
              _editing ? 'Update your license plate' : 'Add your vehicle',
              style: Theme.of(context).textTheme.headlineSmall
                  ?.copyWith(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 8),
            const Text('Use the license plate shown on your vehicle.'),
            const SizedBox(height: 24),
            TextFormField(
              key: const Key('licensePlateField'),
              controller: _plateController,
              textCapitalization: TextCapitalization.characters,
              textInputAction: TextInputAction.done,
              maxLength: 16,
              decoration: const InputDecoration(
                labelText: 'License plate',
                hintText: 'CJ 01 ABC',
                prefixIcon: Icon(Icons.directions_car_outlined),
              ),
              validator: (value) {
                final normalized = (value ?? '').toUpperCase().replaceAll(
                  RegExp(r'[^A-Z0-9]'),
                  '',
                );
                if (normalized.length < 2 || normalized.length > 16) {
                  return 'Enter a plate with 2 to 16 letters or numbers.';
                }
                return null;
              },
            ),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 18),
            FilledButton(
              key: const Key('saveVehicleButton'),
              onPressed: _saving ? null : _save,
              child: _saving
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text(_editing ? 'Save changes' : 'Add vehicle'),
            ),
          ],
        ),
      ),
    ),
  );
}

class VehicleDetailsScreen extends ConsumerWidget {
  const VehicleDetailsScreen({super.key, required this.vehicleId});

  final int vehicleId;

  Future<void> _rotateToken(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Replace this QR code?'),
        content: const Text(
          'The current QR code will stop working. Any printed copies must be replaced.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Replace QR code'),
          ),
        ],
      ),
    );
    if (confirmed != true || !context.mounted) return;

    try {
      await ref.read(vehiclesProvider.notifier).rotatePublicToken(vehicleId);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Your QR code has been replaced.')),
        );
      }
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(apiErrorMessage(error))));
      }
    }
  }

  Future<void> _delete(BuildContext context, WidgetRef ref) async {
    final shouldDelete = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Delete vehicle?'),
        content: const Text(
          'Vehicles with conversation history cannot be deleted.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (shouldDelete != true || !context.mounted) return;

    try {
      await ref.read(vehiclesProvider.notifier).deleteVehicle(vehicleId);
      if (context.mounted) context.pop();
    } catch (error) {
      if (context.mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(apiErrorMessage(error))));
      }
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final vehicles = ref.watch(vehiclesProvider);
    final vehicle = vehicles.asData?.value
        .where((item) => item.id == vehicleId)
        .firstOrNull;

    if (vehicle == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Vehicle')),
        body: vehicles.isLoading
            ? const Center(child: CircularProgressIndicator())
            : const Center(child: Text('Vehicle not found.')),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Vehicle details'),
        actions: [
          IconButton(
            tooltip: 'Edit vehicle',
            onPressed: () => context.push('/vehicles/$vehicleId/edit'),
            icon: const Icon(Icons.edit_outlined),
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(
            vehicle.licensePlate,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.headlineMedium
                ?.copyWith(fontWeight: FontWeight.w800, letterSpacing: 1.2),
          ),
          const SizedBox(height: 10),
          const Text(
            'Print or display this QR code so someone can send you a private, predefined message.',
            textAlign: TextAlign.center,
          ),
          const SizedBox(height: 24),
          Card(
            elevation: 0,
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                children: [
                  QrImageView(
                    key: const Key('vehicleQrCode'),
                    data: vehicle.publicUrl,
                    size: 240,
                    errorCorrectionLevel: QrErrorCorrectLevel.H,
                    backgroundColor: Colors.white,
                  ),
                  const SizedBox(height: 12),
                  const Text('Scan to contact the vehicle owner'),
                ],
              ),
            ),
          ),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: () => SharePlus.instance.share(
              ShareParams(
                uri: Uri.parse(vehicle.publicUrl),
                subject: 'Contact my vehicle through NuBloca',
              ),
            ),
            icon: const Icon(Icons.share_outlined),
            label: const Text('Share QR link'),
          ),
          const SizedBox(height: 8),
          TextButton.icon(
            onPressed: () => _rotateToken(context, ref),
            icon: const Icon(Icons.refresh),
            label: const Text('Replace QR code'),
          ),
          const SizedBox(height: 8),
          TextButton.icon(
            onPressed: () => _delete(context, ref),
            icon: const Icon(Icons.delete_outline),
            label: const Text('Delete vehicle'),
          ),
        ],
      ),
    );
  }
}
