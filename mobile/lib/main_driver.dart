import 'package:albahar_customer/ops/ops_bootstrap.dart';
import 'package:albahar_customer/ops/ops_models.dart';

/// Entry point of the Al Bahar Driver app (`flutter build apk --flavor driverProd -t lib/main_driver.dart`).
Future<void> main() => runOps(OpsRole.driver);
