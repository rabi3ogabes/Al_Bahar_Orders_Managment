package qa.albahar.customer.liveorder

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import io.flutter.plugin.common.BinaryMessenger
import io.flutter.plugin.common.MethodCall
import io.flutter.plugin.common.MethodChannel

/**
 * The Dart side of the live order notification (`lib/core/live_order/live_order_platform.dart`):
 *
 *  - `capabilities` -> `{native: true, sdk: int}`
 *  - `show(map)` -> `posted | skipped | dismissed | disabled | invalid`
 *  - `cancel(orderId)`, `cancelAll()`
 *  - `areEnabled()` -> bool
 *  - `permissionStatus()` / `requestPermission()` -> `granted | denied | blocked`
 *  - `openSettings()` -> bool
 *
 * Registered on the activity's engine by MainActivity. A background isolate cannot reach it, which is why pushes that
 * arrive with the app closed are drawn by [LiveOrderFcmReceiver] instead.
 */
internal class LiveOrderChannel(private val activity: Activity) : MethodChannel.MethodCallHandler {
    private var channel: MethodChannel? = null
    private var pendingPermission: MethodChannel.Result? = null

    fun register(messenger: BinaryMessenger) {
        channel = MethodChannel(messenger, NAME).also { it.setMethodCallHandler(this) }
    }

    fun unregister() {
        channel?.setMethodCallHandler(null)
        channel = null
        pendingPermission = null
    }

    override fun onMethodCall(call: MethodCall, result: MethodChannel.Result) {
        try {
            when (call.method) {
                "capabilities" -> result.success(mapOf("native" to true, "sdk" to Build.VERSION.SDK_INT))
                "show" -> {
                    val order = LiveOrder.fromMap(stringKeyed(call.arguments))
                    result.success(if (order == null) "invalid" else LiveOrderNotifier.show(activity, order).wire)
                }
                "cancel" -> {
                    val id = (call.arguments as? String) ?: stringKeyed(call.arguments)["order_id"]?.toString()
                    if (!id.isNullOrBlank()) LiveOrderNotifier.cancel(activity, id)
                    result.success(null)
                }
                "cancelAll" -> {
                    LiveOrderNotifier.cancelAll(activity)
                    result.success(null)
                }
                "areEnabled" -> result.success(LiveOrderPermissions.areEnabled(activity))
                "permissionStatus" -> result.success(LiveOrderPermissions.status(activity))
                "requestPermission" -> requestPermission(result)
                "openSettings" -> result.success(LiveOrderPermissions.openSettings(activity))
                else -> result.notImplemented()
            }
        } catch (t: Throwable) {
            Log.w(LOG_TAG, "live order call ${call.method} failed", t)
            result.error("live_order_failed", t.message, null)
        }
    }

    private fun requestPermission(result: MethodChannel.Result) {
        val current = LiveOrderPermissions.status(activity)
        // Nothing to ask: already on, or not a permission the system dialog can grant (older Android, or denied for good).
        if (current != LiveOrderPermissions.DENIED || Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) {
            result.success(current)
            return
        }
        if (pendingPermission != null) {
            result.error("busy", "A permission request is already open", null)
            return
        }
        pendingPermission = result
        LiveOrderStore(activity).permissionAsked = true
        activity.requestPermissions(arrayOf(Manifest.permission.POST_NOTIFICATIONS), LiveOrderPermissions.REQUEST_CODE)
    }

    /** Called by MainActivity for every permission result; true when it was ours. */
    fun onPermissionResult(requestCode: Int, grantResults: IntArray): Boolean {
        if (requestCode != LiveOrderPermissions.REQUEST_CODE) return false
        val pending = pendingPermission ?: return true
        pendingPermission = null
        val granted = grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED
        pending.success(if (granted) LiveOrderPermissions.GRANTED else LiveOrderPermissions.status(activity))
        return true
    }

    private fun stringKeyed(arguments: Any?): Map<String, Any?> =
        (arguments as? Map<*, *>)?.entries?.associate { it.key.toString() to it.value } ?: emptyMap()

    companion object {
        const val NAME = "albahar/live_order"
        private const val LOG_TAG = "LiveOrder"
    }
}
