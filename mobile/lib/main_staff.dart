import 'package:albahar_customer/ops/ops_bootstrap.dart';
import 'package:albahar_customer/ops/ops_models.dart';

/// Entry point of the Al Bahar Branch (staff) app (`flutter build apk --flavor staffProd -t lib/main_staff.dart`).
Future<void> main() => runOps(OpsRole.staff);
