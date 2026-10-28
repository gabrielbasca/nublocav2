class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    this.isAdmin = false,
  });

  final int id;
  final String name;
  final String email;
  final bool isAdmin;

  factory User.fromJson(Map<String, dynamic> json) {
    final id = json['id'];
    final name = json['name'];
    final email = json['email'];

    if (id is! num || name is! String || email is! String) {
      throw const FormatException('The API returned an invalid user.');
    }

    return User(
      id: id.toInt(),
      name: name,
      email: email,
      isAdmin: json['is_admin'] == true,
    );
  }
}
