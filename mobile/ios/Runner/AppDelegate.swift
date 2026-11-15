import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    registerLiveOrderBridge(engineBridge)
  }

  /// The iPhone Live Activity (the order on the lock screen and in the Dynamic Island): `LiveOrderBridge` is added to the Runner target
  /// in Xcode together with the widget extension (docs/guides/IOS_LIVE_ACTIVITY.md). It is looked up by name, so until then this does
  /// nothing at all and the app builds and runs exactly as before; the Dart side answers "no Live Activity" and uses its usual notification.
  private func registerLiveOrderBridge(_ engineBridge: FlutterImplicitEngineBridge) {
    guard let bridgeClass = NSClassFromString("LiveOrderBridge") else { return }
    let bridge = bridgeClass as AnyObject
    let selector = NSSelectorFromString("registerWithMessenger:")
    guard bridge.responds(to: selector),
      let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "LiveOrderBridge")
    else { return }
    _ = bridge.perform(selector, with: registrar.messenger())
  }
}
