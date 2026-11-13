package qa.albahar.customer

import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import qa.albahar.customer.liveorder.LiveOrderChannel

class MainActivity : FlutterActivity() {
    private var liveOrder: LiveOrderChannel? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // The live order notification (customer app): `albahar/live_order`, see lib/core/live_order.
        liveOrder = LiveOrderChannel(this).also { it.register(flutterEngine.dartExecutor.binaryMessenger) }
    }

    override fun cleanUpFlutterEngine(flutterEngine: FlutterEngine) {
        liveOrder?.unregister()
        liveOrder = null
        super.cleanUpFlutterEngine(flutterEngine)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<String>, grantResults: IntArray) {
        // Other results (location, camera) belong to the plugins: always pass the call on.
        liveOrder?.onPermissionResult(requestCode, grantResults)
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
    }
}
