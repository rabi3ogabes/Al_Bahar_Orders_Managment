package qa.albahar.customer.liveorder

import android.Manifest
import android.app.Activity
import android.app.NotificationManager
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings

/** Notification permission: Android 13+ asks at run time; before that the only switch is in the system settings. */
internal object LiveOrderPermissions {
    const val GRANTED = "granted"

    /** Not allowed yet, but the system dialog can still be shown. */
    const val DENIED = "denied"

    /** Off, and only the system settings can turn it on (denied for good, or switched off there). */
    const val BLOCKED = "blocked"

    const val REQUEST_CODE = 0x4C4F

    /** Notifications of this app are on, and the live order channel has not been silenced. */
    fun areEnabled(context: Context): Boolean {
        val nm = context.getSystemService(NotificationManager::class.java) ?: return false
        if (!nm.areNotificationsEnabled()) return false
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = nm.getNotificationChannel(LiveOrderNotifier.CHANNEL_ID)
            if (channel != null && channel.importance == NotificationManager.IMPORTANCE_NONE) return false
        }
        return true
    }

    fun status(activity: Activity): String {
        if (areEnabled(activity)) return GRANTED
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return BLOCKED
        val granted = activity.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED
        if (granted) return BLOCKED // allowed for the app, but switched off for the app or the channel in the settings
        val asked = LiveOrderStore(activity).permissionAsked
        val canAskAgain = !asked || activity.shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)
        return if (canAskAgain) DENIED else BLOCKED
    }

    fun openSettings(context: Context): Boolean {
        val settings = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
        } else {
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null))
        }
        return try {
            context.startActivity(settings.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            true
        } catch (_: Exception) {
            try {
                val details = Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", context.packageName, null))
                context.startActivity(details.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                true
            } catch (_: Exception) {
                false
            }
        }
    }
}
