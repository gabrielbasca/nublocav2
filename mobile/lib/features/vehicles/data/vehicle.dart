class Vehicle {
  const Vehicle({
    required this.id,
    required this.licensePlate,
    required this.publicToken,
    required this.publicUrl,
  });

  final int id;
  final String licensePlate;
  final String publicToken;
  final String publicUrl;

  factory Vehicle.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final plate = json['license_plate'];
    final token = json['public_token'];
    final url = json['public_url'];

    if (id is! int || plate is! String || token is! String || url is! String) {
      throw const FormatException('The API returned an invalid vehicle.');
    }

    final parsedUrl = Uri.tryParse(url);
    if (!RegExp(r'^[a-f0-9]{64}$').hasMatch(token) ||
        parsedUrl == null ||
        parsedUrl.scheme != 'https' ||
        parsedUrl.host != 'nubloca.clarfiscal.ro' ||
        parsedUrl.path != '/v/$token' ||
        parsedUrl.hasQuery ||
        parsedUrl.hasFragment ||
        parsedUrl.userInfo.isNotEmpty) {
      throw const FormatException(
        'The API returned an invalid vehicle QR URL.',
      );
    }

    return Vehicle(
      id: id,
      licensePlate: plate,
      publicToken: token,
      publicUrl: parsedUrl.toString(),
    );
  }

  factory Vehicle.fromResponse(Object? response) {
    if (response is! Map<String, dynamic>) {
      throw const FormatException('The API returned an invalid vehicle.');
    }
    return Vehicle.fromJson(response);
  }
}
