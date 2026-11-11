import 'package:albahar_customer/bootstrap.dart';
import 'package:albahar_customer/core/config/app_config.dart';

/// `flutter run -t lib/main_staging.dart` (optionally with --dart-define=API_BASE_URL=…).
Future<void> main() => bootstrap(Flavor.staging);
