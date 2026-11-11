import 'package:albahar_customer/bootstrap.dart';

/// Default entry point: the flavour comes from `--dart-define=FLAVOR=dev|staging|prod`
/// (defaults to dev). Flavour-specific entry points live in `main_<flavor>.dart`.
Future<void> main() => bootstrap(null);
