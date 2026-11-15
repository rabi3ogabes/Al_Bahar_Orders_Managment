package qa.albahar.customer

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context
import android.os.Build

/**
 * The notification channels the server's pushes are shown in. The server names one per push (`android.notification.channel_id`,
 * FcmClient::channel) and mirrors these ids; the manifest's `default_notification_channel_id` is [DEFAULT], which also serves a push
 * whose channel an older build does not know. Separate channels let the customer mute offers without muting order updates.
 *
 * Created every time the app starts: the phone ignores a channel that exists, except that its name and description follow the phone's
 * language (the live order channel is named the same way).
 */
internal object PushChannels {
    const val DEFAULT = "albahar_default"
    const val OFFERS = "albahar_offers"
    const val AUCTIONS = "albahar_auctions"

    fun ensure(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        manager.createNotificationChannels(
            listOf(
                channel(context, DEFAULT, R.string.push_channel_default_name, R.string.push_channel_default_description, NotificationManager.IMPORTANCE_HIGH),
                channel(context, AUCTIONS, R.string.push_channel_auctions_name, R.string.push_channel_auctions_description, NotificationManager.IMPORTANCE_HIGH),
                channel(context, OFFERS, R.string.push_channel_offers_name, R.string.push_channel_offers_description, NotificationManager.IMPORTANCE_DEFAULT),
            ),
        )
    }

    private fun channel(context: Context, id: String, nameRes: Int, descriptionRes: Int, importance: Int): NotificationChannel {
        val channel = NotificationChannel(id, context.getString(nameRes), importance)
        channel.description = context.getString(descriptionRes)
        return channel
    }
}
